<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Helpers;

/**
 * Which IP addresses the link checker may connect to.
 *
 * Pure functions, no Craft dependency — ported as-is to Craft 6. See docs/5.3-design.md §7.3.
 */
class IpRanges
{
    /**
     * IPv4 ranges that are never public: CIDR => reason.
     */
    public const BLOCKED_V4 = [
        '0.0.0.0/8' => 'This network',
        '10.0.0.0/8' => 'Private network',
        '100.64.0.0/10' => 'Shared address space (CGNAT)',
        '127.0.0.0/8' => 'Loopback',
        '169.254.0.0/16' => 'Link-local / cloud metadata',
        '172.16.0.0/12' => 'Private network',
        '192.0.0.0/24' => 'IETF protocol assignments',
        '192.0.2.0/24' => 'Documentation (TEST-NET-1)',
        '192.88.99.0/24' => '6to4 relay anycast',
        '192.168.0.0/16' => 'Private network',
        '198.18.0.0/15' => 'Benchmarking',
        '198.51.100.0/24' => 'Documentation (TEST-NET-2)',
        '203.0.113.0/24' => 'Documentation (TEST-NET-3)',
        '224.0.0.0/4' => 'Multicast',
        '240.0.0.0/4' => 'Reserved / broadcast',
    ];

    /**
     * IPv6 ranges that are blocked even though some sit inside 2000::/3 (global unicast), plus the
     * special non-global ranges named for clearer reasons. Anything outside 2000::/3 is blocked
     * regardless.
     */
    public const BLOCKED_V6 = [
        '::/128' => 'Unspecified address',
        '::1/128' => 'Loopback',
        '::ffff:0:0/96' => 'IPv4-mapped address',
        '::/96' => 'IPv4-compatible address',
        '64:ff9b::/96' => 'NAT64',
        '64:ff9b:1::/48' => 'NAT64 (local use)',
        '100::/64' => 'Discard prefix',
        '2001::/23' => 'IETF special purpose (Teredo, benchmarking, ORCHID)',
        '2001:db8::/32' => 'Documentation',
        '2002::/16' => '6to4',
        'fc00::/7' => 'Unique local address (incl. fd00:ec2::254 metadata)',
        'fe80::/10' => 'Link-local',
        'fec0::/10' => 'Site-local (deprecated)',
        'ff00::/8' => 'Multicast',
    ];

    /** Cloud metadata addresses, named in reasons so a refusal is obvious in the log. */
    public const METADATA = [
        '169.254.169.254' => 'Cloud metadata service',
        '169.254.170.2' => 'Container metadata service',
        '100.100.100.200' => 'Cloud metadata service',
        'fd00:ec2::254' => 'Cloud metadata service',
    ];

    /**
     * Why an IP address is blocked, or null when it is a public address.
     * Unparseable input is blocked.
     */
    public static function blockedReason(string $ip): ?string
    {
        $ip = trim($ip, '[] ');
        $bin = @inet_pton($ip);

        if ($bin === false) {
            return 'Not an IP address';
        }

        $canonical = (string) inet_ntop($bin);

        if (isset(self::METADATA[$canonical])) {
            return self::METADATA[$canonical];
        }

        if (strlen($bin) === 4) {
            foreach (self::BLOCKED_V4 as $cidr => $reason) {
                if (self::inCidr($bin, $cidr)) {
                    return $reason;
                }
            }

            return null;
        }

        // IPv4-mapped / -compatible / NAT64: report what the embedded IPv4 address is, too.
        foreach (['::ffff:0:0/96', '::/96', '64:ff9b::/96'] as $embedding) {
            if (self::inCidr($bin, $embedding)) {
                $v4 = (string) inet_ntop(substr($bin, 12, 4));
                $inner = self::blockedReason($v4);

                return self::BLOCKED_V6[$embedding].' ('.$v4.($inner ? ': '.$inner : '').')';
            }
        }

        foreach (self::BLOCKED_V6 as $cidr => $reason) {
            if (self::inCidr($bin, $cidr)) {
                return $reason;
            }
        }

        if (! self::inCidr($bin, '2000::/3')) {
            return 'Not a global unicast address';
        }

        return null;
    }

    public static function isBlocked(string $ip): bool
    {
        return self::blockedReason($ip) !== null;
    }

    /**
     * Whether a packed address is inside a CIDR range of the same family.
     */
    public static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr) + [1 => null];
        $netBin = @inet_pton($net);

        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }

        $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (strncmp($bin, $netBin, $fullBytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    /**
     * Parses a host the way inet_aton() (and so curl and most resolvers) would read it as an IPv4
     * address: `127.0.0.1`, `2130706433`, `0177.0.0.1`, `0x7f.1`, `127.1`. Returns the dotted-quad
     * address, or null when the host is not a numeric IPv4 form.
     */
    public static function parseLooseIpv4(string $host): ?string
    {
        if ($host === '' || ! preg_match('/^[0-9a-fx.]+$/i', $host)) {
            return null;
        }

        $parts = explode('.', $host);

        // A trailing dot is allowed by resolvers ("127.0.0.1.").
        if (end($parts) === '' && count($parts) > 1) {
            array_pop($parts);
        }

        if (count($parts) < 1 || count($parts) > 4) { // @phpstan-ignore smaller.alwaysFalse (kept as in 5.3: defensive)
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^0x[0-9a-f]*$/i', $part)) {
                $hex = substr($part, 2);
                $values[] = $hex === '' ? 0 : hexdec($hex);
            } elseif (preg_match('/^0[0-7]*$/', $part)) {
                $values[] = octdec($part);
            } elseif (preg_match('/^[1-9][0-9]*$/', $part)) {
                $values[] = (float) $part > PHP_INT_MAX ? INF : (int) $part;
            } else {
                return null;
            }
        }

        $n = count($values);
        $last = array_pop($values);

        foreach ($values as $v) {
            if ($v > 255) {
                return null;
            }
        }

        $lastMax = [1 => 0xFFFFFFFF, 2 => 0xFFFFFF, 3 => 0xFFFF, 4 => 0xFF][$n];

        if (! is_numeric($last) || $last > $lastMax) { // @phpstan-ignore function.alreadyNarrowedType (kept as in 5.3: defensive)
            return null;
        }

        $int = 0;
        foreach ($values as $i => $v) {
            $int |= ((int) $v) << (24 - 8 * $i);
        }
        $int |= (int) $last;

        return long2ip($int) ?: null;
    }
}
