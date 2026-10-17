import { useState } from "react";
import {
  Edit, Trash2, Shield, CheckCircle2,
  Clock, XCircle, UserPlus, Mail, Key, ChevronDown, ChevronUp,
  GitBranch, Crown, Eye, Settings,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

type Role = "owner" | "admin" | "accountant" | "preparer" | "approver" | "viewer";
type UserStatus = "active" | "pending" | "suspended" | "removed";

interface OrgUser {
  id: string;
  name: string;
  email: string;
  role: Role;
  status: UserStatus;
  branches: string[];
  isPrimary: boolean;
  lastActive: string;
  invitedAt: string;
}

const MOCK_USERS: OrgUser[] = [
  { id: "u-001", name: "Ana Reyes", email: "ana.reyes@abctrading.ph", role: "owner", status: "active", branches: ["All"], isPrimary: true, lastActive: "Today", invitedAt: "2024-11-20" },
  { id: "u-002", name: "Jose Santos", email: "jose.santos@abctrading.ph", role: "admin", status: "active", branches: ["All"], isPrimary: false, lastActive: "Today", invitedAt: "2024-11-21" },
  { id: "u-003", name: "Maria Garcia", email: "maria.garcia@abctrading.ph", role: "accountant", status: "active", branches: ["Head Office", "Makati Branch"], isPrimary: false, lastActive: "Yesterday", invitedAt: "2024-12-05" },
  { id: "u-004", name: "Pedro Cruz", email: "pedro.cruz@abctrading.ph", role: "preparer", status: "active", branches: ["Ortigas Branch"], isPrimary: false, lastActive: "2026-05-25", invitedAt: "2025-01-10" },
  { id: "u-005", name: "Rosa Dela Cruz", email: "rosa@abctrading.ph", role: "approver", status: "active", branches: ["Head Office"], isPrimary: false, lastActive: "2026-05-28", invitedAt: "2025-03-01" },
  { id: "u-006", name: "Tony Bautista", email: "tony@abctrading.ph", role: "viewer", status: "pending", branches: ["Makati Branch"], isPrimary: false, lastActive: "Never", invitedAt: "2026-05-28" },
  { id: "u-007", name: "Linda Tan", email: "linda.tan@abctrading.ph", role: "viewer", status: "suspended", branches: ["QC Satellite"], isPrimary: false, lastActive: "2026-04-10", invitedAt: "2025-06-15" },
  { id: "u-008", name: "Carlos Mendoza", email: "carlos@abctrading.ph", role: "accountant", status: "active", branches: ["All"], isPrimary: false, lastActive: "Today", invitedAt: "2025-02-20" },
];

const ROLE_META: Record<Role, { label: string; color: string; bg: string; icon: React.ElementType; perms: string[] }> = {
  owner:      { label: "Owner",      color: "text-foreground",       bg: "bg-foreground/10",  icon: Crown, perms: ["Full access", "Billing", "Delete account"] },
  admin:      { label: "Admin",      color: "text-primary",          bg: "bg-primary/10",     icon: Shield, perms: ["Manage users", "All features", "Settings"] },
  accountant: { label: "Accountant", color: "text-chart-2",          bg: "bg-chart-2/10",     icon: Key, perms: ["Invoices", "VAT reports", "EIS submissions"] },
  preparer:   { label: "Preparer",   color: "text-chart-3",          bg: "bg-chart-3/10",     icon: Edit, perms: ["Create invoices", "Draft only", "No approval"] },
  approver:   { label: "Approver",   color: "text-warning",          bg: "bg-warning/10",     icon: CheckCircle2, perms: ["Approve invoices", "Approve EIS", "View all"] },
  viewer:     { label: "Viewer",     color: "text-muted-foreground", bg: "bg-muted",          icon: Eye, perms: ["View only", "No edits", "No submissions"] },
};

const USER_STATUS_ICON: Record<UserStatus, React.ElementType> = {
  active: CheckCircle2, pending: Clock, suspended: XCircle, removed: XCircle,
};

const USER_STATUS_COLOR: Record<UserStatus, string> = {
  active: "text-success", pending: "text-warning", suspended: "text-destructive", removed: "text-muted-foreground",
};

export function UserManagement() {
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("all");
  const [showInvite, setShowInvite] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);

  const filtered = MOCK_USERS.filter(u =>
    (!search || u.name.toLowerCase().includes(search.toLowerCase()) || u.email.includes(search)) &&
    (roleFilter === "all" || u.role === roleFilter)
  );

  const initials = (name: string) => name.split(" ").map(n => n[0]).join("").slice(0, 2).toUpperCase();

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold text-foreground">User & Access Management</h1>
          <p className="text-xs text-muted-foreground mt-0.5">Team members, roles, branch access, permissions</p>
        </div>
        <Button size="sm" className="gap-1.5" onClick={() => setShowInvite(true)}>
          <UserPlus className="h-3.5 w-3.5" /> Invite User
        </Button>
      </div>

      {/* Role summary */}
      <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {(Object.entries(ROLE_META) as [Role, typeof ROLE_META[Role]][]).map(([role, meta]) => {
          const count = MOCK_USERS.filter(u => u.role === role && u.status === "active").length;
          const Icon = meta.icon;
          return (
            <Card key={role} className="cursor-pointer hover:bg-muted/20 transition-colors" onClick={() => setRoleFilter(role === roleFilter ? "all" : role)}>
              <CardContent className="p-3">
                <div className="flex items-center gap-2 mb-1">
                  <Icon className={cn("h-3.5 w-3.5", meta.color)} />
                  <span className="text-[10px] font-medium text-muted-foreground">{meta.label}</span>
                </div>
                <p className="text-xl font-bold text-foreground tabular-nums">{count}</p>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <Card>
        <CardContent className="p-5">
          <div className="flex flex-wrap gap-3 mb-4">
            <div className="relative flex-1 min-w-0 basis-full sm:min-w-[180px] sm:basis-auto">
              <Input placeholder="Search name or email…" className="h-8 text-xs pl-3" value={search} onChange={e => setSearch(e.target.value)} />
            </div>
            <Select value={roleFilter} onValueChange={setRoleFilter}>
              <SelectTrigger className="h-8 w-full text-xs sm:w-36"><SelectValue placeholder="All roles" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Roles</SelectItem>
                {(Object.entries(ROLE_META) as [Role, typeof ROLE_META[Role]][]).map(([k, v]) => (
                  <SelectItem key={k} value={k}>{v.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            {filtered.map(u => {
              const rm = ROLE_META[u.role];
              const RIcon = rm.icon;
              const SIcon = USER_STATUS_ICON[u.status];
              const isExp = expanded === u.id;
              return (
                <div key={u.id} className="border rounded-lg overflow-hidden">
                  <div className="flex items-center gap-3 p-3 hover:bg-muted/20 transition-colors">
                    <Avatar className="h-8 w-8 flex-shrink-0">
                      <AvatarFallback className="text-[10px] font-bold">{initials(u.name)}</AvatarFallback>
                    </Avatar>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="text-xs font-semibold text-foreground">{u.name}</span>
                        {u.isPrimary && <Badge variant="default" className="text-[9px]">Primary</Badge>}
                        <span className={cn("text-[9px] font-medium px-1.5 py-0.5 rounded-full flex items-center gap-0.5", rm.color, rm.bg)}>
                          <RIcon className="h-2.5 w-2.5" />
                          {rm.label}
                        </span>
                      </div>
                      <p className="text-[10px] text-muted-foreground mt-0.5">{u.email} • Last active: {u.lastActive}</p>
                    </div>
                    <div className="flex items-center gap-3 flex-shrink-0">
                      <span className={cn("flex items-center gap-1 text-xs font-medium", USER_STATUS_COLOR[u.status])}>
                        <SIcon className="h-3 w-3" />
                        <span className="capitalize">{u.status}</span>
                      </span>
                      <div className="flex gap-1">
                        <Button variant="ghost" size="sm" className="h-6 w-6 p-0" onClick={() => toast.success(`Editing ${u.name}`)}>
                          <Edit className="h-3 w-3" />
                        </Button>
                        {!u.isPrimary && (
                          <Button variant="ghost" size="sm" className="h-6 w-6 p-0 text-destructive hover:text-destructive" onClick={() => toast.success(`${u.name} removed`)}>
                            <Trash2 className="h-3 w-3" />
                          </Button>
                        )}
                        <Button variant="ghost" size="sm" className="h-6 w-6 p-0" onClick={() => setExpanded(isExp ? null : u.id)}>
                          {isExp ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                        </Button>
                      </div>
                    </div>
                  </div>
                  {isExp && (
                    <div className="border-t bg-muted/10 px-4 py-3 grid sm:grid-cols-3 gap-4 text-xs">
                      <div>
                        <p className="font-semibold text-foreground mb-1.5">Branch Access</p>
                        <div className="flex flex-wrap gap-1">
                          {u.branches.map(b => (
                            <Badge key={b} variant="outline" className="text-[9px]">
                              <GitBranch className="h-2.5 w-2.5 mr-1" />{b}
                            </Badge>
                          ))}
                        </div>
                      </div>
                      <div>
                        <p className="font-semibold text-foreground mb-1.5">Permissions ({rm.label})</p>
                        <ul className="space-y-0.5">
                          {rm.perms.map(p => (
                            <li key={p} className="flex items-center gap-1.5 text-muted-foreground">
                              <CheckCircle2 className="h-2.5 w-2.5 text-success flex-shrink-0" />
                              {p}
                            </li>
                          ))}
                        </ul>
                      </div>
                      <div>
                        <p className="font-semibold text-foreground mb-1.5">Details</p>
                        <div className="space-y-1 text-muted-foreground">
                          <div><span>Invited: </span><span className="text-foreground">{u.invitedAt}</span></div>
                          <div><span>Status: </span><span className={cn("capitalize", USER_STATUS_COLOR[u.status])}>{u.status}</span></div>
                        </div>
                        <Button size="sm" variant="outline" className="gap-1.5 h-7 text-xs mt-2 w-full" onClick={() => toast.success("Role change dialog")}>
                          <Settings className="h-3 w-3" /> Change Role
                        </Button>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
            {filtered.length === 0 && (
              <p className="py-8 text-center text-sm text-muted-foreground">No users match your search.</p>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Role permission reference */}
      <Card>
        <CardHeader className="px-5 pt-5 pb-2">
          <CardTitle className="text-sm">Role Permissions Reference</CardTitle>
        </CardHeader>
        <CardContent className="px-5 pb-5">
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b">
                  <th className="pb-2 text-left font-medium text-muted-foreground pr-4">Permission</th>
                  {(Object.entries(ROLE_META) as [Role, typeof ROLE_META[Role]][]).map(([k, v]) => (
                    <th key={k} className={cn("pb-2 text-center font-medium pr-3", v.color)}>{v.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {[
                  ["Create Invoices",        true,  true,  true,  true,  false, false],
                  ["Approve Invoices",        true,  true,  false, false, true,  false],
                  ["Submit EIS",              true,  true,  true,  false, true,  false],
                  ["View Reports",            true,  true,  true,  false, true,  true],
                  ["Manage Users",            true,  true,  false, false, false, false],
                  ["Transaction Fees & Storage",  true,  false, false, false, false, false],
                  ["API Key Management",      true,  true,  false, false, false, false],
                  ["Branch Management",       true,  true,  false, false, false, false],
                ].map(([label, ...vals]) => (
                  <tr key={label as string} className="border-b last:border-0">
                    <td className="py-2 pr-4 text-muted-foreground">{label as string}</td>
                    {(vals as boolean[]).map((v, i) => (
                      <td key={i} className="py-2 pr-3 text-center">
                        {v
                          ? <CheckCircle2 className="h-3.5 w-3.5 text-success mx-auto" />
                          : <XCircle className="h-3.5 w-3.5 text-muted-foreground/40 mx-auto" />}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Invite Dialog */}
      <Dialog open={showInvite} onOpenChange={setShowInvite}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="text-sm">Invite Team Member</DialogTitle>
          </DialogHeader>
          <div className="space-y-3 mt-2">
            <div className="space-y-1">
              <Label className="text-xs">Email Address</Label>
              <Input className="h-8 text-xs" placeholder="colleague@email.com" />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Role</Label>
              <Select>
                <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Select role" /></SelectTrigger>
                <SelectContent>
                  {(Object.entries(ROLE_META) as [Role, typeof ROLE_META[Role]][]).filter(([k]) => k !== "owner").map(([k, v]) => (
                    <SelectItem key={k} value={k}>{v.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Branch Access</Label>
              <Select>
                <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Select branches" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Branches</SelectItem>
                  <SelectItem value="ho">Head Office</SelectItem>
                  <SelectItem value="mkt">Makati Branch</SelectItem>
                  <SelectItem value="org">Ortigas Branch</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex gap-3 justify-end">
              <Button variant="outline" size="sm" onClick={() => setShowInvite(false)}>Cancel</Button>
              <Button size="sm" className="gap-1.5" onClick={() => { setShowInvite(false); toast.success("Invitation sent successfully"); }}>
                <Mail className="h-3.5 w-3.5" /> Send Invitation
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
