<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SafeImage
{
    /** Decode/re-encode raster pixels; never keep the client suffix, metadata or executable trailing bytes. */
    public function encode(UploadedFile $file): string
    {
        $size = @getimagesize($file->getRealPath());
        abort_unless($size && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 16000000, 422, 'Image dimensions exceed the supported limit.');
        abort_unless(in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true), 422, 'Unsupported image encoding.');
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($image, 422, 'Cannot decode this image.');
        imagesavealpha($image, true);
        try {
            ob_start();
            $ok = imagepng($image, null, 7);
            $png = (string) ob_get_clean();
            abort_unless($ok && $png !== '', 500, 'Cannot save image.');
        } finally {
            imagedestroy($image);
        }

        return $png;
    }

    /** Re-encode and write to a storage disk (local folder or object store). Returns the generated file name. */
    public function storeOn(UploadedFile $file, Filesystem $disk, string $directory): string
    {
        $png = $this->encode($file);
        $name = (string) Str::uuid().'.png';
        abort_unless($disk->put(trim($directory, '/').'/'.$name, $png), 500, 'Cannot save image.');

        return $name;
    }

    /** Re-encode into a local directory (kept for callers that work on plain paths). */
    public function store(UploadedFile $file, string $directory): string
    {
        $png = $this->encode($file);
        File::ensureDirectoryExists($directory);
        $name = (string) Str::uuid().'.png';
        abort_unless(file_put_contents($directory.'/'.$name, $png) !== false, 500, 'Cannot save image.');
        chmod($directory.'/'.$name, 0644);

        return $name;
    }
}
