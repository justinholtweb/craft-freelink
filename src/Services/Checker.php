<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Services;

use CraftCms\Cms\Support\Query;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JustinHoltWeb\FreeLink\Checks\CurlTransport;
use JustinHoltWeb\FreeLink\Checks\HttpResponse;
use JustinHoltWeb\FreeLink\Checks\HttpTransport;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Helpers\BlockedUrlException;
use JustinHoltWeb\FreeLink\Helpers\SsrfGuard;
use JustinHoltWeb\FreeLink\Models\Settings;
use JustinHoltWeb\FreeLink\Plugin;
use Throwable;

/**
 * Checks stored URL links over HTTP and keeps the results in `freelink_checks`.
 *
 * Only URLs read from the link index are ever checked — no method here takes a URL from a request.
 * See docs/5.3-design.md §7.
 */
class Checker
{
    public const int MAX_REDIRECTS = 5;

    public ?HttpTransport $transport = null;

    public ?SsrfGuard $guard = null;

    /** Overrides the `hostDelay` setting (ms), e.g. in tests. */
    public ?int $hostDelayMs = null;

    /** @var callable(int): void Sleeps for the given microseconds (swappable in tests). */
    public $sleeper = 'usleep';

    /** Overrides the plugin settings (tests). */
    public ?Settings $settings = null;

    /** @var array<string, float> host => microtime of the last request */
    private array $lastRequest = [];

    private function settings(): Settings
    {
        return $this->settings ?? Plugin::settings();
    }

    public function getTransport(): HttpTransport
    {
        return $this->transport ??= new CurlTransport;
    }

    public function getGuard(): SsrfGuard
    {
        if ($this->guard === null) {
            $settings = $this->settings();
            $this->guard = new SsrfGuard($settings->getAllowedPorts(), $settings->getAllowedHosts());
        }

        return $this->guard;
    }

    /**
     * Checks one URL: guard → HEAD (→ GET) → follow up to 5 redirects, guarding every hop.
     *
     * Internal: callers pass URLs from the index, never from a request.
     *
     * @return array{status: string, httpCode: int|null, finalUrl: string|null, redirects: int, error: string|null}
     */
    public function checkUrl(string $url): array
    {
        $current = $url;
        $redirects = 0;

        while (true) {
            try {
                $target = $this->getGuard()->check($current);
            } catch (BlockedUrlException $e) {
                $message = $redirects > 0 ? 'Redirects to a refused address: '.$e->getMessage() : $e->getMessage();

                return $this->result($e->blocked ? 'blocked' : 'error', null, $redirects ? $current : null, $redirects, $message);
            }

            $this->politeness($target['host']);

            $transport = $this->getTransport();
            $pin = ! $target['isIpLiteral'];
            $response = $transport->request('HEAD', $target['url'], $target['host'], $target['port'], $target['ip'], $pin);

            if ($mismatch = $this->addressMismatch($response, $target['ip'])) {
                return $this->result('blocked', null, $redirects ? $current : null, $redirects, $mismatch);
            }

            // Some servers refuse or mishandle HEAD: one GET, with the byte cap.
            if ($response->error !== null || in_array($response->code, [400, 403, 405, 501], true)) {
                $response = $transport->request('GET', $target['url'], $target['host'], $target['port'], $target['ip'], $pin);

                if ($mismatch = $this->addressMismatch($response, $target['ip'])) {
                    return $this->result('blocked', null, $redirects ? $current : null, $redirects, $mismatch);
                }
            }

            if ($response->error !== null && $response->code === 0) {
                return $this->result('error', null, $redirects ? $current : null, $redirects, mb_substr($response->error, 0, 200));
            }

            $code = $response->code;

            if ($code >= 300 && $code < 400 && $response->location) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    return $this->result('error', $code, $current, $redirects, 'Too many redirects');
                }

                $redirects++;

                try {
                    $current = (string) UriResolver::resolve(new Uri($target['url']), new Uri($response->location));
                } catch (Throwable) {
                    return $this->result('error', $code, $current, $redirects, 'Invalid redirect location');
                }

                continue;
            }

            $finalUrl = $redirects > 0 ? $current : null;

            if ($code >= 200 && $code < 300) {
                return $this->result($redirects > 0 ? 'redirect' : 'ok', $code, $finalUrl, $redirects, null);
            }

            if ($code === 429) {
                return $this->result('error', $code, $finalUrl, $redirects, 'Rate limited');
            }

            if ($code >= 400) {
                return $this->result('broken', $code, $finalUrl, $redirects, null);
            }

