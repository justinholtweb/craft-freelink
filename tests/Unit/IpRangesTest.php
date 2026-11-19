<?php

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Helpers\IpRanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every blocked IPv4/IPv6 range, the edges around them, and inet_aton()-style IPv4 parsing.
 */
class IpRangesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'this network' => ['0.0.0.0'],
            'this network top' => ['0.255.255.255'],
            '10/8' => ['10.0.0.1'],
            '10/8 top' => ['10.255.255.255'],
            'CGNAT' => ['100.64.0.1'],
            'CGNAT top' => ['100.127.255.255'],
            'Alibaba metadata' => ['100.100.100.200'],
            'loopback' => ['127.0.0.1'],
            'loopback other' => ['127.255.255.254'],
            'link-local' => ['169.254.1.1'],
            'AWS/GCP/Azure metadata' => ['169.254.169.254'],
            'ECS metadata' => ['169.254.170.2'],
            '172.16/12' => ['172.16.0.1'],
            '172.16/12 top' => ['172.31.255.255'],
            'IETF 192.0.0/24' => ['192.0.0.8'],
            'TEST-NET-1' => ['192.0.2.1'],
            '6to4 relay' => ['192.88.99.1'],
            '192.168/16' => ['192.168.1.1'],
            'benchmarking' => ['198.18.0.1'],
            'benchmarking top' => ['198.19.255.255'],
            'TEST-NET-2' => ['198.51.100.1'],
            'TEST-NET-3' => ['203.0.113.7'],
            'multicast' => ['224.0.0.1'],
            'multicast top' => ['239.255.255.255'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'v6 unspecified' => ['::'],
            'v6 loopback' => ['::1'],
            'v6 loopback bracketed' => ['[::1]'],
            'v4-mapped loopback' => ['::ffff:127.0.0.1'],
            'v4-mapped metadata' => ['::ffff:169.254.169.254'],
            'v4-mapped hex form' => ['::ffff:7f00:1'],
            'v4-mapped public' => ['::ffff:8.8.8.8'],
            'v4-compatible' => ['::127.0.0.1'],
            'NAT64' => ['64:ff9b::7f00:1'],
            'NAT64 local' => ['64:ff9b:1::1'],
            'discard' => ['100::1'],
            'Teredo' => ['2001::1'],
            'ORCHID' => ['2001:10::1'],
            'documentation v6' => ['2001:db8::1'],
            '6to4' => ['2002:7f00:1::1'],
            'ULA' => ['fc00::1'],
            'ULA fd' => ['fd12:3456::1'],
            'AWS IPv6 metadata' => ['fd00:ec2::254'],
            'link-local v6' => ['fe80::1'],
            'site-local v6' => ['fec0::1'],
            'multicast v6' => ['ff02::1'],
            'outside 2000::/3' => ['4000::1'],
            'garbage' => ['not-an-ip'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_blocked(string $ip): void
    {
        self::assertNotNull(IpRanges::blockedReason($ip), "$ip should be blocked");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'example.com' => ['93.184.215.14'],
            'just below 10/8' => ['9.255.255.255'],
            'just above 10/8' => ['11.0.0.0'],
            'just below CGNAT' => ['100.63.255.255'],
            'just above CGNAT' => ['100.128.0.0'],
            'just below 172.16/12' => ['172.15.255.255'],
            'just above 172.16/12' => ['172.32.0.0'],
            'just above 192.168/16' => ['192.169.0.0'],
            'just above benchmarking' => ['198.20.0.0'],
            'last before multicast' => ['223.255.255.255'],
            'v6 public' => ['2606:4700:4700::1111'],
            'v6 google' => ['2001:4860:4860::8888'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public(string $ip): void
    {
        self::assertNull(IpRanges::blockedReason($ip), "$ip should be public");
    }

    public function test_metadata_is_named(): void
    {
        self::assertStringContainsString('metadata', IpRanges::blockedReason('169.254.169.254') ?? '');
        self::assertStringContainsString('metadata', IpRanges::blockedReason('fd00:ec2::254') ?? '');
    }

    public function test_mapped_address_reports_embedded_v4(): void
    {
        self::assertStringContainsString('127.0.0.1', IpRanges::blockedReason('::ffff:127.0.0.1') ?? '');
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function looseIpv4(): array
    {
        return [
            'dotted' => ['127.0.0.1', '127.0.0.1'],
            'decimal integer' => ['2130706433', '127.0.0.1'],
            'octal' => ['0177.0.0.1', '127.0.0.1'],
            'octal full' => ['0177.0000.0000.0001', '127.0.0.1'],
            'hex integer' => ['0x7f000001', '127.0.0.1'],
            'hex dotted' => ['0x7f.0x0.0x0.0x1', '127.0.0.1'],
            'hex short' => ['0x7f.1', '127.0.0.1'],
            'short form' => ['127.1', '127.0.0.1'],
            'three parts' => ['10.0.1', '10.0.0.1'],
            'metadata decimal' => ['2852039166', '169.254.169.254'],
            'metadata octal' => ['0251.0376.0251.0376', '169.254.169.254'],
            'trailing dot' => ['127.0.0.1.', '127.0.0.1'],
            'zero' => ['0', '0.0.0.0'],
            'hostname' => ['example.com', null],
            'hex-looking word' => ['face.be', null],
            'too many parts' => ['1.2.3.4.5', null],
            'octet overflow' => ['256.1.1.1', null],
            'last part overflow' => ['1.2.3.256', null],
            'bad octal' => ['08.0.0.1', null],
            'too big' => ['4294967296', null],
        ];
    }

    #[DataProvider('looseIpv4')]
    public function test_parse_loose_ipv4(string $host, ?string $expected): void
    {
        self::assertSame($expected, IpRanges::parseLooseIpv4($host));
    }
}
