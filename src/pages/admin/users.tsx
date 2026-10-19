import { formatTin } from "@/lib/tin";
import { useEffect, useMemo, useState } from "react";
import {
  Edit,
  Key,
  Mail,
  MoreHorizontal,
  RefreshCw,
  Search,
  Shield,
  Trash2,
  UserCheck,
  Users,
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
  DropdownMenuSeparator,
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
  const { user: currentUser } = useAuth();
  const [profiles, setProfiles] = useState<ProfileRow[]>([]);
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState(defaultRoleFilter);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [editUser, setEditUser] = useState<ProfileRow | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<ProfileRow | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const roleOptions = useMemo(() => {
    const roles = allowedRoles?.length ? allowedRoles : Object.keys(roleConfig);
    return roles.filter((role) => roleConfig[role]);
  }, [allowedRoles]);

  async function loadProfiles(options: { quiet?: boolean } = {}) {
    if (options.quiet) setRefreshing(true);
    else setLoading(true);

    let query = supabase
      .from<ProfileRow[]>("profiles")
      .select("*")
      .order("created_at", { ascending: false });

    if (allowedRoles?.length) {
      query = query.in("role", allowedRoles);
    }

    const { data, error } = await query;

    if (error) {
      toast.error(error.message || "Unable to load user profiles.");
      setProfiles([]);
    } else {
      setProfiles(data ?? []);
    }

    setLoading(false);
    setRefreshing(false);
  }

  useEffect(() => {
    loadProfiles();
  }, [allowedRoles?.join("|")]);

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();

    return profiles.filter((profile) => {
      const fullName = profile.full_name || profile.email || "Unnamed user";
      const organization = profile.organization || "";
      const tin = profile.tin || "";
      const matchesSearch = !query || [fullName, profile.email, organization, tin, profile.role]
        .join(" ")
        .toLowerCase()
        .includes(query);
      const matchesRole = roleFilter === "all" || profile.role === roleFilter;

      return matchesSearch && matchesRole;
    });
  }, [profiles, roleFilter, search]);

  const activeRoles = roleOptions.map((role) => ({
    role,
    label: roleLabel(role),
    count: profiles.filter((profile) => profile.role === role).length,
  }));

  async function handleSaveEdit() {
    if (!editUser) return;
    setSaving(true);

    const payload = {
      full_name: editUser.full_name ?? "",
      email: editUser.email,
      organization: editUser.organization ?? "",
      role: editUser.role,
      tin: editUser.tin ?? null,
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

  async function handleDeleteUser() {
    if (!deleteTarget) return;
    setDeleting(true);

    const { error, count } = await supabase.from("profiles").delete().eq("id", deleteTarget.id);

    if (error) {
      toast.error(error.message || "Unable to delete user.");
    } else {
      setProfiles((current) => current.filter((profile) => profile.id !== deleteTarget.id));
      toast.success(`${deleteTarget.full_name || deleteTarget.email} deleted from NUERS.`);
      if (count === 0) {
        toast.info("No matching database row was removed.");
      }
      setDeleteTarget(null);
    }

    setDeleting(false);
  }

  function sendStub(message: string) {
    toast.info(message);
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

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Total Profiles</span>
              <Users className="h-4 w-4 text-muted-foreground" />
            </div>
            <p className="mt-1 text-2xl font-bold text-foreground">{profiles.length}</p>
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
                <span className="text-xs text-muted-foreground">{item.label}</span>
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
                onChange={(event) => setSearch(event.target.value)}
                className="pl-9"
              />
            </div>
            <Select value={roleFilter} onValueChange={setRoleFilter}>
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
                        <DropdownMenuItem onClick={() => setEditUser(profile)}>
                          <Edit className="mr-2 h-3.5 w-3.5" /> Edit Profile
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => sendStub(`Password reset workflow queued for ${profile.email}.`)}>
                          <Key className="mr-2 h-3.5 w-3.5" /> Reset Password
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => sendStub(`Notification workflow queued for ${profile.email}.`)}>
                          <Mail className="mr-2 h-3.5 w-3.5" /> Send Email
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          className="text-destructive"
                          disabled={isCurrent}
                          onClick={() => setDeleteTarget(profile)}
                        >
                          <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete User
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={!!editUser} onOpenChange={() => setEditUser(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Edit User Profile</DialogTitle>
            <DialogDescription>Update the profile fields used by the NUERS role-based portals.</DialogDescription>
          </DialogHeader>
          {editUser && (
            <div className="space-y-4 py-2">
              <div className="space-y-2">
                <Label>Full Name</Label>
                <Input value={editUser.full_name ?? ""} onChange={(event) => setEditUser({ ...editUser, full_name: event.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Email</Label>
                <Input type="email" value={editUser.email} onChange={(event) => setEditUser({ ...editUser, email: event.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Organization</Label>
                <Input value={editUser.organization ?? ""} onChange={(event) => setEditUser({ ...editUser, organization: event.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>TIN</Label>
                <Input value={editUser.tin ?? ""} onChange={(event) => setEditUser({ ...editUser, tin: event.target.value })} />
              </div>
              <div className="space-y-2">
                <Label>Role</Label>
                <Select value={editUser.role} onValueChange={(value) => setEditUser({ ...editUser, role: value })}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {roleOptions.map((role) => (
                      <SelectItem key={role} value={role}>{roleLabel(role)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditUser(null)}>Cancel</Button>
            <Button onClick={handleSaveEdit} disabled={saving}>{saving ? "Saving..." : "Save Changes"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!deleteTarget} onOpenChange={() => setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete User</AlertDialogTitle>
            <AlertDialogDescription>
              This will permanently delete <strong>{deleteTarget?.full_name || deleteTarget?.email}</strong> and remove the linked login account when one exists.
              This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={handleDeleteUser}
              disabled={deleting}
            >
              {deleting ? "Deleting..." : "Delete User"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
