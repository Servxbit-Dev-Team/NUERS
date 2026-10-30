<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Presentation/default preferences. Tax registration, authority and numbering remain independently controlled. */
class MerchantInvoiceSettings
{
    public function requireOwnerMfa(\App\Models\User $u): void
    {
        if (config('security.enforce_privileged_mfa') && !app(MfaSession::class)->verified($u)) {
            abort(response()->json(['message' => 'Complete multifactor verification to update business branding or invoice settings.','code' => 'MFA_REQUIRED','enrollment_required' => !$u->mfa_secret], 403));
        }
    }
    public function defaults(object $m): array
    {
        return ['default_document_type' => $m->vat_registered ? 'vat_invoice' : 'non_vat_invoice','transaction_type' => 'sales','invoice_date' => 'current','price_display' => 'inclusive','currency' => 'PHP','require_customer_tin' => false,'allow_zero_rated' => false,'invoice_template' => 'standard','receipt_template' => 'minimal','invoice_paper' => 'A4','receipt_paper' => 'A4','primary_color' => '#0056d6','secondary_color' => '#0f2d5b','font_family' => 'Inter','show_logo' => true,'show_website' => true,'footer_text' => 'Thank you for your business.','additional_notes' => '','payment_terms_days' => 30,'payment_methods' => ['cash','bank_transfer','card','e_wallet'],'payment_instructions' => '','terms_and_conditions' => 'Payment due within 30 days of invoice date.'];
    }
    public function get(object $m): array
    {
        $r = DB::table('merchant_invoice_settings')->where('merchant_id', $m->id)->first();
        return ['settings' => array_replace($this->defaults($m), $r ? json_decode($r->settings, true) : []),'revision' => (int)($r->revision ?? 0)];
    }
    public function validate(array $s, object $m): array
    {
        $defaults = $this->defaults($m);
        $rules = ['default_document_type' => 'required|in:vat_invoice,non_vat_invoice,vat_exempt_invoice,zero_rated_invoice,mixed_sales_invoice,service_invoice,payment_receipt','transaction_type' => 'required|in:sales,services','invoice_date' => 'required|in:current,manual','price_display' => 'required|in:inclusive,exclusive','currency' => 'required|in:PHP','require_customer_tin' => 'required|boolean','allow_zero_rated' => 'required|boolean','invoice_template' => 'required|in:standard,minimal,detailed','receipt_template' => 'required|in:standard,minimal,detailed','invoice_paper' => 'required|in:A4,Letter','receipt_paper' => 'required|in:A4,Letter','primary_color' => ['required','regex:/^#[0-9a-fA-F]{6}$/'],'secondary_color' => ['required','regex:/^#[0-9a-fA-F]{6}$/'],'font_family' => 'required|in:Inter,Arial,Georgia','show_logo' => 'required|boolean','show_website' => 'required|boolean','footer_text' => 'nullable|string|max:500','additional_notes' => 'nullable|string|max:500','payment_terms_days' => 'required|integer|min:0|max:365','payment_methods' => 'required|array|min:1|max:4','payment_methods.*' => ['required','distinct',Rule::in(['cash','bank_transfer','card','e_wallet'])],'payment_instructions' => 'nullable|string|max:1000','terms_and_conditions' => 'nullable|string|max:2000'];
        $v = Validator::make(['settings' => $s], ['settings' => 'required|array:'.implode(',', array_keys($defaults)),...collect($rules)->mapWithKeys(fn ($v, $k) => ['settings.'.$k => $v])->all()])->validate()['settings'];
        abort_if(!$m->vat_registered && ($v['allow_zero_rated'] || in_array($v['default_document_type'], ['vat_invoice','zero_rated_invoice'], true)), 422, 'VAT and zero-rated defaults require a VAT-registered seller. Registered tax details are managed in Business Profile.');
        abort_if($v['default_document_type'] === 'zero_rated_invoice' && !$v['allow_zero_rated'], 422, 'Enable eligible zero-rated transactions before choosing that default.');
        foreach (['footer_text','additional_notes','payment_instructions','terms_and_conditions'] as $k) {
            $v[$k] = trim((string)($v[$k] ?? ''));
        }
        // Restrict accent colors to readable contrast against white. Prevent invisible document text.
        foreach (['primary_color','secondary_color'] as $k) {
            $rgb = sscanf($v[$k], '#%02x%02x%02x');
            $l = array_map(fn ($c) => ($c / 255 <= .04045 ? $c / 255 / 12.92 : pow(($c / 255 + .055) / 1.055, 2.4)), $rgb);
            $lum = .2126 * $l[0] + .7152 * $l[1] + .0722 * $l[2];
            abort_if(1.05 / ($lum + .05) < 3, 422, 'Choose darker branding colors with at least 3:1 contrast on white.');
        }
        return $v;
    }
    public function snapshot(object $m): array
    {
        return [...$this->get($m)['settings'],'logo_url' => $m->invoice_logo_url ?? null,'seller_email' => $m->email ?: $m->merchant_account_email,'seller_phone' => $m->phone,'seller_website' => $m->website];
    }
    public function assertPolicy(object $m, array $lines, ?string $tin): void
    {
        $r = DB::table('merchant_invoice_settings')->where('merchant_id', $m->id)->first();
        if (!$r) {
            return;
        }$s = $this->get($m)['settings'];
        abort_if($s['require_customer_tin'] && !trim((string)$tin), 422, 'A customer TIN is required by this business invoice policy.');
        abort_if(!$s['allow_zero_rated'] && collect($lines)->contains('tax_type','zero_rated'), 422, 'Zero-rated transactions are not enabled in Invoice Settings.');
    }
}
