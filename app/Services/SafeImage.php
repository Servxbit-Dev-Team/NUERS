<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SafeImage
{
    /** Decode/re-encode raster pixels; never keep the client suffix, metadata or executable trailing bytes. */
    public function store(UploadedFile $file, string $directory): string
    {
        $size = @getimagesize($file->getRealPath());
        abort_unless($size && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 16000000, 422, 'Image dimensions exceed the supported limit.');
        abort_unless(in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true), 422, 'Unsupported image encoding.');
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($image, 422, 'Cannot decode this image.');
        imagesavealpha($image, true);
        File::ensureDirectoryExists($directory);
        $name = (string) Str::uuid().'.png';
        try {
            abort_unless(imagepng($image, $directory.'/'.$name, 7), 500, 'Cannot save image.');
            chmod($directory.'/'.$name, 0644);
        } finally {
            imagedestroy($image);
        }

        return $name;
    }
}
