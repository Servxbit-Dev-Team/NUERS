import { lazy } from "react";
import { PageBoundary } from "@/components/page-boundary";

import { SupportChatWidget } from "@/components/support/chat";

import { Routes, Route, Navigate } from "react-router-dom";

import { DashboardLayout } from "@/components/layouts/dashboard-layout";
import { useAuth } from "@/lib/auth";

// Electronic Invoicing Engine (Module 1)
// New Modules

const ServerManagement = lazy(() => import("@/pages/infrastructure/server-management"));
const MerchantAccountSecurity = lazy(() => import("@/pages/merchant/security").then(module => ({ default: module.MerchantAccountSecurity })));
const AdminAccountProfile = lazy(() => import("@/pages/admin/account-profile").then(module => ({ default: module.AdminAccountProfile })));
const MerchantUsersPage = lazy(() => import("@/pages/merchant/users").then(module => ({ default: module.MerchantUsersPage })));
const AcceptTeamInvitation = lazy(() => import("@/pages/merchant/users").then(module => ({ default: module.AcceptTeamInvitation })));
const MerchantWalletPage = lazy(() => import("@/pages/merchant/wallet").then(module => ({ default: module.MerchantWalletPage })));
const AdminWalletConsole = lazy(() => import("@/pages/merchant/wallet").then(module => ({ default: module.AdminWalletConsole })));
const BackupRecoveryDashboard = lazy(() => import("@/pages/bcdr/dashboard").then(module => ({ default: module.BackupRecoveryDashboard })));
const AccountSecurityPage = lazy(() => import("@/pages/auth/account-security").then(module => ({ default: module.AccountSecurityPage })));
const AccountSecuritySettings = lazy(() => import("@/pages/auth/account-security").then(module => ({ default: module.AccountSecuritySettings })));
const EmailVerificationPage = lazy(() => import("@/pages/auth/account-security").then(module => ({ default: module.EmailVerificationPage })));
const PasswordRecoveryPage = lazy(() => import("@/pages/auth/account-security").then(module => ({ default: module.PasswordRecoveryPage })));
const OperationsReadiness = lazy(() => import("@/pages/operations/readiness").then(module => ({ default: module.OperationsReadiness })));
const SystemConfiguration = lazy(() => import("@/pages/system/configuration").then(module => ({ default: module.SystemConfiguration })));
const MfaPage = lazy(() => import("@/pages/auth/mfa").then(module => ({ default: module.MfaPage })));
const IntegrationWorkspace = lazy(() => import("@/pages/integrations").then(module => ({ default: module.IntegrationWorkspace })));
const PermitManagement = lazy(() => import("@/pages/authorizations").then(module => ({ default: module.PermitManagement })));
const RdoMappings = lazy(() => import("@/pages/business-registry/rdo-mappings").then(module => ({ default: module.RdoMappings })));
const BusinessRegistry = lazy(() => import("@/pages/business-registry").then(module => ({ default: module.BusinessRegistry })));
const BusinessActivation = lazy(() => import("@/pages/business-registry/activation").then(module => ({ default: module.BusinessActivation })));
const TransactionsCenter = lazy(() => import("@/pages/operations/routes").then(module => ({ default: module.TransactionsCenter })));
const ReconciliationCenter = lazy(() => import("@/pages/operations/routes").then(module => ({ default: module.ReconciliationCenter })));
const SalesSummaryCenter = lazy(() => import("@/pages/operations/routes").then(module => ({ default: module.SalesSummaryCenter })));
const LegalPolicyPage = lazy(() => import("@/pages/legal/policy").then(module => ({ default: module.LegalPolicyPage })));
const AdminSupportInbox = lazy(() => import("@/pages/admin/support-inbox").then(module => ({ default: module.AdminSupportInbox })));
const LandingPage = lazy(() => import("@/pages/landing").then(module => ({ default: module.LandingPage })));
const LoginPage = lazy(() => import("@/pages/auth/login").then(module => ({ default: module.LoginPage })));
const RegisterPage = lazy(() => import("@/pages/auth/register").then(module => ({ default: module.RegisterPage })));
const MerchantCustomers = lazy(() => import("@/pages/merchant/customers").then(module => ({ default: module.MerchantCustomers })));
const MerchantDashboard = lazy(() => import("@/pages/merchant/dashboard").then(module => ({ default: module.MerchantDashboard })));
const MerchantReceipts = lazy(() => import("@/pages/merchant/receipts").then(module => ({ default: module.MerchantReceipts })));
const MerchantSalesReports = lazy(() => import("@/pages/merchant/sales-reports").then(module => ({ default: module.MerchantSalesReports })));
const MerchantBilling = lazy(() => import("@/pages/merchant/billing").then(module => ({ default: module.MerchantBilling })));
const MerchantPosSettings = lazy(() => import("@/pages/merchant/pos-settings").then(module => ({ default: module.MerchantPosSettings })));
const MerchantApiKeys = lazy(() => import("@/pages/merchant/api-keys").then(module => ({ default: module.MerchantApiKeys })));
const MerchantPtiApplications = lazy(() => import("@/pages/authorizations/applications").then(module => ({ default: module.PtiApplications })));
const MerchantProfile = lazy(() => import("@/pages/merchant/profile").then(module => ({ default: module.MerchantProfile })));
const BranchReviewQueue = lazy(() => import("@/pages/merchant/branches").then(module => ({ default: module.BranchReviewQueue })));
const MerchantExpenses = lazy(() => import("@/pages/merchant/expenses").then(module => ({ default: module.MerchantExpenses })));
const MerchantInvoices = lazy(() => import("@/pages/merchant/invoices").then(module => ({ default: module.MerchantInvoices })));
const MerchantLegacyTransactions = lazy(() => import("@/pages/merchant/invoices").then(module => ({ default: module.MerchantLegacyTransactions })));
const InvoiceCreate = lazy(() => import("@/pages/merchant/invoice-create").then(module => ({ default: module.InvoiceCreate })));
const InvoiceDetail = lazy(() => import("@/pages/merchant/invoice-detail").then(module => ({ default: module.InvoiceDetail })));
const AdminDashboard = lazy(() => import("@/pages/admin/dashboard").then(module => ({ default: module.AdminDashboard })));
const SuperAdminDashboard = lazy(() => import("@/pages/super-admin/dashboard").then(module => ({ default: module.SuperAdminDashboard })));
const BirDashboard = lazy(() => import("@/pages/bir/dashboard").then(module => ({ default: module.BirDashboard })));
const BirRdoManagement = lazy(() => import("@/pages/bir/rdo-management").then(module => ({ default: module.BirRdoManagement })));
const CitizenApprovalPage = lazy(() => import("@/pages/citizen/approval").then(module => ({ default: module.CitizenApprovalPage })));
const RdoPortal = lazy(() => import("@/pages/rdo/portal").then(module => ({ default: module.RdoPortal })));
const AdminRiskDetection = lazy(() => import("@/pages/admin/fraud").then(module => ({ default: module.AdminRiskDetection })));
const AdminTaxFilings = lazy(() => import("@/pages/admin/tax-filings").then(module => ({ default: module.AdminTaxFilings })));
const AdminCompliance = lazy(() => import("@/pages/admin/compliance").then(module => ({ default: module.AdminCompliance })));
const AdminAudit = lazy(() => import("@/pages/admin/audit").then(module => ({ default: module.AdminAudit })));
const AdminUsers = lazy(() => import("@/pages/admin/users").then(module => ({ default: module.AdminUsers })));
const AdminClientAccounts = lazy(() => import("@/pages/admin/client-accounts").then(module => ({ default: module.AdminClientAccounts })));
const AdminNotifications = lazy(() => import("@/pages/admin/notifications").then(module => ({ default: module.AdminNotifications })));
const AdminReports = lazy(() => import("@/pages/admin/reports").then(module => ({ default: module.AdminReports })));
const ConsumerPortal = lazy(() => import("@/pages/consumer/portal").then(module => ({ default: module.ConsumerPortal })));
const ClientBarcodePage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientBarcodePage })));
const ClientDashboard = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientDashboard })));
const ClientNotificationsPage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientNotificationsPage })));
const ClientProfilePage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientProfilePage })));
const ClientReceiptsPage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientReceiptsPage })));
const ClientSecurityPage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientSecurityPage })));
const ClientTransactionsPage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientTransactionsPage })));
const ClientWalletPage = lazy(() => import("@/pages/client/dashboard").then(module => ({ default: module.ClientWalletPage })));
const ReceiptVerificationPortal = lazy(() => import("@/pages/verification/portal").then(module => ({ default: module.ReceiptVerificationPortal })));
const PlatformArchitecture = lazy(() => import("@/pages/platform/architecture").then(module => ({ default: module.PlatformArchitecture })));
const ApiPortal = lazy(() => import("@/pages/api/portal").then(module => ({ default: module.ApiPortal })));
const ApiKeys = lazy(() => import("@/pages/api/keys").then(module => ({ default: module.ApiKeys })));
const ApiWebhooks = lazy(() => import("@/pages/api/webhooks").then(module => ({ default: module.ApiWebhooks })));
const ApiSdks = lazy(() => import("@/pages/api/sdks").then(module => ({ default: module.ApiSdks })));
const ApiGuides = lazy(() => import("@/pages/api/guides").then(module => ({ default: module.ApiGuides })));
const ApiSandbox = lazy(() => import("@/pages/developers/sandbox").then(module => ({ default: module.DeveloperSandbox })));
const IntegrationOverview = lazy(() => import("@/pages/developers/overview").then(module => ({ default: module.IntegrationOverview })));
const ApiDocs = lazy(() => import("@/pages/developers/docs").then(module => ({ default: module.DeveloperDocs })));
const ApiMonitoring = lazy(() => import("@/pages/api/monitoring").then(module => ({ default: module.ApiMonitoring })));
const EisGateway = lazy(() => import("@/pages/api/eis-gateway").then(module => ({ default: module.EisGateway })));
const EisTransmissions = lazy(() => import("@/pages/api/eis-transmissions").then(module => ({ default: module.EisTransmissions })));
const EisPerformance = lazy(() => import("@/pages/api/eis-performance").then(module => ({ default: module.EisPerformance })));
const EisErrors = lazy(() => import("@/pages/api/eis-errors").then(module => ({ default: module.EisErrors })));
const TaxpayerDashboard = lazy(() => import("@/pages/taxpayer/dashboard").then(module => ({ default: module.TaxpayerDashboard })));
const TaxpayerRegistry = lazy(() => import("@/pages/taxpayer/registry").then(module => ({ default: module.TaxpayerRegistry })));
const BusinessRegistration = lazy(() => import("@/pages/taxpayer/businesses").then(module => ({ default: module.BusinessRegistration })));
const VerificationCenter = lazy(() => import("@/pages/taxpayer/verification").then(module => ({ default: module.VerificationCenter })));
const TaxpayerPortal = lazy(() => import("@/pages/taxpayer/portal").then(module => ({ default: module.TaxpayerPortal })));
const UserManagement = lazy(() => import("@/pages/taxpayer/users").then(module => ({ default: module.UserManagement })));
const EisHub = lazy(() => import("@/pages/eis/hub").then(module => ({ default: module.EisHub })));
const InvoiceManagementCenter = lazy(() => import("@/pages/invoices/center").then(module => ({ default: module.InvoiceManagementCenter })));
const TransmissionMonitoringCenter = lazy(() => import("@/pages/monitoring/transmissions").then(module => ({ default: module.TransmissionMonitoringCenter })));
const ComplianceCenter = lazy(() => import("@/pages/compliance/center").then(module => ({ default: module.ComplianceCenter })));
const AuditLogManagement = lazy(() => import("@/pages/audit/log").then(module => ({ default: module.AuditLogManagement })));
const NotificationCenter = lazy(() => import("@/pages/notifications/center").then(module => ({ default: module.NotificationCenter })));
const CertificateManagement = lazy(() => import("@/pages/certificates/management").then(module => ({ default: module.CertificateManagement })));
const ESPAccreditation = lazy(() => import("@/pages/accreditation/esp").then(module => ({ default: module.ESPAccreditation })));
const ExecutiveAnalytics = lazy(() => import("@/pages/executive/analytics").then(module => ({ default: module.ExecutiveAnalytics })));
const AIAssistant = lazy(() => import("@/pages/ai/assistant").then(module => ({ default: module.AIAssistant })));
const BirTaxIntelligenceDashboard = lazy(() => import("@/pages/tax-intelligence/center").then(module => ({ default: module.BirTaxIntelligenceDashboard })));
const SuperAdminTaxOperationsCenter = lazy(() => import("@/pages/tax-intelligence/center").then(module => ({ default: module.SuperAdminTaxOperationsCenter })));

