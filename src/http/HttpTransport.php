<?php

namespace justinholtweb\freelink\http;

/**
 * Makes one HTTP request to an address the SSRF guard has already approved. Never follows
 * redirects; the checker does that, re-checking each hop.
 */
interface HttpTransport
{
    /**
     * @param string $method `HEAD` or `GET`
     * @param string $url The URL to request (host as the guard returned it)
     * @param string $host The host name the connection is pinned for
     * @param int $port
     * @param string $ip The approved address to connect to
     * @param bool $pin Whether to pin `$host:$port` to `$ip` (false for IP-literal URLs)
     */
    public function request(string $method, string $url, string $host, int $port, string $ip, bool $pin): HttpResponse;
}
