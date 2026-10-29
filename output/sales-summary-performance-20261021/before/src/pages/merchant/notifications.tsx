import { useState, useMemo, useEffect } from "react";
import {
  Bell, CheckCircle2, AlertTriangle, Info, Zap,
  Trash2, Check, Settings, Filter,
  TrendingUp, ShieldAlert, CreditCard, Activity,
  ExternalLink, ChevronRight, BellOff, RefreshCw,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useAuth } from "@/lib/auth";

type Priority = "critical" | "high" | "medium" | "low";
type NotifType = "warning" | "success" | "info" | "alert";
type Category = "compliance" | "billing" | "system" | "transaction" | "security";

type Notification = {
  id: string;
  type: NotifType;
  priority: Priority;
  title: string;
  message: string;
  time: string;
  relativeTime: string;
  read: boolean;
  category: Category;
  action?: { label: string; href?: string };
  pinned?: boolean;
};

const NOTIFICATIONS: Notification[] = [
  {
    id: "n1", type: "alert", priority: "critical",
    title: "Unusual Transaction Volume Detected",
    message: "Transaction volume at Cebu branch is 340% above normal (2,847 txns vs avg 836). System has flagged 12 transactions for manual review. Immediate verification recommended.",
    time: "May 27, 2026 09:42 AM", relativeTime: "23 min ago", read: false, category: "security",
    action: { label: "Review Transactions" }, pinned: true,
  },
  {
    id: "n2", type: "warning", priority: "high",
    title: "VAT Filing Due in 5 Days",
    message: "Q2 2026 VAT filing (BIR Form 2550Q) is due July 25, 2026. Total VAT payable: ₱3,202,000. Penalty for late filing: ₱25,000 + 25% surcharge.",
    time: "May 27, 2026 09:00 AM", relativeTime: "1 hr ago", read: false, category: "compliance",
    action: { label: "File Now" },
  },
  {
    id: "n3", type: "warning", priority: "high",
    title: "API Rate Limit Warning — 87% Used",
    message: "Production API key LIVE-xxx...abc has used 43,500 of 50,000 daily requests. At current rate, you will hit the limit in approximately 2 hours.",
    time: "May 27, 2026 08:55 AM", relativeTime: "1 hr ago", read: false, category: "system",
    action: { label: "Manage API Keys" },
  },
  {
    id: "n4", type: "success", priority: "medium",
    title: "Monthly Invoice Paid",
    message: "Invoice INV-2026-0006 for May 2026 has been marked as paid. Amount: ₱4,850. Payment confirmed via BancNet.",
    time: "May 27, 2026 08:30 AM", relativeTime: "2 hrs ago", read: false, category: "billing",
    action: { label: "View Invoice" },
  },
  {
    id: "n5", type: "alert", priority: "high",
    title: "POS Device Offline — Lobby Kiosk",
    message: "KSK-2024-001 at Main Lobby has been offline for 3 hours and 42 minutes. 18 transactions may have been affected. Device last seen: 06:10 AM.",
    time: "May 27, 2026 07:52 AM", relativeTime: "3 hrs ago", read: false, category: "system",
    action: { label: "Diagnose Device" },
  },
  {
    id: "n6", type: "info", priority: "medium",
    title: "New API Policy Update Effective June 1",
    message: "Updated rate limiting policy takes effect June 1, 2026. Live keys limited to 50,000 requests/day (up from 30,000). Test keys unchanged.",
    time: "May 26, 2026 02:15 PM", relativeTime: "Yesterday", read: true, category: "system",
  },
  {
    id: "n7", type: "success", priority: "low",
    title: "Compliance Score Improved to 94",
    message: "Your compliance score improved from 89 to 94. Annual ITR (2025) was accepted and all required BIR documents are now on file.",
    time: "May 25, 2026 11:00 AM", relativeTime: "2 days ago", read: true, category: "compliance",
  },
  {
    id: "n8", type: "info", priority: "low",
    title: "Scheduled Maintenance — June 1, 2:00–4:00 AM",
    message: "NUERS platform maintenance scheduled for Sunday, June 1, 2026, 2:00–4:00 AM PHT. API endpoints will be unavailable. Plan accordingly.",
    time: "May 24, 2026 10:00 AM", relativeTime: "3 days ago", read: true, category: "system",
  },
  {
    id: "n9", type: "warning", priority: "medium",
    title: "Document Expiry — Business Permit (90 Days)",
    message: "Your Pasay City Business Permit expires December 31, 2026. Begin renewal process by November to avoid processing delays.",
    time: "May 23, 2026 09:00 AM", relativeTime: "4 days ago", read: true, category: "compliance",
    action: { label: "View Documents" },
  },
  {
    id: "n10", type: "success", priority: "low",
    title: "Q1 2026 Summary Report Available",
    message: "Your Q1 2026 performance report is ready for download. Total revenue: ₱38.4M (+12.3% YoY). VAT collected: ₱4.6M.",
    time: "May 20, 2026 08:00 AM", relativeTime: "1 week ago", read: true, category: "compliance",
    action: { label: "Download Report" },
  },
  {
    id: "n11", type: "alert", priority: "critical",
    title: "Failed Login Attempts — Admin Panel",
    message: "5 consecutive failed login attempts detected on your account from IP 203.177.xx.xx (Manila, PH). Your account has been temporarily locked. Please reset your password.",
    time: "May 18, 2026 03:14 AM", relativeTime: "9 days ago", read: true, category: "security",
    action: { label: "Secure Account" }, pinned: false,
  },
  {
    id: "n12", type: "success", priority: "low",
    title: "New Branch Successfully Onboarded",
    message: "Makati CBD branch has been successfully registered and connected to NUERS. 3 POS terminals are now active and processing transactions.",
    time: "May 15, 2026 11:00 AM", relativeTime: "12 days ago", read: true, category: "transaction",
  },
];

