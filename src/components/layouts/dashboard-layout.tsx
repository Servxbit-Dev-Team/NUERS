import { PageBoundary } from "@/components/page-boundary";
import { useEffect, useMemo, useState } from "react";
import { Outlet, Link, useLocation, useNavigate } from "react-router-dom";
import {
  LayoutDashboard, ShieldAlert, Activity,
  BarChart3, Settings, Code, QrCode, Server, Bell,
  LogOut, ChevronDown, Search, FileText,
  Globe, Lock, Database, Building2, Shield, Users,
  ClipboardList, BookOpen, Download, Receipt, ArrowLeft,
  Key, Webhook, Package, FlaskConical, BookMarked,
  Store, CreditCard, Cpu, GitBranch, Layers, Send,
  Archive, RefreshCw, Award, Bot, Plus,
  Wifi, TrendingUp, Landmark, Wallet, Brain, Fingerprint,
  HardDrive, ShieldCheck, Network, UserCheck,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarGroupContent,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuBadge,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
} from "@/components/ui/sidebar";
import { useAuth } from "@/lib/auth";
import { EnterpriseShell } from "@/components/enterprise/enterprise-shell";
import { apiFetch, publicAssetUrl } from "@/lib/api-url";

type PortalType =
  | "super-admin" | "bir" | "rdo" | "merchant" | "admin" | "consumer" | "client" | "api" | "soc" | "system" | "taxpayer"
  | "engine" | "eis" | "invoices" | "monitoring" | "compliance" | "audit"
  | "notifications" | "certificates" | "archive" | "bcdr"
  | "accreditation" | "executive" | "ai" | "platform";

type NavItem = {
  label: string;
  href: string;
  icon: React.ElementType;
  badge?: string;
};

type NavGroup = {
  section: string;
  items: NavItem[];
};

type BusinessIdentity = {
  business_name?: string;
  owner_name?: string | null;
  email?: string | null;
  tin?: string | null;
  logo_url?: string | null;
  status?: string | null;
};

type ClientIdentity = {
  full_name?: string | null;
  organization?: string | null;
  email?: string | null;
  profile_photo_url?: string | null;
  status?: string | null;
  tin?: string | null;
};

const API_TOKEN_KEY = "nuers_api_token";

function authHeaders() {
  const headers = new Headers({ Accept: "application/json" });

  try {
    const token = window.localStorage.getItem(API_TOKEN_KEY);
    if (token) headers.set("Authorization", `Bearer ${token}`);
  } catch {
    // Local storage can be unavailable in restricted browser contexts.
  }

  return headers;
}

function initialsFor(value?: string | null, fallback = "A") {
  const source = (value || fallback).trim();
  const initials = source
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase();

  return initials || fallback.slice(0, 2).toUpperCase();
}

function displayEmail(value?: string | null) {
  return value || "No email on file";
}

