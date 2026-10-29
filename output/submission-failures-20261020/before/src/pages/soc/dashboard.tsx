import { useEffect, useState } from "react";
import {
  Shield,
  AlertTriangle,
  Globe,
  Lock,
  Eye,
  Activity,
  ShieldAlert,
  Ban,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Progress } from "@/components/ui/progress";
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from "recharts";

const threatData = Array.from({ length: 24 }, (_, i) => ({
  hour: `${i}:00`,
  blocked: Math.floor(Math.random() * 500) + 100,
  suspicious: Math.floor(Math.random() * 50) + 10,
}));

const geoThreats = [
  { country: "China", attempts: 12847, blocked: 12847, percentage: 34 },
  { country: "Russia", attempts: 8234, blocked: 8234, percentage: 22 },
  { country: "Nigeria", attempts: 4521, blocked: 4521, percentage: 12 },
  { country: "Unknown VPN", attempts: 3892, blocked: 3892, percentage: 10 },
  { country: "Brazil", attempts: 2341, blocked: 2341, percentage: 6 },
];

const recentAlerts = [
  { type: "Brute Force", source: "103.45.xx.xx", target: "Auth Service", severity: "Critical", time: "2 min ago" },
  { type: "SQL Injection", source: "45.67.xx.xx", target: "API Gateway", severity: "High", time: "8 min ago" },
  { type: "DDoS Attempt", source: "Multiple IPs", target: "CDN Edge", severity: "Medium", time: "15 min ago" },
  { type: "Invalid Token", source: "192.168.xx.xx", target: "Business Account API", severity: "Low", time: "22 min ago" },
  { type: "Port Scan", source: "78.90.xx.xx", target: "Firewall", severity: "Medium", time: "30 min ago" },
];

export function SocDashboard() {
  const [threatCount, setThreatCount] = useState(38472);

  useEffect(() => {
    const interval = setInterval(() => {
      setThreatCount((p) => p + Math.floor(Math.random() * 3) + 1);
    }, 2000);
    return () => clearInterval(interval);
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Security Operations Center</h1>
          <p className="text-sm text-muted-foreground">Enterprise cybersecurity monitoring</p>
        </div>
        <Badge variant="default" className="gap-1.5">
          <Shield className="h-3 w-3" /> DEFCON 4 - Normal
        </Badge>
      </div>

      {/* Threat Stats */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "Threats Blocked (24h)", value: threatCount.toLocaleString(), icon: Ban, color: "border-l-success" },
          { label: "Active Alerts", value: "7", icon: AlertTriangle, color: "border-l-warning" },
          { label: "Encryption Status", value: "AES-256", icon: Lock, color: "border-l-primary" },
          { label: "DDoS Protection", value: "Active", icon: ShieldAlert, color: "border-l-success" },
        ].map((s) => (
          <Card key={s.label} className={`border-l-4 ${s.color}`}>
            <CardContent className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{s.label}</span>
                <s.icon className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="mt-2 text-xl font-bold text-foreground">{s.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Threat Chart + Geo */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardContent className="p-5">
            <h3 className="text-sm font-semibold text-foreground mb-4">Threat Activity (24h)</h3>
            <ResponsiveContainer width="100%" height={220}>
              <AreaChart data={threatData}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="hour" tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Area type="monotone" dataKey="blocked" stroke="var(--destructive)" fill="var(--destructive)" fillOpacity={0.1} />
                <Area type="monotone" dataKey="suspicious" stroke="var(--chart-4)" fill="var(--chart-4)" fillOpacity={0.1} />
              </AreaChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-5">
            <h3 className="text-sm font-semibold text-foreground mb-4">Geo-IP Threat Sources</h3>
            <div className="space-y-3">
              {geoThreats.map((g) => (
                <div key={g.country} className="space-y-1">
                  <div className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <Globe className="h-3 w-3 text-muted-foreground" />
                      <span className="text-foreground">{g.country}</span>
                    </div>
                    <span className="text-muted-foreground">{g.attempts.toLocaleString()} blocked</span>
                  </div>
                  <Progress value={g.percentage} className="h-1.5" />
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Alerts Table */}
      <Card>
        <CardContent className="p-5">
          <h3 className="text-sm font-semibold text-foreground mb-4">Recent Security Alerts</h3>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Threat Type</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Source</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Target</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Severity</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Time</th>
                </tr>
              </thead>
              <tbody>
                {recentAlerts.map((a, i) => (
                  <tr key={i} className="border-b last:border-0">
                    <td className="py-3 text-xs font-medium text-foreground">{a.type}</td>
                    <td className="py-3 text-xs font-mono text-muted-foreground">{a.source}</td>
                    <td className="py-3 text-xs text-muted-foreground">{a.target}</td>
                    <td className="py-3">
                      <Badge
                        variant={a.severity === "Critical" ? "destructive" : a.severity === "High" ? "destructive" : "secondary"}
                        className="text-[10px]"
                      >
                        {a.severity}
                      </Badge>
                    </td>
                    <td className="py-3 text-xs text-muted-foreground">{a.time}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Security Systems */}
      <div className="grid gap-4 sm:grid-cols-3">
        {[
          { name: "Firewall", status: "Active", rules: "4,892", icon: Shield },
          { name: "IDS/IPS", status: "Monitoring", alerts: "7 active", icon: Eye },
          { name: "SIEM", status: "Collecting", events: "2.4M/day", icon: Activity },
        ].map((sys) => (
          <Card key={sys.name}>
            <CardContent className="p-5 flex items-center gap-4">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-secondary">
                <sys.icon className="h-6 w-6 text-muted-foreground" />
              </div>
              <div>
                <p className="text-sm font-semibold text-foreground">{sys.name}</p>
                <p className="text-xs text-success">{sys.status}</p>
                <p className="text-[10px] text-muted-foreground">
                  {"rules" in sys ? sys.rules + " rules" : "alerts" in sys ? sys.alerts : "events" in sys ? sys.events : ""}
                </p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
