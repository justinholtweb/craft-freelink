<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Checks;

/**
 * The real transport: ext-curl, pinned to the approved address, no proxy, no redirects,
 * http/https only, 5 s timeouts and a small byte cap.
 */
class CurlTransport implements HttpTransport
{
    public const TIMEOUT = 5;

    public const MAX_BYTES = 65536;

    public const USER_AGENT = 'FreeLinkChecker/6.0 (+Craft CMS link checker)';

    public function request(string $method, string $url, string $host, int $port, string $ip, bool $pin): HttpResponse
    {
        $ch = curl_init();

        if ($ch === false) {
            return new HttpResponse(0, null, 'Could not start curl');
        }

        $location = null;
        $bytes = 0;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // A proxy would resolve the host itself and undo the pin.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$location, &$bytes) {
                $bytes += strlen($header);
                if ($bytes > self::MAX_BYTES) {
                    return -1;
                }
                if (stripos($header, 'location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$bytes) {
                $bytes += strlen($data);

                // Abort once enough is read; the status code is already known.
                return $bytes > self::MAX_BYTES ? -1 : strlen($data);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($pin) {
            $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            $options[CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, $address)];
        }

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $primaryIp = $primaryIp !== '' ? $primaryIp : null;
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;

        // A write abort after the headers arrived is our byte cap, not a failure.
        if ($code > 0 && ($ok !== false || $errno === CURLE_WRITE_ERROR)) {
            return new HttpResponse($code, $location, null, $primaryIp);
        }

        return new HttpResponse($code, $location, $error ?: 'Request failed', $primaryIp);
    }
}
