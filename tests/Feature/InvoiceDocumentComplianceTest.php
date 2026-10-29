<?php

namespace Tests\Feature;

use App\Services\{ElectronicDocumentView, IntegrationContract, InvoiceDocumentRules, InvoiceSequence};
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InvoiceDocumentComplianceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']); DB::purge('sqlite');
        foreach (glob(database_path('migrations/*.php')) as $f) (require $f)->up();
        DB::table('merchants')->insert(['id'=>'m','business_name'=>'Synthetic Registered Seller','tin'=>'123456789000','vat_registered'=>true,'address'=>'Recorded address']);
    }

    private function series(array $overrides = []): void
    {
        DB::table('invoice_series')->insert([...['id'=>'s','merchant_id'=>'m','branch_code'=>'00000','document_family'=>'invoice','prefix'=>'SI-','serial_digits'=>6,'range_start'=>1,'range_end'=>10,'status'=>'active','authority_reference'=>'Synthetic evidence only','verified_by'=>'fixture','valid_from'=>now()->subDay(),'valid_until'=>now()->addDay(),'document_registration'=>json_encode(['system_type'=>'CAS','ac_number'=>'SYNTHETIC-AC','authority_issued_at'=>'2020-01-01'])],...$overrides]);
    }

    public function test_numbering_is_registered_scoped_and_rollback_does_not_consume_a_number(): void
    {
        $this->series();
        DB::beginTransaction();
        $first=app(InvoiceSequence::class)->reserve('m','00000','invoice');
        $this->assertSame('SI-000001',$first['number']);
        $this->assertSame('SI-000010',$first['document_registration']['series_end']);
        DB::rollBack();
        $this->assertNull(DB::table('invoice_series')->value('last_number'));
        $this->assertSame('SI-000001',DB::transaction(fn()=>app(InvoiceSequence::class)->reserve('m','00000','invoice'))['number']);
        $this->assertSame('SI-000002',DB::transaction(fn()=>app(InvoiceSequence::class)->reserve('m','00000','invoice'))['number']);
    }

    public function test_voided_number_is_never_reused_or_silently_skipped(): void
    {
        $this->series();
        DB::table('transaction_receipts')->insert(['id'=>'old','merchant_id'=>'m','receipt_number'=>'SI-000001','seller_branch_code'=>'00000','merchant_name'=>'Seller','merchant_tin'=>'123456789000','document_type'=>'vat_invoice','status'=>'voided','total_due'=>112]);
        try { DB::transaction(fn()=>app(InvoiceSequence::class)->reserve('m','00000','invoice')); $this->fail('Reused a voided number'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $this->assertNull(DB::table('invoice_series')->value('last_number'));
    }

    public function test_incomplete_registration_blocks_issuance_without_advancing_counter(): void
    {
        $this->series(['document_registration'=>null]);
        try { DB::transaction(fn()=>app(InvoiceSequence::class)->reserve('m','00000','invoice')); $this->fail('Missing registration allowed'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409,$e->getStatusCode()); }
        $this->assertNull(DB::table('invoice_series')->value('last_number'));
    }

    public function test_eis_number_length_and_control_characters_are_checked_without_rewriting(): void
    {
        $rules=app(InvoiceDocumentRules::class);
        $this->assertNull($rules->numberError(str_repeat('A',50)));
        foreach ([str_repeat('A',51),' INV-1','INV-1 ',"INV\n1",''] as $number) $this->assertNotNull($rules->numberError($number));
        $this->assertSame('123-456-789-00000',$rules->registeredTin('123-456-789-000',null));
        $this->assertSame('123-456-789-00123',$rules->registeredTin('123456789','00123'));
        $this->assertNull($rules->registeredTin('123456789000','00123'));
    }

    public function test_business_buyer_requires_registered_details_but_b2c_does_not(): void
    {
        app(InvoiceDocumentRules::class)->assertBuyer('walk_in','Walk-in',null,null);
        app(InvoiceDocumentRules::class)->assertBuyer('business','Business','98765432100000','Registered address');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(InvoiceDocumentRules::class)->assertBuyer('business','Business',null,null);
    }

    public function test_copies_preserve_source_registration_cwt_and_identity_without_inventing_authority(): void
    {
        $registration=['system_type'=>'CAS','pti_number'=>'SYNTHETIC-PTI','ac_number'=>'SYNTHETIC-AC','authority_issued_at'=>'2020-01-01','series_start'=>'SI-000001','series_end'=>'SI-000010'];
        DB::table('merchant_transactions')->insert(['id'=>'tx','merchant_id'=>'m','amount'=>112,'external_payload'=>json_encode(['invoice_reference'=>'ORIGINAL-1','customer_type'=>'business','withholding_details'=>['atc'=>'WC010','rate'=>2,'base'=>100,'classification'=>'Synthetic buyer-confirmed CWT']])]);
        DB::table('transaction_receipts')->insert(['id'=>'r','merchant_id'=>'m','transaction_id'=>'tx','receipt_number'=>'SI-000001','merchant_name'=>'Original Seller','merchant_tin'=>'123456789000','merchant_address'=>'Original address','merchant_vat_reg'=>'VAT Registered','seller_branch_code'=>'00000','document_type'=>'vat_invoice','record_mode'=>'source_capture','status'=>'issued','issued_at'=>now(),'total_due'=>112,'gross_amount'=>112,'vatable_sales'=>100,'vat_amount'=>12,'withholding_tax'=>2,'buyer_name'=>'Buyer','buyer_tin'=>'98765432100000','buyer_address'=>'Buyer address','document_registration'=>json_encode($registration),'items'=>json_encode([['description'=>'Recorded item','quantity'=>1,'unit_price'=>112,'line_total'=>112,'tax_type'=>'vatable','vat_inclusive'=>true]])]);
        $row=DB::table('transaction_receipts')->where('id','r')->first();
        $before=json_encode($row);
        $dto=app(ElectronicDocumentView::class)->make('receipt',$row);
        $this->assertSame('123-456-789-00000',$dto['registered_tin']);
        $this->assertSame('SYNTHETIC-PTI',$dto['permit_number']);
        $this->assertSame('ORIGINAL-1',$dto['invoice_reference']);
        $this->assertSame('WC010',$dto['withholding_details']['atc']);
        $this->assertSame(110.0,$dto['net_payable']);
        $this->assertSame('REPRINT',$dto['copy_label']);
        $this->assertSame($before,json_encode(DB::table('transaction_receipts')->where('id','r')->first()));
        $render=new Process(['node',base_path('bootstrap/ssr/document-renderer.mjs'),'--stdin'],base_path());
        $render->setInput(json_encode($dto));$render->mustRun();$html=$render->getOutput();
        foreach (['VAT Reg TIN','123-456-789-00000','SYNTHETIC-PTI','SYNTHETIC-AC','SI-000001','SI-000010','WC010','REPRINT','VATable','110.00'] as $text) $this->assertStringContainsString($text,$html);
        $this->assertStringNotContainsString('NUERS-DIGITAL-2026',$html);
    }

    public function test_unknown_source_registration_fields_are_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(InvoiceDocumentRules::class)->registration(['system_type'=>'CAS','verified'=>true]);
    }
}
