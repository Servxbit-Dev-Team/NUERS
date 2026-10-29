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

Route::get('/health', fn () => ['ok' => true, 'app' => 'NUERS Laravel API', 'solution' => ['name' => config('bir_compliance.solution_name'), 'version' => config('bir_compliance.solution_version')]]);

Route::get('/registration/options', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'options']);
Route::get('/registration/locations', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'locations']);
Route::get('/registration/rdo', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'resolveRdo']);
Route::post('/registration/email-code', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'sendCode'])->middleware('throttle:5,1,registration-email:');
Route::post('/registration/verify-code', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'verifyCode'])->middleware('throttle:15,1,registration-verify:');
Route::post('/registration/business', [\App\Http\Controllers\Api\BusinessRegistrationController::class, 'store'])->middleware('throttle:5,1,registration-submit:');

Route::post('/support/conversations', [\App\Http\Controllers\Api\SupportChatController::class,'create'])->middleware('throttle:5,1');
Route::get('/support/conversations/{id}', [\App\Http\Controllers\Api\SupportChatController::class,'messages'])->whereUuid('id');
Route::post('/support/conversations/{id}/messages', [\App\Http\Controllers\Api\SupportChatController::class,'send'])->whereUuid('id')->middleware('throttle:20,1');
Route::post('/support/conversations/{id}/read', [\App\Http\Controllers\Api\SupportChatController::class,'read'])->whereUuid('id');
Route::get('/admin/support', [\App\Http\Controllers\Api\SupportChatController::class,'inbox']);
Route::get('/admin/support/{id}', [\App\Http\Controllers\Api\SupportChatController::class,'adminMessages'])->whereUuid('id');
Route::post('/admin/support/{id}/messages', [\App\Http\Controllers\Api\SupportChatController::class,'adminSend'])->whereUuid('id')->middleware('throttle:40,1');
Route::post('/admin/support/{id}/read', [\App\Http\Controllers\Api\SupportChatController::class,'adminRead'])->whereUuid('id');
Route::patch('/admin/support/{id}', [\App\Http\Controllers\Api\SupportChatController::class,'update'])->whereUuid('id');

Route::get('/pricing', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'index']);
Route::get('/subscription-plans', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'index']);
Route::get('/admin/subscription-plans', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'manage']);
Route::put('/admin/subscription-plans/{id}', [\App\Http\Controllers\Api\SubscriptionPlanController::class, 'update'])->whereNumber('id');

Route::get('/invoice-center', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'index']);
Route::get('/invoice-center/export', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'export']);
Route::get('/invoice-center/businesses', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'merchants']);
Route::post('/invoice-center', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'store']);
Route::get('/invoice-center/{source}/{id}', [\App\Http\Controllers\Api\InvoiceCenterController::class, 'show']);

