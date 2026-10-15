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

Route::get('/legal/policies', fn()=>response()->json(json_decode(file_get_contents(resource_path('data/legal-policies.json')),true)));

Route::get('/health', fn () => ['ok' => true, 'app' => 'NUERS Laravel API']);

Route::get('/registration/options', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'options']);
Route::get('/registration/locations', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'locations']);
Route::get('/registration/rdo', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'resolveRdo']);
Route::post('/registration/email-code', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'sendCode'])->middleware('throttle:5,1');
Route::post('/registration/verify-code', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'verifyCode'])->middleware('throttle:15,1');
Route::post('/registration/business', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'store'])->middleware('throttle:5,1');

Route::post('/support/conversations', [\App\Http\Controllers\Api\SupportChatController::class,'create'])->middleware('throttle:5,1');
Route::get('/support/conversations/{id}', [\App\Http\Controllers\Api\SupportChatController::class,'messages'])->whereUuid('id');
Route::post('/support/conversations/{id}/messages', [\App\Http\Controllers\Api\SupportChatController::class,'send'])->whereUuid('id')->middleware('throttle:20,1');
Route::post('/support/conversations/{id}/read', [\App\Http\Controllers\Api\SupportChatController::class,'read'])->whereUuid('id');
Route::get('/admin/support', [\App\Http\Controllers\Api\SupportChatController::class,'inbox']);
Route::get('/admin/support/{id}', [\App\Http\Controllers\Api\SupportChatController::class,'adminMessages'])->whereUuid('id');
Route::post('/admin/support/{id}/messages', [\App\Http\Controllers\Api\SupportChatController::class,'adminSend'])->whereUuid('id')->middleware('throttle:40,1');
Route::post('/admin/support/{id}/read', [\App\Http\Controllers\Api\SupportChatController::class,'adminRead'])->whereUuid('id');
Route::patch('/admin/support/{id}', [\App\Http\Controllers\Api\SupportChatController::class,'update'])->whereUuid('id');

Route::get('/subscription-plans', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'index']);
Route::get('/admin/subscription-plans', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'manage']);
Route::put('/admin/subscription-plans/{id}', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'update'])->whereNumber('id');

Route::get('/invoice-center', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'index']);
Route::get('/invoice-center/businesses', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'merchants']);
Route::post('/invoice-center', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'store']);
Route::get('/invoice-center/{source}/{id}', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'show']);

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
Route::get('/admin/rdo-mappings', [\App\Http\Controllers\Api\RdoMappingController::class,'index']);
Route::post('/admin/rdo-mappings', [\App\Http\Controllers\Api\RdoMappingController::class,'store']);
Route::get('/business-registry/export', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'export']);
Route::get('/business-registry/template', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'template']);
Route::get('/business-registry/tin', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'validateTin']);
Route::post('/business-registry/import', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'importPreview']);
Route::post('/business-registry/import/{id}', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'importCommit']);
Route::get('/business-registry', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'index']);
Route::post('/business-registry', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'store']);
Route::get('/business-registry/{id}', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'show']);
Route::put('/business-registry/{id}', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'update']);
Route::post('/business-registry/{id}/review', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'review']);
Route::post('/business-registry/{id}/invitation', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'invitation']);
Route::post('/business-activation/inspect', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'activation'])->middleware('throttle:30,1');
Route::post('/business-activation', [\App\Http\Controllers\Api\BusinessRegistryController::class, 'activate'])->middleware('throttle:10,1');
Route::get('/operations-report/export', [\App\Http\Controllers\Api\OperationsReportController::class,'export']);
Route::get('/operations-report', [\App\Http\Controllers\Api\OperationsReportController::class,'index']);
Route::post('/operations-report/{id}/review', [\App\Http\Controllers\Api\OperationsReportController::class,'review']);
Route::get('/transaction-workspace/export', [\App\Http\Controllers\Api\TransactionWorkspaceController::class,'export']);
Route::get('/transaction-workspace', [\App\Http\Controllers\Api\TransactionWorkspaceController::class,'index']);
Route::get('/transaction-workspace/{id}', [\App\Http\Controllers\Api\TransactionWorkspaceController::class,'show']);
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

Route::get('/taxpayer-authorizations/export', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'export']);
Route::get('/taxpayer-authorizations/template', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'template']);
Route::post('/taxpayer-authorizations/import', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'preview']);
Route::post('/taxpayer-authorizations/import/{id}', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'commit'])->whereUuid('id');
Route::get('/taxpayer-authorizations', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'index']);
Route::post('/taxpayer-authorizations', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'store']);
Route::post('/taxpayer-devices', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'device']);
Route::put('/taxpayer-devices/{id}', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'device'])->whereUuid('id');
Route::post('/taxpayer-devices/{id}/observations', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'observation'])->whereUuid('id');
Route::get('/taxpayer-authorizations/{id}', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'show'])->whereUuid('id');
Route::put('/taxpayer-authorizations/{id}', [\App\Http\Controllers\Api\TaxpayerAuthorizationController::class,'update'])->whereUuid('id');

Route::get('/integration-workspace', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'index']);
Route::get('/integration-workspace/export', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'export']);
Route::post('/integration-workspace/connections', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'save']);
Route::put('/integration-workspace/connections/{id}', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'save'])->whereUuid('id');
Route::post('/integration-workspace/connections/{id}/credential', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'credential'])->whereUuid('id')->middleware('throttle:10,1');
Route::post('/integration-workspace/connections/{id}/certificate', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'certificate'])->whereUuid('id');
Route::post('/integration-workspace/connections/{id}/diagnostics', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'diagnose'])->whereUuid('id')->middleware('throttle:10,1');
Route::post('/integration-workspace/endpoints', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'endpoint']);
Route::put('/integration-workspace/endpoints/{id}', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'endpoint'])->whereUuid('id');
Route::post('/integration-workspace/webhooks', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'webhook']);
Route::put('/integration-workspace/webhooks/{id}', [\App\Http\Controllers\Api\IntegrationWorkspaceController::class,'webhook'])->whereUuid('id');

Route::post('/developer/sandbox', [\App\Http\Controllers\Api\DeveloperSandboxController::class,'run'])->middleware('throttle:20,1');
Route::get('/developer/openapi', fn()=>response()->file(resource_path('data/developer-openapi.json'),['Content-Type'=>'application/json','Content-Disposition'=>'attachment; filename="nuers-openapi.json"','Cache-Control'=>'public, max-age=300']));
Route::get('/developer/catalog', fn()=>response()->file(resource_path('data/developer-catalog.json'),['Content-Type'=>'application/json','Cache-Control'=>'public, max-age=300']));
