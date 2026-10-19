import { formatTin } from "@/lib/tin";
import {
  AlertTriangle,
  Shield,
  Brain,
  TrendingDown,
  Search,
  Filter,
  Eye,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  ResponsiveContainer,
  RadarChart,
  Radar,
  PolarGrid,
  PolarAngleAxis,
  Tooltip,
} from "recharts";

const anomalyTrend = [
  { week: "W1", detected: 23, confirmed: 18, falsePositive: 5 },
  { week: "W2", detected: 31, confirmed: 24, falsePositive: 7 },
  { week: "W3", detected: 28, confirmed: 21, falsePositive: 7 },
  { week: "W4", detected: 47, confirmed: 38, falsePositive: 9 },
];

const riskCategories = [
  { category: "Sales Suppression", score: 85 },
  { category: "Invoice Anomaly", score: 62 },
  { category: "Ghost Transactions", score: 45 },
  { category: "Under-Reporting", score: 78 },
  { category: "Phantom Returns", score: 55 },
  { category: "Multiple TIN", score: 30 },
];

const suspiciousMerchants = [
  { name: "ABC Trading Co.", tin: "123-456-789", score: 94, type: "Sales Suppression", region: "NCR", revenue: "₱2.4M" },
  { name: "XYZ Enterprises", tin: "234-567-890", score: 87, type: "Invoice Anomaly", region: "Region III", revenue: "₱890K" },
  { name: "Quick Mart Chain", tin: "345-678-901", score: 82, type: "Under-Reporting", region: "NCR", revenue: "₱5.1M" },
  { name: "Metro Supplies", tin: "456-789-012", score: 76, type: "Pattern Deviation", region: "Region IV-A", revenue: "₱1.2M" },
  { name: "Island Commerce", tin: "567-890-123", score: 71, type: "Ghost Transactions", region: "Region VII", revenue: "₱670K" },
];

export function AdminRiskDetection() {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">AI Risk Detection</h1>
          <p className="text-sm text-muted-foreground">Machine learning-powered anomaly detection</p>
        </div>
        <Badge variant="secondary" className="gap-1.5">
          <Brain className="h-3 w-3" />
          AI Engine v3.2 Active
        </Badge>
      </div>

      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "Active Investigations", value: "47", icon: Eye, color: "text-primary" },
          { label: "High Risk Business Accounts", value: "12", icon: AlertTriangle, color: "text-destructive" },
          { label: "Tax Leakage Detected", value: "₱84.2M", icon: TrendingDown, color: "text-warning" },
          { label: "AI Accuracy", value: "96.8%", icon: Shield, color: "text-success" },
        ].map((stat) => (
          <Card key={stat.label}>
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{stat.label}</span>
                <stat.icon className={`h-4 w-4 ${stat.color}`} />
              </div>
              <p className="mt-2 text-2xl font-bold text-foreground">{stat.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Charts */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardContent className="p-5">
            <h3 className="text-sm font-semibold text-foreground mb-4">Anomaly Detection Trends</h3>
            <ResponsiveContainer width="100%" height={220}>
              <BarChart data={anomalyTrend}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="week" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Bar dataKey="confirmed" fill="var(--destructive)" radius={[4, 4, 0, 0]} stackId="a" />
                <Bar dataKey="falsePositive" fill="var(--chart-4)" radius={[4, 4, 0, 0]} stackId="a" />
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-5">
            <h3 className="text-sm font-semibold text-foreground mb-4">Risk Category Analysis</h3>
            <ResponsiveContainer width="100%" height={220}>
              <RadarChart data={riskCategories}>
                <PolarGrid stroke="var(--border)" />
                <PolarAngleAxis dataKey="category" tick={{ fontSize: 9 }} stroke="var(--muted-foreground)" />
                <Radar dataKey="score" stroke="var(--chart-1)" fill="var(--chart-1)" fillOpacity={0.2} />
              </RadarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      </div>

      {/* Suspicious Business Accounts Table */}
      <Card>
        <CardContent className="p-5">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-sm font-semibold text-foreground">High-Risk Business Accounts</h3>
            <div className="flex gap-2">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input placeholder="Search..." className="h-8 w-48 pl-8 text-xs" />
              </div>
              <Button variant="outline" size="sm" className="h-8 gap-1 text-xs">
                <Filter className="h-3 w-3" /> Filter
              </Button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Business Account</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">TIN</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Anomaly Type</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Region</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Revenue</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Risk Score</th>
                  <th className="pb-3 text-xs font-medium text-muted-foreground">Action</th>
                </tr>
              </thead>
              <tbody>
                {suspiciousMerchants.map((m) => (
                  <tr key={formatTin(m.tin)} className="border-b last:border-0">
                    <td className="py-3 text-xs font-medium text-foreground">{m.name}</td>
                    <td className="py-3 text-xs text-muted-foreground font-mono">{formatTin(m.tin)}</td>
                    <td className="py-3">
                      <Badge variant="secondary" className="text-[10px]">{m.type}</Badge>
                    </td>
                    <td className="py-3 text-xs text-muted-foreground">{m.region}</td>
                    <td className="py-3 text-xs text-foreground">{m.revenue}</td>
                    <td className="py-3">
                      <div className="flex items-center gap-2">
                        <Progress value={m.score} className="h-1.5 w-16" />
                        <span className={`text-xs font-bold ${m.score > 80 ? "text-destructive" : "text-warning"}`}>
                          {m.score}
                        </span>
                      </div>
                    </td>
                    <td className="py-3">
                      <Button variant="ghost" size="sm" className="h-7 text-xs">Investigate</Button>
                    </td>
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
