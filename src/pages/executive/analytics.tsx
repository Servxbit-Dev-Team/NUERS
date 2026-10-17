import {
  TrendingUp, Users, FileText, Shield,
  Globe, Zap, Activity, DollarSign, CheckCircle2,
  Download, RefreshCw, Star,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import type { ChartConfig } from "@/components/ui/chart";
import { AreaChart, Area, BarChart, Bar, Line, XAxis, YAxis, CartesianGrid } from "recharts";

const revenueData = [
  { month: "Dec 25", revenue: 4.2, target: 4.0 },
  { month: "Jan 26", revenue: 4.8, target: 4.5 },
  { month: "Feb 26", revenue: 5.1, target: 4.8 },
  { month: "Mar 26", revenue: 5.8, target: 5.2 },
  { month: "Apr 26", revenue: 6.2, target: 5.6 },
  { month: "May 26", revenue: 6.8, target: 6.0 },
];

const growthData = [
  { month: "Dec", taxpayers: 9840, invoices: 5.2, txns: 184 },
  { month: "Jan", taxpayers: 10240, invoices: 5.8, txns: 198 },
  { month: "Feb", taxpayers: 10840, invoices: 6.1, txns: 212 },
  { month: "Mar", taxpayers: 11420, invoices: 7.2, txns: 248 },
  { month: "Apr", taxpayers: 12184, invoices: 8.4, txns: 271 },
  { month: "May", taxpayers: 12847, invoices: 9.2, txns: 284 },
];

const sectorData = [
  { name: "Retail", value: 28.4, color: "var(--chart-1)" },
  { name: "Finance", value: 22.1, color: "var(--chart-2)" },
  { name: "Telecom", value: 18.6, color: "var(--chart-3)" },
  { name: "Real Estate", value: 12.8, color: "var(--chart-4)" },
  { name: "F&B", value: 9.2, color: "var(--chart-5)" },
  { name: "Others", value: 8.9, color: "var(--chart-1)" },
];

const revenueConfig: ChartConfig = {
  revenue: { label: "Revenue (₱M)", color: "var(--chart-1)" },
  target: { label: "Target", color: "var(--chart-2)" },
};

const growthConfig: ChartConfig = {
  taxpayers: { label: "Taxpayers", color: "var(--chart-1)" },
};

const txnConfig: ChartConfig = {
  txns: { label: "Transmissions (K)", color: "var(--chart-4)" },
};

const topTaxpayers = [
  { rank: 1, name: "SM Prime Holdings, Inc.", tin: "123-456-789-000", invoices: "284,400", revenue: "₱92.4B", compliance: 99.8 },
  { rank: 2, name: "Philippine Airlines", tin: "890-123-456-000", invoices: "192,840", revenue: "₱74.8B", compliance: 99.5 },
  { rank: 3, name: "Globe Telecom, Inc.", tin: "456-789-012-000", invoices: "184,200", revenue: "₱68.2B", compliance: 99.7 },
  { rank: 4, name: "PLDT, Inc.", tin: "678-901-234-000", invoices: "172,100", revenue: "₱62.8B", compliance: 99.6 },
  { rank: 5, name: "Ayala Land, Inc.", tin: "234-567-890-000", invoices: "148,400", revenue: "₱54.1B", compliance: 99.9 },
];

export function ExecutiveAnalytics() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Executive Analytics Center</h1>
          <p className="text-sm text-muted-foreground">Platform-wide KPIs, business intelligence, and executive reporting</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge className="bg-success/15 text-success border-success/30">
            <span className="mr-1.5 h-1.5 w-1.5 rounded-full bg-success animate-pulse inline-block" />
            Real-Time Data
          </Badge>
          <Button variant="outline" size="sm" className="gap-2">
            <RefreshCw className="h-3.5 w-3.5" />
          </Button>
          <Button size="sm" className="gap-2">
            <Download className="h-3.5 w-3.5" /> Executive Report
          </Button>
        </div>
      </div>

      {/* Executive KPIs */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-4">
        {[
          { label: "Total Taxpayers", value: "12,847", delta: "+234 this month", up: true, icon: Users, color: "text-primary" },
          { label: "Active Taxpayers", value: "8,921", delta: "69.4% active rate", up: true, icon: Activity, color: "text-success" },
          { label: "Total Invoices (YTD)", value: "284M+", delta: "+18.4% vs 2025", up: true, icon: FileText, color: "text-primary" },
          { label: "Daily Transmissions", value: "284,392", delta: "+12.8% vs yesterday", up: true, icon: Zap, color: "text-success" },
          { label: "Platform Revenue", value: "₱6.8M", delta: "+9.7% vs last month", up: true, icon: DollarSign, color: "text-gold" },
          { label: "Compliance Score", value: "98.7%", delta: "+0.5% vs last month", up: true, icon: Shield, color: "text-success" },
          { label: "Transmission Rate", value: "99.89%", delta: "+0.02% vs yesterday", up: true, icon: CheckCircle2, color: "text-success" },
          { label: "API Usage", value: "284K", delta: "84% of monthly limit", up: true, icon: Globe, color: "text-primary" },
        ].map((kpi) => (
          <Card key={kpi.label}>
            <CardContent className="p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-xs text-muted-foreground leading-tight mb-1">{kpi.label}</p>
                  <p className="text-xl font-bold tracking-tight">{kpi.value}</p>
                  <p className={`text-xs mt-0.5 flex items-center gap-0.5 ${kpi.up ? "text-success" : "text-destructive"}`}>
                    <TrendingUp className="h-3 w-3" />
                    {kpi.delta}
                  </p>
                </div>
                <kpi.icon className={`h-5 w-5 shrink-0 ${kpi.color}`} />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="overview">
        <TabsList className="h-9">
          <TabsTrigger value="overview" className="text-xs">Overview</TabsTrigger>
          <TabsTrigger value="revenue" className="text-xs">Revenue</TabsTrigger>
          <TabsTrigger value="taxpayers" className="text-xs">Taxpayers</TabsTrigger>
          <TabsTrigger value="sectors" className="text-xs">Sector Analysis</TabsTrigger>
          <TabsTrigger value="predictive" className="text-xs">Predictive</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-4 space-y-4">
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold">Taxpayer Growth (6 Months)</CardTitle>
              </CardHeader>
              <CardContent>
                <ChartContainer config={growthConfig} className="h-52">
                  <AreaChart data={growthData}>
                    <defs>
                      <linearGradient id="tax-g" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="var(--chart-1)" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="var(--chart-1)" stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis dataKey="month" tick={{ fontSize: 10 }} stroke="var(--border)" />
                    <YAxis tick={{ fontSize: 10 }} stroke="var(--border)" />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Area type="monotone" dataKey="taxpayers" stroke="var(--chart-1)" fill="url(#tax-g)" strokeWidth={2.5} />
                  </AreaChart>
                </ChartContainer>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold">Daily Transmission Volume (6 Months)</CardTitle>
              </CardHeader>
              <CardContent>
                <ChartContainer config={txnConfig} className="h-52">
                  <BarChart data={growthData}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis dataKey="month" tick={{ fontSize: 10 }} stroke="var(--border)" />
                    <YAxis tick={{ fontSize: 10 }} stroke="var(--border)" unit="K" />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Bar dataKey="txns" fill="var(--chart-4)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ChartContainer>
              </CardContent>
            </Card>
          </div>

          {/* Top Taxpayers */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold flex items-center gap-2">
                <Star className="h-4 w-4 text-gold" />
                Top 5 Taxpayers by Volume
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b bg-muted/30">
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Rank</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Taxpayer</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">TIN</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Invoices (YTD)</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Revenue</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">Compliance</th>
                  </tr>
                </thead>
                <tbody>
                  {topTaxpayers.map((tp, i) => (
                    <tr key={tp.tin} className={i % 2 === 0 ? "border-b" : "border-b bg-muted/20"}>
                      <td className="px-4 py-2.5">
                        <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-xs">
                          {tp.rank}
                        </div>
                      </td>
                      <td className="px-4 py-2.5 font-medium">{tp.name}</td>
                      <td className="px-4 py-2.5 font-mono text-muted-foreground">{tp.tin}</td>
                      <td className="px-4 py-2.5 font-medium">{tp.invoices}</td>
                      <td className="px-4 py-2.5 font-medium">{tp.revenue}</td>
                      <td className="px-4 py-2.5">
                        <div className="flex items-center gap-2">
                          <Progress value={tp.compliance} className="h-2 w-16" />
                          <span className="font-bold text-success">{tp.compliance}%</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="revenue" className="mt-4 space-y-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold">Platform Revenue vs. Target (6 Months)</CardTitle>
            </CardHeader>
            <CardContent>
              <ChartContainer config={revenueConfig} className="h-64">
                <AreaChart data={revenueData}>
                  <defs>
                    <linearGradient id="rev-g" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="var(--chart-1)" stopOpacity={0.2} />
                      <stop offset="95%" stopColor="var(--chart-1)" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--border)" />
                  <YAxis tick={{ fontSize: 11 }} stroke="var(--border)" unit="M" />
                  <ChartTooltip content={<ChartTooltipContent />} />
                  <Area type="monotone" dataKey="revenue" stroke="var(--chart-1)" fill="url(#rev-g)" strokeWidth={2.5} />
                  <Line type="monotone" dataKey="target" stroke="var(--chart-2)" strokeWidth={1.5} strokeDasharray="5 5" dot={false} />
                </AreaChart>
              </ChartContainer>
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {[
              { label: "Monthly Recurring Revenue", value: "₱6.8M", delta: "+9.7%", note: "Platform fees + transaction fees" },
              { label: "Annual Run Rate", value: "₱81.6M", delta: "+14.2%", note: "Projected at current growth" },
              { label: "Revenue per Taxpayer", value: "₱530", delta: "+6.1%", note: "Average monthly per active taxpayer" },
            ].map((r) => (
              <Card key={r.label}>
                <CardContent className="p-4">
                  <p className="text-xs text-muted-foreground mb-1">{r.label}</p>
                  <p className="text-2xl font-bold">{r.value}</p>
                  <div className="flex items-center gap-1 text-xs text-success mt-1">
                    <TrendingUp className="h-3 w-3" />
                    {r.delta} vs. last month
                  </div>
                  <p className="text-xs text-muted-foreground mt-1">{r.note}</p>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        <TabsContent value="taxpayers" className="mt-4 space-y-4">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {[
              { label: "Total Registered", value: "12,847", pct: null },
              { label: "Active (30-day)", value: "8,921", pct: "69.4%" },
              { label: "VAT Registered", value: "11,842", pct: "92.2%" },
              { label: "EIS Compliant", value: "12,621", pct: "98.2%" },
            ].map((s) => (
              <Card key={s.label}>
                <CardContent className="p-4 text-center">
                  <p className="text-xs text-muted-foreground">{s.label}</p>
                  <p className="text-2xl font-bold mt-1">{s.value}</p>
                  {s.pct && <p className="text-xs text-success mt-0.5">{s.pct}</p>}
                </CardContent>
              </Card>
            ))}
          </div>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold">Taxpayer Distribution by Business Type</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {[
                  { type: "Corporation (Domestic)", count: 8420, pct: 65.5 },
                  { type: "Partnership", count: 2140, pct: 16.7 },
                  { type: "Sole Proprietorship", count: 1284, pct: 10.0 },
                  { type: "Corporation (Foreign)", count: 841, pct: 6.5 },
                  { type: "Government Agency", count: 162, pct: 1.3 },
                ].map((t) => (
                  <div key={t.type} className="space-y-1">
                    <div className="flex items-center justify-between text-xs">
                      <span className="font-medium">{t.type}</span>
                      <span className="text-muted-foreground">{t.count.toLocaleString()} ({t.pct}%)</span>
                    </div>
                    <Progress value={t.pct} className="h-2.5" />
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="sectors" className="mt-4 space-y-4">
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold">Invoice Volume by Industry Sector</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {sectorData.map((s) => (
                    <div key={s.name} className="space-y-1">
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-medium">{s.name}</span>
                        <span className="text-muted-foreground">{s.value}%</span>
                      </div>
                      <Progress value={s.value} className="h-2.5" />
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold">Geographic Distribution (Top Regions)</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {[
                    { region: "National Capital Region (NCR)", count: 7284, pct: 56.7 },
                    { region: "Region VII (Central Visayas)", count: 1842, pct: 14.3 },
                    { region: "Region XI (Davao Region)", count: 1284, pct: 10.0 },
                    { region: "Region III (Central Luzon)", count: 984, pct: 7.7 },
                    { region: "Region IV-A (CALABARZON)", count: 841, pct: 6.5 },
                    { region: "Other Regions", count: 612, pct: 4.8 },
                  ].map((r) => (
                    <div key={r.region} className="space-y-1">
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-medium">{r.region}</span>
                        <span className="text-muted-foreground">{r.count.toLocaleString()} ({r.pct}%)</span>
                      </div>
                      <Progress value={r.pct} className="h-2" />
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="predictive" className="mt-4 space-y-4">
          <Card className="border-primary/30 bg-primary/5">
            <CardContent className="p-4">
              <div className="flex items-center gap-3 mb-3">
                <Zap className="h-5 w-5 text-gold" />
                <p className="text-sm font-semibold">AI-Powered Predictive Insights</p>
                <Badge variant="secondary" className="text-xs">NUERS AI</Badge>
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {[
                  { insight: "Taxpayer growth on track to reach 15,000 by Q4 2026", confidence: 87, type: "growth" },
                  { insight: "Transmission volume projected at 320K/day by Aug 2026", confidence: 82, type: "volume" },
                  { insight: "2 taxpayers showing early non-compliance signals (BDO subsidiary, Region 7)", confidence: 91, type: "risk" },
                ].map((ins, i) => (
                  <Card key={i} className="border">
                    <CardContent className="p-3">
                      <p className="text-xs mb-2">{ins.insight}</p>
                      <div className="flex items-center justify-between">
                        <Progress value={ins.confidence} className="h-1.5 flex-1 mr-2" />
                        <span className="text-xs font-bold text-success">{ins.confidence}%</span>
                      </div>
                      <p className="text-[10px] text-muted-foreground mt-1">Confidence score</p>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {[
              { title: "Capacity Planning", desc: "Current infrastructure supports up to 500K daily transmissions. Scaling needed at ~400K threshold.", action: "Scale Now", urgency: "low" },
              { title: "Revenue Forecast", desc: "Based on current MoM growth of 9.7%, projected annual revenue: ₱84–88M for FY2026.", action: "View Model", urgency: "info" },
              { title: "Anomaly Detection", desc: "3 taxpayers with unusual invoice frequency patterns detected. Possible duplicate submissions.", action: "Investigate", urgency: "medium" },
              { title: "BIR Rate Limit Risk", desc: "Daily rate limit utilization averaging 94.7%. Risk of reaching 100% in peak months (Nov-Dec).", action: "Request Increase", urgency: "high" },
            ].map((ai) => (
              <Card key={ai.title} className={ai.urgency === "high" ? "border-warning/50" : ""}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-2 mb-2">
                    <p className="text-sm font-semibold">{ai.title}</p>
                    {ai.urgency === "high" && <Badge className="bg-warning/15 text-warning-foreground border-warning/30 text-xs">Action Required</Badge>}
                    {ai.urgency === "medium" && <Badge className="bg-destructive/15 text-destructive border-destructive/30 text-xs">Review</Badge>}
                  </div>
                  <p className="text-xs text-muted-foreground mb-3">{ai.desc}</p>
                  <Button variant="outline" size="sm" className="h-7 text-xs">{ai.action}</Button>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