Route::get('/auth/session', [AuthController::class, 'session']);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/signup', [AuthController::class, 'signup'])->middleware('throttle:5,1');
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/forgot-password', [\App\Http\Controllers\Api\AccountSecurityController::class, 'forgot'])->middleware('throttle:3,1,password-forgot:');
Route::post('/auth/reset-password', [\App\Http\Controllers\Api\AccountSecurityController::class, 'reset'])->middleware('throttle:10,1,password-reset:');
Route::post('/auth/change-password', [\App\Http\Controllers\Api\AccountSecurityController::class, 'change'])->middleware('throttle:5,1,password-change:');
Route::get('/dashboard-revision', \App\Http\Controllers\Api\DashboardRevisionController::class)->middleware('throttle:120,1,dashboard-revision:');
Route::get('/auth/security-overview', [\App\Http\Controllers\Api\AccountSecurityController::class, 'overview']);
Route::get('/auth/notification-preferences', [\App\Http\Controllers\Api\AccountSecurityController::class, 'preferences']);
Route::put('/auth/notification-preferences', [\App\Http\Controllers\Api\AccountSecurityController::class, 'preferences']);
Route::get('/admin/configuration/notifications', [\App\Http\Controllers\Api\AccountSecurityController::class, 'admin']);
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
Route::get('/admin/configuration', [\App\Http\Controllers\Api\MailConfigurationController::class, 'show']);
Route::put('/admin/configuration/smtp', [\App\Http\Controllers\Api\MailConfigurationController::class, 'save'])->middleware('throttle:10,1,smtp-save:');
Route::post('/admin/configuration/smtp/test', [\App\Http\Controllers\Api\MailConfigurationController::class, 'test'])->middleware('throttle:5,1,smtp-test:');
Route::get('/document-copies/{source}/{id}', [\App\Http\Controllers\Api\DocumentCopyController::class, 'info']);
Route::get('/document-copies/{source}/{id}/pdf', [\App\Http\Controllers\Api\DocumentCopyController::class, 'pdf'])->middleware('throttle:20,1,document-pdf:');
Route::post('/document-copies/{source}/{id}/email', [\App\Http\Controllers\Api\DocumentCopyController::class, 'send'])->middleware('throttle:10,1,document-email:');
Route::get('/merchant/api-keys', [MerchantApiKeyController::class, 'index']);
Route::post('/merchant/api-keys', [MerchantApiKeyController::class, 'store'])->middleware('throttle:10,1,merchant-key-create:');
Route::patch('/merchant/api-keys/{id}', [MerchantApiKeyController::class, 'update'])->middleware('throttle:20,1,merchant-key-update:');
Route::delete('/merchant/api-keys/{id}', [MerchantApiKeyController::class, 'destroy']);
Route::get('/merchant/api-keys/logs', [MerchantApiKeyController::class, 'logs']);
Route::get('/merchant/pos-devices', [MerchantApiKeyController::class, 'connections']);
Route::get('/merchant/api-branches', [MerchantApiKeyController::class, 'branches']);
Route::get('/merchant/expenses', [MerchantExpenseController::class, 'index']);
Route::post('/merchant/expenses', [MerchantExpenseController::class, 'store']);
Route::get('/merchant/receipts', [MerchantReceiptController::class, 'index']);
Route::get('/merchant/receipts/verify', [MerchantReceiptController::class, 'verify']);
Route::get('/merchant/transactions', [MerchantTransactionController::class, 'index']);
Route::get('/merchant/tax-center', [MerchantTaxCenterController::class, 'show']);
Route::get('/merchant/sales-reports', [\App\Http\Controllers\Api\MerchantSalesReportController::class, 'show'])->middleware('throttle:90,1,merchant-sales-report:');
Route::get('/merchant/sales-reports/export', [\App\Http\Controllers\Api\MerchantSalesReportController::class, 'export'])->middleware('throttle:5,1,merchant-sales-export:');
Route::get('/merchant/sales-reports/exports', [\App\Http\Controllers\Api\MerchantSalesReportController::class, 'exportJobs'])->middleware('throttle:90,1,merchant-sales-job-status:');
Route::post('/merchant/sales-reports/exports', [\App\Http\Controllers\Api\MerchantSalesReportController::class, 'exportJobs'])->middleware('throttle:10,1,merchant-sales-jobs:');
Route::get('/merchant/sales-reports/exports/{id}/download', [\App\Http\Controllers\Api\MerchantSalesReportController::class, 'exportDownload'])->whereUuid('id')->middleware('throttle:5,1,merchant-sales-download:');
Route::post('/integrations/transactions', [IntegrationTransactionController::class, 'store']);
Route::get('/dashboards/{portal}', [DashboardController::class, 'show']);
Route::get('/enterprise-dashboards/{portal}', [EnterpriseDashboardController::class, 'show']);
Route::get('/merchant/overview', [\App\Http\Controllers\Api\MerchantOverviewController::class, 'show']);
Route::get('/merchant/records/export', [\App\Http\Controllers\Api\MerchantRecordsController::class, 'export']);
Route::get('/merchant/records', [\App\Http\Controllers\Api\MerchantRecordsController::class, 'index']);
Route::get('/merchant/records/{record}', [\App\Http\Controllers\Api\MerchantRecordsController::class, 'show']);
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
Route::get('/eis-readiness', [\App\Http\Controllers\Api\EisReadinessController::class,'index']);
Route::post('/eis-readiness/coverage', [\App\Http\Controllers\Api\EisReadinessController::class,'coverage']);
Route::post('/eis-readiness/{source}/{id}/branch-review', [\App\Http\Controllers\Api\EisReadinessController::class,'branchReview'])->middleware('throttle:20,1');
Route::post('/eis-readiness/{source}/{id}/prepare', [\App\Http\Controllers\Api\EisReadinessController::class,'prepare'])->middleware('throttle:30,1');
Route::get('/transaction-workspace/exports/{id}', [\App\Http\Controllers\Api\TransactionWorkspaceController::class,'exportDownload'])->whereUuid('id');
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

