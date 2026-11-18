<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

/** Complete route/method inventory, with explicit limits: missing-resource and malformed-input probes. */
class AuthenticatedEndpointMatrixTest extends TestCase
{
    use IsolatedSecurityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default'=>'array','queue.default'=>'database','app.debug'=>false,'security.enforce_privileged_mfa'=>true,'notifications.dispatch_enabled'=>false,'infrastructure.execution_enabled'=>false,'bir_eis.callback_enabled'=>true]);
        $this->securityDatabase();
        $this->securityMigrate();
        // Legacy reporting SQL uses MySQL DATE_FORMAT. The same matrix also runs on
        // disposable MariaDB; this bridge only permits SQLite regression execution.
        if (DB::getDriverName()==='sqlite') DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT', static function ($value,$format) {
            if ($value===null) return null;
            return date(strtr($format,['%Y'=>'Y','%m'=>'m','%d'=>'d','%H'=>'H','%i'=>'i','%s'=>'s']),strtotime($value));
        });
        $this->artisan('reports:refresh', ['--rebuild'=>true,'--activate'=>true])->assertSuccessful();
        Http::preventStrayRequests();
        Mail::fake(); Queue::fake();
        // Dedicated roots avoid deleting another concurrently running suite's fake uploads.
        foreach (['local','public','private'] as $disk) Storage::set($disk, Storage::build(['driver'=>'local','root'=>storage_path('framework/testing/authenticated-'.getmypid().'/'.$disk)]));
    }

    private function path($route): string
    {
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function ($m) use ($route) {
            $defaults=['table'=>'profiles','source'=>'receipt','type'=>'requests','portal'=>'super-admin','scope'=>'super-admin','path'=>'audit-missing','file'=>'00000000-0000-4000-8000-000000000001.png'];
            return ($route->wheres[$m[1]]??'')==='[0-9]+' ? '999999999' : ($defaults[$m[1]]??'00000000-0000-4000-8000-000000000001');
        },$route->uri());
    }

    private function paths($route): array
    {
        $path=$this->path($route);
        if (str_contains($route->uri(),'{table}')) return array_map(fn($t)=>str_replace('/profiles','/'.$t,$path),['profiles','client_accounts','merchants','notifications','merchant_transactions','transaction_receipts']);
        if (str_contains($route->uri(),'{portal}')) return array_map(fn($p)=>str_replace('/super-admin','/'.$p,$path),str_contains($route->uri(),'enterprise-')?['admin','merchant','citizen']:['super-admin','bir','merchant','client']);
        if (str_contains($route->uri(),'{scope}')) return array_map(fn($p)=>str_replace('/super-admin','/'.$p,$path),['super-admin','bir','merchant']);
        if (str_contains($route->uri(),'{source}')) return [$path,str_replace('/receipt/','/invoice/',$path)];
        return [$path];
    }

    public function test_all_roles_have_authenticated_baseline_and_malformed_input_route_probes(): void
    {
        $evidence=[]; $failures=[];
        foreach (['super_admin','admin','bir','rdo','merchant','client','consumer'] as $role) {
            $token=Str::random(64);$device=(string) Str::uuid();
            $u=User::factory()->create(['api_token'=>$token]);
            $u->forceFill(['api_device_hash'=>hash('sha256',$device),'mfa_secret'=>'synthetic-assurance-only','api_mfa_verified_at'=>now()])->save();
            DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
            if ($role==='merchant') DB::table('merchants')->insert(['id'=>(string) Str::uuid(),'merchant_account_id'=>$u->id,'business_name'=>'Synthetic matrix business','status'=>'active']);
            $headers=['Authorization'=>'Bearer '.$token,'X-NUERS-Device'=>$device];
            foreach (app('router')->getRoutes() as $route) {
                $api=in_array('api',$route->gatherMiddleware());
                foreach ($route->methods() as $method) {
                    foreach ($this->paths($route) as $probePath) {
                    foreach ($api?['baseline','malformed']:['baseline'] as $variant) {
                        Cache::flush(); DB::beginTransaction();
                        try {
                            $path=$probePath;
                            $matched=app('router')->getRoutes()->match(Request::create($path,$method));
                            $shadowed=$matched->getActionName()!==$route->getActionName();
                            $payload=$variant==='malformed'?['search'=>['nested'],'status'=>['nested'],'limit'=>['nested'],'page'=>['nested'],'eq'=>['id'=>['nested']],'values'=>'invalid-scalar','role'=>'super_admin','merchant_id'=>'foreign-matrix-business']:[];
                            if (in_array($method,['GET','HEAD']) && $payload) {$path.='?'.http_build_query($payload);$payload=[];}
                            $response=$this->json($method,$path,$payload,$headers);
                            $status=$response->getStatusCode();
                            // Deliberate fail-closed unavailable projections are recorded for review, not silently counted as successful handlers.
                            $bad=$status>=500 && $status!==503;
                            if ($api && str_starts_with($route->uri(),'api/admin/') && in_array($role,['merchant','client','consumer'])) $bad=$bad||$status!==403;
                            $row=['role'=>$role,'method'=>$method,'uri'=>$route->uri(),'path'=>$probePath,'action'=>$route->getActionName(),'variant'=>$variant,'status'=>$status,'handler_shadowed'=>$shadowed,'unavailable'=>$status===503];
                            if ($bad || $status===503) $row['message']=(json_decode($response->getContent(),true)['message']??'non-JSON response');
                            $evidence[]=$row;
                            if ($bad) $failures[]=$row;
                        } finally { while (DB::transactionLevel()>0) DB::rollBack(); }
                    }
                    }
                }
            }
        }
        $dir=base_path('.local/authenticated-audit-2026-11-15');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/'.DB::getDriverName().'-role-endpoint-matrix.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/'.DB::getDriverName().'-matrix-findings.json',json_encode($failures,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $this->assertGreaterThan(7000,count($evidence));
        $this->assertSame([],$failures,json_encode($failures,JSON_PRETTY_PRINT));
    }
}