            return $this->result('error', $code ?: null, $finalUrl, $redirects, 'Unexpected response');
        }
    }

    /**
     * Checks every due URL in the index (or all with `$force`), up to `maxChecksPerRun`.
     *
     * @param  callable(int $done, int $total, string $url): void|null  $progress
     * @return array{checked: int, results: array<string, int>, skipped: bool}
     */
    public function run(bool $force = false, ?int $limit = null, ?callable $progress = null): array
    {
        $settings = $this->settings();

        if (! $settings->enableExternalChecks) {
            return ['checked' => 0, 'results' => [], 'skipped' => true];
        }

        $limit = $limit ?? $settings->maxChecksPerRun;
        $urls = app(LinkIndex::class)->urlsDueForCheck($limit, $force);
        $total = count($urls);
        $counts = [];

        foreach ($urls as $i => $row) {
            $result = $this->checkUrl($row['url']);
            $this->saveResult($row['urlHash'], $row['url'], $result);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

            if ($progress) {
                $progress($i + 1, $total, $row['url']);
            }
        }

        $this->deleteOrphans();

        return ['checked' => $total, 'results' => $counts, 'skipped' => false];
    }

    /**
     * @param  array{status: string, httpCode: int|null, finalUrl: string|null, redirects: int, error: string|null}  $result
     */
    public function saveResult(string $urlHash, string $url, array $result): void
    {
        $now = Query::prepareDateForDb(now());

        $columns = [
            'url' => $url,
            'status' => $result['status'],
            'httpCode' => $result['httpCode'],
            'finalUrl' => $result['finalUrl'] !== null ? mb_substr($result['finalUrl'], 0, 2048) : null,
            'redirects' => $result['redirects'],
            'error' => $result['error'] !== null ? mb_substr($result['error'], 0, 255) : null,
            'checkedAt' => $now,
            'dateUpdated' => $now,
        ];

        $id = DB::table(Tables::CHECKS)->where('urlHash', $urlHash)->value('id');

        if ($id) {
            DB::table(Tables::CHECKS)->where('id', $id)->update($columns);
        } else {
            DB::table(Tables::CHECKS)->insert($columns + [
                'urlHash' => $urlHash,
                'dateCreated' => $now,
                'uid' => (string) Str::uuid(),
            ]);
        }
    }

    /**
     * Removes results for URLs no longer used by any link.
     */
    public function deleteOrphans(): int
    {
        return DB::table(Tables::CHECKS)
            ->whereNotIn('urlHash', DB::table(Tables::INDEX)->select('urlHash')->whereNotNull('urlHash'))
            ->delete();
    }

    /**
     * Why a response can't be trusted to have come from the approved address, or null when it did.
     *
     * The connection is pinned to the address the guard checked; this confirms it after the fact,
     * for every hop. A response from anywhere else (the pin missed, a proxy, a resolver quirk) is
     * refused, and so is a response whose address the transport can't report.
     */
    private function addressMismatch(HttpResponse $response, string $approvedIp): ?string
    {
        if ($response->code === 0) {
            return null; // Nothing was received; the error path handles it.
        }

        if ($response->primaryIp === null) {
            return 'Couldn’t confirm the address that answered';
        }

        $actual = @inet_pton(trim($response->primaryIp, '[]'));
        $approved = @inet_pton(trim($approvedIp, '[]'));

        if ($actual === false || $approved === false || $actual !== $approved) {
            return sprintf('Connected to %s, not the checked address %s', $response->primaryIp, $approvedIp);
        }

        return null;
    }

    /**
     * Waits so two requests to one host are at least `hostDelay` ms apart.
     */
    private function politeness(string $host): void
    {
        $delay = max(0, $this->hostDelayMs ?? $this->settings()->hostDelay) / 1000;
        $now = microtime(true);

        if ($delay > 0 && isset($this->lastRequest[$host])) {
            $wait = $this->lastRequest[$host] + $delay - $now;

            if ($wait > 0) {
                ($this->sleeper)((int) ($wait * 1_000_000));
            }
        }

        $this->lastRequest[$host] = microtime(true);
    }

    /**
     * @return array{status: string, httpCode: int|null, finalUrl: string|null, redirects: int, error: string|null}
     */
    private function result(string $status, ?int $code, ?string $finalUrl, int $redirects, ?string $error): array
    {
        return ['status' => $status, 'httpCode' => $code, 'finalUrl' => $finalUrl, 'redirects' => $redirects, 'error' => $error];
    }
}
