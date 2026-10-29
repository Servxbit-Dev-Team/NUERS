<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/httpdocs/vendor/autoload.php';
$app=require __DIR__.'/httpdocs/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=app(App\Http\Controllers\Api\BusinessRegistryController::class);$r=Illuminate\Http\Request::create('/api/business-registry','GET');
try{
 [$q,$v,$eis]=(new ReflectionMethod($c,'filteredQuery'))->invoke($c,$r);
 $step='statuses';$statuses=(clone$q)->select('m.status')->selectRaw('COUNT(*) as total')->groupBy('m.status')->pluck('total','status');
 $step='stages';$expr=(new ReflectionMethod($c,'metadata'))->invoke($c,'stage');(clone$q)->selectRaw("$expr as registry_stage, COUNT(*) as total")->groupByRaw($expr)->pluck('total','registry_stage');
 $step='rows';$rows=(clone$q)->select('m.*')->selectRaw("$eis as registry_eis_status")->limit(10)->get();
 $step='present';foreach($rows as$row)(new ReflectionMethod($c,'present'))->invoke($c,$row,false);
 $step='options';app(App\Services\RegistrationIndustry::class)->options();app(App\Services\RegistrationAddress::class)->offices();
 $out=['passed'=>true];
}catch(Throwable$e){$out=['passed'=>false,'step'=>$step??'filtered','exception'=>get_class($e),'message'=>$e->getMessage()];}
file_put_contents(__DIR__.'/nuers-registry-overview-diagnostic.json',json_encode($out,JSON_PRETTY_PRINT));echo json_encode($out).PHP_EOL;
