import {
  Shield, AlertTriangle, Eye, Lock, Globe, Zap,
  AlertCircle,
  Bell,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart";
import type { ChartConfig } from "@/components/ui/chart";
import { AreaChart, Area, XAxis, YAxis, CartesianGrid } from "recharts";

const threatData = [
  { hour: "00", threats: 12, blocked: 12, suspicious: 8 },
  { hour: "04", threats: 8, blocked: 8, suspicious: 5 },
  { hour: "08", threats: 34, blocked: 33, suspicious: 18 },
  { hour: "10", threats: 48, blocked: 47, suspicious: 24 },
  { hour: "12", threats: 52, blocked: 51, suspicious: 28 },
  { hour: "14", threats: 38, blocked: 38, suspicious: 20 },
  { hour: "16", threats: 42, blocked: 42, suspicious: 22 },
  { hour: "18", threats: 28, blocked: 28, suspicious: 15 },
  { hour: "20", threats: 18, blocked: 18, suspicious: 10 },
];

const threatConfig: ChartConfig = {
  threats: { label: "Threats Detected", color: "var(--chart-5)" },
  blocked: { label: "Blocked", color: "var(--chart-4)" },
  suspicious: { label: "Suspicious", color: "var(--chart-2)" },
};

const incidents = [
  { id: "SEC-2026-0084", title: "Brute Force Attack Detected", severity: "high", source: "103.20.84.99", target: "auth.nuers.gov.ph", time: "14:38:45", status: "contained", type: "Brute Force" },
  { id: "SEC-2026-0083", title: "SQL Injection Attempt — WAF Blocked", severity: "high", source: "185.220.101.28", target: "api.nuers.gov.ph", time: "13:21:08", status: "blocked", type: "SQLi" },
  { id: "SEC-2026-0082", title: "Suspicious API Access Pattern", severity: "medium", source: "203.112.84.45", target: "api.nuers.gov.ph", time: "11:45:22", status: "investigating", type: "Anomaly" },
  { id: "SEC-2026-0081", title: "DDoS Mitigation Activated", severity: "critical", source: "Multiple (84 IPs)", target: "nuers.gov.ph", time: "09:12:00", status: "mitigated", type: "DDoS" },
  { id: "SEC-2026-0080", title: "Malformed EIS Payload Detected", severity: "low", source: "Internal", target: "eis-gateway", time: "08:30:15", status: "resolved", type: "Validation" },
];

const vulnerabilities = [
  { cve: "CVE-2026-1842", system: "Node.js 20.x", severity: "high", score: 8.2, status: "patching", discovered: "May 28" },
  { cve: "CVE-2026-1281", system: "PostgreSQL 16", severity: "medium", score: 6.8, status: "scheduled", discovered: "May 25" },
  { cve: "CVE-2026-0984", system: "OpenSSL 3.2", severity: "high", score: 7.9, status: "patched", discovered: "May 20" },
  { cve: "CVE-2025-4821", system: "Redis 7.x", severity: "low", score: 3.4, status: "accepted", discovered: "May 15" },
];

const threatIntel = [
  { indicator: "103.20.84.99/32", type: "IP", confidence: 92, source: "NCERT PH", category: "Brute Force", lastSeen: "14:38" },
  { indicator: "185.220.101.28/32", type: "IP", confidence: 88, source: "AbuseIPDB", category: "SQLi Scanner", lastSeen: "13:21" },
  { indicator: "malicious.domain.tk", type: "Domain", confidence: 95, source: "Palo Alto TIP", category: "C2 Server", lastSeen: "Yesterday" },
  { indicator: "a8f4e2c1b9d7ba3e...", type: "File Hash", confidence: 99, source: "VirusTotal", category: "Ransomware", lastSeen: "May 28" },
];

function SeverityBadge({ sev }: { sev: string }) {
  if (sev === "critical") return <Badge variant="destructive" className="text-xs">Critical</Badge>;
  if (sev === "high") return <Badge className="bg-destructive/15 text-destructive border-destructive/30 text-xs">High</Badge>;
  if (sev === "medium") return <Badge className="bg-warning/15 text-warning-foreground border-warning/30 text-xs">Medium</Badge>;
  return <Badge className="bg-success/15 text-success border-success/30 text-xs">Low</Badge>;
}

function IncidentStatusBadge({ status }: { status: string }) {
  const map: Record<string, string> = {
    contained: "bg-warning/15 text-warning-foreground border-warning/30",
    blocked: "bg-success/15 text-success border-success/30",
    investigating: "bg-destructive/15 text-destructive border-destructive/30",
    mitigated: "bg-success/15 text-success border-success/30",
    resolved: "bg-muted text-muted-foreground",
  };
  return <Badge className={`text-xs border ${map[status] || "bg-muted text-muted-foreground"}`}>{status.charAt(0).toUpperCase() + status.slice(1)}</Badge>;
}

export function SOCOperations() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Security Operations Center (SOC)</h1>
          <p className="text-sm text-muted-foreground">SIEM, threat intelligence, incident response, and security monitoring</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge className="bg-success/15 text-success border-success/30">
            <span className="mr-1.5 h-1.5 w-1.5 rounded-full bg-success animate-pulse inline-block" />
            SOC Active — Tier 2 Monitoring
          </Badge>
          <Button size="sm" className="gap-2">
            <Bell className="h-3.5 w-3.5" /> Escalate Incident
          </Button>
        </div>
      </div>

      {/* Critical Alert */}
      <Card className="border-warning/50 bg-warning/5">
        <CardContent className="p-3">
          <div className="flex items-center gap-3">
            <AlertTriangle className="h-4 w-4 text-warning shrink-0" />
            <p className="text-sm"><span className="font-semibold">Active Investigation:</span> SEC-2026-0082 — Suspicious API Access Pattern from 203.112.84.45. Possible credential stuffing. Under investigation by SOC Tier 2.</p>
            <Button size="sm" variant="outline" className="shrink-0 h-7 text-xs">View Incident</Button>
          </div>
        </CardContent>
      </Card>

      {/* KPIs */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {[
          { label: "Threats Today", value: "280", delta: "+48", icon: Shield, color: "text-destructive" },
          { label: "Blocked", value: "279", delta: "99.6%", icon: Lock, color: "text-success" },
          { label: "Open Incidents", value: "2", delta: "-1", icon: AlertCircle, color: "text-warning" },
          { label: "Vulnerabilities", value: "4", delta: "-1 patched", icon: AlertTriangle, color: "text-warning" },
          { label: "WAF Blocks", value: "1,248", delta: "+284", icon: Globe, color: "text-primary" },
          { label: "Security Score", value: "94.2", delta: "+0.8", icon: Zap, color: "text-success" },
        ].map((kpi) => (
          <Card key={kpi.label}>
            <CardContent className="p-4">
              <div className="flex items-start justify-between gap-1">
                <div>
                  <p className="text-xs text-muted-foreground leading-tight mb-1">{kpi.label}</p>
                  <p className="text-xl font-bold">{kpi.value}</p>
                  <p className="text-xs text-muted-foreground mt-0.5">{kpi.delta}</p>
                </div>
                <kpi.icon className={`h-5 w-5 shrink-0 ${kpi.color}`} />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="siem">
        <TabsList className="h-9">
          <TabsTrigger value="siem" className="text-xs">SIEM Dashboard</TabsTrigger>
          <TabsTrigger value="incidents" className="text-xs">
            Incidents
            <Badge className="ml-1.5 h-4 text-[10px] bg-destructive/15 text-destructive border-destructive/30">2</Badge>
          </TabsTrigger>
          <TabsTrigger value="threats" className="text-xs">Threat Intel</TabsTrigger>
          <TabsTrigger value="vulns" className="text-xs">Vulnerabilities</TabsTrigger>
          <TabsTrigger value="controls" className="text-xs">Security Controls</TabsTrigger>
        </TabsList>

        <TabsContent value="siem" className="mt-4 space-y-4">
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold">Threat Detection Timeline</CardTitle>
              </CardHeader>
              <CardContent>
                <ChartContainer config={threatConfig} className="h-52">
                  <AreaChart data={threatData}>
                    <defs>
                      <linearGradient id="thr-g" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="var(--chart-5)" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="var(--chart-5)" stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                    <XAxis dataKey="hour" tick={{ fontSize: 10 }} stroke="var(--border)" />
                    <YAxis tick={{ fontSize: 10 }} stroke="var(--border)" />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Area type="monotone" dataKey="threats" stroke="var(--chart-5)" fill="url(#thr-g)" strokeWidth={2} />
                    <Area type="monotone" dataKey="blocked" stroke="var(--chart-4)" strokeWidth={1.5} fill="none" />
                  </AreaChart>
                </ChartContainer>
              </CardContent>
            </Card>

            {/* Security events live feed */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                  <span className="h-2 w-2 rounded-full bg-destructive animate-pulse" />
                  Security Event Stream
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <div className="max-h-52 overflow-y-auto">
                  {[
                    { time: "14:41", msg: "[WAF] SQL injection blocked from 185.220.101.28", sev: "high" },
                    { time: "14:40", msg: "[IDS] Port scan detected: 103.20.84.99 (22 ports)", sev: "medium" },
                    { time: "14:38", msg: "[AUTH] Brute force: 3 failed logins from 103.20.84.99", sev: "high" },
                    { time: "14:36", msg: "[WAF] XSS attempt blocked from 192.168.200.45", sev: "medium" },
                    { time: "14:30", msg: "[CNAPP] New container image pulled — verified clean", sev: "info" },
                    { time: "14:22", msg: "[IPS] DDoS mitigation deactivated — traffic normalized", sev: "info" },
                    { time: "14:15", msg: "[AUTH] MFA bypass attempt blocked for user@gov.ph", sev: "high" },
                    { time: "14:10", msg: "[API] Rate limit exceeded: api_key_028401 (1,240 req/min)", sev: "medium" },
                  ].map((ev, i) => (
                    <div key={i} className={`flex items-start gap-3 px-4 py-2 border-b last:border-0 text-xs ${ev.sev === "high" ? "bg-destructive/5" : ev.sev === "medium" ? "bg-warning/5" : ""}`}>
                      <span className="font-mono text-muted-foreground shrink-0">{ev.time}</span>
                      <span className={`font-mono ${ev.sev === "high" ? "text-destructive" : ev.sev === "medium" ? "text-warning-foreground" : "text-muted-foreground"}`}>{ev.msg}</span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Security Posture */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold">Security Posture</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {[
                  { domain: "Network Security", score: 96, controls: "WAF, IDS/IPS, CNAPP, DDoS" },
                  { domain: "Application Security", score: 94, controls: "SAST, DAST, API Security, Auth" },
                  { domain: "Data Security", score: 98, controls: "AES-256, TLS 1.3, PKI, DLP" },
                  { domain: "Identity & Access", score: 92, controls: "MFA, RBAC, SSO, PAM" },
                  { domain: "Endpoint Security", score: 95, controls: "EDR, Patching, Inventory" },
                  { domain: "Cloud Security", score: 97, controls: "CSPM, SASE, Zero Trust" },
                ].map((s) => (
                  <div key={s.domain}>
                    <div className="flex items-center justify-between text-xs mb-1">
                      <span className="font-medium">{s.domain}</span>
                      <span className={`font-bold ${s.score >= 96 ? "text-success" : s.score >= 90 ? "text-warning-foreground" : "text-destructive"}`}>{s.score}%</span>
                    </div>
                    <Progress value={s.score} className="h-2 mb-1" />
                    <p className="text-[10px] text-muted-foreground">{s.controls}</p>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="incidents" className="mt-4 space-y-3">
          {incidents.map((inc) => (
            <Card key={inc.id} className={inc.status === "investigating" ? "border-destructive/50" : ""}>
              <CardContent className="p-4">
                <div className="flex items-start gap-3">
                  <AlertCircle className={`h-5 w-5 shrink-0 mt-0.5 ${inc.severity === "critical" || inc.severity === "high" ? "text-destructive" : "text-warning"}`} />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1">
                      <span className="text-xs font-mono text-muted-foreground">{inc.id}</span>
                      <SeverityBadge sev={inc.severity} />
                      <IncidentStatusBadge status={inc.status} />
                      <Badge variant="secondary" className="text-xs">{inc.type}</Badge>
                    </div>
                    <p className="text-sm font-semibold">{inc.title}</p>
                    <div className="flex items-center gap-4 text-xs text-muted-foreground mt-1">
                      <span>Source: <span className="font-mono">{inc.source}</span></span>
                      <span>Target: <span className="font-mono">{inc.target}</span></span>
                      <span>Time: {inc.time}</span>
                    </div>
                  </div>
                  <Button variant="ghost" size="sm" className="h-7 text-xs shrink-0 gap-1">
                    <Eye className="h-3.5 w-3.5" /> Investigate
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </TabsContent>

        <TabsContent value="threats" className="mt-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold">Threat Intelligence Feed</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b bg-muted/30">
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Indicator</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Type</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Category</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Confidence</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Source</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Last Seen</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {threatIntel.map((ti, i) => (
                    <tr key={i} className={i % 2 === 0 ? "bg-background border-b" : "bg-muted/20 border-b"}>
                      <td className="px-4 py-3 font-mono max-w-[180px] truncate">{ti.indicator}</td>
                      <td className="px-4 py-3"><Badge variant="secondary" className="text-xs">{ti.type}</Badge></td>
                      <td className="px-4 py-3 text-destructive font-medium">{ti.category}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <Progress value={ti.confidence} className="h-2 w-16" />
                          <span className="font-medium">{ti.confidence}%</span>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{ti.source}</td>
                      <td className="px-4 py-3 text-muted-foreground">{ti.lastSeen}</td>
                      <td className="px-4 py-3">
                        <Badge variant="destructive" className="text-xs">Blocked</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="vulns" className="mt-4">
          <div className="space-y-3">
            {vulnerabilities.map((v) => (
              <Card key={v.cve} className={v.status !== "patched" && v.status !== "accepted" ? "border-warning/40" : ""}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 flex-wrap mb-1">
                        <span className="text-xs font-mono font-bold text-destructive">{v.cve}</span>
                        <SeverityBadge sev={v.severity} />
                        <Badge variant="secondary" className="text-xs">CVSS {v.score}</Badge>
                        {v.status === "patched" && <Badge className="bg-success/15 text-success border-success/30 text-xs">Patched</Badge>}
                        {v.status === "patching" && <Badge className="bg-warning/15 text-warning-foreground border-warning/30 text-xs">Patching</Badge>}
                        {v.status === "scheduled" && <Badge variant="secondary" className="text-xs">Scheduled</Badge>}
                        {v.status === "accepted" && <Badge variant="secondary" className="text-xs">Risk Accepted</Badge>}
                      </div>
                      <p className="text-sm font-medium">{v.system}</p>
                      <p className="text-xs text-muted-foreground">Discovered: {v.discovered}</p>
                    </div>
                    {v.status !== "patched" && v.status !== "accepted" && (
                      <Button variant="outline" size="sm" className="h-7 text-xs shrink-0">Apply Patch</Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        <TabsContent value="controls" className="mt-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {[
              { name: "Web Application Firewall (WAF)", vendor: "AWS WAF", status: "active", blocks: "1,248 today" },
              { name: "DDoS Protection", vendor: "AWS Shield Advanced", status: "active", blocks: "1 event today" },
              { name: "Intrusion Detection (IDS)", vendor: "Suricata + OSSEC", status: "active", blocks: "44 alerts today" },
              { name: "Intrusion Prevention (IPS)", vendor: "Suricata", status: "active", blocks: "12 blocked today" },
              { name: "Cloud-Native App Protection (CNAPP)", vendor: "Wiz.io", status: "active", blocks: "2 findings today" },
              { name: "SIEM Platform", vendor: "Microsoft Sentinel", status: "active", blocks: "280 events processed" },
              { name: "Zero Trust Network Access", vendor: "Cloudflare Access", status: "active", blocks: "18 denied today" },
              { name: "Data Loss Prevention (DLP)", vendor: "Microsoft Purview", status: "active", blocks: "3 prevented today" },
              { name: "Privileged Access Mgmt (PAM)", vendor: "CyberArk", status: "active", blocks: "0 incidents today" },
            ].map((ctrl) => (
              <Card key={ctrl.name}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-2 mb-2">
                    <div>
                      <p className="text-sm font-semibold leading-tight">{ctrl.name}</p>
                      <p className="text-xs text-muted-foreground">{ctrl.vendor}</p>
                    </div>
                    <div className="h-2.5 w-2.5 rounded-full bg-success animate-pulse shrink-0 mt-1" />
                  </div>
                  <div className="flex items-center justify-between">
                    <Badge className="bg-success/15 text-success border-success/30 text-xs">Active</Badge>
                    <span className="text-xs text-muted-foreground">{ctrl.blocks}</span>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
