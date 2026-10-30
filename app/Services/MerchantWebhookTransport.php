<?php

namespace App\Services;

/** No proxy/redirect fallback. DNS is checked again and pinned for this TLS connection. */
class MerchantWebhookTransport
{
    public function send(string $url, array $headers, string $body): array
    {
        [$host,$ip] = app(WebhookDestination::class)->resolve($url);
        $handle = curl_init($url);
        $response = '';
        $tooLarge = false;
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        curl_setopt_array($handle, [CURLOPT_POST => true,CURLOPT_POSTFIELDS => $body,CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,CURLOPT_MAXREDIRS => 0,CURLOPT_PROXY => '',CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,CURLOPT_SSL_VERIFYHOST => 2,CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,CURLOPT_RESOLVE => [$host.':443:'.$pin],
            CURLOPT_IPRESOLVE => str_contains($ip, ':') ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 3,CURLOPT_TIMEOUT => 8,CURLOPT_USERAGENT => 'NUERS-Webhooks/1.0',
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$response, &$tooLarge) {
                if (strlen($response) + strlen($chunk) > 16384) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        return ['status' => $status ?: null,'body' => $response,'error' => $tooLarge ? 'response_too_large' : ($ok === false ? 'transport_failure' : null)];
    }
}
