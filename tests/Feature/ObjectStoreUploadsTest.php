<?php

namespace Tests\Feature;

use App\Services\PrivateDocumentStream;
use App\Support\UploadDisk;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MemoryAdapter;
use Tests\TestCase;

/** The upload paths must work on a disk with no local directory behind it (S3 and similar). */
class ObjectStoreUploadsTest extends TestCase
{
    /** @var array<string,MemoryAdapter> */
    private array $adapters = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::extend('memory', function ($app, array $config) {
            $adapter = $this->adapters[$config['name']] ??= new MemoryAdapter;

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
        foreach (['mem_private', 'mem_images', 'mem_a', 'mem_b'] as $name) {
            config(["filesystems.disks.$name" => ['driver' => 'memory', 'name' => $name, 'throw' => true]]);
        }
        config(['filesystems.private_disk' => 'mem_private', 'filesystems.image_disk' => 'mem_images']);
    }

    private function sourceFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nuers');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function row(string $path, string $bytes): object
    {
        return (object) ['private_path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public function test_encrypted_documents_round_trip_on_a_disk_with_no_local_path(): void
    {
        $bytes = random_bytes(2_500_000);
        $source = $this->sourceFile($bytes);
        $stream = app(PrivateDocumentStream::class);
        $stream->store($source, 'merchant-verification/m1/doc.enc');
        unlink($source);

        $stored = $this->adapters['mem_private']->objects['merchant-verification/m1/doc.enc'];
        $this->assertStringStartsWith(PrivateDocumentStream::MAGIC, $stored);
        $this->assertStringNotContainsString(substr($bytes, 0, 64), $stored);

        $out = $stream->verified($this->row('merchant-verification/m1/doc.enc', $bytes));
        $this->assertSame($bytes, stream_get_contents($out));
        fclose($out);
    }

    public function test_legacy_single_object_documents_still_verify_without_seeking(): void
    {
        $bytes = 'legacy evidence '.Str::random(2000);
        UploadDisk::private()->put('branch-evidence/m1/old.enc', Crypt::encryptString(base64_encode($bytes)));
        $out = app(PrivateDocumentStream::class)->verified($this->row('branch-evidence/m1/old.enc', $bytes));
        $this->assertSame($bytes, stream_get_contents($out));
        fclose($out);
    }

    public function test_a_tampered_object_is_refused(): void
    {
        $bytes = random_bytes(5000);
        $source = $this->sourceFile($bytes);
        app(PrivateDocumentStream::class)->store($source, 'merchant-verification/m1/doc.enc');
        unlink($source);
        $this->adapters['mem_private']->objects['merchant-verification/m1/doc.enc'] .= 'tamper';
        try {
            app(PrivateDocumentStream::class)->verified($this->row('merchant-verification/m1/doc.enc', $bytes));
            $this->fail('A tampered document must not be released.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_permission_tightening_is_a_no_op_for_object_stores(): void
    {
        UploadDisk::private()->put('x/y.enc', 'z');
        UploadDisk::restrict('mem_private', 'x/y.enc');
        $this->assertFalse(UploadDisk::isLocal('mem_private'));
        $this->assertTrue(UploadDisk::isLocal('local'));
    }

    public function test_images_are_served_from_the_image_disk_for_known_folders_only(): void
    {
        $name = (string) Str::uuid().'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        UploadDisk::images()->put('client-avatars/'.$name, $png);
        UploadDisk::images()->put('business-logos/'.$name, $png);

        foreach (['/uploads/client-avatars/', '/public/uploads/business-logos/'] as $prefix) {
            $response = $this->get($prefix.$name)->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
            $this->assertSame($png, $response->streamedContent());
        }
        $this->getJson('/public/uploads/invoice-logos/'.$name)->assertNotFound();
        $this->getJson('/uploads/other-folder/'.$name)->assertNotFound();
        $this->getJson('/uploads/client-avatars/'.Str::uuid().'.png')->assertNotFound();
    }

    public function test_the_copy_command_copies_verifies_and_never_touches_the_source(): void
    {
        Storage::disk('mem_a')->put('merchant-verification/m1/a.enc', 'alpha');
        Storage::disk('mem_a')->put('branch-evidence/m1/b.enc', 'bravo');
        Storage::disk('mem_a')->put('verification-uploads/u/0.enc', 'transient chunk, not copied');

        $this->assertSame(0, Artisan::call('uploads:copy', ['kind' => 'private', '--from' => 'mem_a', '--to' => 'mem_b', '--dry-run' => true]));
        $this->assertSame([], $this->adapters['mem_b']->objects ?? []);

        $this->assertSame(0, Artisan::call('uploads:copy', ['kind' => 'private', '--from' => 'mem_a', '--to' => 'mem_b']));
        $this->assertSame(['merchant-verification/m1/a.enc' => 'alpha', 'branch-evidence/m1/b.enc' => 'bravo'], $this->adapters['mem_b']->objects);
        $this->assertCount(3, $this->adapters['mem_a']->objects);

        $this->assertSame(0, Artisan::call('uploads:copy', ['kind' => 'private', '--from' => 'mem_a', '--to' => 'mem_b']));
        $this->assertStringContainsString('0 to copy/copied, 2 already present', Artisan::output());

        $this->adapters['mem_b']->objects['branch-evidence/m1/b.enc'] = 'changed';
        $this->assertSame(0, Artisan::call('uploads:copy', ['kind' => 'private', '--from' => 'mem_a', '--to' => 'mem_b']));
        $this->assertSame('bravo', $this->adapters['mem_b']->objects['branch-evidence/m1/b.enc']);
    }

    public function test_the_copy_command_fails_loudly_when_a_copy_does_not_match(): void
    {
        Storage::disk('mem_a')->put('admin-avatars/a.png', 'avatar');
        Storage::disk('mem_b');
        $this->adapters['mem_b']->corruptWrites = true;
        $this->assertSame(1, Artisan::call('uploads:copy', ['kind' => 'private', '--from' => 'mem_a', '--to' => 'mem_b']));
        $this->assertStringContainsString('MISMATCH', Artisan::output());
    }

    public function test_the_copy_command_refuses_to_copy_a_disk_onto_itself(): void
    {
        $this->assertSame(2, Artisan::call('uploads:copy', ['kind' => 'images', '--from' => 'mem_images', '--to' => 'mem_images']));
        $this->assertSame(2, Artisan::call('uploads:copy', ['kind' => 'nonsense']));
    }

    public function test_application_code_does_not_hardcode_local_upload_locations(): void
    {
        // Temporary working directories (PDF rendering, report exports) legitimately stay on local disk and are not listed.
        $forbidden = ["Storage::disk('local')", "storage_path('app/private/admin-avatars", "public_path('uploads", '$disk->path('];
        $allowed = [str_replace('\\', '/', base_path('app/Support/UploadDisk.php'))];
        $hits = [];
        foreach ([base_path('app'), base_path('routes')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if ($file->getExtension() !== 'php' || in_array($path, $allowed, true)) {
                    continue;
                }
                $text = file_get_contents($path);
                foreach ($forbidden as $needle) {
                    if (str_contains($text, $needle)) {
                        $hits[] = basename($path).': '.$needle;
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'Use App\Support\UploadDisk so uploads follow NUERS_PRIVATE_DISK / NUERS_IMAGE_DISK.');
    }
}