Route::get('/verify-receipt/{receiptNumber}', [\App\Http\Controllers\Api\ReceiptVerificationController::class, 'show'])->middleware('throttle:20,1,receipt-lookup:');

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

Route::get('/auth/mfa/status', [\App\Http\Controllers\Api\MfaController::class,'status']);
Route::post('/auth/mfa/enroll', [\App\Http\Controllers\Api\MfaController::class,'enroll'])->middleware('throttle:5,1');
Route::post('/auth/mfa/confirm', [\App\Http\Controllers\Api\MfaController::class,'confirm'])->middleware('throttle:5,1');
Route::post('/auth/mfa/verify', [\App\Http\Controllers\Api\MfaController::class,'verify'])->middleware('throttle:5,1');

Route::get('/integrations/transactions/wallet', [IntegrationTransactionController::class,'wallet']);
Route::post('/integrations/transactions/batch', [IntegrationTransactionController::class,'batch'])->middleware('throttle:10,1,wallet-batch:');
Route::get('/integrations/transactions/{id}', [IntegrationTransactionController::class, 'show']);

Route::post('/documents/{source}/{id}/review-requests', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'requestReview']);
Route::post('/documents/invoice/{id}/publish', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'publish']);
Route::post('/documents/{source}/{id}/holds', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'hold']);
Route::post('/documents/holds/{id}/release', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'releaseHold']);
Route::post('/identity/claims', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'identityClaim']);
Route::post('/identity/claims/{id}/review', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'identityReview']);
Route::post('/invoice-series', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'series']);
Route::get('/operations/readiness', [\App\Http\Controllers\Api\OperationalEvidenceController::class,'show']);
Route::get('/businesses/{merchant}/portability', [\App\Http\Controllers\Api\TenantPortabilityController::class,'export'])->middleware('throttle:3,1');
Route::post('/merchant/expenses/{id}/review', [\App\Http\Controllers\Api\MerchantExpenseController::class,'review']);
Route::post('/documents/review-requests/{id}/decision', [\App\Http\Controllers\Api\DocumentGovernanceController::class,'decide']);

Route::post('/auth/resend-verification', [\App\Http\Controllers\Api\AccountSecurityController::class, 'resendVerification'])->middleware('throttle:1,1,email-resend:');
Route::post('/auth/verify-email', [\App\Http\Controllers\Api\AccountSecurityController::class, 'verifyEmail'])->middleware('throttle:10,1,email-verify:');

Route::get('/admin/backups', [\App\Http\Controllers\Api\BackupRecoveryController::class,'index']);
Route::get('/admin/backups/records', [\App\Http\Controllers\Api\BackupRecoveryController::class,'records']);
Route::get('/admin/backups/export', [\App\Http\Controllers\Api\BackupRecoveryController::class,'export'])->middleware('throttle:10,1,bcdr-export:');
Route::post('/admin/backups/{type}', [\App\Http\Controllers\Api\BackupRecoveryController::class,'create'])->whereIn('type',['requests','evidence'])->middleware('throttle:20,1,bcdr-create:');
Route::post('/admin/backups/{type}/{id}/review', [\App\Http\Controllers\Api\BackupRecoveryController::class,'review'])->whereIn('type',['requests','evidence'])->whereUuid('id')->middleware('throttle:20,1,bcdr-review:');

