<?php

namespace NuersOperator;

use RuntimeException;
use ZipArchive;

/** Authenticated streaming envelope. The recovery key and application configuration are stored separately. */
class RecoveryArchive
{
    private const MAGIC = "NUERSBK1\n";

    private const CHUNK = 1048576;

    public function create(array $files, string $output, string $key, array $context = []): array
    {
        $this->key($key);
        if (file_exists($output) || is_link($output)) {
            throw new RuntimeException('Recovery archives are never overwritten.');
        }
        $temp = $output.'.'.bin2hex(random_bytes(8)).'.partial.zip';
        $mask = umask(0077);
        $zip = new ZipArchive;
        $manifest = ['format' => 'NUERS-recovery-1', 'created_at' => gmdate('c'), 'context' => $context, 'files' => []];
        try {
            if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Cannot create private archive staging file.');
            }
            $size = 0;
            foreach ($files as $name => $path) {
                $this->name($name);
                if ($name === 'manifest.json' || is_link($path) || ! is_file($path)) {
                    throw new RuntimeException('Invalid backup source.');
                }
                $bytes = filesize($path);
                $size += $bytes;
                if ($size > (5 * 1024 * 1024 * 1024) || count($manifest['files']) >= 100000) {
                    throw new RuntimeException('Archive exceeds the configured size or entry limit.');
                }
                $manifest['files'][$name] = ['size' => $bytes, 'sha256' => hash_file('sha256', $path)];
                if (! $zip->addFile($path, $name)) {
                    throw new RuntimeException('Cannot add backup source.');
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException('Archive could not be finalized.');
            }
            chmod($temp, 0600);
            foreach ($files as $name => $path) {
                if (! hash_equals($manifest['files'][$name]['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('A backup source changed during capture. Retry a consistent snapshot.');
                }
            }
            $this->encrypt($temp, $output, $key);

            return ['encrypted' => true, 'cipher' => 'XChaCha20-Poly1305 secretstream', 'size_bytes' => filesize($output), 'sha256' => hash_file('sha256', $output), 'files' => count($files), 'restore_verified' => false];
        } finally {
            umask($mask);
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function restore(string $archive, string $target, string $key): array
    {
        $this->key($key);
        if (file_exists($target) || is_link($target) || ! is_dir(dirname($target))) {
            throw new RuntimeException('Restore requires a new private directory; existing targets are never overwritten.');
        }
        if (! mkdir($target, 0700)) {
            throw new RuntimeException('Cannot create private recovery directory.');
        }
        $temp = $target.'/.authenticated.zip';
        $zip = new ZipArchive;
        try {
            $this->decrypt($archive, $temp, $key);
            if ($zip->open($temp) !== true || $zip->numFiles > 100001) {
                throw new RuntimeException('Invalid recovery archive.');
            }
            $stat = $zip->statName('manifest.json');
            if (! $stat || $stat['size'] > 32 * 1024 * 1024) {
                throw new RuntimeException('Invalid recovery manifest.');
            }
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 'NUERS-recovery-1' || ! is_array($manifest['files'] ?? null) || $zip->numFiles !== count($manifest['files']) + 1) {
                throw new RuntimeException('Archive entries do not match the manifest.');
            }
            $total = 0;
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $this->name($name);
                if (isset($seen[$name])) {
                    throw new RuntimeException('Duplicate archive member.');
                }
                $seen[$name] = true;
                if ($name === 'manifest.json') {
                    continue;
                }
                $meta = $manifest['files'][$name] ?? null;
                $entry = $zip->statIndex($i);
                $total += $entry['size'];
                if (! $meta || $meta['size'] !== $entry['size'] || $total > (5 * 1024 * 1024 * 1024)) {
                    throw new RuntimeException('Recovery size or manifest mismatch.');
                }
                $path = $target.'/'.$name;
                if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
                    throw new RuntimeException('Cannot create recovered object directory.');
                }
                $in = $zip->getStream($name);
                $out = fopen($path, 'xb');
                if (! $in || ! $out) {
                    throw new RuntimeException('Cannot recover archive member.');
                }
                chmod($path, 0600);
                try {
                    $copied = stream_copy_to_stream($in, $out, $meta['size'] + 1);
                } finally {
                    fclose($in);
                    fclose($out);
                }
                if ($copied !== $meta['size'] || ! hash_equals($meta['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('Recovered file integrity mismatch.');
                }
            }
            $zip->close();
            unlink($temp);

            return $manifest;
        } catch (\Throwable $e) {
            // Only the fresh directory created by this invocation can be removed.
            self::removeTree($target);
            throw $e;
        }
    }

    private function name(string $name): void
    {
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/ -]*$#D', $name) || in_array('..', explode('/', $name), true) || in_array('.', explode('/', $name), true) || str_contains($name, '//')) {
            throw new RuntimeException('Unsafe recovery member path.');
        }
    }

    private function key(string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('A separate 32-byte recovery key is required.');
        }
    }

    private function encrypt(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'xb');
        if (! $in || ! $out) {
            throw new RuntimeException('Cannot open recovery stream.');
        }
        chmod($target, 0600);
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->write($out, self::MAGIC.$header);
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read backup stream.');
                }
                if ($chunk !== '') {
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk);
                    $this->write($out, pack('N', strlen($cipher)).$cipher);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->write($out, pack('N', strlen($final)).$final);
            fflush($out);
            if (function_exists('fsync') && ! fsync($out)) {
                throw new RuntimeException('Recovery archive sync failed.');
            }
            sodium_memzero($state);
        } catch (\Throwable $e) {
            unlink($target);
            throw $e;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function decrypt(string $source, string $target, string $key): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'xb');
        if (! $in || ! $out) {
            throw new RuntimeException('Cannot open recovery stream.');
        }
        chmod($target, 0600);
        try {
            if ($this->read($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Unknown encrypted archive format.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($this->read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $bytes = 0;
            do {
                $length = unpack('N', $this->read($in, 4))[1];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Invalid encrypted frame length.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($in, $length));
                if ($result === false) {
                    throw new RuntimeException('Recovery authentication failed.');
                }
                [$plain, $tag] = $result;
                $bytes += strlen($plain);
                if ($bytes > (5 * 1024 * 1024 * 1024) + 64 * 1024 * 1024) {
                    throw new RuntimeException('Recovery stream exceeds configured limit.');
                }
                $this->write($out, $plain);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (fread($in, 1) !== '' || $plain !== '') {
                throw new RuntimeException('Unexpected trailing recovery data.');
            }
            sodium_memzero($state);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $n = fwrite($stream, $bytes);
            if ($n === false || $n === 0) {
                throw new RuntimeException('Recovery write failed.');
            }
            $bytes = substr($bytes, $n);
        }
    }

    private function read($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Recovery archive is truncated.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
    public static function removeTree(string $path): void
    {
        if (is_link($path)) { unlink($path); return; }
        if (! is_dir($path)) return;
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && ! $item->isLink()) self::removeTree($item->getPathname());
            else unlink($item->getPathname());
        }
        rmdir($path);
    }

}
