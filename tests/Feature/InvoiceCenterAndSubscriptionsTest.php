<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceCenterAndSubscriptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:', 'app.timezone'=>'Asia/Manila']); DB::purge('sqlite');
        foreach (['0001_01_01_000000_create_users_table.php','2026_06_02_000001_create_nuers_tables.php','2026_06_02_000014_create_business_invoices_tables.php','2026_06_02_000018_add_bir_document_templates_to_ledgers.php','2026_10_15_000004_create_website_subscription_plans.php'] as $file) (require database_path('migrations/'.$file))->up();
    }
    private function user(string $role): User
    {
        $u=User::factory()->create(['api_token'=>Str::random(64)]); DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]); return $u;
    }
    private function business(string $id, ?User $user=null): void
    {
        DB::table('merchants')->insert(['id'=>$id,'business_name'=>'Business '.$id,'tin'=>'123456789','merchant_account_id'=>$user?->id,'vat_registered'=>true]);
    }
    private function receipt(string $id,string $merchant,string $status='issued',string $date='2026-10-15 10:00:00'): void
    {
        DB::table('transaction_receipts')->insert(['id'=>$id,'merchant_id'=>$merchant,'receipt_number'=>'OR-'.$id,'merchant_name'=>'Business '.$merchant,'merchant_tin'=>'123456789','document_type'=>'official_receipt','status'=>$status,'issued_at'=>$date,'gross_amount'=>500,'total_due'=>500,'items'=>json_encode([['description'=>'Service','qty'=>2,'unit_price'=>250]])]);
    }
    public function test_document_endpoints_require_role_and_scope_every_lookup(): void
    {
        $this->getJson('/api/invoice-center')->assertUnauthorized(); $client=$this->user('client');
        $this->withToken($client->api_token)->getJson('/api/invoice-center')->assertForbidden();
        $owner=$this->user('merchant');$this->business('own',$owner);$this->business('other');$this->receipt('owned','own');$this->receipt('foreign','other');
        $this->withToken($owner->api_token)->getJson('/api/invoice-center')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('summary.total',1)->assertJsonPath('data.0.id','owned');
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/receipt/foreign')->assertNotFound();
        $this->withToken($owner->api_token)->getJson('/api/invoice-center/receipt/owned')->assertOk()->assertJsonPath('document.items.0.amount',500)->assertJsonPath('document.authority_status','BIR acceptance not verified');
    }
    public function test_server_filters_pagination_counts_and_empty_results(): void
    {
        $admin=$this->user('admin');$this->business('a');$this->receipt('a','a');$this->receipt('b','a','cancelled');$this->receipt('old','a','issued','2026-10-14 10:00:00');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?per_page=1&from=2026-10-15&to=2026-10-15')->assertOk()->assertJsonPath('total',2)->assertJsonPath('last_page',2)->assertJsonPath('summary.cancelled',1)->assertJsonCount(1,'data');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?type=cancelled')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.record_status','cancelled');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?search=notfound')->assertOk()->assertJsonPath('total',0)->assertJsonCount(0,'data');
        $this->withToken($admin->api_token)->getJson('/api/invoice-center?per_page=1000')->assertUnprocessable();
    }
    public function test_draft_recomputes_total_preserves_status_and_is_idempotent(): void
    {
        $owner=$this->user('merchant');$this->business('own',$owner);
        $p=['request_id'=>(string)Str::uuid(),'merchant_id'=>'own','document_type'=>'sales_invoice','buyer_name'=>'Buyer','issue_date'=>'2026-10-15','vat_inclusive'=>true,'items'=>[['description'=>'Item','quantity'=>2,'unit_price'=>280]], 'total_amount'=>1,'status'=>'paid'];
        $this->withToken($owner->api_token)->postJson('/api/invoice-center',$p)->assertCreated();
        $row=DB::table('business_invoices')->first();$this->assertEquals(560,$row->total_amount);$this->assertEquals(60,$row->vat_amount);$this->assertSame('draft',$row->status);
        $this->withToken($owner->api_token)->postJson('/api/invoice-center',$p)->assertOk()->assertJsonPath('duplicate',true);$this->assertSame(1,DB::table('business_invoices')->count());
        $p['buyer_name']='Changed';$this->withToken($owner->api_token)->postJson('/api/invoice-center',$p)->assertConflict();
        $p['request_id']=(string)Str::uuid();$p['merchant_id']='foreign';$this->withToken($owner->api_token)->postJson('/api/invoice-center',$p)->assertNotFound();
        $bir=$this->user('bir');$this->withToken($bir->api_token)->postJson('/api/invoice-center',$p)->assertForbidden();
    }
    public function test_public_plans_match_confirmed_fee_and_storage_terms(): void
    {
        $this->getJson('/api/subscription-plans')->assertOk()->assertJsonCount(2,'plans')->assertJsonPath('plans.0.name','Subscription 1')->assertJsonPath('plans.0.fee_centavos',200)->assertJsonPath('plans.0.minimum_transaction_centavos',50000)->assertJsonPath('plans.0.storage_mb',500)->assertJsonPath('plans.1.fee_centavos',300)->assertJsonPath('plans.1.storage_mb',null)->assertJsonPath('plans.1.retention_years',5);
    }
    public function test_plan_edit_requires_admin_and_prevents_stale_overwrites(): void
    {
        $plan=(array)DB::table('website_subscription_plans')->first();$plan['published']=false;$plan['featured']=true;$plan['name']='Updated plan';
        $this->putJson('/api/admin/subscription-plans/1',$plan)->assertUnauthorized();$merchant=$this->user('merchant');$this->withToken($merchant->api_token)->putJson('/api/admin/subscription-plans/1',$plan)->assertForbidden();
        $bir=$this->user('bir');$this->withToken($bir->api_token)->getJson('/api/admin/subscription-plans')->assertForbidden();
        $admin=$this->user('admin');$this->withToken($admin->api_token)->putJson('/api/admin/subscription-plans/1',$plan)->assertOk()->assertJsonPath('plan.revision',2)->assertJsonPath('plan.name','Updated plan');
        $this->getJson('/api/subscription-plans')->assertJsonCount(1,'plans')->assertJsonPath('plans.0.name','Subscription 2');
        $this->withToken($admin->api_token)->putJson('/api/admin/subscription-plans/1',$plan)->assertConflict();
        $plan['revision']=2;$plan['fee_centavos']=-1;$this->withToken($admin->api_token)->putJson('/api/admin/subscription-plans/1',$plan)->assertUnprocessable();
    }
}
