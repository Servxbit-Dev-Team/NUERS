import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { formatTin } from "@/lib/tin";
import { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  RefreshCw,
  Search,
  ShieldCheck,
  Trash2,
  UserCheck,
  Users,
  Wallet,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { supabase } from "@/lib/supabase";
import { useAuth } from "@/lib/auth";
import { cn } from "@/lib/utils";

type ClientAccountRow = {
  id: string;
  user_id: number | null;
  account_number: string;
  full_name: string;
  account_type: string;
  email: string | null;
  mobile: string | null;
  wallet_balance: number;
  mfa_enabled: boolean;
  notification_preferences?: unknown;
  status: string;
  tin?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

const statusStyles: Record<string, string> = {
  active: "border-success/30 bg-success/10 text-success",
  pending: "border-warning/30 bg-warning/10 text-warning-foreground",
  under_review: "border-primary/30 bg-primary/10 text-primary",
  rejected: "border-destructive/30 bg-destructive/10 text-destructive",
  inactive: "border-muted bg-muted text-muted-foreground",
};

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

function statusLabel(value: string) {
  return value.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function money(value: number) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 2,
  }).format(Number(value ?? 0));
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

export function AdminClientAccounts() {
  const { user: currentUser } = useAuth();
  const [accounts, setAccounts] = useState<ClientAccountRow[]>([]);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<ClientAccountRow | null>(null);
  const [deleting, setDeleting] = useState(false);

  async function loadAccounts(options: { quiet?: boolean } = {}) {
    if (options.quiet) setRefreshing(false);
    else setLoading(true);

    const { data, error } = await supabase
      .from<ClientAccountRow[]>("client_accounts")
      .select("*")
      .order("created_at", { ascending: false });

    if (error) {
      toast.error(error.message || "Unable to load client accounts.");
      setAccounts([]);
    } else {
      setAccounts(data ?? []);
    }

    setLoading(false);
    setRefreshing(false);
  }

  useEffect(() => {
    loadAccounts();
  }, []);
  useRealtimeRefresh(() => loadAccounts({ quiet: true }));

  const statusOptions = useMemo(
    () => Array.from(new Set(accounts.map((account) => account.status || "active"))).sort(),
    [accounts],
  );

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();

    return accounts.filter((account) => {
      const searchable = [
        account.full_name,
        account.email,
        account.mobile,
        account.account_number,
        account.account_type,
        account.status,
        account.tin,
      ]
        .join(" ")
        .toLowerCase();

      return (
        (!query || searchable.includes(query)) &&
        (statusFilter === "all" || account.status === statusFilter)
      );
    });
  }, [accounts, search, statusFilter]);

  const activeCount = accounts.filter((account) => account.status === "active").length;
  const pendingCount = accounts.filter((account) =>
    ["pending", "under_review"].includes(account.status),
  ).length;
  const walletTotal = accounts.reduce(
    (total, account) => total + Number(account.wallet_balance ?? 0),
    0,
  );

  async function handleDeleteAccount() {
    if (!deleteTarget) return;
    setDeleting(true);

    const { error, count } = await supabase
      .from("client_accounts")
      .delete()
      .eq("id", deleteTarget.id);

    if (error) {
      toast.error(error.message || "Unable to delete client account.");
    } else {
      setAccounts((current) => current.filter((account) => account.id !== deleteTarget.id));
      toast.success(`${deleteTarget.full_name} deleted from client accounts.`);
      if (count === 0) {
        toast.info("No matching database row was removed.");
      }
      setDeleteTarget(null);
    }

    setDeleting(false);
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Client Accounts</h1>
          <p className="text-sm text-muted-foreground">
            Manage citizen/client accounts, TIN binding state, wallet balances, and account
            deletion.
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          className="gap-2"
          onClick={() => loadAccounts({ quiet: true })}
          disabled={loading || refreshing}
        >
          <RefreshCw className={cn("h-4 w-4", refreshing && "animate-spin")} /> Refresh
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Total Clients</span>
              <Users className="h-4 w-4 text-muted-foreground" />
            </div>
            <p className="mt-1 text-2xl font-bold text-foreground">{accounts.length}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Active</span>
              <CheckCircle2 className="h-4 w-4 text-success" />
            </div>
            <p className="mt-1 text-2xl font-bold text-success">{activeCount}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Pending Review</span>
              <ShieldCheck className="h-4 w-4 text-warning" />
            </div>
            <p className="mt-1 text-2xl font-bold text-warning">{pendingCount}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Wallet Total</span>
              <Wallet className="h-4 w-4 text-muted-foreground" />
            </div>
            <p className="mt-1 text-2xl font-bold text-foreground">{money(walletTotal)}</p>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="p-5">
          <div className="mb-4 flex flex-wrap gap-3">
            <div className="relative flex-1 min-w-0 basis-full sm:min-w-[260px] sm:basis-auto">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search name, email, mobile, TIN, account..."
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                className="pl-9"
              />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-full sm:w-44">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                {statusOptions.map((status) => (
                  <SelectItem key={status} value={status}>
                    {statusLabel(status)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {loading ? (
            <div className="space-y-2">
              {Array.from({ length: 5 }).map((_, index) => (
                <div key={index} className="h-20 animate-pulse rounded-lg bg-muted" />
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="rounded-lg border border-dashed p-10 text-center">
              <UserCheck className="mx-auto h-10 w-10 text-muted-foreground" />
              <p className="mt-3 text-sm font-medium text-foreground">No client accounts found</p>
              <p className="text-xs text-muted-foreground">
                Try adjusting the search or status filter.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[980px]">
                <thead>
                  <tr className="border-b text-left text-xs text-muted-foreground">
                    <th className="pb-3 font-medium">Client</th>
                    <th className="pb-3 font-medium">Account</th>
                    <th className="pb-3 font-medium">TIN / Contact</th>
                    <th className="pb-3 font-medium">Wallet</th>
                    <th className="pb-3 font-medium">Security</th>
                    <th className="pb-3 font-medium">Updated</th>
                    <th className="pb-3 text-right font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((account) => {
                    const isCurrent =
                      String(currentUser?.id ?? "") === String(account.user_id ?? "");

                    return (
                      <tr key={account.id} className="border-b last:border-0">
                        <td className="py-3 pr-4">
                          <div className="flex items-center gap-3">
                            <Avatar className="h-9 w-9">
                              <AvatarFallback className="bg-primary text-xs text-primary-foreground">
                                {initialsFor(account.full_name)}
                              </AvatarFallback>
                            </Avatar>
                            <div>
                              <div className="flex items-center gap-2">
                                <p className="text-sm font-medium text-foreground">
                                  {account.full_name}
                                </p>
                                {isCurrent && (
                                  <Badge variant="outline" className="text-[10px]">
                                    Current session
                                  </Badge>
                                )}
                              </div>
                              <p className="text-xs text-muted-foreground">
                                {account.email || "No email"}
                              </p>
                            </div>
                          </div>
                        </td>
                        <td className="py-3 pr-4">
                          <p className="font-mono text-xs text-foreground">
                            {account.account_number}
                          </p>
                          <div className="mt-1 flex items-center gap-2">
                            <Badge variant="secondary" className="text-[10px]">
                              {statusLabel(account.account_type)}
                            </Badge>
                            <Badge
                              variant="outline"
                              className={cn(
                                "text-[10px]",
                                statusStyles[account.status] ?? statusStyles.inactive,
                              )}
                            >
                              {statusLabel(account.status)}
                            </Badge>
                          </div>
                        </td>
                        <td className="py-3 pr-4">
                          <p className="font-mono text-xs text-foreground">
                            {formatTin(account.tin) || "No TIN bound"}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            {account.mobile || "No mobile"}
                          </p>
                        </td>
                        <td className="py-3 pr-4 text-xs font-semibold text-foreground">
                          {money(account.wallet_balance)}
                        </td>
                        <td className="py-3 pr-4">
                          <Badge
                            variant={account.mfa_enabled ? "default" : "outline"}
                            className="gap-1 text-[10px]"
                          >
                            {account.mfa_enabled ? (
                              <CheckCircle2 className="h-3 w-3" />
                            ) : (
                              <XCircle className="h-3 w-3" />
                            )}
                            {account.mfa_enabled ? "MFA Enabled" : "MFA Off"}
                          </Badge>
                        </td>
                        <td className="py-3 pr-4 text-xs text-muted-foreground">
                          {dateLabel(account.updated_at)}
                        </td>
                        <td className="py-3 text-right">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8 text-destructive hover:text-destructive"
                            disabled={isCurrent}
                            onClick={() => setDeleteTarget(account)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <AlertDialog open={!!deleteTarget} onOpenChange={() => setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Client Account</AlertDialogTitle>
            <AlertDialogDescription>
              This will permanently delete <strong>{deleteTarget?.full_name}</strong> and remove the
              linked login/profile when one exists. Transactions and issued receipts remain in the
              audit ledger. This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={handleDeleteAccount}
              disabled={deleting}
            >
              {deleting ? "Deleting..." : "Delete Client"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
