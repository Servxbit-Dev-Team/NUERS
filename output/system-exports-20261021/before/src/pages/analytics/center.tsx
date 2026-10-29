import {
  TrendingUp,
  ArrowUpRight,
  Download,
  Calendar,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  AreaChart,
  Area,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  Legend,
} from "recharts";

const monthlyRevenue = [
  { month: "Jul", actual: 38.2, forecast: 37 },
  { month: "Aug", actual: 41.5, forecast: 40 },
  { month: "Sep", actual: 39.8, forecast: 41 },
  { month: "Oct", actual: 45.2, forecast: 43 },
  { month: "Nov", actual: 48.7, forecast: 46 },
  { month: "Dec", actual: 52.1, forecast: 49 },
  { month: "Jan", actual: 47.3, forecast: 48 },
  { month: "Feb", actual: 49.8, forecast: 50 },
  { month: "Mar", actual: 53.4, forecast: 52 },
  { month: "Apr", actual: 51.2, forecast: 53 },
  { month: "May", actual: 56.7, forecast: 55 },
  { month: "Jun", actual: null, forecast: 58 },
];

const sectorPerformance = [
  { sector: "Retail", q1: 125, q2: 142, q3: 138, q4: 156 },
  { sector: "F&B", q1: 89, q2: 95, q3: 102, q4: 118 },
  { sector: "Services", q1: 67, q2: 72, q3: 78, q4: 85 },
  { sector: "E-Commerce", q1: 45, q2: 58, q3: 72, q4: 89 },
  { sector: "Manufacturing", q1: 34, q2: 36, q3: 38, q4: 42 },
];

const regionBreakdown = [
  { name: "NCR", value: 42 },
  { name: "Central Luzon", value: 15 },
  { name: "CALABARZON", value: 18 },
  { name: "Central Visayas", value: 10 },
  { name: "Davao Region", value: 8 },
  { name: "Others", value: 7 },
];

const COLORS = ["var(--chart-1)", "var(--chart-2)", "var(--chart-3)", "var(--chart-4)", "var(--chart-5)", "var(--muted-foreground)"];

const complianceData = [
  { month: "Jan", rate: 94.2 },
  { month: "Feb", rate: 94.8 },
  { month: "Mar", rate: 95.5 },
  { month: "Apr", rate: 96.1 },
  { month: "May", rate: 97.2 },
  { month: "Jun", rate: 98.2 },
];

