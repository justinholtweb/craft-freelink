<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\helpers\SsrfGuard;
use justinholtweb\freelink\http\HttpResponse;
use justinholtweb\freelink\http\HttpTransport;
use justinholtweb\freelink\Plugin;
use justinholtweb\freelink\services\Checker;
use PHPUnit\Framework\TestCase;

/**
 * The checker's request/redirect logic with a scripted transport: no network.
 */
class CheckerTest extends TestCase
{
    /** @var list<array{string, string, string, string, bool}> method, url, host, ip, pin */
    public array $requests = [];

    /**
     * @param array<string, HttpResponse|array<string, HttpResponse>> $script url => response, or url => [method => response]
     */
    private function checker(array $script): Checker
    {
        $test = $this;
        $transport = new class($script, $test) implements HttpTransport {
            public function __construct(private array $script, private CheckerTest $test)
            {
            }

            public function request(string $method, string $url, string $host, int $port, string $ip, bool $pin): HttpResponse
            {
                $this->test->requests[] = [$method, $url, $host, $ip, $pin];
                $r = $this->script[$url] ?? new HttpResponse(0, null, 'no route');
                $r = is_array($r) ? ($r[$method] ?? new HttpResponse(0, null, 'no route')) : $r;

                // A well-behaved client connects where it was pinned, unless the script says otherwise.
                return $r->primaryIp !== null ? $r : new HttpResponse($r->code, $r->location, $r->error, $ip);
            }
        };

        $dns = [
            'site.test' => ['93.184.215.14'],
            'other.test' => ['93.184.215.15'],
            'evil.test' => ['127.0.0.1'],
        ];

        $checker = new Checker();
        $checker->transport = $transport;
        $checker->guard = new SsrfGuard([80, 443], [], fn($h) => $dns[$h] ?? []);
        $checker->hostDelayMs = 0;

        return $checker;
    }

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testOk(): void
    {
        $result = $this->checker(['https://site.test/' => new HttpResponse(200)])->checkUrl('https://site.test/');

        self::assertSame('ok', $result['status']);
        self::assertSame(200, $result['httpCode']);
        self::assertSame('HEAD', $this->requests[0][0]);
        self::assertSame('93.184.215.14', $this->requests[0][3], 'connects to the checked address');
        self::assertTrue($this->requests[0][4], 'pinned');
    }

    public function testNotFoundIsBroken(): void
    {
        $result = $this->checker(['https://site.test/x' => new HttpResponse(404)])->checkUrl('https://site.test/x');
        self::assertSame('broken', $result['status']);
        self::assertSame(404, $result['httpCode']);
    }

    public function testHeadNotAllowedFallsBackToGet(): void
    {
        $result = $this->checker(['https://site.test/' => [
            'HEAD' => new HttpResponse(405),
            'GET' => new HttpResponse(200),
        ]])->checkUrl('https://site.test/');

        self::assertSame('ok', $result['status']);
        self::assertSame(['HEAD', 'GET'], array_column($this->requests, 0));
    }

    public function testTransportErrorFallsBackToGetThenErrors(): void
    {
        $result = $this->checker([])->checkUrl('https://site.test/');

        self::assertSame('error', $result['status']);
        self::assertCount(2, $this->requests);
    }

    public function testRateLimitedIsAnError(): void
    {
        $result = $this->checker(['https://site.test/' => new HttpResponse(429)])->checkUrl('https://site.test/');
        self::assertSame('error', $result['status']);
    }

    public function testRedirectIsFollowedAndRecorded(): void
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

    public function testRedirectToPrivateLiteralIsBlocked(): void
    {
        $result = $this->checker([
            'https://site.test/' => new HttpResponse(302, 'http://169.254.169.254/latest/meta-data/'),
        ])->checkUrl('https://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertStringContainsString('Redirects to a refused address', (string)$result['error']);
        self::assertCount(1, $this->requests, 'the internal address is never requested');
    }

    public function testRedirectToHostResolvingPrivateIsBlocked(): void
    {
        $result = $this->checker([
            'https://site.test/' => new HttpResponse(302, 'http://evil.test/'),
        ])->checkUrl('https://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertCount(1, $this->requests);
    }

    public function testRedirectToLoopbackV6AndDecimalIsBlocked(): void
    {
        foreach (['http://[::1]/', 'http://2130706433/', 'http://localhost/', 'file:///etc/passwd'] as $location) {
            $this->requests = [];
            $result = $this->checker(['https://site.test/' => new HttpResponse(301, $location)])->checkUrl('https://site.test/');
            self::assertSame('blocked', $result['status'], $location);
            self::assertCount(1, $this->requests, $location);
        }
    }

    public function testTooManyRedirects(): void
    {
        $script = [];
        for ($i = 0; $i < 10; $i++) {
            $script["https://site.test/$i"] = new HttpResponse(302, '/' . ($i + 1));
        }

        $result = $this->checker($script)->checkUrl('https://site.test/0');

        self::assertSame('error', $result['status']);
        self::assertSame('Too many redirects', $result['error']);
        self::assertCount(6, $this->requests, 'first request + 5 redirects');
    }

    public function testBlockedFirstUrlMakesNoRequest(): void
    {
        $result = $this->checker([])->checkUrl('http://10.0.0.1/');
        self::assertSame('blocked', $result['status']);
        self::assertSame([], $this->requests);
    }

    public function testConnectingToAnotherAddressThanTheCheckedOneIsBlocked(): void
    {
        // e.g. the pin didn't match the client's host and it resolved the name itself.
        $result = $this->checker([
            'https://site.test/' => new HttpResponse(200, null, null, '127.0.0.1'),
        ])->checkUrl('https://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertStringContainsString('other than the one checked', (string)$result['error']);
        self::assertCount(1, $this->requests, 'no GET fallback or redirect follow-up');
    }

    public function testMismatchOnARedirectHopIsBlocked(): void
    {
        $result = $this->checker([
            'https://site.test/' => new HttpResponse(301, 'https://other.test/'),
            'https://other.test/' => new HttpResponse(200, null, null, '10.0.0.7'),
        ])->checkUrl('https://site.test/');

        self::assertSame('blocked', $result['status']);
        self::assertSame(1, $result['redirects']);
    }

    public function testSameIpCompareIsNotationIndependent(): void
    {
        self::assertTrue(Checker::sameIp('2606:4700:4700::1111', '2606:4700:4700:0:0:0:0:1111'));
        self::assertTrue(Checker::sameIp('[::1]', '::1'));
        self::assertFalse(Checker::sameIp('93.184.215.14', '93.184.215.15'));
        self::assertFalse(Checker::sameIp(null, '93.184.215.14'));
        self::assertFalse(Checker::sameIp('', ''));
    }

    public function testNormalisedHostIsWhatIsRequestedAndPinned(): void
    {
        $this->checker(['https://site.test/a' => new HttpResponse(200)])->checkUrl('https://SITE.Test./a');

        self::assertSame('https://site.test/a', $this->requests[0][1], 'URL rebuilt from the normalised host');
        self::assertSame('site.test', $this->requests[0][2], 'pinned for the same host');
    }

    public function testRunIsSkippedWhenChecksAreOff(): void
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            self::markTestSkipped('needs a booted Craft app');
        }

        $settings = $plugin->getSettings();
        $was = $settings->enableExternalChecks;
        $settings->enableExternalChecks = false;

        try {
            $result = $this->checker([])->run();
            self::assertTrue($result['skipped']);
            self::assertSame([], $this->requests);
        } finally {
            $settings->enableExternalChecks = $was;
        }
    }
}
