<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('staging') || config('database.connections.mysql.database')!=='nuers_vapt_multi') throw new RuntimeException('Synthetic staging guard');
$scanner=app(App\Services\UploadScanner::class);
$checks=['scanner_mandatory'=>config('security.upload_scan.enabled')===true];
$scanner->version();$checks['loaded_signatures_fresh']=true;
$stream=fopen('php://temp','w+');fwrite($stream,'Synthetic clean document');rewind($stream);$scanner->stream($stream);fclose($stream);$checks['clean_content_accepted']=true;
// Standard harmless EICAR anti-malware test signature; never a real executable or upload.
$stream=fopen('php://temp','w+');fwrite($stream,'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');rewind($stream);
try{$scanner->stream($stream);$checks['eicar_rejected']=false;}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$checks['eicar_rejected']=$e->getStatusCode()===422;}finally{fclose($stream);}
config(['security.upload_scan.socket'=>'/run/nuers-test-intentionally-absent.sock']);
$stream=fopen('php://temp','w+');fwrite($stream,'Synthetic clean document');rewind($stream);
try{$scanner->stream($stream);$checks['unavailable_scanner_fails_closed']=false;}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){$checks['unavailable_scanner_fails_closed']=$e->getStatusCode()===503;}finally{fclose($stream);}
echo json_encode($checks)."\n";
if(in_array(false,$checks,true))exit(1);
