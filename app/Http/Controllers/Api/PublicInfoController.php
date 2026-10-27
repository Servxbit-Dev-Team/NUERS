<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public, unauthenticated read endpoints. Controllers (not closures) so `php artisan route:cache` works. */
class PublicInfoController extends Controller
{
    public function legalPolicies()
    {
        return response()->json(json_decode(file_get_contents(resource_path('data/legal-policies.json')), true));
    }

    public function health()
    {
        return ['ok' => true, 'app' => 'NUERS Laravel API', 'solution' => ['name' => config('bir_compliance.solution_name'), 'version' => config('bir_compliance.solution_version')]];
    }

    public function openapi()
    {
        return response()->file(resource_path('data/developer-openapi.json'), ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="nuers-openapi.json"', 'Cache-Control' => 'public, max-age=300']);
    }

    public function catalog()
    {
        return response()->file(resource_path('data/developer-catalog.json'), ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']);
    }

    public function verifyReceipt(Request $request, string $receiptNumber)
    {
        $receipt = DB::table('transaction_receipts')
            ->where('receipt_number', $receiptNumber)
            ->first();

        abort_unless($receipt, 404, 'Receipt not found in the NUERS registry.');

        DB::table('receipt_verifications')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'verification_method' => 'receipt_number',
            'verifier_ip' => $request->ip(),
            'status' => 'registry_match',
            'signature_valid' => false,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'receipt' => [
                'receipt_number' => $receipt->receipt_number,
                'merchant_name' => $receipt->merchant_name,
                'merchant_tin' => $receipt->merchant_tin,
                'gross_amount' => $receipt->gross_amount,
                'total_due' => $receipt->total_due,
                'status' => $receipt->status,
                'issued_at' => $receipt->issued_at,
                'signature_valid' => null,
                'authenticity' => 'NUERS registry match — cryptographic signature and BIR acceptance not verified',
            ],
        ];
    }
}
