<?php

declare(strict_types=1);

namespace CptLibrary\Dov;

final class CurlTransport implements Transport
{
    public function get(string $url, array $headers, int $connectTimeout, int $timeout, int $maxBytes, string $userAgent): TransportResponse
    {
        $started = hrtime(true);
        $body = '';
        $truncated = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => false,
            // Stops reading once the response exceeds the size limit.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $truncated = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = $ok === false && !$truncated ? curl_error($ch) : null;
        curl_close($ch);
        return new TransportResponse($status, $body, $contentType, $effectiveUrl, $error, $truncated, (int)((hrtime(true) - $started) / 1e6));
    }
}
