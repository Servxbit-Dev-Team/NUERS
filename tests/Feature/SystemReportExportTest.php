<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\BirCompliance;
use App\Services\ReportCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemReportExportTest extends TestCase
{
    private User $admin;
    private User $owner;
    private string $merchant;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','notifications.dispatch_enabled'=>false]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh',['--force'=>true])->assertSuccessful();
        $this->admin=$this->actor('super_admin');$this->owner=$this->actor('merchant');
        $this->merchant=(string)Str::uuid();
        DB::table('merchants')->insert(['id'=>$this->merchant,'merchant_account_id'=>$this->owner->id,'business_name'=>'Export QA Merchant','tin'=>'123456789','status'=>'active']);
    }
    private function actor(string $role): User
    {
        $u=User::factory()->create(['api_token'=>Str::random(64)]);
        DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);return $u;
    }
    private function receipt(int $index, ?string $merchant=null): void
    {
        DB::table('transaction_receipts')->insert(['id'=>(string)Str::uuid(),'merchant_id'=>$merchant??$this->merchant,'receipt_number'=>'QA-EXPORT-'.str_pad((string)$index,4,'0',STR_PAD_LEFT),'merchant_name'=>'Export QA Merchant','merchant_tin'=>'123456789','status'=>'issued','total_due'=>112,'gross_amount'=>112,'created_at'=>now()]);
    }
    public function test_audit_csv_obeys_search_includes_integrity_and_escapes_formula_cells(): void
    {
        $p=app(BirCompliance::class);
        $p->audit('qa.export.match','=HYPERLINK("test")',(string)$this->admin->id,['note'=>"Comma, quote \" and\nline"]);
        $p->audit('qa.export.hidden','PRIVATE-OTHER',(string)$this->admin->id,[]);
        $csv=$this->withToken($this->admin->api_token)->get('/api/live-monitoring/audit?download=1&format=csv&search=qa.export.match&report_limit=500')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF",$csv);
        $this->assertStringContainsString('qa.export.match',$csv);$this->assertStringNotContainsString('qa.export.hidden',$csv);
        $this->assertStringContainsString("'=HYPERLINK",$csv);$this->assertStringContainsString('Entry Hash',$csv);
        $this->assertStringNotContainsString('eis.audit.exported',$csv);$this->assertTrue($p->auditIntegrity());
    }
    public function test_audit_and_backup_exports_remain_admin_only(): void
    {
        foreach(['/api/live-monitoring/audit?download=1&format=csv','/api/admin/backups/export?format=csv'] as $path){
            $this->withToken('')->getJson($path)->assertUnauthorized();
            $this->withToken($this->owner->api_token)->getJson($path)->assertForbidden();
        }
    }
    public function test_invoice_pdf_source_rejects_over_limit_while_csv_remains_complete(): void
    {
        for($i=1;$i<=501;$i++)$this->receipt($i);
        $this->withToken($this->owner->api_token)->getJson('/api/invoice-center/export?format=csv&report_limit=500')->assertUnprocessable()->assertJsonFragment(['message'=>'PDF supports up to 500 matching records. Narrow the filters or choose CSV for the complete export. No partial PDF was created.']);
        $csv=$this->get('/api/invoice-center/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('QA-EXPORT-0001',$csv);$this->assertStringContainsString('QA-EXPORT-0501',$csv);
        $this->assertDatabaseCount('transaction_receipts',501);
    }
    public function test_pdf_source_filters_are_preserved_and_foreign_business_cannot_be_selected(): void
    {
        $this->receipt(1);$this->receipt(2);
        $foreign=(string)Str::uuid();DB::table('merchants')->insert(['id'=>$foreign,'merchant_account_id'=>$this->actor('merchant')->id,'business_name'=>'Foreign QA','tin'=>'987654321','status'=>'active']);$this->receipt(3,$foreign);
        $csv=$this->withToken($this->owner->api_token)->get('/api/invoice-center/export?format=csv&search=QA-EXPORT-0001&report_limit=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('QA-EXPORT-0001',$csv);$this->assertStringNotContainsString('QA-EXPORT-0002',$csv);$this->assertStringNotContainsString('QA-EXPORT-0003',$csv);
        $this->getJson('/api/invoice-center/export?format=csv&report_limit=500&merchant_id='.$foreign)->assertNotFound();
        $this->getJson('/api/invoice-center/export?format=csv&report_limit=0')->assertUnprocessable();
        $this->getJson('/api/invoice-center/export?format=csv&report_limit=501')->assertUnprocessable();
    }
    public function test_empty_audit_export_has_headers_and_no_export_audit_side_effect_in_its_rows(): void
    {
        $csv=$this->withToken($this->admin->api_token)->get('/api/live-monitoring/audit?download=1&format=csv&search=NO-MATCH-QA&report_limit=500')->assertOk()->streamedContent();
        $this->assertSame(1,substr_count(trim($csv),"\n")+1);
        $this->assertStringContainsString('Recorded Details',$csv);
    }
    public function test_report_csv_handles_formulas_unicode_and_multiline_values(): void
    {
        $response=ReportCsv::download(['Name','Value'],[['Peña, José',"Line 1\nLine 2"],['Formula',"\t=2+2"],['Refund',-1.25]],'qa.csv');
        ob_start();$response->sendContent();$csv=ob_get_clean();
        $this->assertStringContainsString('Peña, José',$csv);$this->assertStringContainsString("'\t=2+2",$csv);$this->assertStringContainsString("'-1.25",$csv);
        $this->assertStringContainsString("Line 1\nLine 2",$csv);
    }
}