// Merchant customer registry: ownership always comes from the authenticated merchant.
Route::get('/merchant/customers', [\App\Http\Controllers\Api\MerchantCustomerController::class,'index']);
Route::get('/merchant/customers/options', [\App\Http\Controllers\Api\MerchantCustomerController::class,'options']);
Route::get('/merchant/customers/export', [\App\Http\Controllers\Api\MerchantCustomerController::class,'export'])->middleware('throttle:10,1,customer-export:');
Route::get('/merchant/customers/source-buyers', [\App\Http\Controllers\Api\MerchantCustomerController::class,'sourceBuyers']);
Route::post('/merchant/customers/import', [\App\Http\Controllers\Api\MerchantCustomerController::class,'import'])->middleware('throttle:5,1,customer-import:');
Route::post('/merchant/customers', [\App\Http\Controllers\Api\MerchantCustomerController::class,'store'])->middleware('throttle:30,1,customer-create:');
Route::get('/merchant/customers/{id}', [\App\Http\Controllers\Api\MerchantCustomerController::class,'show'])->whereUuid('id');
Route::patch('/merchant/customers/{id}', [\App\Http\Controllers\Api\MerchantCustomerController::class,'update'])->whereUuid('id')->middleware('throttle:30,1,customer-update:');
Route::post('/merchant/customers/{id}/documents', [\App\Http\Controllers\Api\MerchantCustomerController::class,'link'])->whereUuid('id')->middleware('throttle:30,1,customer-link:');

// Merchant-owned operational registry; registration never grants BIR authority.
Route::get('/merchant/branches', [\App\Http\Controllers\Api\MerchantBranchController::class, 'index']);
Route::get('/merchant/branches/export', [\App\Http\Controllers\Api\MerchantBranchController::class, 'export'])->middleware('throttle:10,1,branch-export:');
Route::post('/merchant/branches', [\App\Http\Controllers\Api\MerchantBranchController::class, 'store'])->middleware('throttle:20,1,branch-write:');
Route::put('/merchant/branches/{id}', [\App\Http\Controllers\Api\MerchantBranchController::class, 'update'])->whereUuid('id')->middleware('throttle:20,1,branch-write:');
Route::post('/merchant/branches/{id}/documents', [\App\Http\Controllers\Api\MerchantBranchController::class, 'upload'])->whereUuid('id')->middleware('throttle:10,1,branch-upload:');
Route::post('/merchant/branches/{id}/request-review', [\App\Http\Controllers\Api\MerchantBranchController::class, 'requestReview'])->whereUuid('id')->middleware('throttle:5,1,branch-review:');
Route::get('/merchant/branch-documents/{id}', [\App\Http\Controllers\Api\MerchantBranchController::class, 'download'])->whereUuid('id');
Route::post('/merchant/terminals', [\App\Http\Controllers\Api\MerchantBranchController::class, 'terminalStore'])->middleware('throttle:20,1,terminal-write:');
Route::put('/merchant/terminals/{id}', [\App\Http\Controllers\Api\MerchantBranchController::class, 'terminalUpdate'])->whereUuid('id')->middleware('throttle:20,1,terminal-write:');
Route::get('/admin/branch-reviews', [\App\Http\Controllers\Api\MerchantBranchController::class, 'pending']);
Route::get('/admin/branch-reviews/{id}', [\App\Http\Controllers\Api\MerchantBranchController::class, 'reviewDetail'])->whereUuid('id');
Route::get('/admin/branch-documents/{id}', [\App\Http\Controllers\Api\MerchantBranchController::class, 'download'])->whereUuid('id');
Route::post('/admin/branch-reviews/{id}', [\App\Http\Controllers\Api\MerchantBranchController::class, 'review'])->whereUuid('id')->middleware('throttle:10,1,branch-decision:');

