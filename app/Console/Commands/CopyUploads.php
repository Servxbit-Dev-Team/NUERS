<?php

namespace App\Console\Commands;

use App\Support\UploadDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off, repeatable copy of existing uploaded files from the old local folders to the configured object-store disk.
 * Copy only: it never deletes or changes the source, skips files already present with the same SHA-256, and verifies
 * every copied object by hashing it again. Run it after a backup, again just before the cutover to pick up new files,
 * and keep the local copy until the verified count matches.
 */
class CopyUploads extends Command
{
    protected $signature = 'uploads:copy {kind : private (verification documents, branch evidence, admin avatars) or images (logos, client avatars)} {--from= : source disk (default: the local disk for this kind)} {--to= : target disk (default: the configured disk for this kind)} {--prefix=* : folders to copy (default: all for this kind)} {--dry-run : list what would be copied}';

    protected $description = 'Copy uploaded files to the configured disk with checksum verification (never deletes the source).';

    private const KINDS = [
        'private' => ['from' => 'local', 'prefixes' => ['merchant-verification', 'branch-evidence', 'admin-avatars']],
        'images' => ['from' => 'uploads_public', 'prefixes' => ['client-avatars', 'business-logos', 'invoice-logos']],
    ];

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        if (! isset(self::KINDS[$kind])) {
            $this->error('kind must be "private" or "images".');

            return self::INVALID;
        }
        $fromName = (string) ($this->option('from') ?: self::KINDS[$kind]['from']);
        $toName = (string) ($this->option('to') ?: ($kind === 'private' ? UploadDisk::privateName() : UploadDisk::imagesName()));
        if ($fromName === $toName) {
            $this->error("Source and target are both \"$fromName\". Set NUERS_".($kind === 'private' ? 'PRIVATE' : 'IMAGE').'_DISK (or pass --to) to the new disk first.');

            return self::INVALID;
        }
        $prefixes = $this->option('prefix') ?: self::KINDS[$kind]['prefixes'];
        $from = Storage::disk($fromName);
        $to = Storage::disk($toName);
        $dry = (bool) $this->option('dry-run');
        $copied = $skipped = $failed = $bytes = 0;

        foreach ($prefixes as $prefix) {
            foreach ($from->allFiles($prefix) as $path) {
                $sourceHash = $this->hash($from, $path);
                if ($sourceHash === null) {
                    $this->error("UNREADABLE  $path");
                    $failed++;

                    continue;
                }
                if ($to->exists($path) && $this->hash($to, $path) === $sourceHash) {
                    $skipped++;

                    continue;
                }
                if ($dry) {
                    $this->line("would copy $path");
                    $copied++;

                    continue;
                }
                $in = $from->readStream($path);
                $ok = $in && $to->writeStream($path, $in) !== false;
                if (is_resource($in)) {
                    fclose($in);
                }
                if (! $ok || $this->hash($to, $path) !== $sourceHash) {
                    $this->error("MISMATCH    $path");
                    $failed++;

                    continue;
                }
                $copied++;
                $bytes += (int) $from->size($path);
            }
        }

        $this->info(($dry ? 'Dry run: ' : '')."$copied to copy/copied, $skipped already present and identical, $failed failed, ".number_format($bytes).' bytes written.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function hash($disk, string $path): ?string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
