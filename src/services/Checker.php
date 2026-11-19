<?php

namespace justinholtweb\freelink\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use justinholtweb\freelink\helpers\BlockedUrlException;
use justinholtweb\freelink\helpers\SsrfGuard;
use justinholtweb\freelink\http\CurlTransport;
use justinholtweb\freelink\http\HttpTransport;
use justinholtweb\freelink\migrations\Install;
use justinholtweb\freelink\Plugin;
use yii\base\Component;

/**
 * Checks stored URL links over HTTP and keeps the results in `freelink_checks`.
 *
 * Only URLs read from the link index are ever checked — no method here takes a URL from a request.
 * See docs/5.3-design.md §7.
 */
class Checker extends Component
{
    public const MAX_REDIRECTS = 5;

    public ?HttpTransport $transport = null;
    public ?SsrfGuard $guard = null;

    /** Overrides the `hostDelay` setting (ms), e.g. in tests. */
    public ?int $hostDelayMs = null;

    /** @var callable(int): void Sleeps for the given microseconds (swappable in tests). */
    public $sleeper = 'usleep';

    /** @var array<string, float> host => microtime of the last request */
    private array $_lastRequest = [];

    public function getTransport(): HttpTransport
    {
        return $this->transport ??= new CurlTransport();
    }

    public function getGuard(): SsrfGuard
    {
        if ($this->guard === null) {
            $settings = Plugin::getInstance()->getSettings();
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
                $message = $redirects > 0 ? 'Redirects to a refused address: ' . $e->getMessage() : $e->getMessage();

                return $this->result($e->blocked ? 'blocked' : 'error', null, $redirects ? $current : null, $redirects, $message);
            }

            $this->politeness($target['host']);

            $transport = $this->getTransport();
            $pin = !$target['isIpLiteral'];
            $response = $transport->request('HEAD', $target['url'], $target['host'], $target['port'], $target['ip'], $pin);

            // The pin must have held: whatever answered has to be the address the guard approved.
            // Checked after every request, before anything else is done with the answer.
            $mismatch = fn($r) => $r->code > 0 && !self::sameIp($r->primaryIp, $target['ip']);

            if ($mismatch($response)) {
                return $this->result('blocked', null, $redirects ? $current : null, $redirects, 'Connected to an address other than the one checked');
            }

            // Some servers refuse or mishandle HEAD: one GET, with the byte cap.
            if ($response->error !== null || in_array($response->code, [400, 403, 405, 501], true)) {
                $response = $transport->request('GET', $target['url'], $target['host'], $target['port'], $target['ip'], $pin);

                if ($mismatch($response)) {
                    return $this->result('blocked', null, $redirects ? $current : null, $redirects, 'Connected to an address other than the one checked');
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
                    $current = (string)UriResolver::resolve(new Uri($target['url']), new Uri($response->location));
                } catch (\Throwable) {
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
     * @param callable(int $done, int $total, string $url): void|null $progress
     * @return array{checked: int, results: array<string, int>, skipped: bool}
     */
    public function run(bool $force = false, ?int $limit = null, ?callable $progress = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enableExternalChecks) {
            return ['checked' => 0, 'results' => [], 'skipped' => true];
        }

        $limit = $limit ?? $settings->maxChecksPerRun;
        $urls = Plugin::getInstance()->linkIndex->urlsDueForCheck($limit, $force);
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
     * @param array{status: string, httpCode: int|null, finalUrl: string|null, redirects: int, error: string|null} $result
     */
    public function saveResult(string $urlHash, string $url, array $result): void
    {
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

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

        $id = (new Query())->select(['id'])->from(Install::TABLE_CHECKS)->where(['urlHash' => $urlHash])->scalar();

        if ($id) {
            $db->createCommand()->update(Install::TABLE_CHECKS, $columns, ['id' => $id], [], false)->execute();
        } else {
            $db->createCommand()->insert(Install::TABLE_CHECKS, $columns + [
                'urlHash' => $urlHash,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * Removes results for URLs no longer used by any link.
     */
    public function deleteOrphans(): int
    {
        $used = (new Query())->select(['urlHash'])->distinct()->from(Install::TABLE_INDEX)->where(['not', ['urlHash' => null]]);

        return Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_CHECKS, ['not in', 'urlHash', $used])
            ->execute();
    }

    /**
     * Waits so two requests to one host are at least `hostDelay` ms apart.
     */
    private function politeness(string $host): void
    {
        $delay = max(0, $this->hostDelayMs ?? Plugin::getInstance()->getSettings()->hostDelay) / 1000;
        $now = microtime(true);

        if ($delay > 0 && isset($this->_lastRequest[$host])) {
            $wait = $this->_lastRequest[$host] + $delay - $now;

            if ($wait > 0) {
                ($this->sleeper)((int)($wait * 1_000_000));
            }
        }

        $this->_lastRequest[$host] = microtime(true);
    }

    /**
     * Whether two IP address strings are the same address (any notation).
     */
    public static function sameIp(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return false;
        }

        $pa = @inet_pton(trim($a, '[]'));
        $pb = @inet_pton(trim($b, '[]'));

        return $pa !== false && $pb !== false && $pa === $pb;
    }

    /**
     * @return array{status: string, httpCode: int|null, finalUrl: string|null, redirects: int, error: string|null}
     */
    private function result(string $status, ?int $code, ?string $finalUrl, int $redirects, ?string $error): array
    {
        return ['status' => $status, 'httpCode' => $code, 'finalUrl' => $finalUrl, 'redirects' => $redirects, 'error' => $error];
    }
}