// Optional onboarding uploads remain bound to a verified, unconsumed email challenge.
Route::post('/registration/documents', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'stage'])->middleware('throttle:10,1,registration-documents:');
foreach (['registration/document-uploads', 'merchant/verification-uploads'] as $uploads) {
    Route::post('/'.$uploads, [\App\Http\Controllers\Api\VerificationUploadController::class, 'start'])->middleware('throttle:10,1,verification-upload-start:');
    Route::post('/'.$uploads.'/{id}/chunks', [\App\Http\Controllers\Api\VerificationUploadController::class, 'chunk'])->whereUuid('id')->middleware('throttle:600,1,verification-upload-chunk:');
    Route::post('/'.$uploads.'/{id}/complete', [\App\Http\Controllers\Api\VerificationUploadController::class, 'complete'])->whereUuid('id')->middleware('throttle:10,1,verification-upload-complete:');
}
Route::get('/merchant/verification-documents', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'index']);
Route::post('/merchant/verification-documents', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'upload'])->middleware('throttle:10,1,verification-upload:');
Route::get('/merchant/verification-documents/{id}', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'download'])->whereUuid('id');
Route::get('/admin/businesses/{merchant}/verification-documents', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'index'])->whereUuid('merchant');
Route::get('/admin/businesses/{merchant}/review-notifications', [\App\Http\Controllers\Api\BusinessReviewNotificationController::class,'index'])->whereUuid('merchant');
Route::post('/admin/businesses/{merchant}/review-notifications/preview', [\App\Http\Controllers\Api\BusinessReviewNotificationController::class,'preview'])->whereUuid('merchant')->middleware('throttle:30,1,business-review-preview:');
Route::post('/admin/businesses/{merchant}/review-notifications', [\App\Http\Controllers\Api\BusinessReviewNotificationController::class,'send'])->whereUuid('merchant')->middleware('throttle:20,1,business-review-mail:');
Route::get('/admin/businesses/{merchant}/verification-document-history', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'history'])->whereUuid('merchant');
Route::get('/admin/verification-documents/{id}/preview', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'preview'])->whereUuid('id');
Route::get('/admin/verification-documents/{id}', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'download'])->whereUuid('id');
Route::post('/admin/verification-documents/{id}/review', [\App\Http\Controllers\Api\MerchantVerificationDocumentController::class,'review'])->whereUuid('id')->middleware('throttle:20,1,verification-review:');

Route::get('/merchant/wallet', [\App\Http\Controllers\Api\MerchantWalletController::class,'index']);
Route::put('/merchant/wallet/settings', [\App\Http\Controllers\Api\MerchantWalletController::class,'settings'])->middleware('throttle:10,1,wallet-settings:');
Route::post('/merchant/wallet/topups', [\App\Http\Controllers\Api\MerchantWalletController::class,'topup'])->middleware('throttle:5,1,wallet-topup:');
Route::get('/merchant/wallet/statement', [\App\Http\Controllers\Api\MerchantWalletController::class,'statement'])->middleware('throttle:5,1,wallet-export:');
Route::get('/admin/wallets', [\App\Http\Controllers\Api\MerchantWalletController::class,'adminIndex']);
Route::get('/admin/wallets/{merchant}/statement', [\App\Http\Controllers\Api\AdminBusinessWalletController::class,'statement'])->middleware('throttle:10,1,admin-wallet-export:');
Route::get('/admin/wallets/{merchant}', [\App\Http\Controllers\Api\AdminBusinessWalletController::class,'show']);
Route::post('/admin/wallets/{merchant}/adjustments', [\App\Http\Controllers\Api\AdminBusinessWalletController::class,'adjust'])->middleware('throttle:20,1,admin-wallet-adjustment:');
Route::put('/admin/wallets/pricing', [\App\Http\Controllers\Api\MerchantWalletController::class,'pricing'])->middleware('throttle:5,1,wallet-pricing:');
Route::post('/admin/wallets/topups/{id}/review', [\App\Http\Controllers\Api\MerchantWalletController::class,'review'])->whereUuid('id')->middleware('throttle:10,1,wallet-review:');
Route::get('/admin/wallets/{merchant}/audit', [\App\Http\Controllers\Api\MerchantWalletController::class,'audit'])->middleware('throttle:2,1,wallet-audit:');

