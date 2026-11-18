<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess,BirCompliance,MerchantInvoiceSettings,InvoiceCalculator};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantInvoiceSettingsController extends Controller
{
    private function merchant(Request $r): object
    {
        $u = app(ApiAccess::class)->require($r, ['merchant']);
        $m = DB::table('merchants')->where('merchant_account_id', $u->id)->orderBy('id')->first();
        abort_unless($m, 403, 'Only the business owner can manage invoice settings.');
        if (!$r->isMethod('GET')) {
            abort_if(in_array($m->status, ['suspended','inactive']), 403, 'This business is suspended.');
        }return $m;
    }
    private function present(object $m): array
    {
        return [...app(MerchantInvoiceSettings::class)->get($m),'invoice_logo' => $m->invoice_logo_url ?? null,'defaults' => app(MerchantInvoiceSettings::class)->defaults($m),'business' => ['id' => $m->id,'name' => $m->business_name,'tin' => $m->tin,'address' => $m->business_registration_address ?: $m->address,'email' => $m->email ?: $m->merchant_account_email,'phone' => $m->phone,'website' => $m->website,'logo' => $m->logo_url ?? null,'vat_registered' => (bool)$m->vat_registered,'status' => $m->status],'series' => DB::table('invoice_series')->where('merchant_id', $m->id)->orderBy('branch_code')->get(['id','branch_code','document_family','prefix','range_start','range_end','last_number','serial_digits','document_registration','status','valid_from','valid_until'])->map(function ($s) {
            $next = $s->last_number === null ? $s->range_start : $s->last_number + 1;
            $available = $s->status === 'active' && $next <= $s->range_end && now()->gte($s->valid_from) && now()->lte($s->valid_until);
            $s->next_number = $available ? $s->prefix.str_pad((string)$next, (int)$s->serial_digits, '0', STR_PAD_LEFT) : 'Not available';
            return $s;
        })->all()];
    }
    public function show(Request $r)
    {
        return response()->json($this->present($this->merchant($r)))->header('Cache-Control', 'private, no-store');
    }
    public function update(Request $r)
    {
        $m = $this->merchant($r);
        app(MerchantInvoiceSettings::class)->requireOwnerMfa($r->attributes->get('api_actor'));
        $v = $r->validate(['revision' => 'required|integer|min:0','settings' => 'required|array']);
        return DB::transaction(function () use ($r, $m, $v) {
            $m = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            abort_unless((string)$m->merchant_account_id === (string)$r->attributes->get('api_actor')->id, 403, 'Business ownership changed.');
            abort_if(in_array($m->status, ['suspended','inactive']), 403, 'This business is suspended.');
            $old = DB::table('merchant_invoice_settings')->where('merchant_id', $m->id)->lockForUpdate()->first();
            abort_unless((int)($old->revision ?? 0) === $v['revision'], 409, 'Settings changed in another window. Refresh before saving.');
            $settings = app(MerchantInvoiceSettings::class)->validate($v['settings'], $m);
            $payload = ['settings' => json_encode($settings, JSON_THROW_ON_ERROR),'revision' => ($old->revision ?? 0) + 1,'updated_by' => (string)$r->attributes->get('api_actor')->id,'updated_at' => now()];
            if ($old) {
                DB::table('merchant_invoice_settings')->where('merchant_id', $m->id)->update($payload);
            } else {
                DB::table('merchant_invoice_settings')->insert(['merchant_id' => $m->id,'created_at' => now(),...$payload]);
            }app(BirCompliance::class)->audit('invoice.settings.updated', $m->id, (string)$r->attributes->get('api_actor')->id, ['revision' => $payload['revision'],'fields' => array_keys($settings)]);
            return response()->json($this->present($m))->header('Cache-Control', 'private, no-store');
        });
    }
    public function preview(Request $r)
    {
        $m = $this->merchant($r);
        $v = $r->validate(['settings' => 'required|array','document_type' => 'required|in:invoice,payment_receipt']);
        $s = app(MerchantInvoiceSettings::class)->validate($v['settings'], $m);
        $payment = $v['document_type'] === 'payment_receipt';
        $type = $payment ? 'payment_receipt' : ($s['default_document_type'] === 'payment_receipt' ? ($m->vat_registered ? 'vat_invoice' : 'non_vat_invoice') : $s['default_document_type']);
        $tax = match($type) {
            'zero_rated_invoice' => 'zero_rated','vat_exempt_invoice' => 'vat_exempt','non_vat_invoice' => 'non_vat',default => $m->vat_registered ? 'vatable' : 'non_vat'
        };
        $c = app(InvoiceCalculator::class)->calculate([['description' => 'Sample item 1','quantity' => 1,'unit_price' => 100000,'tax_type' => $tax],['description' => 'Sample item 2','quantity' => 2,'unit_price' => 50000,'tax_type' => $tax]], (bool)$m->vat_registered, 0, 0, $s['price_display'] === 'inclusive');
        $d = ['id' => 'sample','source' => 'invoice','number' => 'SAMPLE — NOT VALID','business' => $m->business_name,'tin' => $m->tin,'document_type' => $type,'record_status' => 'draft','status' => 'pending','category' => $payment ? 'receipts' : 'invoices','issued_at' => now()->toDateString(),'amount' => $c['total_amount'] / 100,'currency' => 'PHP','seller_address' => $m->business_registration_address ?: $m->address,'buyer_name' => 'Sample Customer','buyer_tin' => '000-000-000-000','buyer_address' => 'Sample customer address','reference' => 'PREVIEW ONLY','payment_method' => null,'branch' => null,'vat_registered' => (bool)$m->vat_registered,'items' => array_map(fn ($i) => ['description' => $i['description'],'quantity' => $i['quantity'],'unit_price' => $i['unit_price'] / 100,'amount' => $i['line_total'] / 100], $c['items']),'subtotal' => $c['subtotal_amount'] / 100,'discount' => 0,'vatable' => $c['taxable_amount'] / 100,'vat' => $c['vat_amount'] / 100,'vat_exempt' => $tax === 'non_vat' ? 0 : $c['vat_exempt_amount'] / 100,'sspt_sales' => $tax === 'non_vat' ? $c['total_amount'] / 100 : null,'zero_rated' => $c['zero_rated_amount'] / 100,'withholding' => 0,'other_charges' => 0,'permit_number' => null,'accreditation_number' => null,'verification_url' => null,'authority_status' => 'Preview only','source_notice' => 'SAMPLE PREVIEW — NOT AN ISSUED DOCUMENT','presentation' => [...$s,'logo_url' => $m->invoice_logo_url ?? null,'seller_email' => $m->email ?: $m->merchant_account_email,'seller_phone' => $m->phone,'seller_website' => $m->website]];
        return response()->json(['document' => $d])->header('Cache-Control', 'private, no-store');
    }
    public function pdf(Request $r)
    {
        $d = $this->preview($r)->getData(true)['document'];
        return response(app(\App\Services\DocumentPdf::class)->render($d), 200, ['Content-Type' => 'application/pdf','Content-Disposition' => 'attachment; filename="NUERS-invoice-settings-preview.pdf"','Cache-Control' => 'private, no-store','X-Content-Type-Options' => 'nosniff']);
    }
}
