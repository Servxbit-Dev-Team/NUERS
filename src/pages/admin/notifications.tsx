import { useEffect, useState } from "react";
import {
  Bell, ShieldAlert, Clock, Trophy,
  Info, CheckCheck, RefreshCw,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { supabase } from "@/lib/supabase";
import { cn } from "@/lib/utils";

type Notification = {
  id: string;
  title: string;
  message: string;
  type: string;
  priority: string;
  is_read: boolean;
  created_at: string;
};

const typeConfig: Record<string, { icon: React.ElementType; bg: string; iconColor: string }> = {
  risk: { icon: ShieldAlert, bg: "bg-destructive/10", iconColor: "text-destructive" },
  deadline: { icon: Clock, bg: "bg-warning/10", iconColor: "text-warning" },
  achievement: { icon: Trophy, bg: "bg-success/10", iconColor: "text-success" },
  system: { icon: Info, bg: "bg-secondary", iconColor: "text-muted-foreground" },
  info: { icon: Info, bg: "bg-secondary", iconColor: "text-muted-foreground" },
};

const priorityVariant: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  critical: "destructive",
  high: "secondary",
  medium: "outline",
  low: "outline",
};

export function AdminNotifications() {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [priorityFilter, setPriorityFilter] = useState("all");

  useEffect(() => {
    fetchNotifications();
  }, []);

  async function fetchNotifications() {
    setLoading(true);
    setError("");
    const { data, error } = await supabase
      .from("notifications")
      .select("*")
      .order("created_at", { ascending: false });
    if (error) setError(error.message);
    else setNotifications(data ?? []);
    setLoading(false);
  }

  async function markAllRead() {
    const { error } = await supabase.from("notifications").update({ is_read: true }).eq("is_read", false);
    if (error) { setError(error.message); return; }
    setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true })));
  }

  async function markRead(id: string) {
    const { error } = await supabase.from("notifications").update({ is_read: true }).eq("id", id);
    if (error) { setError(error.message); return; }
    setNotifications((prev) => prev.map((n) => (n.id === id ? { ...n, is_read: true } : n)));
  }

  const filtered = notifications.filter(
    (n) => priorityFilter === "all" || n.priority === priorityFilter
  );
  const unreadCount = notifications.filter((n) => !n.is_read).length;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Notifications & Alerts</h1>
          <p className="text-sm text-muted-foreground">
            System alerts, compliance deadlines, and risk notifications
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {unreadCount > 0 && (
            <Badge variant="default" className="gap-1">
              <Bell className="h-3 w-3" /> {unreadCount} unread
            </Badge>
          )}
          <Button variant="outline" size="sm" className="gap-2" onClick={markAllRead}>
            <CheckCheck className="h-4 w-4" /> Mark All Read
          </Button>
          <Button variant="outline" size="sm" className="gap-2" disabled={loading} onClick={() => void fetchNotifications()}><RefreshCw className="h-4 w-4" />Refresh</Button>
        </div>
      </div>

      {error && <p role="alert" className="text-red-600">{error}</p>}
      <p className="text-sm text-muted-foreground">Notifications are preserved. Marking a notification read does not delete its history. Latest records shown.</p>
      {/* Priority summary */}
      <div className="grid gap-4 sm:grid-cols-4">
        {[
          { label: "Critical", count: notifications.filter((n) => n.priority === "critical").length, color: "border-l-destructive" },
          { label: "High", count: notifications.filter((n) => n.priority === "high").length, color: "border-l-warning" },
          { label: "Medium", count: notifications.filter((n) => n.priority === "medium").length, color: "border-l-primary" },
          { label: "Low", count: notifications.filter((n) => n.priority === "low").length, color: "border-l-border" },
        ].map((s) => (
          <Card key={s.label} className={`border-l-4 ${s.color} cursor-pointer`} onClick={() => setPriorityFilter(s.label.toLowerCase())}>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">{s.label} Priority</p>
              <p className="mt-1 text-2xl font-bold text-foreground">{s.count}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardContent className="p-5">
          <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 className="text-sm font-semibold text-foreground">
              {priorityFilter === "all" ? "All Notifications" : `${priorityFilter.charAt(0).toUpperCase() + priorityFilter.slice(1)} Priority`}
            </h3>
            <div className="flex w-full flex-wrap gap-2 sm:w-auto">
              <Select value={priorityFilter} onValueChange={setPriorityFilter}>
                <SelectTrigger className="w-full sm:w-36">
                  <SelectValue placeholder="Priority" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Priorities</SelectItem>
                  <SelectItem value="critical">Critical</SelectItem>
                  <SelectItem value="high">High</SelectItem>
                  <SelectItem value="medium">Medium</SelectItem>
                  <SelectItem value="low">Low</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {loading ? (
            <div className="space-y-3">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-20 animate-pulse rounded-lg bg-muted" />
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="flex flex-col items-center py-12 text-center">
              <Bell className="h-10 w-10 text-muted-foreground" />
              <p className="mt-3 text-sm font-medium text-foreground">No notifications</p>
              <p className="text-xs text-muted-foreground">You're all caught up!</p>
            </div>
          ) : (
            <div className="space-y-2">
              {filtered.map((notif) => {
                const tc = typeConfig[notif.type] ?? typeConfig.info;
                const Icon = tc.icon;
                return (
                  <div
                    key={notif.id}
                    onClick={() => !notif.is_read && markRead(notif.id)}
                    className={cn(
                      "flex items-start gap-4 rounded-lg border p-4 transition-colors cursor-pointer",
                      notif.is_read
                        ? "bg-background hover:bg-muted/30"
                        : "bg-primary/5 border-primary/20 hover:bg-primary/10"
                    )}
                  >
                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tc.bg}`}>
                      <Icon className={`h-5 w-5 ${tc.iconColor}`} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <div className="flex items-center gap-2 flex-wrap">
                            <p className={cn("text-sm font-medium", notif.is_read ? "text-foreground" : "text-foreground")}>
                              {notif.title}
                            </p>
                            {!notif.is_read && (
                              <span className="h-1.5 w-1.5 rounded-full bg-primary" />
                            )}
                          </div>
                          <p className="mt-0.5 text-xs text-muted-foreground leading-relaxed">{notif.message}</p>
                        </div>
                        <div className="flex items-center gap-2 shrink-0">
                          <Badge variant={priorityVariant[notif.priority] ?? "outline"} className="text-[10px]">
                            {notif.priority}
                          </Badge>

                        </div>
                      </div>
                      <div className="mt-2 flex items-center gap-3">
                        <Badge variant="outline" className="text-[10px]">{notif.type}</Badge>
                        <span className="text-[10px] text-muted-foreground">
                          {new Date(notif.created_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })}
                        </span>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