const priorityConfig: Record<Priority, { label: string; color: string; dotColor: string }> = {
  critical: { label: "Critical", color: "text-destructive", dotColor: "bg-destructive" },
  high: { label: "High", color: "text-warning", dotColor: "bg-warning" },
  medium: { label: "Medium", color: "text-primary", dotColor: "bg-primary" },
  low: { label: "Low", color: "text-muted-foreground", dotColor: "bg-muted-foreground" },
};

const typeConfig: Record<NotifType, { icon: React.ElementType; color: string; bg: string }> = {
  warning: { icon: AlertTriangle, color: "text-warning", bg: "bg-warning/10 border-warning/20" },
  success: { icon: CheckCircle2, color: "text-success", bg: "bg-success/10 border-success/20" },
  info: { icon: Info, color: "text-primary", bg: "bg-primary/10 border-primary/20" },
  alert: { icon: Zap, color: "text-destructive", bg: "bg-destructive/10 border-destructive/20" },
};

const categoryConfig: Record<Category, { icon: React.ElementType; label: string }> = {
  compliance: { icon: CheckCircle2, label: "Compliance" },
  billing: { icon: CreditCard, label: "Billing" },
  system: { icon: Activity, label: "System" },
  transaction: { icon: TrendingUp, label: "Transactions" },
  security: { icon: ShieldAlert, label: "Security" },
};

const notifPreferences = [
  { key: "vat_reminders", label: "VAT Filing Reminders", desc: "Due date alerts and penalty warnings for BIR filings", email: true, push: true, sms: true },
  { key: "invoice_alerts", label: "Invoice & Billing", desc: "New invoices, payment confirmations, overdue notices", email: true, push: false, sms: false },
  { key: "compliance_updates", label: "Compliance Updates", desc: "Score changes, document expiry, audit notifications", email: true, push: true, sms: false },
  { key: "transaction_alerts", label: "Transaction Anomalies", desc: "Unusual volume, risk flags, and high-value alerts", email: false, push: true, sms: true },
  { key: "api_alerts", label: "API & Rate Limits", desc: "Rate limit warnings, key expiry, and endpoint alerts", email: true, push: true, sms: false },
  { key: "security_alerts", label: "Security Alerts", desc: "Failed logins, new devices, and access changes", email: true, push: true, sms: true },
  { key: "system_maintenance", label: "Maintenance Notices", desc: "Scheduled downtime and platform update announcements", email: true, push: false, sms: false },
  { key: "marketing", label: "Product & Feature Updates", desc: "New features, enhancements, and newsletter", email: false, push: false, sms: false },
];

type GroupedNotifs = Record<string, Notification[]>;