// Merchant work access: ownership-controlled grants, separate from platform and taxpayer roles.
Route::get('/merchant/access', [\App\Http\Controllers\Api\MerchantTeamController::class,'access']);
Route::get('/merchant/team', [\App\Http\Controllers\Api\MerchantTeamController::class,'index']);
Route::get('/merchant/team/export', [\App\Http\Controllers\Api\MerchantTeamController::class,'export'])->middleware('throttle:5,1,team-export:');
Route::post('/merchant/team/invitations', [\App\Http\Controllers\Api\MerchantTeamController::class,'invite'])->middleware('throttle:10,1,team-invite:');
Route::patch('/merchant/team/members/{id}', [\App\Http\Controllers\Api\MerchantTeamController::class,'update'])->whereUuid('id');
Route::post('/merchant/team/roles', [\App\Http\Controllers\Api\MerchantTeamController::class,'role']);
Route::put('/merchant/team/roles/{id}', [\App\Http\Controllers\Api\MerchantTeamController::class,'role'])->whereUuid('id');
Route::post('/team-invitations/preview', [\App\Http\Controllers\Api\MerchantTeamController::class,'preview'])->middleware('throttle:20,1,team-preview:');
Route::post('/team-invitations/accept', [\App\Http\Controllers\Api\MerchantTeamController::class,'accept'])->middleware('throttle:5,1,team-accept:');

// Self-service administrator profile; identity and role come exclusively from the authenticated session.
Route::get('/admin/account-profile', [\App\Http\Controllers\Api\AdminAccountProfileController::class,'show']);
Route::put('/admin/account-profile', [\App\Http\Controllers\Api\AdminAccountProfileController::class,'update'])->middleware('throttle:20,1');
Route::post('/admin/account-profile/avatar', [\App\Http\Controllers\Api\AdminAccountProfileController::class,'uploadAvatar'])->middleware('throttle:5,1');
Route::get('/admin/account-profile/avatar', [\App\Http\Controllers\Api\AdminAccountProfileController::class,'avatar']);
Route::put('/admin/account-profile/notifications', [\App\Http\Controllers\Api\AdminAccountProfileController::class,'notifications'])->middleware('throttle:20,1');

// Owner-scoped business profile and independent onboarding review requests.
Route::get('/merchant/business-profile', [\App\Http\Controllers\Api\MerchantBusinessProfileController::class, 'show']);
Route::put('/merchant/business-profile', [\App\Http\Controllers\Api\MerchantBusinessProfileController::class, 'update'])->middleware('throttle:20,1');
Route::post('/merchant/business-profile/review', [\App\Http\Controllers\Api\MerchantBusinessProfileController::class, 'submit'])->middleware('throttle:5,1');

Route::get('/admin/merchant-connections', [\App\Http\Controllers\Api\AdminMerchantConnectionController::class, 'index']);
Route::get('/admin/integration-source-profiles', [\App\Http\Controllers\Api\IntegrationSourceProfileController::class, 'index']);
Route::post('/admin/integration-source-profiles', [\App\Http\Controllers\Api\IntegrationSourceProfileController::class, 'store'])->middleware('throttle:20,1,source-profile-review:');
Route::delete('/admin/integration-source-profiles/{id}', [\App\Http\Controllers\Api\IntegrationSourceProfileController::class, 'destroy']);

// Owner-scoped presentation preferences and raster business identity.
Route::get('/merchant/invoice-settings', [\App\Http\Controllers\Api\MerchantInvoiceSettingsController::class,'show']);
Route::put('/merchant/invoice-settings', [\App\Http\Controllers\Api\MerchantInvoiceSettingsController::class,'update'])->middleware('throttle:30,1,invoice-settings:');
Route::post('/merchant/invoice-settings/preview', [\App\Http\Controllers\Api\MerchantInvoiceSettingsController::class,'preview'])->middleware('throttle:60,1,invoice-preview:');
Route::post('/merchant/invoice-settings/preview/pdf', [\App\Http\Controllers\Api\MerchantInvoiceSettingsController::class,'pdf'])->middleware('throttle:15,1,invoice-preview-pdf:');
Route::post('/merchant/business-profile/image', [\App\Http\Controllers\Api\MerchantBrandController::class,'update'])->middleware('throttle:20,1,business-image:');
Route::delete('/merchant/business-profile/image', [\App\Http\Controllers\Api\MerchantBrandController::class,'update'])->middleware('throttle:20,1,business-image:');

Route::post('/merchant/invoice-settings/logo', [\App\Http\Controllers\Api\MerchantBrandController::class,'invoiceLogo'])->middleware('throttle:20,1,invoice-logo:');
Route::delete('/merchant/invoice-settings/logo', [\App\Http\Controllers\Api\MerchantBrandController::class,'invoiceLogo'])->middleware('throttle:20,1,invoice-logo:');

