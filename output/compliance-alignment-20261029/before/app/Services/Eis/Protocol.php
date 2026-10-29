<?php

namespace App\Services\Eis;

use RuntimeException;

/** BIR EIS API guide 2.3 sections 6–7. Never use these CBC IV rules for other protocols. */
final class Protocol
{
    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function pem(string $value, bool $private = false): string
    {
        if (str_contains($value, '-----BEGIN ')) {
            return trim($value);
        }
        $der = preg_replace('/\s+/', '', $value);
        if (! base64_decode($der, true)) {
            throw new RuntimeException('Invalid key encoding.');
        }
        $label = $private ? 'PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$label}-----\n".chunk_split($der, 64, "\n")."-----END {$label}-----\n";
    }

    public static function key(string $value, bool $private = false): \OpenSSLAsymmetricKey
    {
        $pem = self::pem($value, $private);
        $key = $private ? @openssl_pkey_get_private($pem) : @openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $key || ! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new RuntimeException('A valid RSA key of at least 2048 bits is required.');
        }

        return $key;
    }

    public static function rsa(string $plain, string $publicKey): string
    {
        $key = self::key($publicKey);
        // The official implementation uses a single PKCS#1 v1.5 block, not invented chunking.
        if (strlen($plain) > intdiv(openssl_pkey_get_details($key)['bits'], 8) - 11) {
            throw new RuntimeException('Authentication data exceeds the issued RSA key capacity.');
        }
        if (! openssl_public_encrypt($plain, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            throw new RuntimeException('Authentication encryption failed.');
        }

        return base64_encode($encrypted);
    }

    private static function checkAesKey(string $key): void
    {
        if (strlen($key) !== 32 || ! preg_match('/^[\x21-\x7e]{32}$/D', $key)) {
            throw new RuntimeException('Invalid BIR session key format.');
        }
    }

    public static function encrypt(string $plain, string $key): string
    {
        self::checkAesKey($key);
        $value = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($key, 0, 16));
        if ($value === false) {
            throw new RuntimeException('BIR payload encryption failed.');
        }

        return base64_encode($value);
    }

    public static function decrypt(string $encoded, string $key): string
    {
        self::checkAesKey($key);
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) === 0 || strlen($raw) % 16 !== 0) {
            throw new RuntimeException('Invalid encrypted BIR response.');
        }
        $value = @openssl_decrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($key, 0, 16));
        if ($value === false) {
            throw new RuntimeException('Cannot decrypt BIR response.');
        }

        return $value;
    }

    public static function authorization(string $datetime, string $method, string $path, string $key): string
    {
        return 'Bearer '.base64_encode(hash_hmac('sha256', $datetime.strtoupper($method).$path, $key, true));
    }

    public static function sign(array $invoice, string $kid, string $privateKey): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(self::json(['alg' => 'RS256', 'kid' => $kid])).'.'.$b64(self::json($invoice));
        if (! openssl_sign($input, $signature, self::key($privateKey, true), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Invoice signing failed.');
        }

        return $input.'.'.$b64($signature);
    }
}
