<?php
$root=dirname(__DIR__,2);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path!=='/'&&is_file($root.'/public'.$path))return false;
$app=require __DIR__.'/preview-bootstrap.php';
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
if($path==='/__preview429'){
 header('Content-Type: text/html; charset=UTF-8');header('Retry-After: 60');header('Cache-Control: no-store');http_response_code(429);
 echo view('errors.429',['retryAfter'=>60])->render();return;
}
$r=Illuminate\Http\Request::capture();$res=$kernel->handle($r);$res->send();$kernel->terminate($r,$res);
