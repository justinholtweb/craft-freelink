<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\helpers\BlockedUrlException;
use justinholtweb\freelink\helpers\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SSRF guard with a fake resolver: schemes, credentials, ports, every IP-literal form,
 * DNS answers (including mixed public/private answers) and the config-file allowlist.
 */
class SsrfGuardTest extends TestCase
{
    /** @var array<string, string[]> */
    private const DNS = [
        'public.test' => ['93.184.215.14'],
        'public6.test' => ['2606:4700:4700::1111'],
        'dual.test' => ['2606:4700:4700::1111', '93.184.215.14'],
        'internal.test' => ['10.1.2.3'],
        'metadata.test' => ['169.254.169.254'],
        'rebind.test' => ['93.184.215.14', '127.0.0.1'],
        'loop6.test' => ['::1'],
        'mapped.test' => ['::ffff:10.0.0.1'],
        'intranet.test' => ['192.168.10.5'],
        'xn--bcher-kva.test' => ['93.184.215.14'],
    ];

    private function guard(array $ports = [80, 443], array $hosts = []): SsrfGuard
    {
        return new SsrfGuard($ports, $hosts, fn(string $host) => self::DNS[$host] ?? []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'ftp' => ['ftp://public.test/file'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://public.test/'],
            'javascript' => ['javascript:alert(1)'],
            'relative' => ['/just/a/path'],
            'credentials' => ['http://user:pass@public.test/'],
            'user only' => ['http://user@public.test/'],
            'port' => ['http://public.test:8080/'],
            'port 22' => ['http://public.test:22/'],
            'loopback literal' => ['http://127.0.0.1/'],
            'loopback other' => ['http://127.0.0.2/'],
            '10/8 literal' => ['http://10.0.0.5/'],
            '172.16 literal' => ['https://172.16.4.4/'],
            '192.168 literal' => ['http://192.168.0.1/'],
            'metadata literal' => ['http://169.254.169.254/latest/meta-data/'],
            'Alibaba metadata' => ['http://100.100.100.200/'],
            'zero' => ['http://0.0.0.0/'],
            'decimal loopback' => ['http://2130706433/'],
            'octal loopback' => ['http://0177.0.0.1/'],
            'hex loopback' => ['http://0x7f.0.0.1/'],
            'hex integer loopback' => ['http://0x7f000001/'],
            'short loopback' => ['http://127.1/'],
            'decimal metadata' => ['http://2852039166/'],
            'octal metadata' => ['http://0251.0376.0251.0376/'],
            'v6 loopback' => ['http://[::1]/'],
            'v6 unspecified' => ['http://[::]/'],
            'v4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'v4-mapped metadata' => ['http://[::ffff:a9fe:a9fe]/'],
            'v6 ULA metadata' => ['http://[fd00:ec2::254]/'],
            'v6 link-local' => ['http://[fe80::1]/'],
            'v6 zone id' => ['http://[fe80::1%25eth0]/'],
            'localhost' => ['http://localhost/'],
            'localhost trailing dot' => ['http://localhost./'],
            'sub.localhost' => ['http://api.localhost/'],
            'resolves private' => ['http://internal.test/'],
            'resolves metadata' => ['http://metadata.test/'],
            'one answer private' => ['http://rebind.test/'],
            'resolves v6 loopback' => ['http://loop6.test/'],
            'resolves v4-mapped private' => ['http://mapped.test/'],
            'whitespace' => ["http://public.test/a b"],
            'control char' => ["http://public.test/\x00"],
            'invalid host chars' => ['http://exa$mple.test/'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefused(string $url): void
    {
        $this->expectException(BlockedUrlException::class);
        $this->guard()->check($url);
    }

    public function testUnresolvableIsAnErrorNotABlock(): void
    {
        try {
            $this->guard()->check('http://nowhere.test/');
            self::fail('expected an exception');
        } catch (BlockedUrlException $e) {
            self::assertFalse($e->blocked);
        }
    }

    public function testPrivateRefusalIsABlock(): void
    {
        try {
            $this->guard()->check('http://10.0.0.1/');
            self::fail('expected an exception');
        } catch (BlockedUrlException $e) {
            self::assertTrue($e->blocked);
        }
    }

    public function testPublicHostIsPinnedToItsAddress(): void
    {
        $target = $this->guard()->check('https://public.test/page?x=1#frag');

        self::assertSame('public.test', $target['host']);
        self::assertSame(443, $target['port']);
        self::assertSame('93.184.215.14', $target['ip']);
        self::assertFalse($target['isIpLiteral']);
        self::assertSame('https://public.test/page?x=1', $target['url'], 'fragment dropped');
    }

    public function testDualStackPrefersIpv4(): void
    {
        self::assertSame('93.184.215.14', $this->guard()->check('http://dual.test/')['ip']);
    }

    public function testIpv6OnlyHost(): void
    {
        self::assertSame('2606:4700:4700::1111', $this->guard()->check('http://public6.test/')['ip']);
    }

    public function testPublicIpLiteral(): void
    {
        $target = $this->guard()->check('http://8.8.8.8/');
        self::assertTrue($target['isIpLiteral']);
        self::assertSame('8.8.8.8', $target['ip']);
    }

    public function testNonCanonicalPublicLiteralIsRequestedCanonically(): void
    {
        // 134744072 == 8.8.8.8: the request goes to the canonical form, never the ambiguous one.
        $target = $this->guard()->check('http://134744072/path');
        self::assertSame('8.8.8.8', $target['ip']);
        self::assertSame('http://8.8.8.8/path', $target['url']);
    }

    public function testPublicIpv6Literal(): void
    {
        $target = $this->guard()->check('http://[2606:4700:4700::1111]:443/');
        self::assertSame('2606:4700:4700::1111', $target['ip']);
        self::assertSame('http://[2606:4700:4700::1111]:443/', $target['url']);
    }

    public function testIdnHostIsConvertedBeforeResolving(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('intl not available');
        }

        self::assertSame('xn--bcher-kva.test', $this->guard()->check('http://bücher.test/')['host']);
    }

    public function testConfiguredPortIsAllowed(): void
    {
        self::assertSame(8080, $this->guard([80, 443, 8080])->check('http://public.test:8080/')['port']);
    }

    public function testAllowlistedHostMayBePrivate(): void
    {
        $guard = $this->guard([80, 443], ['intranet.test']);
        self::assertSame('192.168.10.5', $guard->check('http://intranet.test/')['ip']);

        // …and only that host.
        $this->expectException(BlockedUrlException::class);
        $guard->check('http://internal.test/');
    }

    public function testAllowlistDoesNotCoverOtherLiterals(): void
    {
        $this->expectException(BlockedUrlException::class);
        $this->guard([80, 443], ['intranet.test'])->check('http://127.0.0.1/');
    }
}