const portalConfig: Record<PortalType, { title: string; groups: NavGroup[] }> = {
  "super-admin": {
    title: "NUERS Super Admin",
    groups: [
      {
        section: "Ecosystem",
        items: [
          { label: "Command Center", href: "/super-admin", icon: LayoutDashboard },
          { label: "BIR Regulator Portal", href: "/bir", icon: Landmark },
          { label: "Enterprise Blueprint", href: "/platform", icon: Network },
          { label: "Public Verification", href: "/verify", icon: QrCode },
        ],
      },
      {
        section: "Operations",
        items: [
          { label: "Live Transactions", href: "/admin/transactions", icon: Activity },
          { label: "Business Account Management", href: "/admin/merchants", icon: Building2 },
          { label: "Client Accounts", href: "/taxpayer/users", icon: UserCheck },
          { label: "BIR Accounts", href: "/admin/users", icon: ShieldCheck },
          { label: "Transaction Fees & Storage", href: "/merchant/billing", icon: CreditCard },
        ],
      },
      {
        section: "Security",
        items: [
          { label: "RBAC Matrix", href: "/platform", icon: Lock },
          { label: "Audit Trails", href: "/audit", icon: ClipboardList },
          { label: "Fraud Detection", href: "/admin/fraud", icon: Brain, badge: "AI" },
          { label: "Security Monitoring", href: "/soc", icon: ShieldAlert },
          { label: "MFA & Device Trust", href: "/system", icon: Fingerprint },
        ],
      },
      {
        section: "Infrastructure",
        items: [
          { label: "API Management", href: "/api", icon: Code },
          { label: "Payment Gateways", href: "/api/monitoring", icon: Wallet },
          { label: "System Health", href: "/system", icon: Server },
          { label: "Backups & BCDR", href: "/bcdr", icon: HardDrive },
          { label: "AI Analytics", href: "/ai", icon: Bot },
        ],
      },
    ],
  },

  bir: {
    title: "BIR Regulator Portal",
    groups: [
      {
        section: "Regulator",
        items: [
          { label: "BIR Dashboard", href: "/bir", icon: Landmark },
          { label: "Transactions", href: "/bir/transactions", icon: Activity },
          { label: "Business Accounts", href: "/bir/business-accounts", icon: Building2 },
          { label: "Citizen Approval", href: "/bir/citizen-approval", icon: ShieldCheck },
        ],
      },
      {
        section: "Review",
        items: [
          { label: "Compliance Review", href: "/bir/compliance", icon: ShieldCheck },
          { label: "Tax Filings", href: "/bir/tax-filings", icon: FileText },
          { label: "Tax Intelligence", href: "/bir/tax-intelligence", icon: Brain },
          { label: "Reports", href: "/bir/reports", icon: Download },
        ],
      },
    ],
  },

  rdo: {
    title: "RDO Portal",
    groups: [
      {
        section: "RDO Operations",
        items: [
          { label: "RDO Dashboard", href: "/rdo", icon: Landmark },
          { label: "Businesses", href: "/rdo", icon: Building2 },
          { label: "Transactions", href: "/rdo", icon: Activity },
          { label: "Receipts", href: "/rdo", icon: Receipt },
          { label: "Compliance Queue", href: "/rdo", icon: ShieldAlert },
        ],
      },
    ],
  },

  merchant: {
    title: "Business Account",
    groups: [
      {
        section: "Overview",
        items: [
          { label: "Dashboard", href: "/merchant", icon: LayoutDashboard },
          { label: "Receipt History", href: "/merchant/receipts", icon: QrCode },
        ],
      },
      {
        section: "Invoicing",
        items: [
          { label: "Invoicing Engine", href: "/merchant/invoices", icon: Layers, badge: "M1" },
        ],
      },
      {
        section: "Finance",
        items: [
          { label: "Expenses", href: "/merchant/expenses", icon: Wallet },
          { label: "Sales Reports", href: "/merchant/sales-reports", icon: FileText },
          { label: "Billing & Fees", href: "/merchant/billing", icon: CreditCard },
        ],
      },
      {
        section: "Integration",
        items: [
          { label: "POS & Devices", href: "/merchant/pos-settings", icon: Cpu },
          { label: "API Management", href: "/merchant/api-keys", icon: Key },
        ],
      },
      {
        section: "Account",
        items: [
          { label: "Business Profile", href: "/merchant/profile", icon: Store },
          { label: "Compliance Operations", href: "/merchant/compliance-operations", icon: Store },
          { label: "PTI Applications", href: "/merchant/pti-applications", icon: Store },
          { label: "Notifications", href: "/merchant/notifications", icon: Bell },
        ],
      },
    ],
  },

  admin: {
    title: "BIR Admin Console",
    groups: [
      {
        section: "Operations",
        items: [
          { label: "Command Center", href: "/admin", icon: LayoutDashboard },
          { label: "Live Transactions", href: "/admin/transactions", icon: Activity },
          { label: "Business Account Registry", href: "/admin/merchants", icon: Building2 },
        ],
      },
      {
        section: "Compliance",
        items: [
          { label: "Tax Filings", href: "/admin/tax-filings", icon: FileText },
          { label: "Compliance Monitor", href: "/admin/compliance", icon: Shield },
          { label: "Risk Detection", href: "/admin/fraud", icon: ShieldAlert, badge: "12" },
        ],
      },
      {
        section: "Intelligence",
        items: [
          { label: "Analytics Center", href: "/analytics", icon: BarChart3 },
          { label: "Executive Analytics", href: "/executive", icon: TrendingUp },
          { label: "Reports & Export", href: "/admin/reports", icon: Download },
        ],
      },
      {
        section: "Administration",
        items: [
          { label: "User Management", href: "/admin/users", icon: Users },
          { label: "Audit Log", href: "/audit", icon: ClipboardList },
          { label: "Notifications", href: "/notifications", icon: Bell, badge: "3" },
        ],
      },
      {
        section: "Platform Modules",
        items: [
          { label: "Invoicing Engine", href: "/engine", icon: Layers, badge: "M1" },
          { label: "EIS Integration Hub", href: "/eis", icon: Globe, badge: "M2" },
          { label: "Invoice Center", href: "/invoices", icon: FileText, badge: "M5" },
          { label: "Transmission Monitor", href: "/monitoring", icon: Activity, badge: "M6" },
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "Notifications", href: "/notifications", icon: Bell },
        ],
      },
      {
        section: "Infrastructure",
        items: [
          { label: "SOC Dashboard", href: "/soc", icon: Lock },
          { label: "SOC Operations", href: "/soc/operations", icon: ShieldAlert },
          { label: "Certificates & PKI", href: "/certificates", icon: Key, badge: "M10" },
          { label: "Data Archive", href: "/archive", icon: Archive, badge: "M11" },
          { label: "BCDR Center", href: "/bcdr", icon: RefreshCw, badge: "M12" },
          { label: "ESP Accreditation", href: "/accreditation", icon: Award, badge: "M14" },
          { label: "API Platform", href: "/api", icon: Code },
          { label: "System Admin", href: "/system", icon: Server },
          { label: "AI Assistant", href: "/ai", icon: Bot },
        ],
      },
    ],
  },

  consumer: {
    title: "Consumer Portal",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Back to Home", href: "/", icon: LayoutDashboard },
        ],
      },
      {
        section: "Consumer Portal",
        items: [
          { label: "My Receipts", href: "/consumer", icon: QrCode },
          { label: "Verify Receipt", href: "/consumer", icon: FileText },
        ],
      },
    ],
  },

  client: {
    title: "Client Portal",
    groups: [
      {
        section: "Account",
        items: [
          { label: "Client Dashboard", href: "/client", icon: LayoutDashboard },
          { label: "Digital Wallet", href: "/client", icon: Wallet },
          { label: "Notifications", href: "/notifications", icon: Bell },
        ],
      },
      {
        section: "Receipts",
        items: [
          { label: "Receipt History", href: "/client", icon: Receipt },
          { label: "Verify Receipt", href: "/verify", icon: QrCode },
          { label: "Download Receipts", href: "/client", icon: Download },
        ],
      },
      {
        section: "Payments",
        items: [
          { label: "Pay Government Fees", href: "/client", icon: Landmark },
          { label: "Saved Methods", href: "/client", icon: CreditCard },
          { label: "Payment History", href: "/client", icon: FileText },
        ],
      },
    ],
  },

  api: {
    title: "API Platform",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Platform",
        items: [
          { label: "Overview", href: "/api", icon: Code },
          { label: "API Keys", href: "/api/keys", icon: Key },
          { label: "Webhooks", href: "/api/webhooks", icon: Webhook },
          { label: "SDKs & Libraries", href: "/api/sdks", icon: Package },
          { label: "Integration Guides", href: "/api/guides", icon: BookMarked },
          { label: "Sandbox", href: "/api/sandbox", icon: FlaskConical },
          { label: "Documentation", href: "/api/docs", icon: BookOpen },
          { label: "Monitoring", href: "/api/monitoring", icon: Activity },
        ],
      },
      {
        section: "BIR EIS Gateway",
        items: [
          { label: "EIS Gateway", href: "/api/eis", icon: Globe, badge: "M2" },
          { label: "Transmissions", href: "/eis/transmissions", icon: Send },
          { label: "Performance", href: "/api/eis/performance", icon: BarChart3 },
          { label: "Error Monitor", href: "/api/eis/errors", icon: ShieldAlert, badge: "31" },
        ],
      },
    ],
  },

  taxpayer: {
    title: "Taxpayer Management",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 3 — Taxpayer",
        items: [
          { label: "Dashboard", href: "/taxpayer", icon: LayoutDashboard, badge: "M3" },
        ],
      },
      {
        section: "Registration",
        items: [
          { label: "Taxpayer Registry", href: "/taxpayer/registry", icon: Users },
          { label: "Business Registration", href: "/taxpayer/businesses", icon: Building2 },
          { label: "User Management", href: "/taxpayer/users", icon: Shield },
        ],
      },
      {
        section: "Verification",
        items: [
          { label: "Verification Center", href: "/taxpayer/verification", icon: ShieldAlert, badge: "63" },
        ],
      },
      {
        section: "Customer Portal",
        items: [
          { label: "Self-Service Portal", href: "/taxpayer/portal", icon: CreditCard },
        ],
      },
    ],
  },

  engine: {
    title: "Invoicing Engine",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 1 — Engine",
        items: [
          { label: "Engine Overview", href: "/engine", icon: Layers },
        ],
      },
      {
        section: "Create Documents",
        items: [
          { label: "New Invoice", href: "/merchant/invoices/create", icon: Plus },
        ],
      },
      {
        section: "Manage",
        items: [
          { label: "Invoice Repository", href: "/merchant/invoices", icon: FileText },
          { label: "Invoice Center", href: "/invoices", icon: ClipboardList, badge: "M5" },
          { label: "Transmission Monitor", href: "/monitoring", icon: Activity, badge: "M6" },
        ],
      },
    ],
  },

  eis: {
    title: "EIS Integration Hub",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 2 — EIS Hub",
        items: [
          { label: "Integration Hub", href: "/eis", icon: Globe },
        ],
      },
      {
        section: "EIS Gateway",
        items: [
          { label: "EIS Gateway", href: "/api/eis", icon: Wifi },
          { label: "Transmissions", href: "/eis/transmissions", icon: Send },
          { label: "Performance", href: "/api/eis/performance", icon: BarChart3 },
          { label: "Error Monitor", href: "/api/eis/errors", icon: ShieldAlert },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Transmission Monitor", href: "/monitoring", icon: Activity, badge: "M6" },
          { label: "Invoicing Engine", href: "/engine", icon: Layers, badge: "M1" },
        ],
      },
    ],
  },

  invoices: {
    title: "Invoice Management",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 5 — Invoices",
        items: [
          { label: "Invoice Center", href: "/invoices", icon: FileText },
        ],
      },
      {
        section: "Create",
        items: [
          { label: "New Invoice", href: "/merchant/invoices/create", icon: Plus },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Invoicing Engine", href: "/engine", icon: Layers, badge: "M1" },
          { label: "Transmission Monitor", href: "/monitoring", icon: Activity, badge: "M6" },
        ],
      },
    ],
  },

  monitoring: {
    title: "Transmission Monitor",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 6 — Monitoring",
        items: [
          { label: "Monitoring Center", href: "/monitoring", icon: Activity },
        ],
      },
      {
        section: "EIS Gateway",
        items: [
          { label: "EIS Hub", href: "/eis", icon: Globe, badge: "M2" },
          { label: "EIS Transmissions", href: "/eis/transmissions", icon: Send },
          { label: "Error Monitor", href: "/api/eis/errors", icon: ShieldAlert },
        ],
      },
    ],
  },

  compliance: {
    title: "Compliance Center",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 7 — Compliance",
        items: [
          { label: "Compliance Center", href: "/compliance", icon: Shield },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Audit Log", href: "/audit", icon: ClipboardList, badge: "M8" },
          { label: "ESP Accreditation", href: "/accreditation", icon: Award, badge: "M14" },
          { label: "Certificates & PKI", href: "/certificates", icon: Key, badge: "M10" },
        ],
      },
    ],
  },

  audit: {
    title: "Audit Log Management",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 8 — Audit",
        items: [
          { label: "Audit Log", href: "/audit", icon: ClipboardList },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "SOC Operations", href: "/soc/operations", icon: ShieldAlert, badge: "M13" },
          { label: "Data Archive", href: "/archive", icon: Archive, badge: "M11" },
        ],
      },
    ],
  },

  notifications: {
    title: "Notification Center",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 9 — Notifications",
        items: [
          { label: "Notification Center", href: "/notifications", icon: Bell },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Audit Log", href: "/audit", icon: ClipboardList, badge: "M8" },
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "SOC Operations", href: "/soc/operations", icon: ShieldAlert, badge: "M13" },
        ],
      },
    ],
  },

  certificates: {
    title: "Certificate Management",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 10 — Certificates",
        items: [
          { label: "Certificate Management", href: "/certificates", icon: Key },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "SOC Operations", href: "/soc/operations", icon: ShieldAlert, badge: "M13" },
          { label: "ESP Accreditation", href: "/accreditation", icon: Award, badge: "M14" },
        ],
      },
    ],
  },

  archive: {
    title: "Data Archive Center",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 11 — Archive",
        items: [
          { label: "Archive Center", href: "/archive", icon: Archive },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "BCDR Center", href: "/bcdr", icon: RefreshCw, badge: "M12" },
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "Audit Log", href: "/audit", icon: ClipboardList, badge: "M8" },
        ],
      },
    ],
  },

  bcdr: {
    title: "BCDR Center",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 12 — BCDR",
        items: [
          { label: "BCDR Dashboard", href: "/bcdr", icon: RefreshCw },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "SOC Operations", href: "/soc/operations", icon: ShieldAlert, badge: "M13" },
          { label: "Data Archive", href: "/archive", icon: Archive, badge: "M11" },
          { label: "System Admin", href: "/system", icon: Server },
        ],
      },
    ],
  },

  soc: {
    title: "Security Operations",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 13 — SOC",
        items: [
          { label: "SOC Dashboard", href: "/soc", icon: ShieldAlert },
          { label: "SOC Operations", href: "/soc/operations", icon: Lock },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Audit Log", href: "/audit", icon: ClipboardList, badge: "M8" },
          { label: "Certificates & PKI", href: "/certificates", icon: Key, badge: "M10" },
          { label: "BCDR Center", href: "/bcdr", icon: RefreshCw, badge: "M12" },
        ],
      },
    ],
  },

  accreditation: {
    title: "ESP Accreditation",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Module 14 — ESP",
        items: [
          { label: "ESP Accreditation", href: "/accreditation", icon: Award },
        ],
      },
      {
        section: "Related",
        items: [
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "Certificates & PKI", href: "/certificates", icon: Key, badge: "M10" },
          { label: "Audit Log", href: "/audit", icon: ClipboardList, badge: "M8" },
        ],
      },
    ],
  },

  executive: {
    title: "Executive Analytics",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "Analytics",
        items: [
          { label: "Executive Dashboard", href: "/executive", icon: TrendingUp },
        ],
      },
      {
        section: "Reports",
        items: [
          { label: "Analytics Center", href: "/analytics", icon: BarChart3 },
          { label: "Admin Reports", href: "/admin/reports", icon: Download },
          { label: "Compliance Reports", href: "/compliance", icon: Shield },
          { label: "AI Insights", href: "/ai", icon: Bot },
        ],
      },
    ],
  },

  ai: {
    title: "AI Assistant",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "NUERS AI",
        items: [
          { label: "AI Assistant", href: "/ai", icon: Bot },
        ],
      },
      {
        section: "Quick Access",
        items: [
          { label: "Compliance Center", href: "/compliance", icon: Shield, badge: "M7" },
          { label: "Invoice Center", href: "/invoices", icon: FileText, badge: "M5" },
          { label: "EIS Hub", href: "/eis", icon: Globe, badge: "M2" },
          { label: "Executive Analytics", href: "/executive", icon: TrendingUp },
        ],
      },
    ],
  },

  system: {
    title: "System Admin",
    groups: [
      {
        section: "Navigation",
        items: [
          { label: "Admin Dashboard", href: "/admin", icon: LayoutDashboard },
        ],
      },
      {
        section: "System Admin",
        items: [
          { label: "Overview", href: "/system", icon: Server },
          { label: "Users", href: "/system", icon: Users },
          { label: "Database", href: "/system", icon: Database },
          { label: "Settings", href: "/system", icon: Settings },
        ],
      },
    ],
  },

  platform: {
    title: "Enterprise Blueprint",
    groups: [
      {
        section: "Architecture",
        items: [
          { label: "Blueprint", href: "/platform", icon: Network },
          { label: "Super Admin", href: "/super-admin", icon: LayoutDashboard },
          { label: "BIR Portal", href: "/bir", icon: Landmark },
          { label: "Verification Portal", href: "/verify", icon: QrCode },
        ],
      },
      {
        section: "Controls",
        items: [
          { label: "RBAC", href: "/platform", icon: Lock },
          { label: "API Architecture", href: "/api/docs", icon: Code },
          { label: "Database Schema", href: "/platform", icon: Database },
          { label: "Security Operations", href: "/soc", icon: ShieldAlert },
        ],
      },
    ],
  },
};

