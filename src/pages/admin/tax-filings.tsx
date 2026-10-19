import { formatTin } from "@/lib/tin";
import { useState } from "react";
import {
  FileText, CheckCircle2, Clock, AlertTriangle,
  Download, Search, Calendar,
} from "lucide-react";
import { toast } from "sonner";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  BarChart, Bar, XAxis, YAxis,
  CartesianGrid, ResponsiveContainer, Tooltip,
} from "recharts";

const vatMonthlyData = [
  { period: "Jan", filed: 2340, pending: 87, overdue: 12 },
  { period: "Feb", filed: 2280, pending: 95, overdue: 8 },
  { period: "Mar", filed: 2410, pending: 72, overdue: 15 },
  { period: "Apr", filed: 2356, pending: 81, overdue: 6 },
  { period: "May", filed: 2389, pending: 68, overdue: 4 },
];

const revenueByType = [
  { type: "VAT", amount: 12.4 },
  { type: "Income Tax", amount: 8.7 },
  { type: "Withholding", amount: 4.2 },
  { type: "Excise", amount: 2.1 },
  { type: "Documentary", amount: 0.8 },
];

const statusConfig: Record<string, { label: string; variant: "default" | "secondary" | "destructive" | "outline"; icon: React.ElementType }> = {
  filed: { label: "Filed", variant: "default", icon: CheckCircle2 },
  pending: { label: "Pending", variant: "secondary", icon: Clock },
  overdue: { label: "Overdue", variant: "destructive", icon: AlertTriangle },
  amended: { label: "Amended", variant: "outline", icon: FileText },
};

// Generate realistic mock filings
const MOCK_FILINGS = Array.from({ length: 30 }, (_, i) => ({
  id: `FIL-${100 + i}`,
  merchant_id: `MCH-${i % 10}`,
  business_name: [
    "SM Supermalls", "Jollibee Foods", "Mercury Drug", "Robinsons Retail",
    "Puregold", "ABC Trading", "XYZ Enterprises", "Cebu Pacific", "BDO Unibank", "Quick Mart",
  ][i % 10],
  tin: `${String(i * 111 + 123).padStart(3, "0")}-${String(i * 222 + 456).padStart(3, "0")}-${String(i * 333 + 789).padStart(3, "0")}`,
  filing_type: i % 3 === 0 ? "Income Tax" : "VAT",
  period: `${["Jan", "Feb", "Mar", "Apr", "May"][i % 5]} 2026`,
  gross_sales: Math.floor(Math.random() * 50000000) + 100000,
  vat_payable: Math.floor(Math.random() * 6000000) + 12000,
  income_tax: Math.floor(Math.random() * 3000000) + 5000,
  status: i % 7 === 0 ? "overdue" : i % 4 === 0 ? "pending" : "filed",
  due_date: `2026-05-${String(15 + (i % 10)).padStart(2, "0")}`,
  filed_date: i % 4 === 0 ? null : `2026-05-${String(10 + (i % 10)).padStart(2, "0")}`,
}));

