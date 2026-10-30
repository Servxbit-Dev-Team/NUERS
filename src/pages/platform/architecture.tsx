import {
  Activity,
  Brain,
  Building2,
  CheckCircle2,
  Code,
  CreditCard,
  Database,
  FileText,
  Fingerprint,
  Globe,
  Key,
  Landmark,
  Layers,
  Lock,
  Network,
  QrCode,
  Receipt,
  Server,
  ShieldCheck,
  Users,
  Wallet,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { Separator } from "@/components/ui/separator";

const portals = [
  {
    name: "Super Admin",
    audience: "NUERS platform operators",
    modules: "System, users, RBAC, APIs, security, transaction fees, gateways",
    icon: Server,
  },
  {
    name: "BIR Dashboard",
    audience: "BIR personnel and regulators",
    modules: "Revenue, compliance, audit, verification, reports, forecasts",
    icon: Landmark,
  },
  {
    name: "Business Account Dashboard",
    audience: "Businesses and institutions",
    modules: "EOR issuance, POS, branches, settlements, reports, compliance",
    icon: Building2,
  },
  {
    name: "Client Dashboard",
    audience: "Citizens, taxpayers, students, patients",
    modules: "Wallet, payments, receipts, notifications, verification",
    icon: Users,
  },
];

const permissions = [
  {
    permission: "Manage platform configuration",
    superAdmin: true,
    bir: false,
    merchant: false,
    client: false,
  },
  {
    permission: "Manage users and RBAC policies",
    superAdmin: true,
    bir: false,
    merchant: false,
    client: false,
  },
  {
    permission: "Monitor national collections",
    superAdmin: true,
    bir: true,
    merchant: false,
    client: false,
  },
  {
    permission: "Review audit trails and fraud cases",
    superAdmin: true,
    bir: true,
    merchant: false,
    client: false,
  },
  {
    permission: "Issue electronic official receipts",
    superAdmin: false,
    bir: false,
    merchant: true,
    client: false,
  },
  {
    permission: "Manage POS and payment integrations",
    superAdmin: true,
    bir: false,
    merchant: true,
    client: false,
  },
  {
    permission: "Pay fees and manage wallet",
    superAdmin: false,
    bir: false,
    merchant: false,
    client: true,
  },
  {
    permission: "Verify public receipts",
    superAdmin: true,
    bir: true,
    merchant: true,
    client: true,
  },
];

const schemaGroups = [
  {
    name: "Identity and Access",
    tables: "users, profiles, roles, permissions, role_permissions, bir_accounts, client_accounts",
    icon: Fingerprint,
  },
  {
    name: "Revenue and Receipts",
    tables: "Business account registry, transactions, receipt records, receipt verifications",
    icon: Receipt,
  },
  {
    name: "Payments and Settlement",
    tables: "payment_gateways, settlement_batches, subscriptions",
    icon: Wallet,
  },
  {
    name: "Security and Audit",
    tables: "audit_events, fraud_signals, ai_insights, notifications",
    icon: ShieldCheck,
  },
  {
    name: "Integrations",
    tables: "api_clients, API scopes, webhook events, gateway health metadata",
    icon: Code,
  },
];

const apiLayers = [
  {
    layer: "Authentication",
    contract: "JWT bearer token, OAuth2-ready clients, MFA challenge metadata",
    icon: Key,
  },
  {
    layer: "Receipt API",
    contract: "Issue EOR, cancel workflow, reprint, QR payload, digital signature",
    icon: QrCode,
  },
  {
    layer: "Collection API",
    contract: "Government, business account, school, hospital, utility, and regulatory payments",
    icon: CreditCard,
  },
  {
    layer: "Compliance API",
    contract: "Filing status, scorecards, audit cases, exception reports",
    icon: FileText,
  },
  {
    layer: "Analytics API",
    contract: "Revenue forecasting, anomaly detection, risk scoring, dashboards",
    icon: Brain,
  },
];

const flows = [
  "Business account registers business and BIR details, then receives RBAC-scoped access.",
  "Business account issues a digitally signed EOR through POS, API, kiosk, or dashboard.",
  "NUERS records payment, settlement, reconciliation, receipt archive, and audit event.",
  "BIR monitors regional revenue, compliance score, tax reports, and audit exceptions.",
  "Client receives receipt notification, downloads PDF, verifies QR, and pays future fees.",
  "AI services score anomalies and generate fraud, compliance, and collection forecasts.",
];

function PermissionMark({ enabled }: { enabled: boolean }) {
  return enabled ? (
    <CheckCircle2 className="mx-auto h-4 w-4 text-success" />
  ) : (
    <span className="mx-auto block h-1.5 w-1.5 rounded-full bg-muted-foreground/30" />
  );
}

export function PlatformArchitecture() {
  return (
    <div className="space-y-6">
      <div>
        <Badge variant="secondary" className="gap-1.5 text-xs">
          <Network className="h-3 w-3" /> Enterprise SaaS Architecture
        </Badge>
        <h1 className="mt-3 text-2xl font-bold text-foreground">NUERS Enterprise Blueprint</h1>
        <p className="mt-1 max-w-4xl text-sm leading-6 text-muted-foreground">
          The current Laravel and MySQL implementation now presents the full enterprise operating
          model: four portals, RBAC, receipt verification, payment processing, auditability,
          security controls, AI analytics, and national-scale reporting surfaces.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-4">
        {portals.map((portal) => (
          <Card key={portal.name}>
            <CardContent className="p-4">
              <div className="flex h-10 w-10 items-center justify-center rounded-md bg-secondary">
                <portal.icon className="h-5 w-5 text-muted-foreground" />
              </div>
              <h2 className="mt-4 text-sm font-semibold text-foreground">{portal.name}</h2>
              <p className="mt-1 text-xs text-muted-foreground">{portal.audience}</p>
              <Separator className="my-3" />
              <p className="text-[10px] leading-4 text-muted-foreground">{portal.modules}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm">Roles and Permissions Matrix</CardTitle>
          <p className="text-xs text-muted-foreground">
            Role-Based Access Control for Super Admin, BIR, Business Account, and Client portals.
          </p>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px]">
              <thead>
                <tr className="border-b text-left text-[10px] text-muted-foreground">
                  <th className="pb-2 font-medium">Permission</th>
                  <th className="pb-2 text-center font-medium">Super Admin</th>
                  <th className="pb-2 text-center font-medium">BIR</th>
                  <th className="pb-2 text-center font-medium">Business Account</th>
                  <th className="pb-2 text-center font-medium">Client</th>
                </tr>
              </thead>
              <tbody>
                {permissions.map((row) => (
                  <tr key={row.permission} className="border-b last:border-0">
                    <td className="py-3 text-xs font-medium text-foreground">{row.permission}</td>
                    <td className="py-3 text-center">
                      <PermissionMark enabled={row.superAdmin} />
                    </td>
                    <td className="py-3 text-center">
                      <PermissionMark enabled={row.bir} />
                    </td>
                    <td className="py-3 text-center">
                      <PermissionMark enabled={row.merchant} />
                    </td>
                    <td className="py-3 text-center">
                      <PermissionMark enabled={row.client} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Database Schema Groups</CardTitle>
            <p className="text-xs text-muted-foreground">
              Tables added for enterprise identity, payments, audit, AI, and verification.
            </p>
          </CardHeader>
          <CardContent className="space-y-3">
            {schemaGroups.map((group) => (
              <div key={group.name} className="rounded-lg border p-4">
                <div className="flex items-start gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-md bg-secondary">
                    <group.icon className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-foreground">{group.name}</p>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">{group.tables}</p>
                  </div>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">API Architecture</CardTitle>
            <p className="text-xs text-muted-foreground">
              Service contracts for scalable nationwide integrations.
            </p>
          </CardHeader>
          <CardContent className="space-y-3">
            {apiLayers.map((layer, index) => (
              <div key={layer.layer} className="rounded-lg border p-4">
                <div className="flex items-start gap-3">
                  <div className="flex h-9 w-9 items-center justify-center rounded-md bg-secondary">
                    <layer.icon className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <div className="flex-1">
                    <div className="flex items-center justify-between">
                      <p className="text-sm font-medium text-foreground">{layer.layer}</p>
                      <Badge variant="outline" className="text-[10px]">
                        v{index + 1}
                      </Badge>
                    </div>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">{layer.contract}</p>
                  </div>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-[1fr_360px]">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Complete UI/UX Flow Map</CardTitle>
            <p className="text-xs text-muted-foreground">
              Operational journey from onboarding through audit and verification.
            </p>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {flows.map((flow, index) => (
                <div key={flow} className="flex gap-3 rounded-lg border p-3">
                  <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-bold text-primary-foreground">
                    {index + 1}
                  </div>
                  <p className="text-sm text-foreground">{flow}</p>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Production Readiness Controls</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {[
              { label: "Encryption in transit and at rest", value: 96 },
              { label: "MFA and device tracking", value: 93 },
              { label: "Audit trail coverage", value: 98 },
              { label: "API health and rate limiting", value: 94 },
              { label: "Backup and disaster recovery", value: 99 },
            ].map((control) => (
              <div key={control.label}>
                <div className="flex items-center justify-between text-xs">
                  <span className="text-muted-foreground">{control.label}</span>
                  <span className="font-medium text-foreground">{control.value}%</span>
                </div>
                <Progress value={control.value} className="mt-2 h-1.5" />
              </div>
            ))}
            <Separator />
            <div className="flex flex-wrap gap-2">
              {[
                "Docker ready",
                "Kubernetes ready",
                "Redis queue ready",
                "S3 storage ready",
                "OAuth2 ready",
                "AI scoring ready",
              ].map((tag) => (
                <Badge key={tag} variant="outline" className="text-[10px]">
                  {tag}
                </Badge>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm">Core System Feature Coverage</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: "Electronic Official Receipt", icon: Receipt },
              { label: "Revenue Collection", icon: Wallet },
              { label: "Payment Gateway", icon: CreditCard },
              { label: "Verification Portal", icon: QrCode },
              { label: "Audit Trail System", icon: FileText },
              { label: "Security and RBAC", icon: Lock },
              { label: "AI Analytics", icon: Brain },
              { label: "National Reporting", icon: Globe },
              { label: "Database Schema", icon: Database },
              { label: "API Architecture", icon: Code },
              { label: "Module Orchestration", icon: Layers },
              { label: "System Monitoring", icon: Activity },
            ].map((feature) => (
              <div key={feature.label} className="flex items-center gap-3 rounded-lg border p-3">
                <feature.icon className="h-4 w-4 text-muted-foreground" />
                <span className="text-xs font-medium text-foreground">{feature.label}</span>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
