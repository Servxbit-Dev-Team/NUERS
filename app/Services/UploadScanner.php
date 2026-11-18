<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Local, bounded ClamD INSTREAM scanning. No document is sent to an external service. */
class UploadScanner
{
    public function file(string $path): void
    {
        if (! config('security.upload_scan.enabled')) {
            return;
        }
        $stream = @fopen($path, 'rb');
        abort_unless($stream, 503, 'Document security check is unavailable. Please try again later.');
        try {
            $this->stream($stream);
        } finally {
            fclose($stream);
        }
    }

    /** Scans from the beginning and restores the caller's stream position. */
    public function stream($stream): void
    {
        if (! config('security.upload_scan.enabled')) {
            return;
        }
        $position = ftell($stream);
        $slot = null;
        try {
            $size = fstat($stream)['size'] ?? 0;
            abort_unless($size > 0 && $size <= MerchantVerificationDocuments::MAX_BYTES, 422, 'Document size exceeds the security scan limit.');
            $slot = $this->acquireSlot();
            $this->version();
            if (! rewind($stream)) {
                throw new \RuntimeException('Unseekable stream.');
            }
            $result = $this->exchange('INSTREAM', $stream);
            if (str_ends_with($result, ' FOUND')) {
                Log::warning('upload_security_rejected', ['reason' => 'scanner_detection']);
                abort(422, 'This file did not pass the document security check. Upload a clean, unencrypted copy.');
            }
            if ($result !== 'stream: OK') {
                throw new \RuntimeException('Incomplete scan.');
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('upload_security_unavailable');
            abort(503, 'Document security check is unavailable. Please try again later.');
        } finally {
            if (is_resource($slot)) {
                flock($slot, LOCK_UN);
                fclose($slot);
            }
            if ($position !== false && is_resource($stream)) {
                fseek($stream, $position);
            }
        }
    }

    private function acquireSlot()
    {
        // Refuse excess work immediately instead of filling PHP workers with scan waiters.
        for ($number = 0; $number < 2; $number++) {
            $path = storage_path('framework/cache/upload-scan-'.$number.'.lock');
            $lock = @fopen($path, 'c');
            if ($lock) {
                @chmod($path, 0600);
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    return $lock;
                }
                fclose($lock);
            }
        }
        abort(503, 'Document security checks are busy. Please try again shortly.', ['Retry-After' => '5']);
    }

    /** Loaded engine/signature state, not merely a signature file timestamp. */
    public function version(): string
    {
        $version = $this->exchange('VERSION');
        $parts = explode('/', $version, 3);
        $date = isset($parts[2]) ? strtotime($parts[2].' UTC') : false;
        if (! str_starts_with($version, 'ClamAV ') || count($parts) !== 3 || ! ctype_digit($parts[1]) || $date === false || $date > time() + 300 || time() - $date > 72 * 3600) {
            throw new \RuntimeException('Scanner signatures are unavailable or stale.');
        }

        return $version;
    }

    protected function exchange(string $command, $input = null): string
    {
        $socket = (string) config('security.upload_scan.socket');
        if (! str_starts_with($socket, '/') || str_contains($socket, "\0")) {
            throw new \RuntimeException('Invalid local scanner socket.');
        }
        $connection = @stream_socket_client('unix://'.$socket, $code, $message, 2);
        if (! $connection) {
            throw new \RuntimeException('Scanner unavailable.');
        }
        $deadline = microtime(true) + 25;
        try {
            $this->write($connection, 'z'.$command."\0", $deadline);
            if ($input !== null) {
                $bytes = 0;
                while (! feof($input)) {
                    $chunk = fread($input, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Unreadable input.');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $bytes += strlen($chunk);
                    if ($bytes > MerchantVerificationDocuments::MAX_BYTES) {
                        throw new \RuntimeException('Input exceeded scan limit.');
                    }
                    $this->write($connection, pack('N', strlen($chunk)).$chunk, $deadline);
                }
                $this->write($connection, pack('N', 0), $deadline);
            }
            $result = '';
            while (strlen($result) < 4096) {
                $this->timeout($connection, $deadline);
                $part = @fread($connection, 4096 - strlen($result));
                if ($part === false || $part === '') {
                    throw new \RuntimeException('Missing scanner response.');
                }
                $result .= $part;
                if (str_contains($result, "\0")) {
                    if (! str_ends_with($result, "\0") || substr_count($result, "\0") !== 1) {
                        throw new \RuntimeException('Unexpected scanner response.');
                    }

                    return substr($result, 0, -1);
                }
            }
            throw new \RuntimeException('Invalid scanner response.');
        } finally {
            fclose($connection);
        }
    }

    private function timeout($stream, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new \RuntimeException('Scanner timeout.');
        }
        stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
    }

    private function write($stream, string $bytes, float $deadline): void
    {
        while ($bytes !== '') {
            $this->timeout($stream, $deadline);
            $written = @fwrite($stream, $bytes);
            if (! $written) {
                throw new \RuntimeException('Scanner connection closed.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