function groupByDate(notifications: Notification[]): GroupedNotifs {
  const groups: GroupedNotifs = {};
  for (const n of notifications) {
    const key = n.relativeTime.startsWith("Today") || n.relativeTime.includes("min") || n.relativeTime.includes("hr")
      ? "Today"
      : n.relativeTime === "Yesterday" ? "Yesterday"
      : n.relativeTime.includes("day") ? "This Week"
      : "Older";
    if (!groups[key]) groups[key] = [];
    groups[key].push(n);
  }
  return groups;
}

type StoredNotificationState = {
  readIds: string[];
  deletedIds: string[];
};

const NOTIFICATION_STORAGE_PREFIX = "nuers:merchant-notifications:v1";

function notificationStorageKey(scope?: string | null) {
  return `${NOTIFICATION_STORAGE_PREFIX}:${scope || "business-account"}`;
}

function readStoredNotificationState(scope?: string | null): StoredNotificationState {
  if (typeof window === "undefined") return { readIds: [], deletedIds: [] };

  try {
    const raw = window.localStorage.getItem(notificationStorageKey(scope));
    if (!raw) return { readIds: [], deletedIds: [] };

    const parsed = JSON.parse(raw) as Partial<StoredNotificationState>;
    return {
      readIds: Array.isArray(parsed.readIds) ? parsed.readIds.filter(Boolean) : [],
      deletedIds: Array.isArray(parsed.deletedIds) ? parsed.deletedIds.filter(Boolean) : [],
    };
  } catch {
    return { readIds: [], deletedIds: [] };
  }
}

function writeStoredNotificationState(scope: string | null | undefined, state: StoredNotificationState) {
  if (typeof window === "undefined") return;

  try {
    window.localStorage.setItem(notificationStorageKey(scope), JSON.stringify(state));
  } catch {
    // Storage can fail in private/restricted contexts; the page still works for the current session.
  }
}

function applyStoredNotificationState(scope?: string | null): Notification[] {
  const stored = readStoredNotificationState(scope);
  const readIds = new Set(stored.readIds);
  const deletedIds = new Set(stored.deletedIds);

  return NOTIFICATIONS
    .filter((notification) => !deletedIds.has(notification.id))
    .map((notification) => readIds.has(notification.id) ? { ...notification, read: true } : notification);
}

function persistNotificationState(scope: string | null | undefined, notifications: Notification[]) {
  const visibleIds = new Set(notifications.map((notification) => notification.id));

  const state = {
    readIds: notifications.filter((notification) => notification.read).map((notification) => notification.id),
    deletedIds: NOTIFICATIONS
      .filter((notification) => !visibleIds.has(notification.id))
      .map((notification) => notification.id),
  };

  writeStoredNotificationState(scope, state);

  if (scope !== "business-account") {
    writeStoredNotificationState("business-account", state);
  }
}

