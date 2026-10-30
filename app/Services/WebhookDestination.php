<?php

namespace App\Services;

class WebhookDestination
{
    public function normalize(string $url): string
    {
        $u = parse_url($url);
        $host = strtolower($u['host'] ?? '');
        $own = strtolower((string)parse_url(config('app.url'), PHP_URL_HOST));
        abort_unless(
            $u && ($u['scheme'] ?? '') === 'https' && !isset($u['user'],$u['pass'])
            && !isset($u['user']) && !isset($u['pass']) && !isset($u['query']) && !isset($u['fragment'])
            && (!isset($u['port']) || $u['port'] === 443) && strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            && !preg_match('/[\x00-\x20\x7f\\\\]|%(?:00|0a|0d)/i', $url)
            && !preg_match('/(?:^|\.)(localhost|local|internal|test|invalid)$/D', $host)
            && $host !== $own && $host !== 'nuers.net' && !str_ends_with($host, '.nuers.net'),
            422,
            'Use your own public HTTPS callback hostname on port 443, without credentials, query parameters or fragments.'
        );
        return 'https://'.$host.($u['path'] ?? '/');
    }

    public function dns(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');
        return array_values(array_unique([...$v4,...$v6]));
    }

    private function within(string $ip, string $range): bool
    {
        [$net,$bits] = explode('/', $range);
        $a = inet_pton($ip);
        $b = inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bytes = intdiv((int)$bits, 8);
        $rem = (int)$bits % 8;
        return substr($a, 0, $bytes) === substr($b, 0, $bytes) && (!$rem || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xff << (8 - $rem))) === 0);
    }

    public function resolve(string $url): array
    {
        $url = $this->normalize($url);
        $host = parse_url($url, PHP_URL_HOST);
        $ips = $this->dns($host);
        abort_unless($ips, 422, 'Callback hostname does not resolve to a supported public address.');
        foreach ($ips as $ip) {
            abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 422, 'Callback resolves to a private or reserved network.');
            if (str_contains($ip, ':')) {
                abort_unless($this->within($ip, '2000::/3'), 422, 'Callback IPv6 address is not public unicast.');
            }
            foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4','2001::/23','2001:db8::/32','2002::/16','3fff::/20'] as $range) {
                abort_if($this->within($ip, $range), 422, 'Callback resolves to a special-use network.');
            }
        }
        return [$host,$ips[0]];
    }
}