// Isolated API test records; no production financial or document mutation.
Route::get('/merchant/sandbox-records',[\App\Http\Controllers\Api\SandboxRecordsController::class,'index']);
Route::get('/merchant/sandbox-records/{id}',[\App\Http\Controllers\Api\SandboxRecordsController::class,'show'])->whereUuid('id');
Route::get('/admin/sandbox-records/export',[\App\Http\Controllers\Api\SandboxRecordsController::class,'export']);
Route::get('/admin/sandbox-records',[\App\Http\Controllers\Api\SandboxRecordsController::class,'index']);
Route::get('/admin/sandbox-records/{id}',[\App\Http\Controllers\Api\SandboxRecordsController::class,'show'])->whereUuid('id');

// Merchant-owned callbacks: independent of the legacy platform/BIR webhook queue.
Route::get('/merchant/webhooks',[\App\Http\Controllers\Api\MerchantWebhookController::class,'index']);
Route::get('/merchant/webhooks/deliveries',[\App\Http\Controllers\Api\MerchantWebhookController::class,'logs']);
Route::get('/merchant/webhooks/deliveries/{id}',[\App\Http\Controllers\Api\MerchantWebhookController::class,'detail'])->whereUuid('id');
Route::post('/merchant/webhooks',[\App\Http\Controllers\Api\MerchantWebhookController::class,'store'])->middleware('throttle:10,1,merchant-hook-create:');
Route::patch('/merchant/webhooks/{id}',[\App\Http\Controllers\Api\MerchantWebhookController::class,'update'])->whereUuid('id')->middleware('throttle:20,1,merchant-hook-edit:');
Route::delete('/merchant/webhooks/{id}',[\App\Http\Controllers\Api\MerchantWebhookController::class,'destroy'])->whereUuid('id')->middleware('throttle:10,1,merchant-hook-revoke:');
Route::post('/merchant/webhooks/{id}/rotate',[\App\Http\Controllers\Api\MerchantWebhookController::class,'rotate'])->whereUuid('id')->middleware('throttle:5,1,merchant-hook-rotate:');
Route::post('/merchant/webhooks/{id}/test',[\App\Http\Controllers\Api\MerchantWebhookController::class,'test'])->whereUuid('id')->middleware('throttle:5,1,merchant-hook-test:');
Route::post('/merchant/webhooks/deliveries/{id}/retry',[\App\Http\Controllers\Api\MerchantWebhookController::class,'retry'])->whereUuid('id')->middleware('throttle:10,1,merchant-hook-retry:');

// Proposed merchant identity changes: owner submission, independent administrator decision.
Route::get('/merchant/profile-changes', [\App\Http\Controllers\Api\BusinessProfileChangeController::class,'index']);
Route::post('/merchant/profile-changes', [\App\Http\Controllers\Api\BusinessProfileChangeController::class,'store'])->middleware('throttle:20,1');
Route::post('/merchant/profile-changes/{id}/withdraw', [\App\Http\Controllers\Api\BusinessProfileChangeController::class,'withdraw'])->middleware('throttle:20,1');
Route::get('/admin/profile-changes', [\App\Http\Controllers\Api\BusinessProfileChangeController::class,'adminIndex']);
Route::post('/admin/profile-changes/{id}/review', [\App\Http\Controllers\Api\BusinessProfileChangeController::class,'review'])->middleware('throttle:20,1');

Route::get('/auth/mfa/devices', [\App\Http\Controllers\Api\MfaDeviceController::class,'index']);
Route::post('/auth/mfa/devices', [\App\Http\Controllers\Api\MfaDeviceController::class,'start'])->middleware('throttle:5,1');
Route::post('/auth/mfa/devices/{id}/confirm', [\App\Http\Controllers\Api\MfaDeviceController::class,'confirm'])->middleware('throttle:5,1');
Route::post('/auth/mfa/devices/{id}/cancel', [\App\Http\Controllers\Api\MfaDeviceController::class,'cancel'])->middleware('throttle:5,1');
Route::delete('/auth/mfa/devices/{id}', [\App\Http\Controllers\Api\MfaDeviceController::class,'revoke'])->middleware('throttle:5,1');

require __DIR__.'/infrastructure.php';
