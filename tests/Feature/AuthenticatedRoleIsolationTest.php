<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSecurityDatabase;
use Tests\TestCase;

class AuthenticatedRoleIsolationTest extends TestCase
{
    use IsolatedSecurityDatabase;

    private static array $migratedDatabases=[];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default'=>'array','queue.default'=>'database','app.debug'=>false,'security.enforce_privileged_mfa'=>true,'notifications.dispatch_enabled'=>false,'infrastructure.execution_enabled'=>false]);
        $this->securityDatabase();
        if (DB::getDriverName()==='mysql') {
            $database=DB::connection()->getDatabaseName();
            if (!isset(self::$migratedDatabases[$database])) {
                $this->securityMigrate(); self::$migratedDatabases[$database]=true;
            }
            DB::beginTransaction();
        } else $this->securityMigrate();
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
    }

    protected function tearDown(): void
    {
        try { while (DB::transactionLevel()>0) DB::rollBack(); }
        finally { parent::tearDown(); }
    }

    private function actor(string $role): array
    {
        $token=Str::random(64);$device=(string) Str::uuid();
        $u=User::factory()->create(['api_token'=>$token]);
        $u->forceFill(['api_device_hash'=>hash('sha256',$device),'mfa_secret'=>'synthetic-assurance-only','api_mfa_verified_at'=>now()])->save();
        DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);
        return [$u,['Authorization'=>'Bearer '.$token,'X-NUERS-Device'=>$device]];
    }

    public function test_all_nonprivileged_roles_are_denied_administrative_route_families_with_fresh_mfa(): void
    {
        $patterns=['api/admin/*','api/business-registry*','api/live-monitoring*','api/esp-compliance*','api/operations-report*','api/operations/readiness','api/eis-readiness/coverage','api/eis-readiness/*/*/branch-review','api/transaction-workspace*','api/bir-eis/*','api/taxpayer-authorizations*','api/taxpayer-devices*','api/integration-workspace*','api/rdos','api/rdo/dashboard','api/transactions/overview'];
        $results=[];
        foreach (['merchant','client','consumer'] as $role) {
            [$u,$h]=$this->actor($role);
            foreach (app('router')->getRoutes() as $route) {
                if (!Str::is($patterns,$route->uri()) || Str::is('api/bir-eis/receive_invoice_result/*',$route->uri())) continue;
                $path='/'.preg_replace_callback('/\{([^}?]+)\??\}/',fn($m)=>(($route->wheres[$m[1]]??'')==='[0-9]+'?'999999999':match($m[1]){'source'=>'receipt','type'=>'requests',default=>'00000000-0000-4000-8000-000000000001'}),$route->uri());
                foreach ($route->methods() as $method) {
                    Cache::flush();
                    $r=$this->json($method,$path,['role'=>'super_admin','user_id'=>$u->id],$h+['X-User-Role'=>'super_admin','X-Forwarded-User'=>'admin']);
                    $results[]=['role'=>$role,'method'=>$method,'uri'=>$route->uri(),'status'=>$r->status()];
                    $this->assertSame(403,$r->status(),"$role $method $path");
                }
            }
        }
        file_put_contents(base_path('.local/authenticated-audit-2026-11-15/role-denial-matrix.json'),json_encode($results,JSON_PRETTY_PRINT));
    }

    public function test_nonprivileged_table_filters_and_idor_cannot_read_foreign_financial_records(): void
    {
        [$owner,$ownerHeaders]=$this->actor('merchant');
        $foreign=(string) Str::uuid();$receipt=(string) Str::uuid();
        DB::table('merchants')->insert(['id'=>$foreign,'merchant_account_id'=>$owner->id,'business_name'=>'FOREIGN_MATRIX_SECRET','status'=>'active']);
        DB::table('transaction_receipts')->insert(['id'=>$receipt,'merchant_id'=>$foreign,'receipt_number'=>'FOREIGN-MATRIX-0001','merchant_name'=>'FOREIGN_MATRIX_SECRET','merchant_tin'=>'999888777','status'=>'issued','total_due'=>731.25,'gross_amount'=>731.25]);
        foreach (['merchant','client','consumer','bir','rdo','admin','super_admin'] as $role) {
            [$u,$h]=$this->actor($role);
            foreach (['eq'=>json_encode(['merchant_id'=>$foreign]),'in'=>json_encode(['merchant_id'=>[$foreign]]),'or'=>'merchant_id.eq.'.$foreign] as $key=>$value) {
                Cache::flush();$r=$this->getJson('/api/tables/transaction_receipts?'.http_build_query([$key=>$value,'count'=>'exact']),$h);
                if ($role==='rdo') {$r->assertForbidden();continue;}
                $r->assertOk();
                $visible=in_array($role,['bir','admin','super_admin']);
                $r->assertJsonCount($visible?1:0,'data')->assertJsonPath('count',$visible?1:0);
            }
            if (in_array($role,['merchant','client','consumer'])) foreach (["/api/invoice-center/receipt/$receipt","/api/document-copies/receipt/$receipt","/api/document-copies/receipt/$receipt/pdf","/api/businesses/$foreign/portability","/api/verify-receipt/FOREIGN-MATRIX-0001"] as $path) {
                Cache::flush();$r=$this->getJson($path,$h);$this->assertContains($r->status(),[403,404],"$role $path");
                $this->assertStringNotContainsString('FOREIGN_MATRIX_SECRET',$r->getContent());
            }
        }
        $this->getJson('/api/document-copies/receipt/'.$receipt,$ownerHeaders)->assertOk();
    }

    public function test_delegated_profile_queries_validate_shapes_without_expanding_permission(): void
    {
        [$owner]=$this->actor('merchant');[$member,$h]=$this->actor('merchant');
        $merchant=(string) Str::uuid();$role=(string) Str::uuid();$membership=(string) Str::uuid();
        DB::table('merchants')->insert(['id'=>$merchant,'merchant_account_id'=>$owner->id,'business_name'=>'Delegated matrix','status'=>'active']);
        DB::table('merchant_team_roles')->insert(['id'=>$role,'merchant_id'=>$merchant,'name'=>'No permissions','description'=>'Synthetic denied role','color'=>'blue','permissions'=>'[]','created_at'=>now(),'updated_at'=>now()]);
        DB::table('merchant_team_members')->insert(['id'=>$membership,'merchant_id'=>$merchant,'user_id'=>$member->id,'role_id'=>$role,'profile_encrypted'=>Crypt::encryptString('{}'),'email_hash'=>hash('sha256',$member->email),'scope'=>'all','branch_ids'=>'[]','terminal_scope'=>'all','terminal_ids'=>'[]','status'=>'active','invited_by'=>$owner->id,'created_at'=>now(),'updated_at'=>now()]);
        foreach ([['id'=>(string)$member->id],json_encode(['id'=>(string)$member->id])] as $eq) $this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>$eq]),$h)->assertOk()->assertJsonCount(1,'data');
        foreach ([['id'=>['nested']],['id'=>(string)$owner->id]] as $eq) $this->getJson('/api/tables/profiles?'.http_build_query(['eq'=>$eq]),$h)->assertForbidden();
        foreach (['/api/merchant/api-keys','/api/merchant/wallet','/api/merchant/records','/api/merchant/customers','/api/merchant/sales-reports'] as $path) $this->getJson($path,$h)->assertForbidden();
        DB::table('merchant_team_members')->where('id',$membership)->update(['status'=>'revoked']);
        $this->getJson('/api/merchant/access',$h)->assertForbidden();
    }

    public function test_rdo_cannot_select_another_office_by_query_or_request_body(): void
    {
        [$a,$h]=$this->actor('rdo');[$b]=$this->actor('rdo');
        foreach ([[$a,'001','OWN_OFFICE'],[$b,'002','FOREIGN_OFFICE_SECRET']] as [$u,$code,$name]) DB::table('rdo_offices')->insert(['id'=>(string) Str::uuid(),'rdo_code'=>$code,'rdo_name'=>$name,'region'=>'Synthetic region','user_id'=>$u->id]);
        $own=$this->getJson('/api/rdo/dashboard',$h)->assertOk();
        $this->assertStringContainsString('OWN_OFFICE',$own->getContent());
        foreach (['code=002','code[]=002','user_id='.$b->id.'&code=002'] as $query) {
            $r=$this->getJson('/api/rdo/dashboard?'.$query,$h);
            $this->assertLessThan(500,$r->status());
            $this->assertStringNotContainsString('FOREIGN_OFFICE_SECRET',$r->getContent());
        }
        $this->postJson('/api/rdos',['rdo_code'=>'003','rdo_name'=>'Unauthorized'], $h)->assertForbidden();
    }

    public function test_individual_filter_and_profile_payload_shapes_fail_validation(): void
    {
        [$admin,$a]=$this->actor('super_admin');[$merchant,$m]=$this->actor('merchant');
        DB::table('merchants')->insert(['id'=>(string) Str::uuid(),'merchant_account_id'=>$merchant->id,'business_name'=>'Filter probe','status'=>'active']);
        $paths=[
            '/api/citizen-approvals'=>[$a,['status','search']],
            '/api/live-monitoring'=>[$a,['search','status']],
            '/api/transactions/overview'=>[$a,['search','status','region','payment','rdo','date_from','date_to']],
            '/api/business-invoices'=>[$m,['status','type','search']],
            '/api/merchant/expenses'=>[$m,['search','category','status','supplier']],
            '/api/merchant/receipts'=>[$m,['search','status','channel']],
            '/api/merchant/transactions'=>[$m,['search','status','channel','branch','payment']],
        ];
        foreach ($paths as $path=>[$h,$fields]) foreach ($fields as $field) {
            Cache::flush();$this->getJson($path.'?'.http_build_query([$field=>['nested'=>'value']]),$h)->assertUnprocessable();
        }
        foreach (['super_admin','admin','bir','client','consumer'] as $role) {
            [$u,$h]=$this->actor($role);
            foreach (['not-an-object',['not-an-object'],['id'=>['nested']],['role'=>['super_admin']],['email'=>['nested']]] as $values) {
                $this->patchJson('/api/tables/profiles?'.http_build_query(['eq'=>['id'=>$u->id]]),['values'=>$values],$h)->assertUnprocessable();
            }
            $this->assertDatabaseHas('profiles',['id'=>$u->id,'role'=>$role]);
        }
    }

    public function test_infrastructure_operations_require_super_admin_even_after_other_roles_complete_mfa(): void
    {
        foreach (['admin','bir','rdo','merchant','client','consumer'] as $role) {
            [$u,$h]=$this->actor($role);
            foreach (app('router')->getRoutes() as $route) {
                if (!str_starts_with($route->uri(),'api/admin/infrastructure/')) continue;
                $path='/'.preg_replace('/\{[^}]+\}/','00000000-0000-4000-8000-000000000001',$route->uri());
                foreach ($route->methods() as $method) {
                    Cache::flush();$this->json($method,$path,['role'=>'super_admin'],$h)->assertForbidden();
                }
            }
        }
    }

    public function test_dashboard_variants_enforce_explicit_role_allowlists(): void
    {
        $policies=[
            '/api/enterprise-dashboards/admin'=>['super_admin','admin'],
            '/api/enterprise-dashboards/merchant'=>['super_admin','admin','merchant'],
            '/api/enterprise-dashboards/citizen'=>['super_admin','admin','client','consumer'],
            '/api/dashboards/super-admin'=>['super_admin','admin','bir'],
            '/api/dashboards/bir'=>['super_admin','admin','bir'],
            '/api/tax-intelligence/super-admin'=>['super_admin','admin','bir'],
            '/api/tax-intelligence/bir'=>['super_admin','admin','bir'],
        ];
        foreach (['super_admin','admin','bir','rdo','merchant','client','consumer'] as $role) {
            [$u,$h]=$this->actor($role);
            foreach ($policies as $path=>$allowed) {
                Cache::flush();$r=$this->getJson($path,$h);
                $this->assertSame(in_array($role,$allowed)?200:403,$r->status(),"$role $path");
            }
        }
    }

    public function test_indexed_source_buyers_preserve_owner_scope_and_exclude_linked_documents(): void
    {
        [$a,$h]=$this->actor('merchant');[$b]=$this->actor('merchant');
        $own=(string) Str::uuid();$foreign=(string) Str::uuid();$receipt=(string) Str::uuid();
        foreach ([[$own,$a],[$foreign,$b]] as [$id,$u]) DB::table('merchants')->insert(['id'=>$id,'merchant_account_id'=>$u->id,'business_name'=>'Synthetic source','status'=>'active']);
        foreach ([[$receipt,$own,'OWN_BUYER'],[(string) Str::uuid(),$foreign,'FOREIGN_BUYER_SECRET']] as [$id,$merchant,$buyer]) DB::table('transaction_receipts')->insert(['id'=>$id,'merchant_id'=>$merchant,'receipt_number'=>$id,'merchant_name'=>'Synthetic source','merchant_tin'=>'123456789','buyer_name'=>$buyer,'status'=>'issued','total_due'=>100,'gross_amount'=>100,'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        foreach ([false,true] as $indexed) {
            if ($indexed) $this->artisan('reports:refresh',['--rebuild'=>true,'--activate'=>true])->assertSuccessful();
            $r=$this->getJson('/api/merchant/customers/source-buyers',$h)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.document_id',$receipt);
            $this->assertStringNotContainsString('FOREIGN_BUYER_SECRET',$r->getContent());
        }
        DB::table('merchant_customer_documents')->insert(['id'=>(string) Str::uuid(),'actor_id'=>(string)$a->id,'reason_encrypted'=>Crypt::encryptString('Synthetic link'),'merchant_id'=>$own,'customer_id'=>(string) Str::uuid(),'document_source'=>'receipt','document_id'=>$receipt,'created_at'=>now()]);
        $this->getJson('/api/merchant/customers/source-buyers',$h)->assertOk()->assertJsonPath('total',0);
    }
}