export function MerchantNotifications() {
  const { user } = useAuth();
  const notificationScope = user?.id || user?.email || "business-account";
  const [notifications, setNotifications] = useState<Notification[]>(() => applyStoredNotificationState("business-account"));
  const [priorityFilter, setPriorityFilter] = useState<string>("all");
  const [prefs, setPrefs] = useState(
    Object.fromEntries(notifPreferences.map((p) => [p.key, { email: p.email, push: p.push, sms: p.sms }]))
  );

  useEffect(() => {
    setNotifications(applyStoredNotificationState(notificationScope));
  }, [notificationScope]);

  const unread = notifications.filter((n) => !n.read).length;
  const critical = notifications.filter((n) => n.priority === "critical" && !n.read).length;

  function updateNotifications(updater: (current: Notification[]) => Notification[]) {
    setNotifications((current) => {
      const next = updater(current);
      persistNotificationState(notificationScope, next);
      return next;
    });
  }

  function markRead(id: string) {
    updateNotifications((prev) => prev.map((n) => n.id === id ? { ...n, read: true } : n));
  }

  function markAllRead() {
    updateNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
    toast.success("All notifications marked as read.");
  }

  function deleteNotif(id: string) {
    updateNotifications((prev) => prev.filter((n) => n.id !== id));
  }

  function clearRead() {
    updateNotifications((prev) => prev.filter((n) => !n.read));
    toast.success("Read notifications cleared.");
  }

  function syncStoredNotifications() {
    setNotifications(applyStoredNotificationState(notificationScope));
    toast.success("Notifications synced.");
  }

  function filterNotifs(category: string) {
    let base = category === "all" ? notifications : notifications.filter((n) => n.category === category);
    if (priorityFilter !== "all") base = base.filter((n) => n.priority === priorityFilter);
    return base;
  }

  const categoryCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const n of notifications) {
      if (!n.read) counts[n.category] = (counts[n.category] || 0) + 1;
    }
    return counts;
  }, [notifications]);

  function NotifList({ items }: { items: Notification[] }) {
    if (items.length === 0) {
      return (
        <div className="py-16 text-center text-muted-foreground">
          <BellOff className="h-10 w-10 mx-auto mb-3 opacity-30" />
          <p className="text-sm font-medium">No notifications</p>
          <p className="text-xs mt-1">You're all caught up.</p>
        </div>
      );
    }

    const grouped = groupByDate(items);
    const groupOrder = ["Today", "Yesterday", "This Week", "Older"];

    return (
      <div className="space-y-6">
        {groupOrder.filter((g) => grouped[g]?.length).map((group) => (
          <div key={group}>
            <div className="flex items-center gap-3 mb-3">
              <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{group}</p>
              <div className="flex-1 h-px bg-border" />
            </div>
            <div className="space-y-2">
              {grouped[group].map((n) => {
                const tc = typeConfig[n.type];
                const pc = priorityConfig[n.priority];
                return (
                  <div
                    key={n.id}
                    className={cn(
                      "group relative flex items-start gap-3 rounded-lg border p-4 transition-all cursor-pointer",
                      !n.read ? tc.bg : "bg-background hover:bg-muted/20 border-border",
                      n.pinned && !n.read && "ring-1 ring-destructive/30",
                    )}
                    onClick={() => markRead(n.id)}
                  >
                    {/* Priority Indicator */}
                    {!n.read && (
                      <div className={cn("absolute left-0 top-0 bottom-0 w-1 rounded-l-lg", pc.dotColor)} />
                    )}

                    <div className={cn("mt-0.5 rounded-full p-1.5 shrink-0", !n.read ? tc.bg : "bg-muted")}>
                      <tc.icon className={cn("h-3.5 w-3.5", !n.read ? tc.color : "text-muted-foreground")} />
                    </div>

                    <div className="flex-1 min-w-0 pl-1">
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2 flex-wrap">
                          <p className={cn("text-sm font-semibold leading-tight", !n.read ? "text-foreground" : "text-muted-foreground font-medium")}>
                            {n.title}
                          </p>
                          {!n.read && <span className="h-1.5 w-1.5 rounded-full bg-primary shrink-0" />}
                        </div>
                        <span className="text-[10px] text-muted-foreground whitespace-nowrap shrink-0">{n.relativeTime}</span>
                      </div>

                      <p className="text-xs text-muted-foreground mt-1 leading-relaxed">{n.message}</p>

                      <div className="flex items-center gap-2 mt-2 flex-wrap">
                        <Badge variant="secondary" className="text-[10px] gap-1 capitalize">
                          {(() => { const C = categoryConfig[n.category]; return <><C.icon className="h-2.5 w-2.5" />{C.label}</>; })()}
                        </Badge>
                        <Badge
                          variant="outline"
                          className={cn("text-[10px]", pc.color, "border-current/30")}
                        >
                          {pc.label}
                        </Badge>
                        {n.action && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-5 px-2 text-[10px] gap-1 text-primary hover:text-primary ml-1"
                            onClick={(e) => { e.stopPropagation(); toast.info(`Opening: ${n.action!.label}`); }}
                          >
                            {n.action.label} <ChevronRight className="h-2.5 w-2.5" />
                          </Button>
                        )}
                      </div>
                    </div>

                    <Button
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 shrink-0 text-muted-foreground hover:text-destructive opacity-0 group-hover:opacity-100 transition-opacity"
                      onClick={(e) => { e.stopPropagation(); deleteNotif(n.id); }}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Critical Alert Banner */}
      {critical > 0 && (
        <div className="flex items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
          <ShieldAlert className="h-5 w-5 text-destructive shrink-0" />
          <div className="flex-1">
            <p className="text-sm font-semibold text-foreground">
              {critical} critical alert{critical > 1 ? "s" : ""} require{critical === 1 ? "s" : ""} immediate attention
            </p>
            <p className="text-xs text-muted-foreground">Review and resolve to maintain platform standing.</p>
          </div>
          <Button size="sm" variant="destructive" className="text-xs gap-1.5" onClick={() => setPriorityFilter("critical")}>
            <ExternalLink className="h-3.5 w-3.5" /> View Critical
          </Button>
        </div>
      )}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Notifications</h1>
          <p className="text-sm text-muted-foreground">
            {unread > 0 ? <><span className="font-semibold text-foreground">{unread} unread</span> · </> : ""}
            {notifications.length} total
          </p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <div className="flex w-full items-center gap-1.5 sm:w-auto">
            <Filter className="h-3.5 w-3.5 text-muted-foreground" />
            <Select value={priorityFilter} onValueChange={setPriorityFilter}>
              <SelectTrigger className="h-8 w-full text-xs sm:w-32">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All priorities</SelectItem>
                <SelectItem value="critical">Critical</SelectItem>
                <SelectItem value="high">High</SelectItem>
                <SelectItem value="medium">Medium</SelectItem>
                <SelectItem value="low">Low</SelectItem>
              </SelectContent>
            </Select>
          </div>
          {unread > 0 && (
            <Button variant="outline" size="sm" className="gap-2 text-xs h-8" onClick={markAllRead}>
              <Check className="h-3.5 w-3.5" /> Mark All Read
            </Button>
          )}
          <Button variant="outline" size="sm" className="gap-2 text-xs h-8" onClick={clearRead}>
            <Trash2 className="h-3.5 w-3.5" /> Clear Read
          </Button>
          <Button variant="ghost" size="sm" className="gap-2 text-xs h-8" onClick={syncStoredNotifications}>
            <RefreshCw className="h-3.5 w-3.5" />
          </Button>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        {(["all", "security", "compliance", "transaction", "system"] as const).map((cat) => {
          const count = cat === "all" ? unread : (categoryCounts[cat] || 0);
          const cfg = cat !== "all" ? categoryConfig[cat] : null;
          return (
            <Card
              key={cat}
              className={cn("cursor-pointer transition-colors hover:bg-muted/30", count > 0 && "border-primary/30")}
              onClick={() => {}}
            >
              <CardContent className="p-3 flex items-center gap-2">
                {cfg ? <cfg.icon className="h-4 w-4 text-muted-foreground" /> : <Bell className="h-4 w-4 text-muted-foreground" />}
                <div>
                  <p className="text-xs text-muted-foreground capitalize">{cat === "all" ? "Total Unread" : cfg?.label}</p>
                  <p className={cn("text-sm font-bold", count > 0 ? "text-foreground" : "text-muted-foreground")}>{count}</p>
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <Tabs defaultValue="all">
        <TabsList className="flex-wrap h-auto gap-1">
          <TabsTrigger value="all" className="gap-2">
            All
            {unread > 0 && <Badge variant="destructive" className="h-4 min-w-4 px-1 text-[10px]">{unread}</Badge>}
          </TabsTrigger>
          {(["security", "compliance", "billing", "transaction", "system"] as const).map((cat) => (
            <TabsTrigger key={cat} value={cat} className="gap-1.5 capitalize">
              {categoryConfig[cat].label}
              {(categoryCounts[cat] || 0) > 0 && (
                <Badge variant="destructive" className="h-4 min-w-4 px-1 text-[10px]">{categoryCounts[cat]}</Badge>
              )}
            </TabsTrigger>
          ))}
          <TabsTrigger value="settings" className="gap-1.5">
            <Settings className="h-3.5 w-3.5" /> Settings
          </TabsTrigger>
        </TabsList>

        {(["all", "security", "compliance", "billing", "transaction", "system"] as const).map((tab) => (
          <TabsContent key={tab} value={tab} className="mt-4">
            <NotifList items={filterNotifs(tab)} />
          </TabsContent>
        ))}

        {/* Settings Tab */}
        <TabsContent value="settings" className="mt-4">
          <div className="grid gap-4 lg:grid-cols-3">
            <div className="lg:col-span-2">
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex items-center gap-2">
                    <Settings className="h-4 w-4 text-primary" />
                    <CardTitle className="text-sm">Notification Preferences</CardTitle>
                  </div>
                  <p className="text-xs text-muted-foreground">Choose how you receive each type of notification</p>
                </CardHeader>
                <CardContent>
                  <div className="overflow-x-auto">
                    <table className="w-full">
                      <thead>
                        <tr className="border-b">
                          <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Notification Type</th>
                          <th className="pb-3 text-center text-xs font-medium text-muted-foreground">Email</th>
                          <th className="pb-3 text-center text-xs font-medium text-muted-foreground">In-App</th>
                          <th className="pb-3 text-center text-xs font-medium text-muted-foreground">SMS</th>
                        </tr>
                      </thead>
                      <tbody>
                        {notifPreferences.map((p) => (
                          <tr key={p.key} className="border-b last:border-0">
                            <td className="py-3 pr-4">
                              <p className="text-sm font-medium text-foreground">{p.label}</p>
                              <p className="text-xs text-muted-foreground">{p.desc}</p>
                            </td>
                            <td className="py-3 text-center">
                              <Switch
                                checked={prefs[p.key]?.email}
                                onCheckedChange={(v) => setPrefs({ ...prefs, [p.key]: { ...prefs[p.key], email: v } })}
                              />
                            </td>
                            <td className="py-3 text-center">
                              <Switch
                                checked={prefs[p.key]?.push}
                                onCheckedChange={(v) => setPrefs({ ...prefs, [p.key]: { ...prefs[p.key], push: v } })}
                              />
                            </td>
                            <td className="py-3 text-center">
                              <Switch
                                checked={prefs[p.key]?.sms}
                                onCheckedChange={(v) => setPrefs({ ...prefs, [p.key]: { ...prefs[p.key], sms: v } })}
                              />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <div className="mt-4">
                    <Button onClick={() => toast.success("Notification preferences saved.")} size="sm">
                      Save Preferences
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </div>

            <div className="space-y-4">
              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Quiet Hours</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-foreground">Enable Quiet Hours</p>
                      <p className="text-xs text-muted-foreground">Silence non-critical alerts</p>
                    </div>
                    <Switch />
                  </div>
                  <Separator />
                  <div className="grid grid-cols-2 gap-2">
                    <div className="space-y-1">
                      <p className="text-xs text-muted-foreground">From</p>
                      <Select defaultValue="22">
                        <SelectTrigger className="text-xs h-8"><SelectValue /></SelectTrigger>
                        <SelectContent>
                          {Array.from({ length: 24 }, (_, i) => (
                            <SelectItem key={i} value={String(i)}>{String(i).padStart(2, "0")}:00</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-1">
                      <p className="text-xs text-muted-foreground">Until</p>
                      <Select defaultValue="8">
                        <SelectTrigger className="text-xs h-8"><SelectValue /></SelectTrigger>
                        <SelectContent>
                          {Array.from({ length: 24 }, (_, i) => (
                            <SelectItem key={i} value={String(i)}>{String(i).padStart(2, "0")}:00</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                  <p className="text-[10px] text-muted-foreground">Critical security alerts always go through.</p>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Digest Settings</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-foreground">Daily Digest</p>
                      <p className="text-xs text-muted-foreground">Summary email at 8:00 AM</p>
                    </div>
                    <Switch defaultChecked />
                  </div>
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-foreground">Weekly Report</p>
                      <p className="text-xs text-muted-foreground">Every Monday at 9:00 AM</p>
                    </div>
                    <Switch defaultChecked />
                  </div>
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-foreground">Compliance Reminders</p>
                      <p className="text-xs text-muted-foreground">7 days before filing due</p>
                    </div>
                    <Switch defaultChecked />
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Notification Stats</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {[
                    { label: "This week", value: "12 received" },
                    { label: "Avg response time", value: "14 min" },
                    { label: "Action rate", value: "78%" },
                    { label: "Critical resolved", value: "3 of 3" },
                  ].map((s) => (
                    <div key={s.label} className="flex items-center justify-between text-xs">
                      <span className="text-muted-foreground">{s.label}</span>
                      <span className="font-medium text-foreground">{s.value}</span>
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
