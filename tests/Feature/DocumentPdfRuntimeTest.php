<?php

namespace Tests\Feature;

use App\Services\DocumentPdf;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentPdfRuntimeTest extends TestCase
{
    public function test_web_worker_without_home_gets_private_writable_runtime_and_no_application_secrets(): void
    {
        $dir = sys_get_temp_dir().'/nuers-pdf-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $probe = $dir.'/renderer';
        $body = <<<'CODE'
<?php
$home=getenv('HOME');if(!$home||!is_writable($home)||getenv('NUERS_SYNTHETIC_SECRET')!==false||getenv('TMPDIR')===$home||getenv('XDG_CONFIG_HOME')!==$home.'/config')exit(3);
file_put_contents(__DIR__.'/observed-home',$home);
foreach($argv as$arg)if(str_starts_with($arg,'--print-to-pdf='))file_put_contents(substr($arg,15),'%PDF-1.7 synthetic runtime fixture');
CODE;
        file_put_contents($probe, '#!'.PHP_BINARY."\n".$body);
        chmod($probe, 0700);
        $prior = getenv('HOME');
        putenv('HOME');
        putenv('NUERS_SYNTHETIC_SECRET=must-not-reach-renderer');
        config(['document_pdf.chrome_binary' => $probe]);
        try {
            $this->assertStringStartsWith('%PDF-', app(DocumentPdf::class)->renderHtml('<html>Test</html>'));
            $runtime = file_get_contents($dir.'/observed-home');
            $this->assertStringStartsWith(storage_path('app/private/pdf-'), $runtime);
            $this->assertDirectoryDoesNotExist($runtime);
        } finally {
            putenv($prior === false ? 'HOME' : 'HOME='.$prior);
            putenv('NUERS_SYNTHETIC_SECRET');
            File::deleteDirectory($dir);
        }
    }
}
