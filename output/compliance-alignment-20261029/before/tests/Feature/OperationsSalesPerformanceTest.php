<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerProjection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsSalesPerformanceTest extends TestCase
{
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','app.timezone'=>'Asia/Manila']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh',['--force'=>true])->assertSuccessful();
        $this->admin=User::factory()->create(['api_token'=>fake()->uuid()]);
        DB::table('profiles')->insert(['id'=>$this->admin->id,'role'=>'super_admin','email'=>$this->admin->email]);
        DB::table('merchants')->insert(['id'=>'m','business_name'=>'Synthetic Business','tin'=>'123456789000']);
        $this->withToken($this->admin->api_token);
    }
    private function receipt(string $id, array $extra=[]): void
    {
        DB::table('transaction_receipts')->insert(array_replace(['id'=>$id,'merchant_id'=>'m','receipt_number'=>'INV-'.$id,'merchant_name'=>'Synthetic Business','merchant_tin'=>'123456789000','document_type'=>'vat_invoice','status'=>'issued','issued_at'=>'2026-10-20 10:00:00','created_at'=>'2026-10-20 11:00:00','total_due'=>112,'vat_amount'=>12,'vatable_sales'=>100,'items'=>json_encode([['description'=>'Synthetic item','quantity'=>1,'unit_price'=>112,'tax_type'=>'vatable']])],$extra));
    }
    private function activate(): void { while(app(LedgerProjection::class)->refresh()){} app(LedgerProjection::class)->activate(); }
    public function test_pagination_hydrates_only_the_page_and_cached_totals_do_not_scan_sources(): void
    {
        for($i=0;$i<120;$i++)$this->receipt(sprintf('%04d',$i));
        $this->activate();
        $queries=[]; DB::listen(function($q)use(&$queries){$queries[]=$q->sql;});
        $first=$this->getJson('/api/operations-report?mode=sales&per_page=10')->assertOk()->assertJsonPath('query_mode','indexed_sales')->assertJsonPath('total',120)->assertJsonPath('summary.sales',13440)->assertJsonCount(10,'data')->json();
        $this->assertFalse(collect($queries)->contains(fn($sql)=>str_contains($sql,'from "transaction_receipts"')),'Page requests must not inspect original receipt rows.');
        $queries=[];
        $page=$this->getJson('/api/operations-report?mode=sales&per_page=10&page=12')->assertOk()->assertJsonCount(10,'data')->assertJsonPath('page',12)->json();
        $this->assertEmpty(array_intersect(array_column($first['data'],'id'),array_column($page['data'],'id')));
        $this->assertFalse(collect($queries)->contains(fn($sql)=>str_contains($sql,'as candidates')),'Page changes must reuse revision-scoped aggregate totals.');
        $this->getJson('/api/operations-report?mode=sales&page=999999')->assertOk()->assertJsonPath('page',12);
        $this->getJson('/api/operations-report?mode=sales&per_page=1000000')->assertUnprocessable();
    }
    public function test_invalid_duplicate_supplementary_and_missing_date_records_are_not_sales(): void
    {
        $this->receipt('valid');$this->receipt('bad-financial',['total_due'=>999]);
        $this->receipt('bad-tin',['merchant_tin'=>'123']);$this->receipt('no-date',['issued_at'=>null]);
        $this->receipt('supplementary',['document_type'=>'payment_receipt']);
        $this->receipt('duplicate-a',['receipt_number'=>'DUP']);$this->receipt('duplicate-b',['receipt_number'=>'DUP']);
        $this->receipt('failed',['status'=>'failed']);
        $legacy=$this->getJson('/api/operations-report?mode=sales')->assertOk()->json();
        $this->activate();
        $fast=$this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',1)->assertJsonPath('summary.sales',112)->assertJsonPath('summary.excluded_count',5)->json();
        foreach(['total','sales','vat','taxable','average','excluded_count','excluded_amount'] as $key)$this->assertEquals($legacy['summary'][$key],$fast['summary'][$key],$key);
        DB::table('transaction_receipts')->where('id','bad-financial')->update(['total_due'=>112]);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',2)->assertJsonPath('summary.sales',224);
    }
    public function test_linked_payload_mismatch_hash_and_changes_invalidate_sales(): void
    {
        $this->receipt('r');
        $raw=json_encode(['seller_tin'=>'123456789000','total_due'=>999,'document_type'=>'vat_invoice']);
        DB::table('eis_invoice_payloads')->insert(['id'=>'p','merchant_id'=>'m','receipt_id'=>'r','payload'=>json_encode(['encrypted'=>Crypt::encryptString($raw)]),'payload_hash'=>hash('sha256',$raw)]);
        DB::table('eis_transmissions')->insert(['id'=>'t','merchant_id'=>'m','payload_id'=>'p','payload_hash'=>hash('sha256',$raw),'status'=>'queued','environment'=>'certification','created_at'=>now()]);
        $this->activate();
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',0)->assertJsonPath('summary.excluded_count',1);
        $raw=json_encode(['seller_tin'=>'123456789000','total_due'=>112,'document_type'=>'vat_invoice']);
        DB::table('eis_invoice_payloads')->where('id','p')->update(['payload'=>json_encode(['encrypted'=>Crypt::encryptString($raw)]),'payload_hash'=>hash('sha256',$raw)]);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',1);
        DB::table('eis_invoice_payloads')->where('id','p')->update(['payload_hash'=>'bad-hash']);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',0);
    }
    public function test_group_pagination_and_filtered_exports_are_complete_and_scoped(): void
    {
        for($i=0;$i<61;$i++)$this->receipt('r'.$i,['merchant_name'=>'Business '.sprintf('%03d',$i)]);
        $this->activate();
        $this->getJson('/api/operations-report?mode=sales&group=business')->assertOk()->assertJsonCount(50,'groups')->assertJsonPath('groups_pagination.total',61);
        $this->getJson('/api/operations-report?mode=sales&group=business&group_page=2')->assertOk()->assertJsonCount(11,'groups')->assertJsonPath('groups_pagination.page',2);
        $csv=$this->get('/api/operations-report/export?mode=sales&search=Business%20060')->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-r60',$csv);$this->assertStringNotContainsString('INV-r59',$csv);
        $this->get('/api/operations-report/export?mode=sales&report_limit=50')->assertUnprocessable();
        $user=User::factory()->create(['api_token'=>fake()->uuid()]);DB::table('profiles')->insert(['id'=>$user->id,'role'=>'merchant','email'=>$user->email]);
        $this->withToken($user->api_token)->getJson('/api/operations-report?mode=sales')->assertForbidden();
        $this->get('/api/operations-report/export?mode=sales')->assertForbidden();
    }
    public function test_adjustment_eligibility_follows_original_status_and_drafts_stay_excluded(): void
    {
        foreach(['original'=>'vat_invoice','credit'=>'credit_memo','draft'=>'vat_invoice'] as $id=>$type){
            DB::table('business_invoices')->insert(['id'=>$id,'merchant_id'=>'m','invoice_number'=>'INV-'.$id,'merchant_name'=>'Synthetic Business','merchant_tin'=>'123456789000','seller_branch_code'=>'00000','document_type'=>$type,'status'=>$id==='draft'?'draft':'sent','issue_date'=>'2026-10-20','total_amount'=>112,'vat_amount'=>12,'taxable_amount'=>100,'vat_registered'=>true,'buyer_name'=>'Synthetic Buyer','original_invoice_id'=>$id==='credit'?'original':null]);
            DB::table('business_invoice_items')->insert(['id'=>'item-'.$id,'invoice_id'=>$id,'merchant_id'=>'m','description'=>'Service','quantity'=>1,'unit_price'=>112,'line_total'=>112,'vat_amount'=>12,'taxable_amount'=>100,'tax_type'=>'vatable']);
        }
        $this->activate();
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',2)->assertJsonPath('summary.sales',0)->assertJsonPath('summary.excluded_count',0);
        DB::table('business_invoices')->where('id','original')->update(['status'=>'cancelled']);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',0)->assertJsonPath('summary.excluded_count',1);
        DB::table('business_invoices')->where('id','original')->update(['status'=>'sent']);
        $this->getJson('/api/operations-report?mode=sales')->assertOk()->assertJsonPath('total',2)->assertJsonPath('summary.sales',0);
    }
    public function test_pending_evidence_fails_promptly_instead_of_returning_false_zero_totals(): void
    {
        $this->receipt('r');$this->activate();DB::table('ledger_documents')->update(['operations_sales_valid'=>null]);
        $this->getJson('/api/operations-report?mode=sales')->assertStatus(503)->assertJsonPath('message','Sales evidence is being indexed. Please retry shortly.');
    }
}
