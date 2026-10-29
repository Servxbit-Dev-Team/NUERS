import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { Link } from "react-router-dom";
import { formatTin } from "@/lib/tin";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  Edit,
  MoreHorizontal,
  RefreshCw,
  Search,
  Shield,
  UserCheck,
  Users,
} from "lucide-react";
import { toast } from "sonner";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { supabase } from "@/lib/supabase";
import { useAuth } from "@/lib/auth";
import { publicAssetUrl } from "@/lib/api-url";

type ProfileRow = {
  id: string | number;
  email: string;
  role: string;
  full_name: string | null;
  organization: string | null;
  tin?: string | null;
  tin_bound_at?: string | null;
  profile_photo_url?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};

type AdminUsersProps = {
  title?: string;
  description?: string;
  allowedRoles?: string[];
  defaultRoleFilter?: string;
};

const roleConfig: Record<string, { label: string; color: string; permissions: string[] }> = {
  super_admin: {
    label: "Super Admin",
    color: "bg-primary text-primary-foreground",
    permissions: ["Platform control", "Users and RBAC", "System settings"],
  },
  admin: {
    label: "Admin",
    color: "bg-primary/10 text-primary",
    permissions: ["Operations", "Reports", "User support"],
  },
  bir: {
    label: "BIR Regulator",
    color: "bg-destructive/10 text-destructive",
    permissions: ["BIR dashboard", "Approvals", "Tax review"],
  },
  rdo: {
    label: "RDO Officer",
    color: "bg-warning/10 text-warning-foreground",
    permissions: ["RDO operations", "Regional review", "Citizen approval"],
  },
  merchant: {
    label: "Business Account",
    color: "bg-secondary text-secondary-foreground",
    permissions: ["Merchant dashboard", "Receipts", "Invoices"],
  },
  client: {
    label: "Client",
    color: "bg-muted text-muted-foreground",
    permissions: ["Client portal", "Receipt vault", "TIN barcode"],
  },
  consumer: {
    label: "Consumer",
    color: "bg-muted text-muted-foreground",
    permissions: ["Client portal", "Receipt verification"],
  },
};

function initialsFor(value?: string | null) {
  return (value || "UA")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase() || "UA";
}

