<?php

namespace App\Http\Controllers;

use App\Support\UploadDisk;
use Illuminate\Support\Facades\Storage;

/**
 * Serves merchant logos and client avatars from the configured image disk. With the default local disk the web server
 * serves these files itself and never reaches this controller; with an object store this is the only path to them.
 * A controller (not a closure) so `route:cache` works.
 */
class UploadedImageController extends Controller
{
    private const FOLDERS = ['business-logos', 'invoice-logos', 'client-avatars'];

    public function __invoke(string $folder, string $file)
    {
        abort_unless(in_array($folder, self::FOLDERS, true) && preg_match('/^[a-f0-9-]{36}\.png$/i', $file), 404);
        $disk = Storage::disk(UploadDisk::imagesName());
        abort_unless($disk->exists($folder.'/'.$file), 404);

        return $disk->response($folder.'/'.$file, null, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
