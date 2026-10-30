<?php

namespace App\Services;

/** RFC 4226 / RFC 6238 HOTP/TOTP, 160-bit random secret, 30s step, six digits. */
class Totp
{
    public function secret(): string
    {
        return $this->encode(random_bytes(20));
    }

    private function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $part) {
            $out .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec(str_pad($part, 5, '0'))];
        }

        return $out;
    }

    private function decode(string $secret): string
    {
        abort_unless((bool) preg_match('/^[A-Z2-7]{16,128}$/D', $secret), 422, 'Invalid authenticator secret.');
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $part) {
            if (strlen($part) === 8) {
                $out .= chr(bindec($part));
            }
        }

        return $out;
    }

    public function code(string $secret, int $counter, int $digits = 6): string
    {
        $mac = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $this->decode($secret), true);
        $offset = ord($mac[19]) & 15;
        $n = unpack('N', substr($mac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function verify(string $secret, string $code, ?int $last = null): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }$now = intdiv(now()->timestamp, 30);
        foreach ([$now, $now - 1, $now + 1] as $counter) {
            if ($counter > ($last ?? -1) && hash_equals($this->code($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }
}