const sharedToolItems: NavItem[] = [
  { label: "Verify Receipt", href: "/verify", icon: QrCode },
];

const superAdminToolItems: NavItem[] = [
  { label: "Verify Receipt", href: "/verify", icon: QrCode },
];

const clientToolItems: NavItem[] = [
  { label: "Verify Receipt", href: "/verify", icon: QrCode },
];

const cleanSidebarGroups: Partial<Record<PortalType, NavGroup[]>> = {
  "super-admin": [
    {
      section: "Workspace",
      items: [
        { label: "Command Center", href: "/super-admin", icon: LayoutDashboard },
        { label: "Transactions", href: "/super-admin/transactions", icon: Activity },
        { label: "Revenue Analytics", href: "/super-admin/revenue-analytics", icon: TrendingUp },
        { label: "Blueprint", href: "/super-admin/blueprint", icon: Network },
      ],
    },
    {
      section: "Accounts",
      items: [
        { label: "Business Accounts", href: "/super-admin/merchants", icon: Building2 },
        { label: "BIR Accounts", href: "/super-admin/bir-accounts", icon: Landmark },
        { label: "Client Accounts", href: "/super-admin/client-accounts", icon: UserCheck },
        { label: "Citizen Approval", href: "/super-admin/citizen-approval", icon: ShieldCheck },
        { label: "Users & RBAC", href: "/super-admin/users-rbac", icon: Users },
        { label: "Transaction Pricing", href: "/super-admin/subscriptions", icon: CreditCard },
      ],
    },
    {
      section: "Tax & Compliance",
      items: [
        { label: "Compliance", href: "/super-admin/compliance", icon: ShieldCheck },
        { label: "Fraud Signals", href: "/super-admin/fraud", icon: Brain },
        { label: "Tax Filings", href: "/super-admin/tax-filings", icon: FileText },
        { label: "Reports", href: "/super-admin/reports", icon: Download },
        { label: "AI Recommendations", href: "/super-admin/ai-recommendations", icon: Bot },
      ],
    },
    {
      section: "Tax Intelligence",
      items: [
        { label: "Tax Operations", href: "/super-admin/tax-operations", icon: Landmark },
        { label: "Tax Rules", href: "/super-admin/tax-rules", icon: Settings },
        { label: "Fraud Models", href: "/super-admin/fraud-models", icon: Brain },
        { label: "B2B Reconciliation", href: "/super-admin/reconciliation", icon: GitBranch },
        { label: "Tax Analytics", href: "/super-admin/tax-analytics", icon: BarChart3 },
        { label: "Tax Graph", href: "/super-admin/graph", icon: Network },
      ],
    },
    {
      section: "Security & Audit",
      items: [
        { label: "Security", href: "/super-admin/security", icon: ShieldAlert },
        { label: "SOC Operations", href: "/super-admin/soc-operations", icon: Lock },
        { label: "Audit Trail", href: "/super-admin/audit", icon: ClipboardList },
        { label: "Certificates", href: "/super-admin/certificates", icon: Key },
        { label: "MFA & Devices", href: "/super-admin/mfa-device-trust", icon: Fingerprint },
      ],
    },
    {
      section: "Platform Ops",
      items: [
        { label: "API Platform", href: "/super-admin/api-platform", icon: Code },
        { label: "BIR EIS Readiness", href: "/super-admin/eis-readiness", icon: Globe, badge: "EIS" },
        { label: "EIS Transmissions", href: "/super-admin/eis-transmissions", icon: Send },
        { label: "API Keys", href: "/super-admin/api-keys", icon: Key },
        { label: "Webhooks", href: "/super-admin/webhooks", icon: Webhook },
        { label: "Payment Gateways", href: "/super-admin/payment-gateways", icon: Wallet },
        { label: "System Health", href: "/super-admin/system-health", icon: Server },
        { label: "Backups", href: "/super-admin/backups", icon: HardDrive },
        { label: "Data Archive", href: "/super-admin/data-archive", icon: Archive },
        { label: "Notifications", href: "/super-admin/notifications", icon: Bell },
      ],
    },
  ],
  admin: [
    {
      section: "Workspace",
      items: [
        { label: "Command Center", href: "/admin", icon: LayoutDashboard },
        { label: "Transactions", href: "/admin/transactions", icon: Activity },
        { label: "Business Accounts", href: "/admin/merchants", icon: Building2 },
        { label: "Tax Filings", href: "/admin/tax-filings", icon: FileText },
        { label: "Reports", href: "/admin/reports", icon: Download },
        { label: "EIS Readiness", href: "/bir/eis-readiness", icon: Globe },
        { label: "EIS Transmissions", href: "/bir/eis-transmissions", icon: Send },
      ],
    },
    {
      section: "Controls",
      items: [
        { label: "Compliance", href: "/admin/compliance", icon: ShieldCheck },
        { label: "Risk Detection", href: "/admin/fraud", icon: ShieldAlert },
        { label: "Users", href: "/admin/users", icon: Users },
      ],
    },
  ],
  bir: [
    {
      section: "Regulator",
      items: [
        { label: "Revenue Dashboard", href: "/bir", icon: Landmark },
        { label: "Transactions", href: "/bir/transactions", icon: Activity },
        { label: "Business Accounts", href: "/bir/business-accounts", icon: Building2 },
        { label: "Citizen Approval", href: "/bir/citizen-approval", icon: ShieldCheck },
      ],
    },
    {
      section: "Review",
      items: [
        { label: "Compliance Review", href: "/bir/compliance", icon: ShieldCheck },
        { label: "Tax Filings", href: "/bir/tax-filings", icon: FileText },
        { label: "Tax Intelligence", href: "/bir/tax-intelligence", icon: Brain },
        { label: "Reports", href: "/bir/reports", icon: Download },
      ],
    },
  ],
  rdo: [
    {
      section: "RDO",
      items: [
        { label: "Dashboard", href: "/rdo", icon: LayoutDashboard },
        { label: "Businesses", href: "/rdo", icon: Building2 },
        { label: "Transactions", href: "/rdo", icon: Activity },
        { label: "Receipts", href: "/rdo", icon: Receipt },
        { label: "Compliance Queue", href: "/rdo", icon: ShieldAlert },
      ],
    },
  ],
  merchant: [
    {
      section: "Business Account",
      items: [
        { label: "Dashboard", href: "/merchant", icon: LayoutDashboard },
        { label: "Receipts", href: "/merchant/receipts", icon: QrCode },
        { label: "Invoices", href: "/merchant/invoices", icon: FileText },
        { label: "Sales Reports", href: "/merchant/sales-reports", icon: ShieldCheck },
        { label: "Notifications", href: "/merchant/notifications", icon: Bell },
      ],
    },
    {
      section: "B2B Tax",
      items: [
        { label: "B2B Expenses", href: "/merchant/expenses", icon: Wallet },
      ],
    },
    {
      section: "Business",
      items: [
        { label: "POS & Devices", href: "/merchant/pos-settings", icon: Cpu },
        { label: "API Keys", href: "/merchant/api-keys", icon: Key },
        { label: "Profile", href: "/merchant/profile", icon: Store },
      ],
    },
  ],
  client: [
    {
      section: "Client",
      items: [
        { label: "Dashboard", href: "/client", icon: LayoutDashboard },
        { label: "My Transactions", href: "/client/transactions", icon: Receipt },
        { label: "Receipt Vault", href: "/client/receipts", icon: FileText },
        { label: "TIN Barcode", href: "/client/barcode", icon: QrCode },
        { label: "Wallet & Payments", href: "/client/wallet", icon: Wallet },
        { label: "Notifications", href: "/client/notifications", icon: Bell },
        { label: "Security", href: "/client/security", icon: ShieldCheck },
        { label: "Profile", href: "/client/profile", icon: UserCheck },
      ],
    },
  ],
  consumer: [
    {
      section: "Client",
      items: [
        { label: "Dashboard", href: "/consumer", icon: LayoutDashboard },
        { label: "Receipt History", href: "/consumer", icon: Receipt },
        { label: "Verify Receipt", href: "/verify", icon: QrCode },
      ],
    },
  ],
  api: [
    {
      section: "API",
      items: [
        { label: "Overview", href: "/api", icon: Code },
        { label: "EIS Readiness", href: "/eis", icon: Globe },
        { label: "EIS Transmissions", href: "/eis/transmissions", icon: Send },
        { label: "Keys", href: "/api/keys", icon: Key },
        { label: "Webhooks", href: "/api/webhooks", icon: Webhook },
        { label: "Docs", href: "/api/docs", icon: BookOpen },
        { label: "Monitoring", href: "/api/monitoring", icon: Activity },
      ],
    },
  ],
  taxpayer: [
    {
      section: "Taxpayer",
      items: [
        { label: "Dashboard", href: "/taxpayer", icon: LayoutDashboard },
        { label: "Registry", href: "/taxpayer/registry", icon: Users },
        { label: "Businesses", href: "/taxpayer/businesses", icon: Building2 },
        { label: "Verification", href: "/taxpayer/verification", icon: ShieldAlert },
      ],
    },
  ],
  eis: [
    {
      section: "BIR EIS",
      items: [
        { label: "Readiness Hub", href: "/eis", icon: Globe },
        { label: "Transmissions", href: "/eis/transmissions", icon: Send },
        { label: "API Gateway", href: "/api/eis", icon: Wifi },
        { label: "Performance", href: "/api/eis/performance", icon: BarChart3 },
        { label: "Error Monitor", href: "/api/eis/errors", icon: ShieldAlert },
      ],
    },
  ],
  platform: [
    {
      section: "Blueprint",
      items: [
        { label: "Architecture", href: "/platform", icon: Network },
        { label: "RBAC", href: "/platform", icon: Lock },
        { label: "API Docs", href: "/api/docs", icon: Code },
        { label: "Security", href: "/soc", icon: ShieldAlert },
      ],
    },
  ],
};

