import ServerManagement from "@/pages/infrastructure/server-management";
import { MerchantAccountSecurity } from "@/pages/merchant/security";
import { AdminAccountProfile } from "@/pages/admin/account-profile";
import { MerchantUsersPage, AcceptTeamInvitation } from "@/pages/merchant/users";
import { MerchantWalletPage, AdminWalletConsole } from "@/pages/merchant/wallet";
import { BackupRecoveryDashboard } from "@/pages/bcdr/dashboard";
import { AccountSecurityPage, AccountSecuritySettings, EmailVerificationPage, PasswordRecoveryPage } from "@/pages/auth/account-security";
import { OperationsReadiness } from "@/pages/operations/readiness";
import { SystemConfiguration } from "@/pages/system/configuration";
import { MfaPage } from "@/pages/auth/mfa";
import { IntegrationWorkspace } from "@/pages/integrations";
import { PermitManagement } from "@/pages/authorizations";
import { RdoMappings } from "@/pages/business-registry/rdo-mappings";
import { BusinessRegistry } from "@/pages/business-registry";
import { BusinessActivation } from "@/pages/business-registry/activation";
import { TransactionsCenter, ReconciliationCenter, SalesSummaryCenter } from "@/pages/operations/routes";
import { LegalPolicyPage } from "@/pages/legal/policy";
import { SupportChatWidget } from "@/components/support/chat";
import { AdminSupportInbox } from "@/pages/admin/support-inbox";
import { Routes, Route, Navigate } from "react-router-dom";
import { LandingPage } from "@/pages/landing";
import { LoginPage } from "@/pages/auth/login";
import { RegisterPage } from "@/pages/auth/register";
import { DashboardLayout } from "@/components/layouts/dashboard-layout";
import { useAuth } from "@/lib/auth";
import { MerchantCustomers } from "@/pages/merchant/customers";
import { MerchantDashboard } from "@/pages/merchant/dashboard";
import { MerchantReceipts } from "@/pages/merchant/receipts";
import { MerchantSalesReports } from "@/pages/merchant/sales-reports";
import { MerchantBilling } from "@/pages/merchant/billing";
import { MerchantPosSettings } from "@/pages/merchant/pos-settings";
import { MerchantApiKeys } from "@/pages/merchant/api-keys";
import { MerchantProfile } from "@/pages/merchant/profile";
import { BranchReviewQueue } from "@/pages/merchant/branches";

import { MerchantExpenses } from "@/pages/merchant/expenses";
import { MerchantInvoices, MerchantLegacyTransactions } from "@/pages/merchant/invoices";
import { InvoiceCreate } from "@/pages/merchant/invoice-create";
import { InvoiceDetail } from "@/pages/merchant/invoice-detail";
import { AdminDashboard } from "@/pages/admin/dashboard";
import { SuperAdminDashboard } from "@/pages/super-admin/dashboard";
import { BirDashboard } from "@/pages/bir/dashboard";
import { BirRdoManagement } from "@/pages/bir/rdo-management";
import { CitizenApprovalPage } from "@/pages/citizen/approval";
import { RdoPortal } from "@/pages/rdo/portal";
import { AdminRiskDetection } from "@/pages/admin/fraud";
import { AdminTaxFilings } from "@/pages/admin/tax-filings";
import { AdminCompliance } from "@/pages/admin/compliance";
import { AdminAudit } from "@/pages/admin/audit";
import { AdminUsers } from "@/pages/admin/users";
import { AdminClientAccounts } from "@/pages/admin/client-accounts";
import { AdminNotifications } from "@/pages/admin/notifications";
import { AdminReports } from "@/pages/admin/reports";
import { ConsumerPortal } from "@/pages/consumer/portal";
import {
  ClientBarcodePage,
  ClientDashboard,
  ClientNotificationsPage,
  ClientProfilePage,
  ClientReceiptsPage,
  ClientSecurityPage,
  ClientTransactionsPage,
  ClientWalletPage,
} from "@/pages/client/dashboard";
import { ReceiptVerificationPortal } from "@/pages/verification/portal";
import { PlatformArchitecture } from "@/pages/platform/architecture";
import { ApiPortal } from "@/pages/api/portal";
import { ApiKeys } from "@/pages/api/keys";
import { ApiWebhooks } from "@/pages/api/webhooks";
import { ApiSdks } from "@/pages/api/sdks";
import { ApiGuides } from "@/pages/api/guides";
import { DeveloperSandbox as ApiSandbox } from "@/pages/developers/sandbox";
import { IntegrationOverview } from "@/pages/developers/overview";
import { DeveloperDocs as ApiDocs } from "@/pages/developers/docs";
import { ApiMonitoring } from "@/pages/api/monitoring";
import { EisGateway } from "@/pages/api/eis-gateway";
import { EisTransmissions } from "@/pages/api/eis-transmissions";
import { EisPerformance } from "@/pages/api/eis-performance";
import { EisErrors } from "@/pages/api/eis-errors";
import { TaxpayerDashboard } from "@/pages/taxpayer/dashboard";
import { TaxpayerRegistry } from "@/pages/taxpayer/registry";
import { BusinessRegistration } from "@/pages/taxpayer/businesses";
import { VerificationCenter } from "@/pages/taxpayer/verification";
import { TaxpayerPortal } from "@/pages/taxpayer/portal";
import { UserManagement } from "@/pages/taxpayer/users";
// Electronic Invoicing Engine (Module 1)
// New Modules
import { EisHub } from "@/pages/eis/hub";
import { InvoiceManagementCenter } from "@/pages/invoices/center";
import { TransmissionMonitoringCenter } from "@/pages/monitoring/transmissions";
import { ComplianceCenter } from "@/pages/compliance/center";
import { AuditLogManagement } from "@/pages/audit/log";
import { NotificationCenter } from "@/pages/notifications/center";
import { CertificateManagement } from "@/pages/certificates/management";
import { ESPAccreditation } from "@/pages/accreditation/esp";
import { ExecutiveAnalytics } from "@/pages/executive/analytics";
import { AIAssistant } from "@/pages/ai/assistant";
import {
  BirTaxIntelligenceDashboard,
  SuperAdminTaxOperationsCenter,
} from "@/pages/tax-intelligence/center";

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
    <SupportChatWidget />
    </>
  );
}
