<?php

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Checks\HttpResponse;
use JustinHoltWeb\FreeLink\Checks\HttpTransport;
use JustinHoltWeb\FreeLink\Helpers\SsrfGuard;
use JustinHoltWeb\FreeLink\Models\Settings;
use JustinHoltWeb\FreeLink\Services\Checker;
use PHPUnit\Framework\TestCase;

/**
 * The checker's request/redirect logic with a scripted transport: no network.
 */
class CheckerTest extends TestCase
{
    /** @var list<array{string, string, string, string, bool}> method, url, host, ip, pin */
    public array $requests = [];

    /**
     * @param  array<string, HttpResponse|array<string, HttpResponse>>  $script  url => response, or url => [method => response]
     */
    private function checker(array $script): Checker
    {
        $test = $this;
        $transport = new class($script, $test) implements HttpTransport
        {
            public function __construct(private array $script, private CheckerTest $test) {}

            public function request(string $method, string $url, string $host, int $port, string $ip, bool $pin): HttpResponse
            {
                $this->test->requests[] = [$method, $url, $host, $ip, $pin];
                $r = $this->script[$url] ?? new HttpResponse(0, null, 'no route');
                $r = is_array($r) ? ($r[$method] ?? new HttpResponse(0, null, 'no route')) : $r;

                // A scripted response came from the pinned address unless it says otherwise.
                return $r->code > 0 && $r->primaryIp === null ? new HttpResponse($r->code, $r->location, $r->error, $ip) : $r;
            }
        };

        $dns = [
            'site.test' => ['93.184.215.14'],
            'other.test' => ['93.184.215.15'],
            'evil.test' => ['127.0.0.1'],
        ];

        $checker = new Checker;
        $checker->transport = $transport;
        $checker->guard = new SsrfGuard([80, 443], [], fn ($h) => $dns[$h] ?? []);
        $checker->hostDelayMs = 0;

        return $checker;
    }

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function test_ok(): void
    {
        $result = $this->checker(['https://site.test/' => new HttpResponse(200)])->checkUrl('https://site.test/');

        self::assertSame('ok', $result['status']);
        self::assertSame(200, $result['httpCode']);
        self::assertSame('HEAD', $this->requests[0][0]);
        self::assertSame('93.184.215.14', $this->requests[0][3], 'connects to the checked address');
        self::assertTrue($this->requests[0][4], 'pinned');
    }

    public function test_not_found_is_broken(): void
    {
        $result = $this->checker(['https://site.test/x' => new HttpResponse(404)])->checkUrl('https://site.test/x');
        self::assertSame('broken', $result['status']);
        self::assertSame(404, $result['httpCode']);
    }

    public function test_head_not_allowed_falls_back_to_get(): void
    {
        $result = $this->checker(['https://site.test/' => [
            'HEAD' => new HttpResponse(405),
            'GET' => new HttpResponse(200),
        ]])->checkUrl('https://site.test/');

        self::assertSame('ok', $result['status']);
        self::assertSame(['HEAD', 'GET'], array_column($this->requests, 0));
    }

    public function test_transport_error_falls_back_to_get_then_errors(): void
    {
        $result = $this->checker([])->checkUrl('https://site.test/');

        self::assertSame('error', $result['status']);
        self::assertCount(2, $this->requests);
    }

    public function test_rate_limited_is_an_error(): void
    {
        $result = $this->checker(['https://site.test/' => new HttpResponse(429)])->checkUrl('https://site.test/');
        self::assertSame('error', $result['status']);
    }

    public function test_redirect_is_followed_and_recorded(): void
    {
        $result = $this->checker([
            'http://site.test/old' => new HttpResponse(301, '/new'),
            'http://site.test/new' => new HttpResponse(302, 'https://other.test/final'),
            'https://other.test/final' => new HttpResponse(200),
        ])->checkUrl('http://site.test/old');

        self::assertSame('redirect', $result['status']);
        self::assertSame(2, $result['redirects']);
        self::assertSame('https://other.test/final', $result['finalUrl']);
        self::assertSame('93.184.215.15', $this->requests[2][3], 'each hop resolved and pinned separately');
    }

