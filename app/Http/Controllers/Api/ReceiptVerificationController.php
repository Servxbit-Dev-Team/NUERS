<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptVerificationController
{
    public function show(Request $request, string $receiptNumber)
    {
        $access = app(ApiAccess::class);
        $user = $access->require($request, ['merchant', 'client', 'consumer', 'admin', 'super_admin', 'bir']);
        $v = $request->validate(['merchant_id' => 'nullable|string|max:80', 'branch_code' => 'nullable|regex:/^\d{5}$/']);
        abort_if(strlen($receiptNumber) > 120, 422, 'Receipt number is too long.');
        $query = DB::table('transaction_receipts')->where('receipt_number', $receiptNumber);
        $role = $request->attributes->get('api_role');
        if ($role === 'merchant') {
            $query->whereIn('merchant_id', $access->merchants($user)->select('id'));
        } elseif (in_array($role, ['client', 'consumer'], true)) {
            $tin = preg_replace('/\D/', '', $access->taxpayerTin($user) ?? '');
            abort_unless($tin !== '', 404, 'Receipt not available within your account.');
            $query->whereRaw("REPLACE(REPLACE(buyer_tin, '-', ''), ' ', '') = ?", [$tin]);
        }
        foreach (['merchant_id' => 'merchant_id', 'branch_code' => 'seller_branch_code'] as $key => $column) {
            if (! empty($v[$key])) {
                $query->where($column, $v[$key]);
            }
        }
        $matches = $query->limit(2)->get();
        abort_if($matches->isEmpty(), 404, 'Receipt not available within your account.');
        abort_if($matches->count() > 1, 422, 'This number has multiple authorized records. Select the business and branch or open the receipt from Invoices & Receipts.');
        $receipt = $matches->first();
        DB::table('receipt_verifications')->insert([
            'id' => (string) Str::uuid(), 'receipt_id' => $receipt->id, 'receipt_number' => $receipt->receipt_number,
            'verification_method' => 'authenticated_registry_lookup', 'verifier_ip' => $request->ip(),
            'status' => 'registry_match', 'signature_valid' => false, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['receipt' => [
            'receipt_number' => $receipt->receipt_number, 'merchant_name' => $receipt->merchant_name,
            'merchant_tin' => $receipt->merchant_tin, 'gross_amount' => $receipt->gross_amount, 'total_due' => $receipt->total_due,
            'status' => $receipt->status, 'issued_at' => $receipt->issued_at, 'signature_valid' => null,
            'authenticity' => 'NUERS registry match — cryptographic signature and BIR acceptance not verified',
        ]])->header('Cache-Control', 'private, no-store');
    }
}
