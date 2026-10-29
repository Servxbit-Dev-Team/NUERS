import { useMemo, useState } from "react";
import {
  Activity,
  AlertTriangle,
  Building2,
  Download,
  Landmark,
  MapPin,
  Receipt,
  RefreshCw,
  Search,
  ShieldCheck,
  TrendingUp,
} from "lucide-react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useRdoDashboard } from "@/lib/rdo-data";

function money(value: number) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(value);
}

function statusClass(status: string) {
  const normalized = status.toLowerCase();
  if (normalized.includes("active") || normalized.includes("completed") || normalized.includes("low")) return "border-success/20 bg-success/10 text-success";
  if (normalized.includes("high") || normalized.includes("suspended")) return "border-destructive/20 bg-destructive/10 text-destructive";
  if (normalized.includes("pending") || normalized.includes("review") || normalized.includes("medium")) return "border-warning/20 bg-warning/10 text-warning";
  return "border-border bg-secondary text-muted-foreground";
}

export function RdoPortal() {
  const params = new URLSearchParams(window.location.search);
  const code = params.get("code") ?? undefined;
  const { data, loading, error } = useRdoDashboard(code);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  const filteredBusinesses = useMemo(() => {
    const q = search.trim().toLowerCase();

    return data.businesses.filter((business) => {
      const matchesSearch = !q || [
        business.business_name,
        business.tin,
        business.city,
        business.barangay,
        business.sector,
      ].filter(Boolean).some((value) => String(value).toLowerCase().includes(q));
      const matchesStatus = statusFilter === "all" || business.status === statusFilter;

      return matchesSearch && matchesStatus;
    });
  }, [data.businesses, search, statusFilter]);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-foreground">RDO Business and Transaction Portal</h1>
            <Badge variant="secondary" className="gap-1.5 text-xs">
              <Landmark className="h-3 w-3" /> {data.office?.rdo_code ?? "RDO"} Scoped View
            </Badge>
          </div>
          <p className="mt-1 max-w-5xl text-sm text-muted-foreground">
            View businesses, electronic official receipts, transaction activity, VAT capture, and compliance cases under this Revenue District Office.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" className="gap-2" onClick={() => window.location.reload()}>
            <RefreshCw className="h-3.5 w-3.5" /> Refresh
          </Button>
          <Button size="sm" className="gap-2">
            <Download className="h-3.5 w-3.5" /> Export RDO Pack
          </Button>
        </div>
      </div>

      {loading && (
        <Card>
          <CardContent className="p-4 text-sm text-muted-foreground">Loading RDO-scoped data from MySQL...</CardContent>
        </Card>
      )}

      {error && (
        <Card className="border-destructive/30">
          <CardContent className="p-4 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      {data.office && (
        <Card>
          <CardContent className="p-4">
            <div className="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-center">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-lg font-bold text-foreground">{data.office.rdo_code} · {data.office.rdo_name}</p>
                  <Badge variant="outline" className={statusClass(data.office.status)}>{data.office.status}</Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">{data.office.region} · {data.office.city}</p>
                <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                  <MapPin className="h-3.5 w-3.5" />
                  <span>{data.office.office_address}</span>
                </div>
              </div>
              <div className="grid gap-2 text-xs sm:grid-cols-3 lg:w-[420px]">
                <div className="rounded-md bg-secondary/50 p-3">
                  <p className="text-muted-foreground">Officer</p>
                  <p className="mt-1 font-semibold text-foreground">{data.office.head_name ?? "Unassigned"}</p>
                </div>
                <div className="rounded-md bg-secondary/50 p-3">
                  <p className="text-muted-foreground">Portal Email</p>
                  <p className="mt-1 truncate font-semibold text-foreground">{data.office.email ?? "Pending"}</p>
                </div>
                <div className="rounded-md bg-secondary/50 p-3">
                  <p className="text-muted-foreground">Coverage</p>
                  <p className="mt-1 font-semibold text-foreground">{data.office.coverage_area.length} areas</p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        {data.kpis.map((item, index) => {
          const icons = [Building2, Activity, TrendingUp, ShieldCheck, Receipt, AlertTriangle];
          const Icon = icons[index % icons.length];
          return (
            <Card key={item.label}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted-foreground">{item.label}</span>
                  <Icon className="h-4 w-4 text-muted-foreground" />
                </div>
                <p className="mt-2 text-xl font-bold tabular-nums text-foreground">{item.value}</p>
                <p className="mt-1 text-[10px] text-muted-foreground">{item.subtext}</p>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">RDO Revenue and VAT Trend</CardTitle>
            <p className="text-xs text-muted-foreground">Revenue and VAT captured by transactions under this RDO, shown in PHP thousands.</p>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={260}>
              <AreaChart data={data.series}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Area type="monotone" dataKey="revenue" name="Revenue (K)" stroke="var(--chart-1)" fill="var(--chart-1)" fillOpacity={0.14} strokeWidth={2} />
                <Area type="monotone" dataKey="vat" name="VAT (K)" stroke="var(--chart-2)" fill="var(--chart-2)" fillOpacity={0.18} strokeWidth={2} />
              </AreaChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Transaction Volume</CardTitle>
            <p className="text-xs text-muted-foreground">Daily transaction count under this RDO.</p>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={260}>
              <BarChart data={data.series}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Bar dataKey="transactions" name="Transactions" fill="var(--chart-3)" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader className="pb-3">
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div>
                <CardTitle className="text-sm">Businesses Under This RDO</CardTitle>
                <p className="text-xs text-muted-foreground">Business account registry filtered by RDO code and compliance posture.</p>
              </div>
              <div className="flex flex-col gap-2 sm:flex-row">
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input value={search} onChange={(event) => setSearch(event.target.value)} className="h-9 w-full pl-8 text-xs sm:w-64" placeholder="Search business, TIN..." />
                </div>
                <Select value={statusFilter} onValueChange={setStatusFilter}>
                  <SelectTrigger className="h-9 w-full text-xs sm:w-36"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Status</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="under_review">Under Review</SelectItem>
                    <SelectItem value="suspended">Suspended</SelectItem>
                    <SelectItem value="pending">Pending</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[780px]">
                <thead>
                  <tr className="border-b">
                    <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Business</th>
                    <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Location</th>
                    <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Sector</th>
                    <th className="pb-3 text-right text-xs font-medium text-muted-foreground">Monthly Revenue</th>
                    <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Status</th>
                    <th className="pb-3 text-right text-xs font-medium text-muted-foreground">Compliance</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredBusinesses.map((business) => (
                    <tr key={business.id} className="border-b last:border-0">
                      <td className="py-3">
                        <p className="text-xs font-semibold text-foreground">{business.business_name}</p>
                        <p className="font-mono text-[10px] text-muted-foreground">{business.tin}</p>
                      </td>
                      <td className="py-3 text-xs text-muted-foreground">{business.barangay ? `${business.barangay}, ` : ""}{business.city}</td>
                      <td className="py-3 text-xs capitalize text-muted-foreground">{business.sector.replace("_", " ")}</td>
                      <td className="py-3 text-right text-xs font-medium text-foreground">{money(business.monthly_revenue)}</td>
                      <td className="py-3"><Badge variant="outline" className={`text-[10px] ${statusClass(business.status)}`}>{business.status}</Badge></td>
                      <td className="py-3">
                        <div className="ml-auto flex w-28 items-center justify-end gap-2">
                          <Progress value={business.compliance_score} className="h-1.5 w-16" />
                          <span className="text-xs font-bold text-foreground">{business.compliance_score}</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Compliance and Audit Queue</CardTitle>
            <p className="text-xs text-muted-foreground">Businesses requiring RDO attention.</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.riskQueue.length === 0 ? (
              <div className="rounded-lg border p-4 text-sm text-muted-foreground">No elevated RDO compliance cases.</div>
            ) : data.riskQueue.map((item) => (
              <div key={item.tin} className="rounded-lg border p-3">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold text-foreground">{item.business_name}</p>
                    <p className="text-xs text-muted-foreground">{item.issue}</p>
                  </div>
                  <Badge variant="outline" className={`text-[10px] ${statusClass(item.risk_level)}`}>{item.risk_level}</Badge>
                </div>
                <div className="mt-3 flex items-center gap-2">
                  <Progress value={item.score} className="h-1.5" />
                  <span className="w-8 text-right text-xs font-bold text-foreground">{item.score}</span>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm">Recent Transactions Under This RDO</CardTitle>
          <p className="text-xs text-muted-foreground">Electronic receipts and payment transactions linked to businesses in this Revenue District Office.</p>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px]">
              <thead>
                <tr className="border-b">
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Reference</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Business</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Receipt</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Channel</th>
                  <th className="pb-3 text-right text-xs font-medium text-muted-foreground">Amount</th>
                  <th className="pb-3 text-right text-xs font-medium text-muted-foreground">VAT</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.transactions.map((transaction) => (
                  <tr key={transaction.transaction_ref} className="border-b last:border-0">
                    <td className="py-3 text-xs font-mono font-semibold text-foreground">{transaction.transaction_ref}</td>
                    <td className="py-3">
                      <p className="text-xs text-foreground">{transaction.business_name}</p>
                      <p className="font-mono text-[10px] text-muted-foreground">{transaction.tin}</p>
                    </td>
                    <td className="py-3 text-xs font-mono text-muted-foreground">{transaction.receipt_number ?? "Pending EOR"}</td>
                    <td className="py-3 text-xs capitalize text-muted-foreground">{transaction.channel}</td>
                    <td className="py-3 text-right text-xs font-semibold text-foreground">{money(transaction.amount)}</td>
                    <td className="py-3 text-right text-xs text-foreground">{money(transaction.vat_amount)}</td>
                    <td className="py-3"><Badge variant="outline" className={`text-[10px] ${statusClass(transaction.status)}`}>{transaction.status}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
