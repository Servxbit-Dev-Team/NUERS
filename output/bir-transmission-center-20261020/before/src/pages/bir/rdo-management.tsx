import { useMemo, useState } from "react";
import {
  Building2,
  CheckCircle2,
  Landmark,
  MapPin,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  UserCheck,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { registerRdoOffice, useRdoOffices } from "@/lib/rdo-data";

const regionOptions = [
  "NCR", "Region I", "Region II", "Region III", "Region IV-A", "Region IV-B",
  "Region V", "Region VI", "Region VII", "Region VIII", "Region IX", "Region X",
  "Region XI", "Region XII", "CAR", "BARMM",
];

const initialForm = {
  rdo_code: "",
  rdo_name: "",
  region: "NCR",
  city: "",
  office_address: "",
  head_name: "",
  email: "",
  phone: "",
  password: "",
  coverage_area: "",
  notes: "",
};

export function BirRdoManagement() {
  const [refreshKey, setRefreshKey] = useState(0);
  const { offices, loading, error } = useRdoOffices(refreshKey);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState("");
  const [form, setForm] = useState(initialForm);

  const filteredOffices = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return offices;

    return offices.filter((office) =>
      [
        office.rdo_code,
        office.rdo_name,
        office.region,
        office.city,
        office.head_name,
        office.email,
      ].filter(Boolean).some((value) => String(value).toLowerCase().includes(q)),
    );
  }, [offices, search]);

  const totals = useMemo(() => ({
    offices: offices.length,
    businesses: offices.reduce((sum, office) => sum + office.business_count, 0),
    transactions: offices.reduce((sum, office) => sum + office.transaction_count, 0),
    active: offices.filter((office) => office.status === "active").length,
  }), [offices]);

  async function handleSubmit() {
    if (!form.rdo_code || !form.rdo_name || !form.region) {
      toast.error("RDO code, RDO name, and region are required.");
      return;
    }

    setSaving(true);
    try {
      await registerRdoOffice({
        ...form,
        coverage_area: form.coverage_area
          .split(",")
          .map((item) => item.trim())
          .filter(Boolean),
      });
      toast.success(`${form.rdo_code} registered and ready for RDO portal access.`);
      setForm(initialForm);
      setOpen(false);
      setRefreshKey((value) => value + 1);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to register RDO office.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-foreground">RDO Registration and Portal Management</h1>
            <Badge variant="secondary" className="gap-1.5 text-xs">
              <Landmark className="h-3 w-3" /> BIR RDO Network
            </Badge>
          </div>
          <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
            Register Revenue District Offices, create RDO portal accounts, and monitor businesses and transactions assigned to each RDO.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" className="gap-2" onClick={() => setRefreshKey((value) => value + 1)}>
            <RefreshCw className="h-3.5 w-3.5" /> Refresh
          </Button>
          <Button size="sm" className="gap-2" onClick={() => setOpen(true)}>
            <Plus className="h-3.5 w-3.5" /> Register RDO
          </Button>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {[
          { label: "Registered RDOs", value: totals.offices, sub: "Revenue District Offices", icon: Landmark },
          { label: "Active RDO Portals", value: totals.active, sub: "Operational portal accounts", icon: UserCheck },
          { label: "Businesses Covered", value: totals.businesses, sub: "Mapped by business account RDO code", icon: Building2 },
          { label: "Transactions Covered", value: totals.transactions, sub: "Scoped NUERS records", icon: ShieldCheck },
        ].map((item) => (
          <Card key={item.label}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{item.label}</span>
                <item.icon className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="mt-2 text-2xl font-bold tabular-nums text-foreground">{item.value.toLocaleString()}</p>
              <p className="mt-1 text-[10px] text-muted-foreground">{item.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {error && (
        <Card className="border-destructive/30">
          <CardContent className="p-4 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <CardTitle className="text-sm">RDO Office Registry</CardTitle>
              <p className="text-xs text-muted-foreground">Each RDO has its own portal for scoped businesses, receipts, transactions, and compliance review.</p>
            </div>
            <div className="relative w-full md:w-80">
              <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input value={search} onChange={(event) => setSearch(event.target.value)} className="h-9 pl-8 text-xs" placeholder="Search RDO, city, officer..." />
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid gap-3 lg:grid-cols-2">
            {loading ? (
              <div className="col-span-full rounded-lg border p-6 text-sm text-muted-foreground">Loading RDO offices from MySQL...</div>
            ) : filteredOffices.map((office) => (
              <div key={office.rdo_code} className="rounded-lg border p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-sm font-semibold text-foreground">{office.rdo_code} · {office.rdo_name}</p>
                      <Badge variant="outline" className="border-success/20 bg-success/10 text-[10px] text-success">
                        {office.status}
                      </Badge>
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">{office.region} · {office.city ?? "Coverage pending"}</p>
                  </div>
                  <Badge variant="outline" className="h-8 px-3 text-xs">
                    Registered RDO
                  </Badge>
                </div>
                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                  <div className="rounded-md bg-secondary/40 p-3">
                    <p className="text-[10px] uppercase text-muted-foreground">Businesses</p>
                    <p className="text-lg font-bold text-foreground">{office.business_count}</p>
                  </div>
                  <div className="rounded-md bg-secondary/40 p-3">
                    <p className="text-[10px] uppercase text-muted-foreground">Transactions</p>
                    <p className="text-lg font-bold text-foreground">{office.transaction_count}</p>
                  </div>
                  <div className="rounded-md bg-secondary/40 p-3">
                    <p className="text-[10px] uppercase text-muted-foreground">Portal</p>
                    <p className="text-lg font-bold text-foreground">{office.email ? "Ready" : "Pending"}</p>
                  </div>
                </div>
                <div className="mt-4 flex items-center gap-3 text-xs text-muted-foreground">
                  <MapPin className="h-3.5 w-3.5" />
                  <span className="line-clamp-1">{office.office_address ?? "No office address saved"}</span>
                </div>
                <div className="mt-3 flex items-center gap-2">
                  <Progress value={Math.min(100, office.business_count * 20)} className="h-1.5" />
                  <span className="w-20 text-right text-xs text-muted-foreground">{office.business_count} businesses</span>
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Register Revenue District Office</DialogTitle>
            <DialogDescription>
              Create an RDO profile and optional portal account. Businesses are automatically scoped by matching business account RDO code.
            </DialogDescription>
          </DialogHeader>

          <div className="grid max-h-[70vh] gap-4 overflow-y-auto pr-1 sm:grid-cols-2">
            <div className="space-y-2">
              <Label>RDO Code *</Label>
              <Input value={form.rdo_code} onChange={(event) => setForm({ ...form, rdo_code: event.target.value })} placeholder="047" />
            </div>
            <div className="space-y-2">
              <Label>RDO Name *</Label>
              <Input value={form.rdo_name} onChange={(event) => setForm({ ...form, rdo_name: event.target.value })} placeholder="RDO 047 - East Makati" />
            </div>
            <div className="space-y-2">
              <Label>Region *</Label>
              <Select value={form.region} onValueChange={(value) => setForm({ ...form, region: value })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {regionOptions.map((region) => <SelectItem key={region} value={region}>{region}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>City / Municipality</Label>
              <Input value={form.city} onChange={(event) => setForm({ ...form, city: event.target.value })} placeholder="Makati City" />
            </div>
            <div className="space-y-2 sm:col-span-2">
              <Label>Office Address</Label>
              <Textarea value={form.office_address} onChange={(event) => setForm({ ...form, office_address: event.target.value })} placeholder="Full RDO office address" />
            </div>
            <div className="space-y-2">
              <Label>RDO Head / Officer</Label>
              <Input value={form.head_name} onChange={(event) => setForm({ ...form, head_name: event.target.value })} placeholder="Revenue Officer name" />
            </div>
            <div className="space-y-2">
              <Label>Phone</Label>
              <Input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} placeholder="+63..." />
            </div>
            <div className="space-y-2">
              <Label>Portal Email</Label>
              <Input value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} placeholder="rdo047@nuers.net" />
            </div>
            <div className="space-y-2">
              <Label>Portal Password</Label>
              <Input type="password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} placeholder="Minimum 6 characters" />
            </div>
            <div className="space-y-2 sm:col-span-2">
              <Label>Coverage Area</Label>
              <Input value={form.coverage_area} onChange={(event) => setForm({ ...form, coverage_area: event.target.value })} placeholder="Makati City, Bel-Air, Ayala Center" />
            </div>
            <div className="space-y-2 sm:col-span-2">
              <Label>Notes</Label>
              <Textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} placeholder="Operational notes, rollout status, or coverage reminders" />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
            <Button onClick={handleSubmit} disabled={saving}>
              {saving ? "Saving..." : <><CheckCircle2 className="mr-2 h-4 w-4" /> Register RDO</>}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
