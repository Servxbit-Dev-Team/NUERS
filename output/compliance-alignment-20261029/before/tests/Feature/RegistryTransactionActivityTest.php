<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentPdf;
use App\Services\TransactionReportExport;
use Illuminate\Support\Facades\{DB, Crypt};
use Tests\TestCase;

class RegistryTransactionActivityTest extends TestCase
{
    protected User $admin;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','app.timezone'=>'Asia/Manila','cache.default'=>'array']);
        DB::purge('sqlite');
        foreach(glob(database_path('migrations/*.php')) as $file)(require $file)->up();
        config(['document_pdf.export_directory'=>storage_path('framework/testing/transaction-exports-'.bin2hex(random_bytes(8)))]);
        $this->admin=$this->actor('super_admin');
        DB::table('merchants')->insert([['id'=>'m1','business_name'=>'Harbor Foods','tin'=>'123456789000'],['id'=>'m2','business_name'=>'Other Merchant','tin'=>'987654321000']]);
        $this->withToken($this->admin->api_token);
    }
    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory(config('document_pdf.export_directory'));
        parent::tearDown();
    }
    protected function actor(string $role): User
    {
        $u=User::factory()->create(['api_token'=>fake()->uuid()]);
        DB::table('profiles')->insert(['id'=>$u->id,'role'=>$role,'email'=>$u->email]);return $u;
    }
    protected function row(string $id,array $changes=[]):void
    {
        DB::table('transaction_receipts')->insert(array_replace(['id'=>'r'.$id,'merchant_id'=>'m1','merchant_name'=>'Harbor Foods','merchant_tin'=>'123456789000','receipt_number'=>'INV-'.$id,'document_type'=>'vat_invoice','status'=>'issued','issued_at'=>'2026-10-18 09:00:00','created_at'=>'2026-10-20 09:00:00','total_due'=>112,'vat_amount'=>12,'vatable_sales'=>100,'buyer_name'=>'Customer One','buyer_tin'=>'111222333000','sale_category'=>'regular','discount_amount'=>0,'statutory_discount_amount'=>0,'items'=>'[]'],$changes));
        DB::table('merchant_transactions')->insert(['id'=>$id,'merchant_id'=>$changes['merchant_id']??'m1','receipt_id'=>'r'.$id,'transaction_ref'=>'TX-'.$id,'status'=>'completed','amount'=>$changes['total_due']??112,'payment_method'=>'cash','branch'=>'Main','channel'=>'api','created_at'=>'2026-10-20 09:00:00']);
    }
    public function test_registry_scope_locks_list_totals_options_and_detail():void
    {
        $this->row('a',['items'=>json_encode([['description'=>'Test item','quantity'=>2,'unit_price'=>56,'line_total'=>112,'tax_type'=>'vatable','secret'=>'MUST-NOT-LEAK']])]);
        $this->row('b',['total_due'=>300,'discount_amount'=>20]);
        $this->row('foreign',['merchant_id'=>'m2','merchant_name'=>'Other Merchant']);
        DB::table('merchant_transactions')->where('id','foreign')->update(['branch'=>'PRIVATE-BRANCH','channel'=>'PRIVATE-CHANNEL']);
        $url='/api/business-registry/m1/transactions';
        $r=$this->getJson($url.'?per_page=1&page=2')->assertOk()->assertJsonPath('total',2)->assertJsonPath('summary.recorded_amount',412)->assertJsonPath('last_page',2)->assertJsonCount(1,'data')->assertJsonCount(1,'options.merchants')->assertJsonPath('options.merchants.0.id','m1');
        $this->assertStringNotContainsString('PRIVATE-', $r->getContent());
        $this->getJson($url.'/a')->assertOk()->assertJsonPath('transaction.merchant_id','m1')->assertJsonPath('transaction.line_items.0.description','Test item')->assertJsonMissing(['secret'=>'MUST-NOT-LEAK']);
        $this->getJson($url.'/foreign')->assertNotFound();
        $this->getJson($url.'?merchant=m2')->assertUnprocessable();
        $this->getJson('/api/business-registry/missing/transactions')->assertNotFound();
        $this->getJson($url.'?discount=manual')->assertOk()->assertJsonPath('total',1)->assertJsonPath('summary.recorded_amount',300);
        $this->getJson($url.'?min=0&max=0')->assertOk()->assertJsonPath('total',0);
        $this->getJson($url.'?min=100&max=0')->assertUnprocessable();
        $this->assertSame(3,DB::table('merchant_transactions')->count());
    }
    public function test_registry_exports_intersect_selection_scope_and_pdf_is_branded():void
    {
        $this->row('a');$this->row('b');$this->row('foreign',['merchant_id'=>'m2']);
        $url='/api/business-registry/m1/transactions/export';
        $csv=$this->get($url.'?ids[]=a&ids[]=foreign')->assertOk()->streamedContent();
        $this->assertStringContainsString('TX-a',$csv);$this->assertStringNotContainsString('TX-b',$csv);$this->assertStringNotContainsString('TX-foreign',$csv);
        $this->getJson($url.'?merchant=m2')->assertUnprocessable();
        $this->mock(DocumentPdf::class,function($mock){$mock->shouldReceive('renderHtml')->once()->withArgs(function($html){
            $this->assertStringContainsString('TX-a',$html);$this->assertStringContainsString('TX-b',$html);$this->assertStringNotContainsString('TX-foreign',$html);$this->assertStringContainsString('data:image/png;base64,',$html);$this->assertStringContainsString('₱224.00',$html);return true;
        })->andReturn('%PDF-1.7 synthetic');});
        $id=$this->getJson($url.'?format=pdf')->assertStatus(202)->json('export_id');
        app(\App\Services\TransactionPdfQueue::class)->runOne();
        $this->get('/api/transaction-workspace/exports/'.$id)->assertOk()->assertHeader('Content-Type','application/pdf');
    }
    public function test_registry_financial_routes_reject_unprivileged_roles_and_require_mfa():void
    {
        $this->row('a');
        foreach(['merchant','client','bir','rdo'] as $role){
            $u=$this->actor($role);$this->withToken($u->api_token);
            foreach(['transactions','transactions/a','transactions/export','sandbox-records','sandbox-records/export'] as $path)$this->getJson('/api/business-registry/m1/'.$path)->assertForbidden();
        }
        $admin=$this->actor('admin');$this->withToken($admin->api_token)->getJson('/api/business-registry/m1/transactions')->assertOk();
        config(['security.enforce_privileged_mfa'=>true]);
        $this->getJson('/api/business-registry/m1/transactions')->assertForbidden()->assertJsonPath('code','MFA_REQUIRED');
    }
    public function test_sandbox_scope_remains_separate_for_list_detail_and_export():void
    {
        $this->row('live');
        $ids=[];
        foreach(['m1','m2'] as $m){$id=(string)\Illuminate\Support\Str::uuid();$ids[$m]=$id;DB::table('integration_sandbox_events')->insert([
            'id'=>$id,'merchant_id'=>$m,'environment'=>'sandbox','branch_code'=>'00000','source_system'=>'synthetic','source_event_id'=>$m,'external_reference'=>'TEST-'.$m,'receipt_number'=>'TEST-'.$m,'document_type'=>'vat_invoice','event_type'=>'sale','gross_centavos'=>11200,'sale_category'=>'regular','statutory_discount_amount'=>0,'vat_relief_amount'=>0,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now(),'details_encrypted'=>Crypt::encryptString(json_encode(['request'=>[],'response'=>['calculation'=>['vat_amount'=>12]]])),'api_key_id'=>'synthetic-key','receipt_hash'=>hash('sha256','receipt'.$m),'namespace_hash'=>hash('sha256','namespace'.$m),'event_hash'=>hash('sha256','event'.$m),'request_hash'=>hash('sha256','request'.$m),
        ]);}
        $url='/api/business-registry/m1/sandbox-records';
        $this->getJson($url)->assertOk()->assertJsonPath('total',1)->assertJsonPath('summary.gross_centavos',11200)->assertJsonPath('data.0.merchant_id','m1');
        $this->getJson($url.'/'.$ids['m1'])->assertOk()->assertJsonPath('record.merchant_id','m1');
        $this->getJson($url.'/'.$ids['m2'])->assertNotFound();
        $this->getJson($url.'?merchant=m2')->assertUnprocessable();
        $csv=$this->get($url.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('TEST-m1',$csv);$this->assertStringNotContainsString('TEST-m2',$csv);$this->assertStringNotContainsString('TX-live',$csv);
        $this->getJson('/api/business-registry/m1/transactions')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id','live');
    }
}
