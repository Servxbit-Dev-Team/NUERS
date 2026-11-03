<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The two places the application keeps uploaded files, resolved from configuration so a web fleet can share them.
 *
 * private(): encrypted verification documents, branch evidence, upload chunks, admin avatars. Never publicly readable.
 * images():  merchant logos and client avatars, served by the application (see UploadedImageController).
 *
 * Defaults are the local disks the application has always used, so nothing changes until NUERS_PRIVATE_DISK /
 * NUERS_IMAGE_DISK are set (for example to s3_private / s3_images). Code must use stream and put/get calls only:
 * a path on the local filesystem does not exist for an object store.
 */
class UploadDisk
{
    public static function privateName(): string
    {
        return (string) config('filesystems.private_disk', 'local');
    }

    public static function imagesName(): string
    {
        return (string) config('filesystems.image_disk', 'uploads_public');
    }

    public static function private(): Filesystem
    {
        return Storage::disk(self::privateName());
    }

    public static function images(): Filesystem
    {
        return Storage::disk(self::imagesName());
    }

    /** True when the named disk is a directory on this server (permissions can then be tightened with chmod). */
    public static function isLocal(string $disk): bool
    {
        return config("filesystems.disks.$disk.driver") === 'local';
    }

    /** Owner-only permissions on a freshly written local file; object stores have no such concept. */
    public static function restrict(string $disk, string $path): void
    {
        if (self::isLocal($disk)) {
            @chmod(Storage::disk($disk)->path($path), 0600);
        }
    }
}
