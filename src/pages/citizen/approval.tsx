import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { formatTin } from "@/lib/tin";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  AlertCircle,
  CheckCircle2,
  Clock3,
  Fingerprint,
  Mail,
  Phone,
  RefreshCw,
  Search,
  ShieldCheck,
  UserCheck,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { apiFetch, publicAssetUrl, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

type CitizenStatus = "active" | "pending" | "under_review" | "rejected" | string;

type CitizenApprovalRow = {
  id: string;
  user_id: number | null;
  account_number: string;
  full_name: string;
  account_type: string;
  email: string | null;
  mobile: string | null;
  status: CitizenStatus;
  mfa_enabled: boolean;
  wallet_balance: number;
  tin: string | null;
  tin_bound_at: string | null;
  profile_photo_url: string | null;
  created_at: string | null;
  updated_at: string | null;
};

type CitizenApprovalSummary = {
  total: number;
  pending: number;
  active: number;
  rejected: number;
};

type CitizenApprovalPayload = {
  data: CitizenApprovalRow[];
  summary: CitizenApprovalSummary;
};

type CitizenApprovalUpdatePayload = {
  data: CitizenApprovalRow;
  summary: CitizenApprovalSummary;
};

const emptySummary: CitizenApprovalSummary = {
  total: 0,
  pending: 0,
  active: 0,
  rejected: 0,
};

const statusMeta: Record<string, { label: string; icon: typeof CheckCircle2; className: string }> =
  {
    active: {
      label: "Active",
      icon: CheckCircle2,
      className: "border-success/30 bg-success/10 text-success",
    },
    pending: {
      label: "Pending",
      icon: Clock3,
      className: "border-warning/30 bg-warning/10 text-warning-foreground",
    },
    under_review: {
      label: "Under Review",
      icon: ShieldCheck,
      className: "border-primary/30 bg-primary/10 text-primary",
    },
    rejected: {
      label: "Rejected",
      icon: XCircle,
      className: "border-destructive/30 bg-destructive/10 text-destructive",
    },
  };

function statusLabel(value: string) {
  return (
    statusMeta[value]?.label ??
    value.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase())
  );
}

function initialsFor(value?: string | null) {
  return (
    (value || "CA")
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0])
      .join("")
      .toUpperCase() || "CA"
  );
}