function roleLabel(role: string) {
  return roleConfig[role]?.label ?? role.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function dateLabel(value?: string | null) {
  if (!value) return "Never";
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

export function AdminUsers({
  title = "Users & RBAC",
  description = "Manage platform profiles, roles, and access records backed by the NUERS database.",
  allowedRoles,
  defaultRoleFilter = "all",
}: AdminUsersProps) {
  const { user: currentUser, profile: currentProfile } = useAuth();
  const requestSequence = useRef(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  const [total, setTotal] = useState(0);
  const [profiles, setProfiles] = useState<ProfileRow[]>([]);
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState(defaultRoleFilter);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [editUser, setEditUser] = useState<ProfileRow | null>(null);
  const [saving, setSaving] = useState(false);

  const roleOptions = useMemo(() => {
    const roles = allowedRoles?.length ? allowedRoles : Object.keys(roleConfig);
    return roles.filter((role) => roleConfig[role]);
  }, [allowedRoles]);

  async function loadProfiles(options: { quiet?: boolean } = {}) {
    const sequence = ++requestSequence.current;
    if (!options.quiet) setLoading(true);

    let query = supabase
      .from<ProfileRow[]>("profiles")
      .select("*", { count: "exact" })
      .order("created_at", { ascending: false })
      .range((page - 1) * pageSize, page * pageSize - 1)
      .search(search.trim());
    if (roleFilter !== "all") query = query.eq("role", roleFilter);

    if (allowedRoles?.length) {
      query = query.in("role", allowedRoles);
    }

    const { data, error, count } = await query;
    if (sequence !== requestSequence.current) return;
    setTotal(error ? 0 : count ?? 0);

    if (error) {
      if(!options.quiet){toast.error(error.message || "Unable to load user profiles.");setProfiles([]);}
    } else {
      setProfiles(data ?? []);
    }

    setLoading(false);
    setRefreshing(false);
  }

  useEffect(() => {
    setLoading(true);
    const timer = window.setTimeout(() => { void loadProfiles(); }, 250);
    return () => { window.clearTimeout(timer); requestSequence.current++; };
  }, [allowedRoles?.join("|"), page, pageSize, search, roleFilter]);

  useRealtimeRefresh(()=>loadProfiles({quiet:true}),!saving&&!editUser);
  const filtered = profiles;

  const activeRoles = roleOptions.map((role) => ({
    role,
    label: roleLabel(role),
    count: profiles.filter((profile) => profile.role === role).length,
  }));

  async function handleSaveEdit() {
    if (!editUser) return;
    setSaving(true);

    const original = profiles.find(p => p.id === editUser.id);
    const payload = {
      ...(original?.role === "merchant" ? {} : { full_name: editUser.full_name ?? "", organization: editUser.organization ?? "" }),
      ...(editUser.role !== profiles.find(p => p.id === editUser.id)?.role ? { role: editUser.role } : {}),
    };

    const { error } = await supabase.from("profiles").update(payload).eq("id", editUser.id);

    if (error) {
      toast.error(error.message || "Unable to update user profile.");
    } else {
      setProfiles((current) => current.map((profile) => profile.id === editUser.id ? { ...profile, ...payload } : profile));
      toast.success(`${editUser.full_name || editUser.email} updated.`);
      setEditUser(null);
    }

    setSaving(false);
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">{title}</h1>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
        <Button variant="outline" size="sm" className="gap-2" onClick={() => loadProfiles({ quiet: true })} disabled={loading || refreshing}>
          <RefreshCw className={`h-4 w-4 ${refreshing ? "animate-spin" : ""}`} /> Refresh
        </Button>
      </div>

      <p className="text-sm text-muted-foreground">Merchant identity and suspension are managed in <Link className="text-primary underline" to="/super-admin/merchants">Registered Businesses</Link>. Users recover their own passwords through the sign-in page. Privileged roles require a Super Administrator.</p>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Matching Profiles</span>
              <Users className="h-4 w-4 text-muted-foreground" />
            </div>
            <p className="mt-1 text-2xl font-bold text-foreground">{total}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Shown</span>
              <UserCheck className="h-4 w-4 text-muted-foreground" />
            </div>
            <p className="mt-1 text-2xl font-bold text-foreground">{filtered.length}</p>
          </CardContent>
        </Card>
        {activeRoles.slice(0, 2).map((item) => (
          <Card key={item.role}>
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{item.label} · this page</span>
                <Shield className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="mt-1 text-2xl font-bold text-foreground">{item.count}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardContent className="p-5">
          <div className="mb-4 flex flex-wrap gap-3">
            <div className="relative flex-1 min-w-0 basis-full sm:min-w-[240px] sm:basis-auto">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search name, email, TIN, organization..."
                value={search}
                onChange={(event) => { setSearch(event.target.value); setPage(1); }}
                className="pl-9"
              />
            </div>
            <Select value={roleFilter} onValueChange={value => { setRoleFilter(value); setPage(1); }}>
              <SelectTrigger className="w-full sm:w-48">
                <SelectValue placeholder="Role" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Roles</SelectItem>
                {roleOptions.map((role) => (
                  <SelectItem key={role} value={role}>{roleLabel(role)}</SelectItem>
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
              <Users className="mx-auto h-10 w-10 text-muted-foreground" />
              <p className="mt-3 text-sm font-medium text-foreground">No user profiles found</p>
              <p className="text-xs text-muted-foreground">Try adjusting the role filter or search text.</p>
            </div>
          ) : (
            <div className="space-y-2">
              {filtered.map((profile) => {
                const displayName = profile.full_name || profile.email || "Unnamed user";
                const isCurrent = String(currentUser?.id ?? "") === String(profile.id);

                return (
                  <div key={String(profile.id)} className="flex items-center gap-4 rounded-lg border p-4 transition-colors hover:bg-muted/30">
                    <Avatar className="h-10 w-10">
                      <AvatarImage src={publicAssetUrl(profile.profile_photo_url)} alt={displayName} />
                      <AvatarFallback className="bg-primary text-sm text-primary-foreground">
                        {initialsFor(displayName)}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-foreground">{displayName}</span>
                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${roleConfig[profile.role]?.color ?? "bg-secondary text-secondary-foreground"}`}>
                          {roleLabel(profile.role)}
                        </span>
                        {isCurrent && <Badge variant="outline" className="text-[10px]">Current session</Badge>}
                      </div>
                      <div className="mt-0.5 flex flex-wrap items-center gap-3">
                        <span className="text-xs text-muted-foreground">{profile.email}</span>
                        {profile.organization && <span className="text-xs text-muted-foreground">{profile.organization}</span>}
                        {profile.tin && <span className="font-mono text-xs text-muted-foreground">{formatTin(profile.tin)}</span>}
                        <span className="text-xs text-muted-foreground">Updated: {dateLabel(profile.updated_at)}</span>
                      </div>
                    </div>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8">
                          <MoreHorizontal className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem disabled={currentProfile?.role !== "super_admin" && ["admin", "super_admin", "bir", "rdo"].includes(profile.role)} onClick={() => setEditUser(profile)}>
                          <Edit className="mr-2 h-3.5 w-3.5" /> Edit Profile
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                );
              })}
            </div>
          )}
          <nav aria-label="User directory pagination" className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-sm">
            <span>{total ? `Showing ${(page - 1) * pageSize + 1}–${Math.min(page * pageSize, total)} of ${total} profiles` : "No matching profiles"}</span>
            <div className="flex flex-wrap items-center gap-2"><label>Rows per page <select className="rounded border px-2 py-1" value={pageSize} onChange={e => { setPageSize(Number(e.target.value)); setPage(1); }}><option>10</option><option>25</option><option>50</option></select></label><Button variant="outline" size="sm" disabled={loading || page <= 1} onClick={() => setPage(p => p - 1)}>Previous</Button><span>Page {page} of {Math.max(1, Math.ceil(total / pageSize))}</span><Button variant="outline" size="sm" disabled={loading || page * pageSize >= total} onClick={() => setPage(p => p + 1)}>Next</Button></div>
          </nav>
        </CardContent>
      </Card>

      <Dialog open={!!editUser} onOpenChange={() => setEditUser(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Edit User Profile</DialogTitle>
            <DialogDescription>Merchant identity changes belong in Registered Businesses. Login email and TIN remain controlled identity fields; account records are preserved.</DialogDescription>
          </DialogHeader>
          {editUser && (
            <div className="space-y-4 py-2">
              <div className="space-y-2">
                <Label>Full Name</Label>
                <Input disabled={profiles.find(p => p.id === editUser.id)?.role === "merchant"} value={editUser.full_name ?? ""} onChange={(event) => setEditUser({ ...editUser, full_name: event.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Email</Label>
                <Input type="email" value={editUser.email} readOnly />
              </div>
              <div className="space-y-2">
                <Label>Organization</Label>
                <Input disabled={profiles.find(p => p.id === editUser.id)?.role === "merchant"} value={editUser.organization ?? ""} onChange={(event) => setEditUser({ ...editUser, organization: event.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>TIN</Label>
                <Input value={editUser.tin ?? ""} readOnly />
              </div>
              <div className="space-y-2">
                <Label>Role</Label>
                <Select disabled={currentProfile?.role !== "super_admin" || String(editUser.id) === String(currentUser?.id) || ["bir", "rdo"].includes(editUser.role)} value={editUser.role} onValueChange={(value) => setEditUser({ ...editUser, role: value })}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {roleOptions.filter(role => !["bir", "rdo"].includes(role) || role === editUser.role).map((role) => (
                      <SelectItem key={role} value={role}>{roleLabel(role)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditUser(null)}>Cancel</Button>
            <Button onClick={handleSaveEdit} disabled={saving || (profiles.find(p => p.id === editUser?.id)?.role === "merchant" && profiles.find(p => p.id === editUser?.id)?.role === editUser?.role)}>{saving ? "Saving..." : "Save Changes"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>


    </div>
  );
}