function formatPeso(n: number) {
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(2)}M`;
  return `₱${(n / 1_000).toFixed(0)}K`;
}

export function AdminTaxFilings() {
  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");

  const filtered = MOCK_FILINGS.filter((f) => {
    const matchSearch = f.business_name.toLowerCase().includes(search.toLowerCase()) || f.tin.includes(search);
    const matchType = typeFilter === "all" || f.filing_type === typeFilter;
    const matchStatus = statusFilter === "all" || f.status === statusFilter;
    return matchSearch && matchType && matchStatus;
  });

  const totalVAT = MOCK_FILINGS.reduce((s, f) => s + f.vat_payable, 0);
  const totalIncomeTax = MOCK_FILINGS.reduce((s, f) => s + f.income_tax, 0);
  const overdue = MOCK_FILINGS.filter((f) => f.status === "overdue").length;
  const complianceRate = Math.round((MOCK_FILINGS.filter((f) => f.status === "filed").length / MOCK_FILINGS.length) * 100);

  function handleExportBIR() {
    const rows = [
      ["Filing ID", "Business Name", "TIN", "Filing Type", "Period", "Gross Sales", "VAT Due", "Income Tax", "Due Date", "Status"],
      ...filtered.map((f) => [f.id, f.business_name, f.tin, f.filing_type, f.period, f.gross_sales, f.vat_payable, f.income_tax, f.due_date, f.status]),
    ];
    const csv = rows.map((r) => r.join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "bir_tax_filings_report.csv";
    a.click();
    URL.revokeObjectURL(url);
    toast.success("BIR report exported as CSV.");
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Tax Filings & Returns</h1>
          <p className="text-sm text-muted-foreground">VAT, income tax, and withholding tax monitoring</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="gap-2">
            <Calendar className="h-4 w-4" /> May 2026
          </Button>
          <Button size="sm" className="gap-2" onClick={handleExportBIR}>
            <Download className="h-4 w-4" /> Export BIR Report
          </Button>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid gap-4 sm:grid-cols-4">
        {[
          { label: "Total VAT Collected", value: formatPeso(totalVAT), icon: FileText, color: "border-l-primary" },
          { label: "Income Tax Collected", value: formatPeso(totalIncomeTax), icon: CheckCircle2, color: "border-l-success" },
          { label: "Overdue Filings", value: overdue.toString(), icon: AlertTriangle, color: "border-l-destructive" },
          { label: "Filing Compliance", value: `${complianceRate}%`, icon: Clock, color: "border-l-warning" },
        ].map((s) => (
          <Card key={s.label} className={`border-l-4 ${s.color}`}>
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{s.label}</span>
                <s.icon className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="mt-1 text-2xl font-bold text-foreground">{s.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="filings">
        <TabsList>
          <TabsTrigger value="filings">Filing Records</TabsTrigger>
          <TabsTrigger value="analytics">Analytics</TabsTrigger>
        </TabsList>

        <TabsContent value="filings" className="mt-6">
          <Card>
            <CardContent className="p-5">
              <div className="mb-4 flex flex-wrap gap-3">
                <div className="relative flex-1 min-w-0 basis-full sm:min-w-[200px] sm:basis-auto">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input placeholder="Search business account or TIN..." className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} />
                </div>
                <Select value={typeFilter} onValueChange={setTypeFilter}>
                  <SelectTrigger className="w-full sm:w-40">
                    <SelectValue placeholder="Filing Type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Types</SelectItem>
                    <SelectItem value="VAT">VAT</SelectItem>
                    <SelectItem value="Income Tax">Income Tax</SelectItem>
                  </SelectContent>
                </Select>
                <Select value={statusFilter} onValueChange={setStatusFilter}>
                  <SelectTrigger className="w-full sm:w-36">
                    <SelectValue placeholder="Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="filed">Filed</SelectItem>
                    <SelectItem value="pending">Pending</SelectItem>
                    <SelectItem value="overdue">Overdue</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b">
                      {["Business", "TIN", "Type", "Period", "Gross Sales", "VAT Due", "Income Tax", "Due Date", "Status"].map((h) => (
                        <th key={h} className="pb-3 text-left text-xs font-medium text-muted-foreground">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((f) => {
                      const sc = statusConfig[f.status] ?? statusConfig.pending;
                      return (
                        <tr key={f.id} className="border-b last:border-0 hover:bg-muted/30">
                          <td className="py-3 text-xs font-medium text-foreground">{f.business_name}</td>
                          <td className="py-3 font-mono text-xs text-muted-foreground">{formatTin(f.tin)}</td>
                          <td className="py-3">
                            <Badge variant="outline" className="text-[10px]">{f.filing_type}</Badge>
                          </td>
                          <td className="py-3 text-xs text-muted-foreground">{f.period}</td>
                          <td className="py-3 text-xs text-foreground">{formatPeso(f.gross_sales)}</td>
                          <td className="py-3 text-xs text-foreground">{formatPeso(f.vat_payable)}</td>
                          <td className="py-3 text-xs text-foreground">{formatPeso(f.income_tax)}</td>
                          <td className="py-3 text-xs text-muted-foreground">{f.due_date}</td>
                          <td className="py-3">
                            <Badge variant={sc.variant} className="gap-1 text-[10px]">
                              <sc.icon className="h-2.5 w-2.5" />
                              {sc.label}
                            </Badge>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <p className="mt-3 text-xs text-muted-foreground">Showing {filtered.length} of {MOCK_FILINGS.length} records</p>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="analytics" className="mt-6 space-y-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardContent className="p-5">
                <h3 className="mb-4 text-sm font-semibold text-foreground">Monthly Filing Status</h3>
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={vatMonthlyData}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                    <XAxis dataKey="period" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                    <Tooltip />
                    <Bar dataKey="filed" fill="var(--chart-1)" radius={[4, 4, 0, 0]} name="Filed" stackId="a" />
                    <Bar dataKey="pending" fill="var(--chart-4)" radius={[4, 4, 0, 0]} name="Pending" stackId="a" />
                    <Bar dataKey="overdue" fill="var(--destructive)" radius={[4, 4, 0, 0]} name="Overdue" stackId="a" />
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-5">
                <h3 className="mb-4 text-sm font-semibold text-foreground">Revenue by Tax Type (₱B)</h3>
                <div className="space-y-3">
                  {revenueByType.map((r) => (
                    <div key={r.type} className="space-y-1">
                      <div className="flex items-center justify-between text-xs">
                        <span className="text-foreground">{r.type}</span>
                        <span className="font-medium text-foreground">₱{r.amount}B</span>
                      </div>
                      <Progress value={(r.amount / 14) * 100} className="h-2" />
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
