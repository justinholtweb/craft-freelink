<?php

namespace justinholtweb\freelink\helpers;

/**
 * Decides whether the link checker may request a URL, and which address it must connect to.
 *
 * Run for the first request and again for every redirect hop. No Craft dependency; DNS is injected
 * so it is testable. See docs/5.3-design.md §7.3.
 */
class SsrfGuard
{
    /** @var callable(string): string[] */
    private $resolver;

    /**
     * @param int[] $allowedPorts
     * @param string[] $allowedHosts Hosts that may resolve to private addresses (config file only).
     * @param (callable(string): string[])|null $resolver Returns every A/AAAA address for a host.
     */
    public function __construct(
        private array $allowedPorts = [80, 443],
        private array $allowedHosts = [],
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? [self::class, 'resolve'];
    }

    /**
     * Validates a URL and returns where to connect.
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string, isIpLiteral: bool}
     * @throws BlockedUrlException when the URL may not be requested
     */
    public function check(string $url): array
    {
        if (strlen($url) > 2048) {
            throw new BlockedUrlException('URL is too long');
        }

        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new BlockedUrlException('URL contains whitespace or control characters');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new BlockedUrlException('Not an absolute URL');
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedUrlException('Only http and https URLs are checked');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException('URLs with credentials are not checked');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (!in_array($port, $this->allowedPorts, true)) {
            throw new BlockedUrlException("Port $port is not allowed");
        }

        $host = strtolower($parts['host']);
        $host = rawurldecode($host);

        // IPv6 literal
        if (str_starts_with($host, '[')) {
            $literal = trim($host, '[]');

            if (str_contains($literal, '%')) {
                throw new BlockedUrlException('IPv6 zone IDs are not allowed');
            }

            if (@inet_pton($literal) === false || !str_contains($literal, ':')) {
                throw new BlockedUrlException('Invalid IPv6 address');
            }

            $this->assertPublic($literal, $literal);

            return $this->target($url, $scheme, $host, $port, (string)inet_ntop((string)inet_pton($literal)), true);
        }

        $host = rtrim($host, '.');

        if ($host === '') {
            throw new BlockedUrlException('Empty host');
        }

        // Numeric IPv4 in any form a resolver accepts (decimal, octal, hex, short).
        $v4 = IpRanges::parseLooseIpv4($host);
        if ($v4 !== null) {
            $this->assertPublic($v4, $host);

            return $this->target($url, $scheme, $host, $port, $v4, true);
        }

        if (preg_match('/[^\x20-\x7e]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                throw new BlockedUrlException('Internationalised host names need the intl extension');
            }

            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                throw new BlockedUrlException('Invalid host name');
            }

            $host = strtolower($ascii);
        }

        if (!preg_match('/^[a-z0-9_]([a-z0-9_\-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_\-]*[a-z0-9_])?)*$/', $host)) {
            throw new BlockedUrlException('Invalid host name');
        }

        $allowedHost = in_array($host, $this->allowedHosts, true);

        if (!$allowedHost && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            throw new BlockedUrlException('Loopback host name');
        }

        $ips = array_values(array_unique(array_filter(($this->resolver)($host), fn($ip) => is_string($ip) && $ip !== '')));

        if (!$ips) {
            throw new BlockedUrlException('Host name does not resolve', false);
        }

        // Every answer must be public: one private answer is enough to refuse.
        if (!$allowedHost) {
            foreach ($ips as $ip) {
                $this->assertPublic($ip, $host);
            }
        }

        // Prefer IPv4 for the pinned connection when there is one.
        usort($ips, fn($a, $b) => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));

        return $this->target($url, $scheme, $host, $port, $ips[0], false);
    }

    /**
     * @return array{url: string, scheme: string, host: string, port: int, ip: string, isIpLiteral: bool}
     */
    private function target(string $url, string $scheme, string $host, int $port, string $ip, bool $isIpLiteral): array
    {
        if ($isIpLiteral) {
            // Request the canonical address, so the client can't read the host differently.
            $hostPart = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $url = self::replaceHost($url, $hostPart);
            $host = $hostPart;
        } else {
            // Request the normalised host (lower case, no trailing dot, IDN as ASCII): it's the name
            // the connection is pinned for, so the client must not see a different spelling of it.
            $url = self::replaceHost($url, $host);
        }

        // Fragments are never sent.
        $url = preg_replace('/#.*$/s', '', $url) ?? $url;

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ip, 'isIpLiteral' => $isIpLiteral];
    }

    private function assertPublic(string $ip, string $host): void
    {
        $reason = IpRanges::blockedReason($ip);

        if ($reason !== null && !in_array($host, $this->allowedHosts, true)) {
            throw new BlockedUrlException(sprintf('%s resolves to a blocked address (%s)', $host, $reason));
        }
    }

    private static function replaceHost(string $url, string $host): string
    {
        return (string)preg_replace('#^([a-z][a-z0-9+.\-]*://)(\[[^\]]*\]|[^/?\#:]*)#i', '${1}' . $host, $url, 1);
    }

    /**
     * Every A and AAAA address for a host, from DNS and the system resolver (which reads
     * /etc/hosts). Both are used so a host that is private in either is refused.
     *
     * @return string[]
     */
    public static function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        return array_values(array_unique($ips));
    }
}
