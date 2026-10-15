<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessAccountController;
use App\Http\Controllers\Api\BusinessInvoiceController;
use App\Http\Controllers\Api\CitizenApprovalController;
use App\Http\Controllers\Api\ClientProfileController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EnterpriseDashboardController;
use App\Http\Controllers\Api\LiveMonitoringController;
use App\Http\Controllers\Api\EspComplianceController;
use App\Http\Controllers\Api\EisController;
use App\Http\Controllers\Api\IntegrationTransactionController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MerchantApiKeyController;
use App\Http\Controllers\Api\MerchantExpenseController;
use App\Http\Controllers\Api\MerchantReceiptController;
use App\Http\Controllers\Api\MerchantTaxCenterController;
use App\Http\Controllers\Api\MerchantTransactionController;
use App\Http\Controllers\Api\RdoController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TaxIntelligenceController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/health', fn () => ['ok' => true, 'app' => 'NUERS Laravel API']);

Route::get('/auth/session', [AuthController::class, 'session']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/signup', [AuthController::class, 'signup']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/merchant-accounts', [AuthController::class, 'createMerchantAccount']);
Route::get('/client/profile', [ClientProfileController::class, 'show']);
Route::put('/client/profile', [ClientProfileController::class, 'update']);
Route::post('/client/profile/avatar', [ClientProfileController::class, 'uploadAvatar']);
Route::get('/business-accounts/current', [BusinessAccountController::class, 'current']);
Route::put('/business-accounts/current', [BusinessAccountController::class, 'update']);
Route::post('/business-accounts/current/logo', [BusinessAccountController::class, 'uploadLogo']);
Route::get('/business-accounts/lookup', [BusinessAccountController::class, 'lookupByTin']);
Route::get('/citizen-approvals', [CitizenApprovalController::class, 'index']);
Route::patch('/citizen-approvals/{id}', [CitizenApprovalController::class, 'update']);
Route::get('/business-invoices', [BusinessInvoiceController::class, 'index']);
Route::post('/business-invoices', [BusinessInvoiceController::class, 'store']);
Route::get('/business-invoices/{invoice}', [BusinessInvoiceController::class, 'show']);
Route::get('/merchant/api-keys', [MerchantApiKeyController::class, 'index']);
Route::post('/merchant/api-keys', [MerchantApiKeyController::class, 'store']);
Route::patch('/merchant/api-keys/{id}', [MerchantApiKeyController::class, 'update']);
Route::delete('/merchant/api-keys/{id}', [MerchantApiKeyController::class, 'destroy']);
Route::get('/merchant/pos-devices', [MerchantApiKeyController::class, 'connections']);
Route::get('/merchant/api-branches', [MerchantApiKeyController::class, 'branches']);
Route::get('/merchant/expenses', [MerchantExpenseController::class, 'index']);
Route::post('/merchant/expenses', [MerchantExpenseController::class, 'store']);
Route::get('/merchant/receipts', [MerchantReceiptController::class, 'index']);
Route::get('/merchant/receipts/verify', [MerchantReceiptController::class, 'verify']);
Route::get('/merchant/transactions', [MerchantTransactionController::class, 'index']);
Route::get('/merchant/tax-center', [MerchantTaxCenterController::class, 'show']);
Route::post('/integrations/transactions', [IntegrationTransactionController::class, 'store']);
Route::get('/dashboards/{portal}', [DashboardController::class, 'show']);
Route::get('/enterprise-dashboards/{portal}', [EnterpriseDashboardController::class, 'show']);
Route::get('/live-monitoring', [LiveMonitoringController::class, 'show']);
Route::get('/live-monitoring/audit', [LiveMonitoringController::class, 'auditLog']);
Route::get('/live-monitoring/export', [LiveMonitoringController::class, 'export']);
Route::get('/esp-compliance', [EspComplianceController::class, 'show']);
Route::post('/esp-compliance/applications', [EspComplianceController::class, 'application']);
Route::post('/esp-compliance/applications/{id}/evidence', [EspComplianceController::class, 'evidence']);
Route::post('/esp-compliance/applications/{id}/reviews', [EspComplianceController::class, 'review']);
Route::post('/esp-compliance/applications/{id}/certificate', [EspComplianceController::class, 'certificate']);
Route::post('/esp-compliance/incidents', [EspComplianceController::class, 'incident']);
Route::patch('/esp-compliance/incidents/{id}', [EspComplianceController::class, 'updateIncident']);
Route::get('/transactions/overview', [TransactionController::class, 'overview']);
Route::get('/tax-intelligence/{scope}', [TaxIntelligenceController::class, 'show']);
Route::get('/bir-eis/readiness', [EisController::class, 'readiness']);
Route::get('/bir-eis/transmissions', [EisController::class, 'transmissions']);
Route::post('/bir-eis/validate', [EisController::class, 'validatePayload']);
Route::post('/bir-eis/transmissions', [EisController::class, 'storeTransmission']);
Route::post('/bir-eis/transmissions/{id}/retry', [EisController::class, 'retry']);
Route::post('/bir-eis/transmissions/{id}/acknowledge', [EisController::class, 'acknowledge']);
Route::get('/rdos', [RdoController::class, 'index']);
Route::post('/rdos', [RdoController::class, 'store']);
Route::get('/rdo/dashboard', [RdoController::class, 'dashboard']);
Route::get('/locations/countries', [LocationController::class, 'countries']);
Route::get('/locations/cities', [LocationController::class, 'cities']);
Route::get('/locations/barangays', [LocationController::class, 'barangays']);
Route::get('/locations/rdo', [LocationController::class, 'rdo']);

Route::get('/verify-receipt/{receiptNumber}', function (string $receiptNumber) {
    $receipt = DB::table('transaction_receipts')
        ->where('receipt_number', $receiptNumber)
        ->first();

    abort_unless($receipt, 404, 'Receipt not found in the NUERS registry.');

    DB::table('receipt_verifications')->insert([
        'id' => (string) Str::uuid(),
        'receipt_id' => $receipt->id,
        'receipt_number' => $receipt->receipt_number,
        'verification_method' => 'receipt_number',
        'verifier_ip' => request()->ip(),
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
});

Route::match(['get', 'post', 'put', 'patch', 'delete'], '/tables/{table}', [TableController::class, 'handle']);