    public function test_redirect_to_private_literal_is_blocked(): void
    {
        $result = $this->checker([
            'https://site.test/' => new HttpResponse(302, 'http://169.254.169.254/latest/meta-data/'),
        ])->checkUrl('https://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertStringContainsString('Redirects to a refused address', (string) $result['error']);
        self::assertCount(1, $this->requests, 'the internal address is never requested');
    }

    public function test_redirect_to_host_resolving_private_is_blocked(): void
    {
        $result = $this->checker([
            'https://site.test/' => new HttpResponse(302, 'http://evil.test/'),
        ])->checkUrl('https://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertCount(1, $this->requests);
    }

    public function test_redirect_to_loopback_v6_and_decimal_is_blocked(): void
    {
        foreach (['http://[::1]/', 'http://2130706433/', 'http://localhost/', 'file:///etc/passwd'] as $location) {
            $this->requests = [];
            $result = $this->checker(['https://site.test/' => new HttpResponse(301, $location)])->checkUrl('https://site.test/');
            self::assertSame('blocked', $result['status'], $location);
            self::assertCount(1, $this->requests, $location);
        }
    }

    public function test_too_many_redirects(): void
    {
        $script = [];
        for ($i = 0; $i < 10; $i++) {
            $script["https://site.test/$i"] = new HttpResponse(302, '/'.($i + 1));
        }

        $result = $this->checker($script)->checkUrl('https://site.test/0');

        self::assertSame('error', $result['status']);
        self::assertSame('Too many redirects', $result['error']);
        self::assertCount(6, $this->requests, 'first request + 5 redirects');
    }

    public function test_blocked_first_url_makes_no_request(): void
    {
        $result = $this->checker([])->checkUrl('http://10.0.0.1/');
        self::assertSame('blocked', $result['status']);
        self::assertSame([], $this->requests);
    }

    public function test_run_is_skipped_when_checks_are_off(): void
    {
        $settings = new Settings;
        $settings->enableExternalChecks = false;

        $checker = $this->checker([]);
        $checker->settings = $settings;

        $result = $checker->run();
        self::assertTrue($result['skipped']);
        self::assertSame([], $this->requests);
    }

    public function test_mixed_case_and_trailing_dot_host_is_requested_normalised(): void
    {
        $result = $this->checker(['http://site.test/A' => new HttpResponse(200)])->checkUrl('http://SiTe.TEST./A');

        self::assertSame('ok', $result['status']);
        // The URL curl is given carries exactly the host the connection is pinned for.
        self::assertSame(['HEAD', 'http://site.test/A', 'site.test', '93.184.215.14', true], $this->requests[0]);
    }

    public function test_answer_from_another_address_is_refused(): void
    {
        $result = $this->checker(['http://site.test/' => new HttpResponse(200, null, null, '10.0.0.5')])->checkUrl('http://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertStringContainsString('not the checked address', (string) $result['error']);
        self::assertCount(1, $this->requests, 'nothing more is requested');
    }

    public function test_redirect_hop_answered_by_another_address_is_refused(): void
    {
        $result = $this->checker([
            'http://site.test/' => new HttpResponse(301, 'http://other.test/next'),
            'http://other.test/next' => new HttpResponse(200, null, null, '93.184.215.14'),
        ])->checkUrl('http://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertSame(1, $result['redirects']);
    }

    public function test_ipv6_addresses_compare_canonically(): void
    {
        $checker = $this->checker(['http://[2606:2800:220:1:248:1893:25c8:1946]/' => new HttpResponse(200, null, null, '2606:2800:0220:0001:0248:1893:25c8:1946')]);

        self::assertSame('ok', $checker->checkUrl('http://[2606:2800:220:1:248:1893:25c8:1946]/')['status']);
    }

    public function test_answer_with_unknown_address_is_refused(): void
    {
        $transport = new class implements HttpTransport
        {
            public function request(string $method, string $url, string $host, int $port, string $ip, bool $pin): HttpResponse
            {
                return new HttpResponse(200);
            }
        };
        $checker = new Checker;
        $checker->transport = $transport;
        $checker->guard = new SsrfGuard([80, 443], [], fn ($h) => ['93.184.215.14']);
        $checker->hostDelayMs = 0;

        self::assertSame('blocked', $checker->checkUrl('http://site.test/')['status']);
    }
}