function dateLabel(value?: string | null) {
  if (!value) return "Not recorded";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

function CitizenStatusBadge({ status }: { status: string }) {
  const meta = statusMeta[status] ?? statusMeta.pending;
  const Icon = meta.icon;

  return (
    <Badge variant="outline" className={cn("gap-1 text-[10px]", meta.className)}>
      <Icon className="h-3 w-3" />
      {statusLabel(status)}
    </Badge>
  );
}

export function CitizenApprovalPage({ scope }: { scope: "bir" | "super-admin" }) {
  const [rows, setRows] = useState<CitizenApprovalRow[]>([]);
  const [summary, setSummary] = useState<CitizenApprovalSummary>(emptySummary);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savingId, setSavingId] = useState<string | null>(null);

  const reviewRows = useMemo(
    () => rows.filter((row) => ["pending", "under_review"].includes(row.status)),
    [rows],
  );

  const sequence = useRef(0);
  useRealtimeRefresh(() => loadApprovals({ quiet: true }), !savingId);

  async function loadApprovals(options: { quiet?: boolean } = {}) {
    const ticket = ++sequence.current;
    if (!options.quiet) {
      setLoading(true);
    }
    setError(null);

    try {
      const params = new URLSearchParams();
      if (search.trim()) params.set("search", search.trim());
      if (status !== "all") params.set("status", status);

      const response = await apiFetch(
        `/api/citizen-approvals${params.toString() ? `?${params}` : ""}`,
        {
          cache: "no-store",
        },
      );
      const payload = await readJsonResponse<CitizenApprovalPayload>(
        response,
        "Unable to load citizen approvals.",
      );

      if (ticket !== sequence.current) return;
      setRows(payload.data ?? []);
      setSummary(payload.summary ?? emptySummary);
    } catch (err) {
      if (ticket !== sequence.current) return;
      setError(err instanceof Error ? err.message : "Unable to load citizen approvals.");
    } finally {
      if (ticket === sequence.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadApprovals();
    }, 250);

    return () => {
      window.clearTimeout(timeoutId);
      ++sequence.current;
    };
  }, [search, status]);

  async function updateStatus(
    row: CitizenApprovalRow,
    nextStatus: "active" | "under_review" | "rejected",
  ) {
    setSavingId(row.id);

    try {
      const response = await apiFetch(`/api/citizen-approvals/${encodeURIComponent(row.id)}`, {
        method: "PATCH",
        body: JSON.stringify({ status: nextStatus }),
      });
      const payload = await readJsonResponse<CitizenApprovalUpdatePayload>(
        response,
        "Unable to update citizen account.",
      );
      const nextRow = payload.data;

      setRows((current) => current.map((item) => (item.id === row.id ? nextRow : item)));
      setSummary(payload.summary ?? summary);

      if (nextStatus === "active") toast.success(`${row.full_name} approved and activated.`);
      if (nextStatus === "under_review") toast.success(`${row.full_name} marked under review.`);
      if (nextStatus === "rejected") toast.error(`${row.full_name} rejected.`);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to update citizen account.");
    } finally {
      setSavingId(null);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-foreground">Citizen Approval</h1>
            <Badge variant="secondary" className="gap-1.5 text-xs">
              <UserCheck className="h-3 w-3" />
              {scope === "bir" ? "BIR Reviewer Queue" : "National Admin Queue"}
            </Badge>
          </div>
          <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
            Review citizen client accounts before TIN barcode access, receipt matching, and taxpayer
            notifications become active.
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          className="gap-2 text-xs"
          onClick={() => loadApprovals({ quiet: true })}
          disabled={loading || refreshing}
        >
          <RefreshCw className={cn("h-3.5 w-3.5", refreshing && "animate-spin")} />
          Refresh Queue
        </Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {[
          {
            label: "Total Citizens",
            value: summary.total,
            icon: UserCheck,
            className: "text-foreground",
          },
          {
            label: "Pending Review",
            value: summary.pending,
            icon: Clock3,
            className: "text-warning",
          },
          {
            label: "Active Accounts",
            value: summary.active,
            icon: CheckCircle2,
            className: "text-success",
          },
          {
            label: "Rejected",
            value: summary.rejected,
            icon: XCircle,
            className: "text-destructive",
          },
        ].map((item) => {
          const Icon = item.icon;
          return (
            <Card key={item.label}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <p className="text-xs text-muted-foreground">{item.label}</p>
                  <Icon className={cn("h-4 w-4", item.className)} />
                </div>
                <p className={cn("mt-2 text-2xl font-bold tabular-nums", item.className)}>
                  {item.value.toLocaleString("en-PH")}
                </p>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div>
              <CardTitle className="text-sm">Citizen Account Queue</CardTitle>
              <p className="text-xs text-muted-foreground">
                {reviewRows.length} account{reviewRows.length === 1 ? "" : "s"} currently require
                reviewer action.
              </p>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
              <div className="relative sm:w-80">
                <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  className="h-9 pl-9 text-xs"
                  placeholder="Search citizen, email, TIN, account..."
                />
              </div>
              <Select value={status} onValueChange={setStatus}>
                <SelectTrigger className="h-9 text-xs sm:w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                  <SelectItem value="under_review">Under review</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="rejected">Rejected</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {error && (
            <div className="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          {loading ? (
            <div className="rounded-lg border p-8 text-center text-sm text-muted-foreground">
              Loading citizen approval queue...
            </div>
          ) : rows.length === 0 ? (
            <div className="rounded-lg border p-8 text-center">
              <AlertCircle className="mx-auto h-8 w-8 text-muted-foreground" />
              <p className="mt-3 text-sm font-medium text-foreground">No citizen accounts found</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Try another status filter or search term.
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {rows.map((row) => (
                <div key={row.id} className="rounded-lg border bg-card p-4">
                  <div className="flex flex-col gap-4 xl:flex-row xl:items-center">
                    <div className="flex min-w-0 flex-1 gap-3">
                      <Avatar className="h-11 w-11 rounded-xl border bg-primary text-primary-foreground">
                        {row.profile_photo_url && (
                          <AvatarImage
                            src={publicAssetUrl(row.profile_photo_url)}
                            alt={`${row.full_name} profile`}
                            className="object-cover"
                          />
                        )}
                        <AvatarFallback className="rounded-xl bg-primary text-xs font-bold text-primary-foreground">
                          {initialsFor(row.full_name)}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="truncate text-sm font-semibold text-foreground">
                            {row.full_name}
                          </p>
                          <CitizenStatusBadge status={row.status} />
                          <Badge variant="outline" className="text-[10px] capitalize">
                            {row.account_type}
                          </Badge>
                        </div>
                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                          <span className="font-mono">{row.account_number}</span>
                          {row.tin && (
                            <span className="flex items-center gap-1">
                              <Fingerprint className="h-3 w-3" />
                              {formatTin(row.tin)}
                            </span>
                          )}
                          {row.email && (
                            <span className="flex items-center gap-1">
                              <Mail className="h-3 w-3" />
                              {row.email}
                            </span>
                          )}
                          {row.mobile && (
                            <span className="flex items-center gap-1">
                              <Phone className="h-3 w-3" />
                              {row.mobile}
                            </span>
                          )}
                        </div>
                        <p className="mt-2 text-[10px] text-muted-foreground">
                          Submitted {dateLabel(row.created_at)} · Last updated{" "}
                          {dateLabel(row.updated_at)}
                        </p>
                      </div>
                    </div>

                    <div className="flex flex-wrap gap-2 xl:justify-end">
                      {row.status !== "under_review" && row.status !== "active" && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="gap-1.5 text-xs"
                          disabled={savingId === row.id}
                          onClick={() => updateStatus(row, "under_review")}
                        >
                          <ShieldCheck className="h-3.5 w-3.5" />
                          Review
                        </Button>
                      )}
                      {row.status !== "active" && (
                        <Button
                          size="sm"
                          className="gap-1.5 text-xs"
                          disabled={savingId === row.id}
                          onClick={() => updateStatus(row, "active")}
                        >
                          <CheckCircle2 className="h-3.5 w-3.5" />
                          Activate
                        </Button>
                      )}
                      {row.status !== "rejected" && (
                        <Button
                          variant="outline"
                          size="sm"
                          className="gap-1.5 border-destructive/40 text-xs text-destructive hover:text-destructive"
                          disabled={savingId === row.id}
                          onClick={() => updateStatus(row, "rejected")}
                        >
                          <XCircle className="h-3.5 w-3.5" />
                          Reject
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
