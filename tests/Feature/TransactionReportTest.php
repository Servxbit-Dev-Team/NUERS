<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentPdf;
use App\Services\TransactionReportExport;
use Illuminate\Support\Facades\{DB, Crypt};
use Tests\TestCase;

class TransactionReportTest extends TestCase
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
    public function test_filters_share_scope_with_totals_and_export_and_zero_bounds():void
    {
        $this->row('manual',['discount_amount'=>10]);
        $this->row('senior',['sale_category'=>'senior_citizen','statutory_discount_amount'=>20,'discount_amount'=>20,'total_due'=>80]);
        $this->row('zero',['total_due'=>0,'vatable_sales'=>0,'vat_amount'=>0]);
        $this->row('other',['merchant_id'=>'m2','merchant_name'=>'Other Merchant']);
        $this->getJson('/api/transaction-workspace?merchant=m1&discount=manual&channel=api&source=receipt&customer=Customer&document=vat_invoice')->assertOk()->assertJsonPath('total',1)->assertJsonPath('summary.recorded_amount',112)->assertJsonPath('summary.manual_discount_amount',10)->assertJsonPath('payment_summary.total',1);
        $this->getJson('/api/transaction-workspace?min=0&max=0')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id','zero');
        $this->getJson('/api/transaction-workspace?merchant=m1&per_page=1&page=2')->assertOk()->assertJsonPath('total',3)->assertJsonPath('payment_summary.total',3)->assertJsonPath('payment_summary.successful_amount',192);
        $csv=$this->get('/api/transaction-workspace/export?merchant=m1&discount=statutory&per_page=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('TX-senior',$csv);$this->assertStringNotContainsString('TX-manual',$csv);$this->assertStringNotContainsString('TX-other',$csv);
        $this->getJson('/api/transaction-workspace?from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/transaction-workspace?from=2026-10-20&to=2026-10-20&date_basis=received')->assertOk()->assertJsonPath('total',4);
        $this->getJson('/api/transaction-workspace?search=123-456-789')->assertOk()->assertJsonPath('total',4);
        $this->getJson('/api/transaction-workspace?customer=111-222-333')->assertOk()->assertJsonPath('total',4);
        $this->getJson('/api/transaction-workspace?search=%25')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/transaction-workspace?min=20&max=10')->assertUnprocessable();
        $this->getJson('/api/transaction-workspace?source=invalid')->assertUnprocessable();
    }
    public function test_selected_export_is_intersection_and_preserves_formula_safety():void
    {
        $this->row('a',['merchant_name'=>'=FORMULA()']);$this->row('b');$this->row('foreign',['merchant_id'=>'m2']);
        $csv=$this->get('/api/transaction-workspace/export?merchant=m1&ids[]=a&ids[]=foreign')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=FORMULA()",$csv);$this->assertStringContainsString('TX-a',$csv);$this->assertStringNotContainsString('TX-b',$csv);$this->assertStringNotContainsString('TX-foreign',$csv);
        $this->assertStringContainsString('Manual Discount PHP',$csv);$this->assertStringContainsString('Customer TIN',$csv);
    }
    public function test_pdf_contains_brand_filters_totals_details_and_is_private():void
    {
        $this->row('a',['merchant_name'=>'Harbor <script>alert(1)</script>','discount_amount'=>10]);$this->row('b',['merchant_id'=>'m2']);
        $this->mock(DocumentPdf::class,function($mock){$mock->shouldReceive('renderHtml')->once()->withArgs(function($html){
            $this->assertStringContainsString('data:image/png;base64,',$html);$this->assertStringContainsString('Harbor &lt;script&gt;',$html);$this->assertStringNotContainsString('<script>alert(1)</script>',$html);
            $this->assertStringContainsString('TX-a',$html);$this->assertStringNotContainsString('TX-b',$html);$this->assertStringContainsString('₱112.00',$html);$this->assertStringContainsString('₱10.00',$html);$this->assertStringContainsString('Applied filters',$html);$this->assertStringContainsString('Record ID: a',$html);return true;
        })->andReturn('%PDF-1.7 synthetic');});
        $id=$this->getJson('/api/transaction-workspace/export?merchant=m1&format=pdf')->assertStatus(202)->json('export_id');
        $queue=app(\App\Services\TransactionPdfQueue::class);
        $this->assertStringNotContainsString('Harbor',file_get_contents(config('document_pdf.export_directory').'/'.$id.'.input'));
        $this->getJson('/api/transaction-workspace/exports/'.$id)->assertStatus(202)->assertJsonPath('state','queued');
        $other=$this->actor('super_admin');$this->withToken($other->api_token)->getJson('/api/transaction-workspace/exports/'.$id)->assertNotFound();
        $merchant=$this->actor('merchant');$this->withToken($merchant->api_token)->getJson('/api/transaction-workspace/exports/'.$id)->assertForbidden();
        $this->withToken($this->admin->api_token);
        $queue->runOne();$this->assertNull($queue->runOne());
        $r=$this->get('/api/transaction-workspace/exports/'.$id)->assertOk()->assertHeader('Content-Type','application/pdf')->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$r->headers->get('X-NUERS-Snapshot'));
        $this->assertTrue(app(\App\Services\BirCompliance::class)->auditIntegrity());
    }
    public function test_pdf_refuses_over_limit_without_silent_truncation():void
    {
        $this->mock(DocumentPdf::class,fn($m)=>$m->shouldNotReceive('renderHtml'));
        for($i=0;$i<=TransactionReportExport::PDF_LIMIT;$i++)$this->row('bulk'.$i);
        if(app(\App\Services\LedgerProjection::class)->ready())while(app(\App\Services\LedgerProjection::class)->refresh()){}
        $this->getJson('/api/transaction-workspace/export?format=pdf')->assertUnprocessable()->assertJsonPath('message','Detailed PDF supports up to 500 matching records. Narrow the date/merchant filters, select rows, or export all matching records as CSV. No records were exported.');
    }
    public function test_pdf_queue_limits_concurrency_and_expires_private_snapshots():void
    {
        $this->row('a');
        $id=$this->getJson('/api/transaction-workspace/export?format=pdf')->assertStatus(202)->json('export_id');
        $this->getJson('/api/transaction-workspace/export?format=pdf')->assertStatus(429);
        $path=config('document_pdf.export_directory').'/'.$id;
        $job=json_decode(file_get_contents($path.'.json'),true);$job['expires']=time()-1;
        file_put_contents($path.'.json',json_encode($job));
        $this->getJson('/api/transaction-workspace/exports/'.$id)->assertStatus(410);
        app(\App\Services\TransactionPdfQueue::class)->runOne();
        $this->assertFileDoesNotExist($path.'.json');$this->assertFileDoesNotExist($path.'.input');
    }
    public function test_revoked_admin_cannot_render_or_download_queued_pdf():void
    {
        $this->row('a');
        $id=$this->getJson('/api/transaction-workspace/export?format=pdf')->assertStatus(202)->json('export_id');
        DB::table('profiles')->where('id',$this->admin->id)->update(['role'=>'merchant']);
        $this->mock(DocumentPdf::class,fn($m)=>$m->shouldNotReceive('renderHtml'));
        app(\App\Services\TransactionPdfQueue::class)->runOne();
        $this->getJson('/api/transaction-workspace/exports/'.$id)->assertForbidden();
        $path=config('document_pdf.export_directory').'/'.$id;
        $this->assertSame('failed',json_decode(file_get_contents($path.'.json'),true)['state']);
        $this->assertFileDoesNotExist($path.'.input');$this->assertFileDoesNotExist($path.'.pdf.enc');
    }
    public function test_merchant_cannot_read_or_export_admin_data():void
    {
        $u=$this->actor('merchant');$this->withToken($u->api_token);
        foreach(['/api/transaction-workspace','/api/transaction-workspace/export?format=pdf','/api/transaction-workspace/export?format=csv','/api/admin/sandbox-records/export?format=pdf'] as $url)$this->getJson($url)->assertForbidden();
    }
    public function test_sandbox_export_never_contains_live_records():void
    {
        $this->row('live');
        DB::table('integration_sandbox_events')->insert(['id'=>'sb1','merchant_id'=>'m1','api_key_id'=>'k','branch_code'=>'00000','source_system'=>'ERP','source_event_id'=>'e1','external_reference'=>'SANDBOX-ONLY','receipt_number'=>'TEST-1','document_type'=>'vat_invoice','event_type'=>'sale','gross_centavos'=>11200,'sale_category'=>'regular','details_encrypted'=>Crypt::encryptString(json_encode(['request'=>['customer_name'=>'Synthetic buyer'],'response'=>['calculation'=>['vat_amount'=>12,'vatable_sales'=>100,'net_amount'=>100]]])),'receipt_hash'=>str_repeat('1',64),'namespace_hash'=>str_repeat('2',64),'event_hash'=>str_repeat('3',64),'request_hash'=>str_repeat('4',64),'occurred_at'=>'2026-10-18 12:00:00','created_at'=>'2026-10-20 12:00:00','updated_at'=>'2026-10-20 12:00:00']);
        $csv=$this->get('/api/admin/sandbox-records/export?format=csv&min=112&max=112')->assertOk()->streamedContent();
        $this->assertStringContainsString('SANDBOX-ONLY',$csv);$this->assertStringNotContainsString('TX-live',$csv);$this->assertStringContainsString('sandbox',$csv);
        $this->getJson('/api/admin/sandbox-records?from=2026-10-21&to=2026-10-20')->assertUnprocessable();
        $this->getJson('/api/admin/sandbox-records?min=113')->assertOk()->assertJsonPath('total',0);
    }
}
