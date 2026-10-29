<?php

namespace Tests\Unit;

use App\Services\Eis\InvoiceMapper;
use App\Services\Eis\Protocol;
use App\Services\Eis\ResultVerifier;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BirProtocolTest extends TestCase
{
    public function test_crypto_matches_the_bir_wire_algorithms_and_detects_tampering(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $plain = Protocol::json(['userId' => 'synthetic', 'password' => 'lab-only', 'authKey' => str_repeat('A', 32)]);
        $rsa = Protocol::rsa($plain, $public);
        $this->assertTrue(openssl_private_decrypt(base64_decode($rsa), $out, $key, OPENSSL_PKCS1_PADDING));
        $this->assertSame($plain, $out);
        $session = '0123456789ABCDEFGHIJKLMNOPQRSTUV';
        $encrypted = Protocol::encrypt('Synthetic invoice ₱', $session);
        $this->assertSame('Synthetic invoice ₱', Protocol::decrypt($encrypted, $session));
        $this->assertSame(base64_encode(openssl_encrypt('Synthetic invoice ₱', 'aes-256-cbc', $session, OPENSSL_RAW_DATA, substr($session, 0, 16))), $encrypted);
        $this->assertSame('Bearer '.base64_encode(hash_hmac('sha256', '20261028120000GET/api/invoice_result/submit', $session, true)), Protocol::authorization('20261028120000', 'GET', '/api/invoice_result/submit', $session));
        $jws = Protocol::sign(['CompInvoiceId' => 'TEST', 'NetAmtPay' => 112.0], 'synthetic-key', $private);
        [$h,$p,$s] = explode('.', $jws);
        $this->assertSame(['alg' => 'RS256', 'kid' => 'synthetic-key'], json_decode(base64_decode(strtr($h, '-_', '+/')), true));
        $this->assertSame(1, openssl_verify($h.'.'.$p, base64_decode(strtr($s, '-_', '+/')), $public, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($h.'.'.strrev($p), base64_decode(strtr($s, '-_', '+/')), $public, OPENSSL_ALGO_SHA256));
        $this->expectException(\RuntimeException::class);
        Protocol::decrypt('invalid cipher', $session);
    }

    public static function model(): array
    {
        return ['schema' => 'NUERS-normalized-document-5', 'currency' => 'PHP', 'seller' => ['taxpayer_tin' => '123456789', 'branch_code' => '00000', 'name' => 'Synthetic seller', 'address' => 'Synthetic address'], 'issue_date' => '2026-01-01', 'document_type' => 'sales_invoice', 'number' => 'INV-TEST', 'buyer' => ['tin' => '123456789000', 'name' => 'Synthetic buyer', 'address' => 'Synthetic address'], 'totals' => ['amount_centavos' => 21200, 'withholding_centavos' => 0], 'items' => [
            ['description' => 'VAT item', 'quantity' => '1', 'gross_base_centavos' => 12500, 'net_base_centavos' => 10000, 'regular_discount_base_centavos' => 2500, 'statutory_discount_centavos' => 0, 'vat_centavos' => 1200, 'tax_type' => 'vatable'],
            ['description' => 'Exempt item', 'quantity' => '2', 'gross_base_centavos' => 10000, 'net_base_centavos' => 10000, 'regular_discount_base_centavos' => 0, 'statutory_discount_centavos' => 0, 'vat_centavos' => 0, 'tax_type' => 'vat_exempt'],
        ]];
    }

    public static function profile(array $changes = []): object
    {
        return (object) array_replace(['accreditation_id' => '20146IT4', 'branch_code' => '00000', 'system_type' => 'CAS', 'seller_type' => '0', 'ptu_number' => 'LAB-ONLY', 'machine_id' => 'LAB-MIN', 'machine_serial' => 'LAB-MSN', 'schema_dialect' => 'workbook_20260926'], $changes);
    }

    public function test_cas_splits_mixed_classifications_preserving_source_discount_and_payable(): void
    {
        $mapped = (new InvoiceMapper)->map(self::model(), self::profile());
        $this->assertCount(2, $mapped);
        $this->assertEquals(125.0, $mapped['01']['ItemList'][0]['SalesAmt']);
        $this->assertEquals(25.0, $mapped['01']['ItemList'][0]['RegDscntAmt']);
        $this->assertEquals(112.0, $mapped['01']['NetAmtPay']);
        $this->assertEquals(100.0, $mapped['03']['NetAmtPay']);
        $this->assertNotSame($mapped['01']['EisUniqueId'], $mapped['03']['EisUniqueId']);
        $this->assertSame(24, strlen($mapped['01']['EisUniqueId']));
        $this->assertSame($mapped['01']['CompInvoiceId'], $mapped['03']['CompInvoiceId']);
    }

    public function test_exempt_invoice_preserves_seller_number_and_separate_eis_identity(): void
    {
        $m=self::model();$m['document_type']='vat_exempt_invoice';$m['number']='EXEMPT-000001';
        $m['items']=[$m['items'][1]];$m['totals']['amount_centavos']=10000;
        $d=(new InvoiceMapper)->map($m,self::profile())['03'];
        $this->assertSame('EXEMPT-000001',$d['CompInvoiceId']);
        $this->assertSame(24,strlen($d['EisUniqueId']));
        $this->assertEquals(100,$d['NetAmtPay']);
        $m['number']=str_repeat('A',51);
        $this->expectException(ValidationException::class);
        (new InvoiceMapper)->map($m,self::profile());
    }

    public function test_pos_preserves_mixed_invoice_and_uses_versioned_case_sensitive_fields(): void
    {
        $mapped = (new InvoiceMapper)->map(self::model(), self::profile(['system_type' => 'POS']));
        $d = $mapped['00'];
        $this->assertCount(1, $mapped);
        $this->assertEquals(125.0, $d['VATSales']);
        $this->assertEquals(25.0, $d['Discount']['RegAmt']);
        $this->assertEquals(212.0, $d['NetAmtPay']);
        $this->assertArrayNotHasKey('TransClass', $d);
        $this->assertSame('LAB-MIN', $d['Min']);
        $d = (new InvoiceMapper)->map(self::model(), self::profile(['system_type' => 'POS', 'schema_dialect' => 'guide_20260730']))['00'];
        $this->assertArrayHasKey('VatSales', $d);
        $this->assertArrayNotHasKey('VATSales', $d);
    }

    public function test_senior_discount_is_reported_once_in_the_bir_summary(): void
    {
        $m = self::model();
        $m['items'] = [['description' => 'Eligible item', 'quantity' => '1', 'gross_base_centavos' => 10000, 'net_base_centavos' => 8000, 'regular_discount_base_centavos' => 0, 'statutory_discount_centavos' => 2000, 'vat_centavos' => 0, 'tax_type' => 'vat_exempt']];
        $m['totals']['amount_centavos'] = 8000;
        $m['sales_benefit']['eligibility']['category'] = 'senior_citizen';
        $d = (new InvoiceMapper)->map($m, self::profile())['03'];
        $this->assertEquals(100.0, $d['TotNetItemSales']);
        $this->assertEquals(20.0, $d['Discount']['ScAmt']);
        $this->assertEquals(0.0, $d['ItemList'][0]['RegDscntAmt']);
        $this->assertEquals(80.0, $d['NetAmtPay']);
    }

    public function test_missing_buyer_registration_is_not_silently_invented(): void
    {
        $m = self::model();
        $m['buyer']['tin'] = '';
        $this->expectException(ValidationException::class);
        (new InvoiceMapper)->map($m, self::profile());
    }

    public function test_unsupported_debit_note_dialect_fails_closed(): void
    {
        $m = self::model();
        $m['document_type'] = 'debit_note';
        $this->expectException(ValidationException::class);
        (new InvoiceMapper)->map($m, self::profile());
    }

    public function test_credit_notes_require_original_identity_and_preserve_negative_amounts(): void
    {
        $m = self::model();
        $m['document_type'] = 'credit_memo';
        $map = (new InvoiceMapper)->map($m, self::profile(), ['correction_code' => '05', 'previous_uids' => ['01' => '2026010120146IT4ABCDEFGH', '03' => '2026010120146IT4ABCDEFGJ']]);
        $this->assertEquals(-112.0, $map['01']['NetAmtPay']);
        $this->assertEquals(-25.0, $map['01']['ItemList'][0]['RegDscntAmt']);
        $this->assertSame('Y', $map['01']['CorrYN']);
        $this->assertSame('03', $map['01']['DocType']);
    }

    public function test_result_verification_requires_exact_ids_counts_and_known_codes(): void
    {
        $p = (object) ['accreditation_id' => '20146IT4', 'user_id' => 'test'];
        $data = ['accreditationId' => '20146IT4', 'userId' => 'test', 'refSubmitId' => 'submission', 'ackId' => 'BIR-20261028120000-AB123', 'responseDtm' => '2026-10-28T12:00:00', 'processStatusCode' => '01', 'totalCountQuantity' => 2, 'successCountQuantity' => 1, 'failCountQuantity' => 1, 'processedDocuments' => [['invoiceUid' => 'A', 'resultStatusCode' => 'SUC001'], ['invoiceUid' => 'B', 'resultStatusCode' => 'SYN003', 'description' => 'Duplicate']]];
        $v = new ResultVerifier;
        $this->assertSame('partial',$v->verify($data,$p,'submission',['A', 'B'])['state']);
        $this->expectException(\RuntimeException::class);
        $data['processedDocuments'][1]['invoiceUid'] = 'A';
        $v->verify($data,$p,'submission',['A', 'B']);
    }
}