function roleDefaultPath(role: string | null | undefined): string {
  if (role === "admin" || role === "super_admin") return "/super-admin";
  if (role === "bir") return "/bir";
  if (role === "rdo") return "/rdo";
  if (role === "merchant") return "/merchant";
  if (role === "consumer" || role === "client") return "/client";
  return "/super-admin";
}

function roleSidebarPortal(role: string | null | undefined): React.ComponentProps<typeof DashboardLayout>["portal"] {
  if (role === "super_admin") return "super-admin";
  if (role === "admin") return "admin";
  if (role === "bir") return "bir";
  if (role === "rdo") return "rdo";
  if (role === "merchant") return "merchant";
  if (role === "consumer" || role === "client") return "client";
  return "super-admin";
}

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { user, session, loading, sessionError, sessionInvalid } = useAuth();
  if (loading) return <div role="status" className="ne-session-loading">{sessionError||"Checking your session…"}</div>;
  if (!user) return <Navigate to={sessionInvalid?"/login?session=invalid":"/login"} replace />;
  if (session?.mfa_required) return <Navigate to={`/security/mfa?returnTo=${encodeURIComponent(window.location.pathname+window.location.search+window.location.hash)}`} replace />;
  return <>{children}</>;
}

function RequireRole({ role, children }: { role: string | string[]; children: React.ReactNode }) {
  const { user, profile, session, loading, sessionError, sessionInvalid } = useAuth();
  if (loading) return <div role="status" className="ne-session-loading">{sessionError||"Checking your session…"}</div>;
  if (!user) return <Navigate to={sessionInvalid?"/login?session=invalid":"/login"} replace />;
  const allowedRoles = Array.isArray(role) ? role : [role];
  if (!profile) return <div role="status" className="ne-session-loading">{sessionError||"Verifying account permissions…"}</div>;
  if (!allowedRoles.includes(profile.role)) return <Navigate to={roleDefaultPath(profile.role)} replace />;
  if (session?.mfa_required) return <Navigate to={`/security/mfa?returnTo=${encodeURIComponent(window.location.pathname+window.location.search+window.location.hash)}`} replace />;
  return <>{children}</>;
}

