<?php
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['document_pdf.node_binary'=>'/opt/homebrew/bin/node','document_pdf.chrome_binary'=>'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome']);
foreach (glob(__DIR__.'/sample-*.json') as $path) {
    $dto=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    file_put_contents(substr($path,0,-5).'.pdf',app(App\Services\DocumentPdf::class)->render($dto));
    echo basename($path)." rendered\n";
}