function compactModuleGroups(config: { title: string; groups: NavGroup[] }): NavGroup[] {
  const navigationItems = config.groups.flatMap((group) => group.items);
  const homeItem = navigationItems.find((item) => item.label.includes("Dashboard") || item.label.includes("Overview")) ?? navigationItems[0];
  const relatedItems = navigationItems
    .filter((item) => item.href !== homeItem?.href)
    .filter((item, index, arr) => arr.findIndex((candidate) => candidate.href === item.href) === index)
    .slice(0, 5);

  return [
    {
      section: config.title,
      items: [homeItem, ...relatedItems].filter(Boolean) as NavItem[],
    },
  ];
}

function cleanGroupsForPortal(portal: PortalType, config: { title: string; groups: NavGroup[] }): NavGroup[] {
  return cleanSidebarGroups[portal] ?? compactModuleGroups(config);
}

function isNavItemActive(currentLocation: string, href: string): boolean {
  const [currentPath, currentHash = ""] = currentLocation.split("#");
  const [hrefPath, hrefHash = ""] = href.split("#");
  const normalizedCurrentHash = currentHash ? `#${currentHash}` : "";
  const normalizedHrefHash = hrefHash ? `#${hrefHash}` : "";

  if (normalizedHrefHash) {
    if (currentPath !== hrefPath) return false;
    if (normalizedHrefHash === "#overview") return normalizedCurrentHash === "" || normalizedCurrentHash === "#overview";
    return normalizedCurrentHash === normalizedHrefHash;
  }

  if (currentPath === hrefPath && normalizedCurrentHash === "") return true;
  const shouldMatchChildren = hrefPath !== "/" && !["/admin", "/merchant", "/client", "/consumer", "/super-admin", "/bir"].includes(hrefPath);
  return shouldMatchChildren && currentPath.startsWith(`${hrefPath}/`);
}