function GuestOnly({ children }: { children: React.ReactNode }) {
  const { user, profile, loading, sessionError } = useAuth();
  if (loading) return <div role="status" className="ne-session-loading">{sessionError||"Checking your session…"}</div>;
  if (user) return <Navigate to={roleDefaultPath(profile?.role)} replace />;
  return <>{children}</>;
}

function RoleScopedDashboardLayout() {
  const { profile, loading } = useAuth();
  if (loading) return null;
  return <DashboardLayout portal={roleSidebarPortal(profile?.role)} />;
}

export default function App() {
  return (
    <>
    <PageBoundary>
    <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route path="/developers/integration" element={<IntegrationOverview />} />
      <Route path="/api/docs" element={<ApiDocs />} />
      <Route path="/api/sandbox" element={<ApiSandbox />} />
      <Route path="/verify" element={<ReceiptVerificationPortal />} />
      <Route path="/activate-business" element={<BusinessActivation />} />
      <Route path="/verify-email" element={<EmailVerificationPage />} />
      <Route path="/forgot-password" element={<PasswordRecoveryPage />} />
      <Route path="/reset-password" element={<PasswordRecoveryPage />} />
      <Route path="/security/account" element={<RequireAuth><AccountSecurityPage /></RequireAuth>} />
      <Route path="/security/mfa" element={<MfaPage />} />
      <Route path="/accept-team-invite" element={<AcceptTeamInvitation />} />
      <Route path="/login" element={<GuestOnly><LoginPage /></GuestOnly>} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/terms-of-service" element={<LegalPolicyPage kind="terms" />} />
      <Route path="/privacy-notice" element={<LegalPolicyPage kind="privacy" />} />
      <Route path="/terms" element={<Navigate to="/terms-of-service" replace />} />
      <Route path="/privacy" element={<Navigate to="/privacy-notice" replace />} />

      {/* Super Admin Portal */}
      <Route path="/super-admin" element={<RequireRole role={["admin", "super_admin"]}><DashboardLayout portal="super-admin" /></RequireRole>}>
        <Route index element={<SuperAdminDashboard />} />
        <Route path="profile" element={<AdminAccountProfile />} />
        <Route path="transactions" element={<TransactionsCenter />} />
        <Route path="revenue-analytics" element={<ExecutiveAnalytics />} />
        <Route path="blueprint" element={<PlatformArchitecture />} />
        <Route path="merchants" element={<BusinessRegistry />} />
        <Route path="bir-accounts" element={<AdminUsers title="BIR Accounts" description="Manage BIR regulator and RDO officer profiles backed by the live NUERS database." allowedRoles={["bir", "rdo"]} />} />
        <Route path="client-accounts" element={<AdminClientAccounts />} />
        <Route path="citizen-approval" element={<CitizenApprovalPage scope="super-admin" />} />
        <Route path="taxpayer-onboarding" element={<BusinessRegistry onboarding />} />
        <Route path="rdo-mappings" element={<RdoMappings />} />
        <Route path="users-rbac" element={<AdminUsers />} />
        <Route path="subscriptions" element={<AdminWalletConsole />} />
        <Route path="wallets" element={<AdminWalletConsole />} />
        <Route path="branch-reviews" element={<BranchReviewQueue />} />
        <Route path="support" element={<AdminSupportInbox />} />
        <Route path="compliance" element={<AdminCompliance />} />
        <Route path="fraud" element={<AdminRiskDetection />} />
        <Route path="tax-filings" element={<SalesSummaryCenter />} />
        <Route path="reports" element={<AdminReports />} />
        <Route path="ai-recommendations" element={<AIAssistant />} />
        <Route path="tax-operations" element={<SuperAdminTaxOperationsCenter />} />
        <Route path="tax-intelligence" element={<SuperAdminTaxOperationsCenter />} />
        <Route path="tax-rules" element={<SuperAdminTaxOperationsCenter />} />
        <Route path="fraud-models" element={<SuperAdminTaxOperationsCenter />} />
        <Route path="reconciliation" element={<ReconciliationCenter />} />
        <Route path="tax-analytics" element={<SuperAdminTaxOperationsCenter />} />
        <Route path="graph" element={<SuperAdminTaxOperationsCenter />} />
        <Route path="security" element={<OperationsReadiness />} />
        <Route path="soc-operations" element={<OperationsReadiness />} />
        <Route path="audit" element={<AuditLogManagement />} />
        <Route path="certificates" element={<CertificateManagement />} />
        <Route path="mfa-device-trust" element={<OperationsReadiness />} />
        <Route path="pti-ptt" element={<PermitManagement />} />
        <Route path="api-platform" element={<IntegrationWorkspace />} />
        <Route path="eis-readiness" element={<EisHub />} />
        <Route path="eis-transmissions" element={<EisTransmissions />} />
        <Route path="eis-gateway" element={<EisGateway />} />
        <Route path="api-keys" element={<ApiKeys />} />
        <Route path="webhooks" element={<ApiWebhooks />} />
        <Route path="payment-gateways" element={<ApiMonitoring />} />
        <Route path="system-health" element={<OperationsReadiness />} />
        <Route path="server-management" element={<RequireRole role="super_admin"><ServerManagement /></RequireRole>} />
        <Route path="backups" element={<BackupRecoveryDashboard />} />
        <Route path="data-archive" element={<OperationsReadiness />} />
        <Route path="notifications" element={<NotificationCenter />} />
      </Route>

      {/* BIR / Regulator Portal */}
      <Route path="/bir" element={<RequireRole role={["admin", "bir", "super_admin"]}><DashboardLayout portal="bir" /></RequireRole>}>
        <Route index element={<BirDashboard />} />
        <Route path="rdo-registration" element={<BirRdoManagement />} />
        <Route path="citizen-approval" element={<CitizenApprovalPage scope="bir" />} />
        <Route path="transactions" element={<TransactionsCenter />} />
        <Route path="business-accounts" element={<BusinessRegistry />} />
        <Route path="compliance" element={<AdminCompliance />} />
        <Route path="reports" element={<AdminReports />} />
        <Route path="tax-filings" element={<AdminTaxFilings />} />
        <Route path="forecasts" element={<ExecutiveAnalytics />} />
        <Route path="tax-intelligence" element={<BirTaxIntelligenceDashboard />} />
        <Route path="vat-reconciliation" element={<BirTaxIntelligenceDashboard />} />
        <Route path="invoice-matching" element={<BirTaxIntelligenceDashboard />} />
        <Route path="network" element={<BirTaxIntelligenceDashboard />} />
        <Route path="risk-scoring" element={<BirTaxIntelligenceDashboard />} />
        <Route path="ai-audit" element={<BirTaxIntelligenceDashboard />} />
        <Route path="eis-readiness" element={<EisHub />} />
        <Route path="eis-transmissions" element={<EisTransmissions />} />
      </Route>

      {/* RDO Portal */}
      <Route path="/rdo" element={<RequireRole role={["rdo", "bir", "admin", "super_admin"]}><DashboardLayout portal="rdo" /></RequireRole>}>
        <Route index element={<RdoPortal />} />
      </Route>

      {/* Business Account Portal */}
      <Route path="/merchant" element={<RequireRole role={["merchant", "admin", "super_admin"]}><DashboardLayout portal="merchant" /></RequireRole>}>
        <Route index element={<MerchantDashboard />} />
        <Route path="transactions" element={<MerchantLegacyTransactions />} />
        <Route path="receipts" element={<MerchantReceipts />} />
        <Route path="sales-reports" element={<MerchantSalesReports />} />
        <Route path="vat-reports" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="wallet" element={<MerchantWalletPage />} />
        <Route path="users" element={<MerchantUsersPage />} />
        <Route path="security" element={<MerchantAccountSecurity />} />
        <Route path="billing" element={<MerchantBilling />} />
        <Route path="pos-settings" element={<MerchantPosSettings />} />
        <Route path="api-keys" element={<MerchantApiKeys />} />
        <Route path="profile" element={<MerchantProfile />} />
        <Route path="branches" element={<Navigate to="/merchant/api-keys" replace />} />
        <Route path="pti-applications" element={<MerchantPtiApplications merchant />} />
        <Route path="customers" element={<MerchantCustomers />} />
        <Route path="notifications" element={<AccountSecuritySettings />} />
        <Route path="expenses" element={<MerchantExpenses />} />
        <Route path="invoices" element={<MerchantInvoices />} />
        <Route path="b2b-tax" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="output-vat" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="input-vat" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="invoice-matching" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="withholding-tax" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="suppliers" element={<Navigate to="/merchant/sales-reports" replace />} />
        <Route path="invoices/create" element={<InvoiceCreate />} />
        <Route path="invoices/recurring" element={<Navigate to="/merchant/invoices" replace />} />
        <Route path="invoices/bulk" element={<Navigate to="/merchant/invoices" replace />} />
        <Route path="invoices/:id" element={<InvoiceDetail />} />
      </Route>

      {/* Admin / BIR Console */}
      <Route path="/admin" element={<RequireRole role={["admin", "super_admin"]}><DashboardLayout portal="admin" /></RequireRole>}>
        <Route index element={<AdminDashboard />} />
        <Route path="transactions" element={<TransactionsCenter />} />
        <Route path="fraud" element={<AdminRiskDetection />} />
        <Route path="merchants" element={<BusinessRegistry />} />
        <Route path="tax-filings" element={<AdminTaxFilings />} />
        <Route path="compliance" element={<AdminCompliance />} />
        <Route path="audit" element={<AdminAudit />} />
        <Route path="users" element={<AdminUsers />} />
        <Route path="notifications" element={<AdminNotifications />} />
        <Route path="reports" element={<AdminReports />} />
      </Route>

      {/* Consumer Portal */}
      <Route path="/consumer" element={<RequireAuth><DashboardLayout portal="consumer" /></RequireAuth>}>
        <Route index element={<ConsumerPortal />} />
      </Route>
      <Route path="/client" element={<RequireRole role={["client", "consumer", "admin", "super_admin"]}><DashboardLayout portal="client" /></RequireRole>}>
        <Route index element={<ClientDashboard />} />
        <Route path="transactions" element={<ClientTransactionsPage />} />
        <Route path="receipts" element={<ClientReceiptsPage />} />
        <Route path="barcode" element={<ClientBarcodePage />} />
        <Route path="wallet" element={<ClientWalletPage />} />
        <Route path="notifications" element={<ClientNotificationsPage />} />
        <Route path="security" element={<ClientSecurityPage />} />
        <Route path="profile" element={<ClientProfilePage />} />
      </Route>

      {/* API Platform */}
      <Route path="/api" element={<RequireAuth><DashboardLayout portal="api" /></RequireAuth>}>
        <Route index element={<ApiPortal />} />
        <Route path="keys" element={<ApiKeys />} />
        <Route path="webhooks" element={<ApiWebhooks />} />
        <Route path="sdks" element={<ApiSdks />} />
        <Route path="guides" element={<ApiGuides />} />
        <Route path="monitoring" element={<ApiMonitoring />} />
        <Route path="eis" element={<EisGateway />} />
        <Route path="eis/transmissions" element={<EisTransmissions />} />
        <Route path="eis/performance" element={<EisPerformance />} />
        <Route path="eis/errors" element={<EisErrors />} />
      </Route>

      {/* Taxpayer Management */}
      <Route path="/taxpayer" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="taxpayer" /></RequireRole>}>
        <Route index element={<TaxpayerDashboard />} />
        <Route path="registry" element={<TaxpayerRegistry />} />
        <Route path="businesses" element={<BusinessRegistration />} />
        <Route path="verification" element={<VerificationCenter />} />
        <Route path="portal" element={<TaxpayerPortal />} />
        <Route path="users" element={<UserManagement />} />
      </Route>

      {/* Electronic Invoicing Engine (Module 1) */}
      <Route path="/engine" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="engine" /></RequireRole>}>
        <Route index element={<OperationsReadiness />} />
      </Route>

      {/* EIS Integration Hub */}
      <Route path="/eis" element={<RequireAuth><DashboardLayout portal="eis" /></RequireAuth>}>
        <Route index element={<EisHub />} />
        <Route path="transmissions" element={<EisTransmissions />} />
      </Route>

      {/* Invoice Management Center */}
      <Route path="/invoices" element={<RequireAuth><DashboardLayout portal="invoices" /></RequireAuth>}>
        <Route index element={<InvoiceManagementCenter />} />
      </Route>

      {/* Transmission Monitoring */}
      <Route path="/monitoring" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="monitoring" /></RequireRole>}>
        <Route index element={<TransmissionMonitoringCenter />} />
      </Route>

      {/* Compliance Center */}
      <Route path="/compliance" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="compliance" /></RequireRole>}>
        <Route index element={<ComplianceCenter />} />
      </Route>

      {/* Audit Log */}
      <Route path="/audit" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="audit" /></RequireRole>}>
        <Route index element={<AuditLogManagement />} />
      </Route>

      {/* Notifications */}
      <Route path="/notifications" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="notifications" /></RequireRole>}>
        <Route index element={<NotificationCenter />} />
      </Route>

      {/* Certificates */}
      <Route path="/certificates" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="certificates" /></RequireRole>}>
        <Route index element={<CertificateManagement />} />
      </Route>

      {/* Data Archive */}
      <Route path="/archive" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="archive" /></RequireRole>}>
        <Route index element={<OperationsReadiness />} />
      </Route>

      {/* BCDR */}
      <Route path="/bcdr" element={<RequireRole role={["admin", "super_admin"]}><Navigate to="/super-admin/backups" replace /></RequireRole>} />

      {/* SOC */}
      <Route path="/soc" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="soc" /></RequireRole>}>
        <Route index element={<OperationsReadiness />} />
        <Route path="operations" element={<OperationsReadiness />} />
      </Route>

      {/* ESP Accreditation */}
      <Route path="/accreditation" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="accreditation" /></RequireRole>}>
        <Route index element={<ESPAccreditation />} />
      </Route>

      {/* Executive Analytics */}
      <Route path="/executive" element={<RequireRole role={["admin", "super_admin", "bir"]}><RoleScopedDashboardLayout /></RequireRole>}>
        <Route index element={<ExecutiveAnalytics />} />
      </Route>

      {/* AI Assistant */}
      <Route path="/ai" element={<RequireAuth><DashboardLayout portal="ai" /></RequireAuth>}>
        <Route index element={<AIAssistant />} />
      </Route>

      {/* System */}
      <Route path="/system" element={<RequireRole role={["admin", "super_admin"]}><DashboardLayout portal="system" /></RequireRole>}>
        <Route index element={<SystemConfiguration />} />
      </Route>

      {/* Enterprise Architecture */}
      <Route path="/platform" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="platform" /></RequireRole>}>
        <Route index element={<PlatformArchitecture />} />
      </Route>

      {/* Analytics (legacy) */}
      <Route path="/analytics" element={<RequireRole role={["admin", "super_admin", "bir"]}><DashboardLayout portal="admin" /></RequireRole>}>
        <Route index element={<SalesSummaryCenter />} />
      </Route>
    </Routes>
    </PageBoundary>
    <SupportChatWidget />
    </>
  );
}
