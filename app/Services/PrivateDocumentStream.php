<?php

namespace App\Services;

use App\Support\UploadDisk;
use Illuminate\Support\Facades\Crypt;

/** Authenticated encryption in bounded frames; legacy small documents remain readable. */
class PrivateDocumentStream
{
    public const MAGIC = "NUERSVD1\n";
    public const BLOCK_BYTES = 1048576;

    public function store(string $source, string $path): void
    {
        $name = UploadDisk::privateName();
        $disk = UploadDisk::private();
        // Frame the document in a temporary stream, then hand the finished object to the disk in one write:
        // an object store has no local path to append to, and never exposes a half-written document.
        $in = fopen($source, 'rb');
        $out = tmpfile();
        try {
            if (! $in || ! $out) throw new \RuntimeException('Private storage is unavailable.');
            $this->write($out, self::MAGIC);
            while (! feof($in)) {
                $block = fread($in, self::BLOCK_BYTES);
                if ($block === false) throw new \RuntimeException('Document could not be read.');
                if ($block === '') break;
                $encrypted = Crypt::encryptString($block);
                $this->write($out, pack('N', strlen($encrypted)).$encrypted);
            }
            rewind($out);
            if ($disk->writeStream($path, $out) === false) throw new \RuntimeException('Private storage is full or unavailable.');
            UploadDisk::restrict($name, $path);
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        } finally {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
        }
    }

    private function write($out, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($out, $bytes);
            if (! $written) throw new \RuntimeException('Private storage is full or unavailable.');
            $bytes = substr($bytes, $written);
        }
    }

    /** Verify every byte before exposing a plaintext stream to a reviewer. Caller closes the temporary stream. */
    public function verified(object $row)
    {
        $disk = UploadDisk::private();
        abort_unless($disk->exists($row->private_path), 409, 'The private document is unavailable. Upload a new copy for review.');
        $in = $disk->readStream($row->private_path);
        $out = tmpfile();
        try {
            if (! $in || ! $out) throw new \RuntimeException('Storage unavailable.');
            $hash = hash_init('sha256'); $bytes = 0;
            $put = function (string $raw) use ($out, $hash, &$bytes) {
                $bytes += strlen($raw);
                if ($bytes > MerchantVerificationDocuments::MAX_BYTES) throw new \RuntimeException('Invalid document length.');
                hash_update($hash, $raw); $this->write($out, $raw);
            };
            if (fread($in, strlen(self::MAGIC)) === self::MAGIC) {
                while (! feof($in)) {
                    $length = fread($in, 4);
                    if ($length === '') break;
                    if (strlen($length) !== 4) throw new \RuntimeException('Invalid frame.');
                    $count = unpack('N', $length)[1];
                    if ($count < 1 || $count > 2 * self::BLOCK_BYTES) throw new \RuntimeException('Invalid frame length.');
                    $encrypted = '';
                    while (strlen($encrypted) < $count && ! feof($in)) {
                        $part = fread($in, $count - strlen($encrypted));
                        if ($part === false || $part === '') break;
                        $encrypted .= $part;
                    }
                    if (strlen($encrypted) !== $count) throw new \RuntimeException('Truncated frame.');
                    $raw = Crypt::decryptString($encrypted);
                    if (strlen($raw) > self::BLOCK_BYTES) throw new \RuntimeException('Invalid frame payload.');
                    $put($raw);
                }
            } else {
                // Object-store streams cannot be rewound: read the small legacy file again from the start.
                fclose($in);
                $in = $disk->readStream($row->private_path);
                if (! $in) throw new \RuntimeException('Storage unavailable.');
                $legacy = stream_get_contents($in, 8 * self::BLOCK_BYTES + 1);
                if (strlen($legacy) > 8 * self::BLOCK_BYTES) throw new \RuntimeException('Invalid legacy file.');
                $raw = base64_decode(Crypt::decryptString($legacy), true);
                if ($raw === false) throw new \RuntimeException('Invalid legacy payload.');
                $put($raw);
            }
            if ($bytes !== (int) $row->bytes || ! hash_equals($row->sha256, hash_final($hash))) throw new \RuntimeException('Document hash mismatch.');
            rewind($out);
            return $out;
        } catch (\Throwable $e) {
            if (is_resource($out)) fclose($out);
            abort(409, 'Document integrity check failed. Upload a new copy for review.');
        } finally {
            if (is_resource($in)) fclose($in);
        }
    }
}