export function DashboardLayout({ portal }: { portal: PortalType }) {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, profile, signOut } = useAuth();
  const [businessIdentity, setBusinessIdentity] = useState<BusinessIdentity | null>(null);
  const [clientIdentity, setClientIdentity] = useState<ClientIdentity | null>(null);
  const config = portalConfig[portal];
  const isClientAccountActive = (clientIdentity?.status ?? "").toLowerCase() === "active";
  const visibleGroups = useMemo(() => {
    const groups = cleanGroupsForPortal(portal, config);

    if (portal !== "client" || isClientAccountActive) {
      return groups;
    }

    return groups
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => item.href !== "/client/barcode"),
      }))
      .filter((group) => group.items.length > 0);
  }, [config, isClientAccountActive, portal]);
  const visibleToolItems =
    portal === "super-admin"
      ? superAdminToolItems
      : portal === "client" || portal === "consumer"
        ? clientToolItems
        : sharedToolItems;
  const activeLocation = `${location.pathname}${location.hash}`;
  const notificationPath =
    portal === "super-admin"
      ? "/super-admin/notifications"
      : portal === "client" || portal === "consumer"
        ? "/client/notifications"
        : portal === "merchant"
          ? "/merchant/notifications"
        : "/notifications";
  const accountName = useMemo(() => {
    if (portal === "merchant" && businessIdentity?.business_name) return businessIdentity.business_name;
    if ((portal === "client" || portal === "consumer") && (clientIdentity?.full_name || clientIdentity?.organization)) {
      return clientIdentity.full_name || clientIdentity.organization || config.title;
    }
    return profile?.full_name || profile?.organization || user?.email?.split("@")[0] || config.title;
  }, [businessIdentity?.business_name, clientIdentity?.full_name, clientIdentity?.organization, config.title, portal, profile?.full_name, profile?.organization, user?.email]);
  const accountEmail = portal === "merchant"
    ? businessIdentity?.email || user?.email
    : portal === "client" || portal === "consumer"
      ? clientIdentity?.email || user?.email
      : user?.email;
  const accountLogo = portal === "merchant"
    ? publicAssetUrl(businessIdentity?.logo_url)
    : portal === "client" || portal === "consumer"
      ? publicAssetUrl(clientIdentity?.profile_photo_url || profile?.profile_photo_url)
      : null;
  const accountInitials = initialsFor(accountName, user?.email?.slice(0, 1).toUpperCase() ?? "A");
  const roleLabel = portal === "merchant"
    ? "Business Account"
    : portal === "client" || portal === "consumer"
      ? "Client Portal"
      : portal === "bir"
        ? "BIR Regulator"
        : portal === "super-admin"
          ? "Super Admin"
          : config.title;
  const profilePath = portal === "merchant"
    ? "/merchant/profile"
    : portal === "client" || portal === "consumer"
      ? "/client/profile"
      : portal === "bir"
        ? "/bir"
        : ["admin", "super_admin"].includes(profile?.role ?? "") ? "/super-admin/profile" : "/super-admin";
  const integrationPath = portal === "merchant" ? "/merchant/api-keys" : "/api";
  const securityPath = portal === "merchant" ? "/merchant/profile" : portal === "client" || portal === "consumer" ? "/client/security" : "/soc";
  const showUtilityAccountLinks = portal !== "client" && portal !== "consumer";

  useEffect(() => {
    if (portal !== "client" && portal !== "consumer") {
      setClientIdentity(null);
      return;
    }

    setClientIdentity({
      full_name: profile?.full_name,
      organization: profile?.organization,
      email: user?.email,
      profile_photo_url: profile?.profile_photo_url,
      status: null,
      tin: profile?.tin,
    });

    let cancelled = false;

    async function fetchClientIdentity() {
      try {
        const response = await apiFetch("/api/client/profile", {
          headers: authHeaders(),
          cache: "no-store",
        });
        const payload = await response.json().catch(() => ({}));
        const nextProfile = payload?.data?.profile ?? {};
        const nextAccount = payload?.data?.account ?? {};

        if (!cancelled && response.ok) {
          setClientIdentity((current) => ({
            ...(current ?? {}),
            full_name: nextProfile.full_name ?? nextAccount.full_name ?? current?.full_name,
            organization: nextProfile.organization ?? current?.organization,
            email: nextAccount.email ?? nextProfile.email ?? current?.email,
            profile_photo_url: nextProfile.profile_photo_url ?? current?.profile_photo_url,
            status: nextAccount.status ?? null,
            tin: nextAccount.tin ?? nextProfile.tin ?? current?.tin,
          }));
        }
      } catch {
        if (!cancelled) {
          setClientIdentity((current) => ({ ...(current ?? {}), status: null }));
        }
      }
    }

    fetchClientIdentity();

    function handleClientProfileUpdate(event: Event) {
      const detail = (event as CustomEvent<ClientIdentity>).detail;
      if (detail) {
        setClientIdentity((current) => ({ ...(current ?? {}), ...detail }));
      }
    }

    window.addEventListener("nuers:client-profile-updated", handleClientProfileUpdate);

    return () => {
      cancelled = true;
      window.removeEventListener("nuers:client-profile-updated", handleClientProfileUpdate);
    };
  }, [portal, profile?.full_name, profile?.organization, profile?.profile_photo_url, profile?.tin, user?.email]);

  useEffect(() => {
    let cancelled = false;

    async function fetchBusinessIdentity() {
      if (portal !== "merchant") {
        setBusinessIdentity(null);
        return;
      }

      try {
        const response = await apiFetch("/api/business-accounts/current", {
          headers: authHeaders(),
          cache: "no-store",
        });
        const payload = await response.json().catch(() => ({}));

        if (!cancelled && response.ok) {
          setBusinessIdentity(payload.data ?? null);
        }
      } catch {
        if (!cancelled) setBusinessIdentity(null);
      }
    }

    fetchBusinessIdentity();

    function handleBusinessProfileUpdate(event: Event) {
      const detail = (event as CustomEvent<BusinessIdentity>).detail;
      if (portal === "merchant" && detail) {
        setBusinessIdentity((current) => ({ ...(current ?? {}), ...detail }));
      }
    }

    window.addEventListener("nuers:business-profile-updated", handleBusinessProfileUpdate);

    return () => {
      cancelled = true;
      window.removeEventListener("nuers:business-profile-updated", handleBusinessProfileUpdate);
    };
  }, [portal, user?.id, user?.email]);

  async function handleSignOut() {
    await signOut();
    navigate("/login");
  }

  const enterprisePortal = portal === "merchant" ? "merchant" : portal === "client" || portal === "consumer" ? "citizen" : ["admin", "super-admin"].includes(portal) || ["admin", "super_admin"].includes(profile?.role ?? "") ? "admin" : null;
  if (enterprisePortal) return <EnterpriseShell portal={enterprisePortal} root={portal === "admin" ? "/admin" : "/super-admin"} accountName={accountName} accountEmail={accountEmail} accountLogo={accountLogo} initials={accountInitials} roleLabel={enterprisePortal === "admin" ? (profile?.role === "super_admin" ? "Super Administrator" : "System Administrator") : enterprisePortal === "merchant" ? "Business Account" : "Citizen Account"} profilePath={profilePath} notificationPath={enterprisePortal === "admin" ? "/super-admin/notifications" : notificationPath} accountActive={isClientAccountActive} onSignOut={handleSignOut} />;

  return (
    <SidebarProvider>
      <div className="flex h-dvh w-full overflow-hidden bg-background">
        <Sidebar collapsible="icon" className="border-sidebar-border/80">
          {/* Logo */}
          <SidebarHeader className="border-b border-sidebar-border/70 px-4 py-4">
            <div className="flex h-14 items-center gap-2">
              <img
                src="/brand/nuers-official-logo.png"
                alt="NUERS"
                className="h-11 max-w-[190px] object-contain group-data-[collapsible=icon]:hidden"
              />
              <img
                src="/app-icon-192.png?v=b50631c79afb"
                alt="NUERS"
                className="hidden h-8 w-auto object-contain group-data-[collapsible=icon]:block"
              />
            </div>
            <div className="rounded-md border border-sidebar-border/70 bg-sidebar-accent/40 px-3 py-2 group-data-[collapsible=icon]:hidden">
              <p className="text-[10px] uppercase tracking-wide text-sidebar-foreground/50">Current workspace</p>
              <p className="truncate text-sm font-semibold text-sidebar-foreground">{config.title}</p>
            </div>
          </SidebarHeader>

          <SidebarContent className="gap-1 px-2 py-3">
            {visibleGroups.map((group) => (
              <SidebarGroup key={group.section} className="px-0 py-1">
                <SidebarGroupLabel className="h-6 px-2 text-[10px] uppercase tracking-wide text-sidebar-foreground/45">
                  {group.section}
                </SidebarGroupLabel>
                <SidebarGroupContent>
                  <SidebarMenu>
                    {group.items.map((item) => {
                      const isActive = isNavItemActive(activeLocation, item.href);
                      return (
                        <SidebarMenuItem key={item.href + item.label}>
                          <SidebarMenuButton
                            asChild
                            isActive={isActive}
                            tooltip={item.label}
                            size="sm"
                            className="h-8 px-2 text-[13px]"
                          >
                            <Link to={item.href}>
                              <item.icon />
                              <span>{item.label}</span>
                            </Link>
                          </SidebarMenuButton>
                          {item.badge && (
                            <SidebarMenuBadge className="right-2 text-[10px] text-sidebar-foreground/45">{item.badge}</SidebarMenuBadge>
                          )}
                        </SidebarMenuItem>
                      );
                    })}
                  </SidebarMenu>
                </SidebarGroupContent>
              </SidebarGroup>
            ))}

            <SidebarGroup className="px-0 py-1">
              <SidebarGroupLabel className="h-6 px-2 text-[10px] uppercase tracking-wide text-sidebar-foreground/45">
                Tools
              </SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {visibleToolItems.map((item) => {
                    const isActive = isNavItemActive(activeLocation, item.href);
                    return (
                      <SidebarMenuItem key={item.href}>
                        <SidebarMenuButton asChild isActive={isActive} tooltip={item.label} size="sm" className="h-8 px-2 text-[13px]">
                          <Link to={item.href}>
                            <item.icon />
                            <span>{item.label}</span>
                          </Link>
                        </SidebarMenuButton>
                      </SidebarMenuItem>
                    );
                  })}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          </SidebarContent>

          {/* User footer */}
          <SidebarFooter className="border-t border-sidebar-border/70">
            <div className="flex items-center gap-3 px-2 py-2">
              <Avatar className="h-8 w-8 shrink-0 border border-sidebar-border bg-sidebar-primary text-sidebar-primary-foreground">
                {accountLogo && (
                  <AvatarImage
                    src={accountLogo}
                    alt={`${accountName} logo`}
                    className="object-cover"
                  />
                )}
                <AvatarFallback className="bg-sidebar-primary text-[10px] font-bold text-sidebar-primary-foreground">
                  {accountInitials}
                </AvatarFallback>
              </Avatar>
              <div className="flex-1 min-w-0 group-data-[collapsible=icon]:hidden">
                <p className="text-xs font-medium text-sidebar-foreground truncate">
                  {accountName}
                </p>
                <p className="text-[10px] text-sidebar-foreground/60 truncate">{displayEmail(accountEmail)}</p>
              </div>
              <Button
                variant="ghost"
                size="icon"
                className="h-7 w-7 shrink-0 text-sidebar-foreground/60 hover:text-sidebar-foreground group-data-[collapsible=icon]:hidden"
                onClick={handleSignOut}
                title="Sign out"
              >
                <LogOut className="h-3.5 w-3.5" />
              </Button>
            </div>
          </SidebarFooter>

          <SidebarRail />
        </Sidebar>

        {/* Main content */}
        <div className="flex flex-1 flex-col overflow-hidden min-w-0">
          {/* Header */}
          <header className="flex min-h-14 shrink-0 flex-wrap items-center justify-between gap-2 border-b bg-card px-3 py-2 sm:min-h-16 sm:flex-nowrap sm:px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
              <SidebarTrigger className="text-muted-foreground hover:text-foreground" />
              <Button
                variant="ghost"
                size="icon"
                className="hidden h-8 w-8 text-muted-foreground hover:text-foreground sm:inline-flex"
                onClick={() => navigate(-1)}
                title="Go back"
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <div className="relative hidden min-w-0 flex-1 md:block lg:max-w-sm">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder={portal === "client" ? "Search your transactions..." : "Search transactions, business accounts..."}
                  className="h-9 w-full pl-9 text-sm"
                />
              </div>
            </div>
            <div className="flex shrink-0 items-center gap-1.5 sm:gap-3">
              <Badge variant="secondary" className="hidden text-xs sm:inline-flex">
                <span className="mr-1.5 h-1.5 w-1.5 rounded-full bg-success animate-pulse" />
                System Online
              </Badge>
              <Link to={notificationPath}>
                <Button variant="ghost" size="icon" className="relative h-9 w-9">
                  <Bell className="h-4 w-4" />
                  <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-destructive" />
                </Button>
              </Link>
              <Separator orientation="vertical" className="hidden h-6 sm:block" />
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="sm" className="h-10 gap-2 rounded-xl px-2 text-sm sm:px-3">
                    <Avatar className="h-7 w-7 border bg-primary text-primary-foreground">
                      {accountLogo && <AvatarImage src={accountLogo} alt={`${accountName} logo`} className="object-cover" />}
                      <AvatarFallback className="bg-primary text-[10px] font-bold text-primary-foreground">
                        {accountInitials}
                      </AvatarFallback>
                    </Avatar>
                    <span className="hidden max-w-36 truncate font-semibold sm:inline">
                      {accountName}
                    </span>
                    <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-80 p-2">
                  <DropdownMenuLabel className="p-2">
                    <div className="flex items-start gap-3">
                      <Avatar className="h-12 w-12 rounded-xl border bg-primary text-primary-foreground">
                        {accountLogo && <AvatarImage src={accountLogo} alt={`${accountName} logo`} className="object-cover" />}
                        <AvatarFallback className="rounded-xl bg-primary text-sm font-bold text-primary-foreground">
                          {accountInitials}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-bold text-foreground">{accountName}</p>
                        <p className="truncate text-xs font-normal text-muted-foreground">{displayEmail(accountEmail)}</p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                          <Badge variant="secondary" className="text-[10px]">{roleLabel}</Badge>
                          {businessIdentity?.tin && <Badge variant="outline" className="font-mono text-[10px]">{businessIdentity.tin}</Badge>}
                          {businessIdentity?.status && <Badge variant="outline" className="text-[10px] capitalize">{businessIdentity.status.replace(/_/g, " ")}</Badge>}
                        </div>
                      </div>
                    </div>
                  </DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={() => navigate(profilePath)}>
                    <UserCheck className="mr-2 h-4 w-4" />
                    Profile and account details
                  </DropdownMenuItem>
                  {showUtilityAccountLinks && (
                    <DropdownMenuItem onClick={() => navigate(integrationPath)}>
                      <Key className="mr-2 h-4 w-4" />
                      API and integration center
                    </DropdownMenuItem>
                  )}
                  <DropdownMenuItem onClick={() => navigate(notificationPath)}>
                    <Bell className="mr-2 h-4 w-4" />
                    Notifications
                  </DropdownMenuItem>
                  {showUtilityAccountLinks && (
                    <>
                      <DropdownMenuItem onClick={() => navigate("/verify")}>
                        <QrCode className="mr-2 h-4 w-4" />
                        Verify receipt
                      </DropdownMenuItem>
                      <DropdownMenuItem onClick={() => navigate(securityPath)}>
                        <ShieldCheck className="mr-2 h-4 w-4" />
                        Security and compliance
                      </DropdownMenuItem>
                    </>
                  )}
                  <DropdownMenuSeparator />
                  <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={handleSignOut}>
                    <LogOut className="mr-2 h-4 w-4" />
                    Sign out
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </header>

          {/* Page content */}
          <main className="dashboard-main flex-1 overflow-y-auto overflow-x-hidden bg-background p-3 sm:p-4 lg:p-6">
            <PageBoundary><Outlet /></PageBoundary>
          </main>
        </div>
      </div>
    </SidebarProvider>
  );
}