export function AnalyticsCenter() {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Analytics & Reporting Center</h1>
          <p className="text-sm text-muted-foreground">Nationwide revenue intelligence and forecasting</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="gap-2 text-xs">
            <Calendar className="h-3.5 w-3.5" /> FY 2025-2026
          </Button>
          <Button size="sm" className="gap-2 text-xs">
            <Download className="h-3.5 w-3.5" /> Export Report
          </Button>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "YTD Revenue", value: "₱456.7B", change: "+14.2%", sub: "vs last FY" },
          { label: "Monthly Avg", value: "₱50.7B", change: "+8.4%", sub: "6-mo rolling" },
          { label: "Compliance Rate", value: "98.2%", change: "+4.0pp", sub: "vs Jan baseline" },
          { label: "Tax Leakage", value: "₱2.1B", change: "-42%", sub: "reduction YoY" },
        ].map((kpi) => (
          <Card key={kpi.label}>
            <CardContent className="p-5">
              <span className="text-xs text-muted-foreground">{kpi.label}</span>
              <p className="mt-1 text-2xl font-bold text-foreground">{kpi.value}</p>
              <div className="mt-1 flex items-center gap-1 text-xs">
                <ArrowUpRight className="h-3 w-3 text-success" />
                <span className="text-success">{kpi.change}</span>
                <span className="text-muted-foreground">{kpi.sub}</span>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="revenue">
        <TabsList>
          <TabsTrigger value="revenue">Revenue</TabsTrigger>
          <TabsTrigger value="sectors">Sectors</TabsTrigger>
          <TabsTrigger value="regions">Regions</TabsTrigger>
          <TabsTrigger value="compliance">Compliance</TabsTrigger>
        </TabsList>

        <TabsContent value="revenue" className="mt-6">
          <Card>
            <CardContent className="p-5">
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h3 className="text-sm font-semibold text-foreground">Revenue vs Forecast</h3>
                  <p className="text-xs text-muted-foreground">Monthly collection in Billion PHP</p>
                </div>
                <Badge variant="secondary" className="gap-1 text-xs">
                  <TrendingUp className="h-3 w-3" /> AI Forecast Active
                </Badge>
              </div>
              <ResponsiveContainer width="100%" height={300}>
                <AreaChart data={monthlyRevenue}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <Tooltip />
                  <Legend />
                  <Area type="monotone" dataKey="actual" stroke="var(--chart-1)" fill="var(--chart-1)" fillOpacity={0.15} strokeWidth={2} name="Actual" />
                  <Area type="monotone" dataKey="forecast" stroke="var(--chart-2)" fill="var(--chart-2)" fillOpacity={0.05} strokeWidth={2} strokeDasharray="5 5" name="AI Forecast" />
                </AreaChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="sectors" className="mt-6">
          <Card>
            <CardContent className="p-5">
              <h3 className="text-sm font-semibold text-foreground mb-4">Sector Performance (Billion PHP)</h3>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={sectorPerformance}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="sector" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <Tooltip />
                  <Legend />
                  <Bar dataKey="q1" fill="var(--chart-1)" radius={[2, 2, 0, 0]} name="Q1" />
                  <Bar dataKey="q2" fill="var(--chart-2)" radius={[2, 2, 0, 0]} name="Q2" />
                  <Bar dataKey="q3" fill="var(--chart-3)" radius={[2, 2, 0, 0]} name="Q3" />
                  <Bar dataKey="q4" fill="var(--chart-4)" radius={[2, 2, 0, 0]} name="Q4" />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="regions" className="mt-6">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardContent className="p-5">
                <h3 className="text-sm font-semibold text-foreground mb-4">Regional Revenue Share</h3>
                <ResponsiveContainer width="100%" height={280}>
                  <PieChart>
                    <Pie data={regionBreakdown} cx="50%" cy="50%" outerRadius={100} dataKey="value" label={({ name, value }) => `${name} ${value}%`}>
                      {regionBreakdown.map((_, index) => (
                        <Cell key={index} fill={COLORS[index]} />
                      ))}
                    </Pie>
                    <Tooltip />
                  </PieChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-5">
                <h3 className="text-sm font-semibold text-foreground mb-4">AI-Generated Insights</h3>
                <div className="space-y-4">
                  {[
                    { title: "E-Commerce Growth", desc: "E-Commerce sector showing 97% YoY growth, projected to surpass F&B by Q3 2027." },
                    { title: "NCR Saturation", desc: "NCR approaching market saturation at 98.7% compliance. Focus expansion on Region VI and X." },
                    { title: "Revenue Forecast", desc: "AI models predict ₱58B collection for June, 5.2% above government target." },
                    { title: "Tax Leakage Reduction", desc: "AI risk detection reduced estimated tax leakage by 42% since system deployment." },
                  ].map((insight) => (
                    <div key={insight.title} className="rounded-lg border p-3">
                      <p className="text-xs font-semibold text-foreground">{insight.title}</p>
                      <p className="mt-1 text-xs text-muted-foreground leading-relaxed">{insight.desc}</p>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="compliance" className="mt-6">
          <Card>
            <CardContent className="p-5">
              <h3 className="text-sm font-semibold text-foreground mb-4">National Compliance Rate Trend</h3>
              <ResponsiveContainer width="100%" height={280}>
                <LineChart data={complianceData}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <YAxis tick={{ fontSize: 11 }} domain={[90, 100]} stroke="var(--muted-foreground)" />
                  <Tooltip />
                  <Line type="monotone" dataKey="rate" stroke="var(--success)" strokeWidth={3} dot={{ r: 4 }} name="Compliance %" />
                </LineChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
