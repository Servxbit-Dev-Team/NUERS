import { useState } from "react";
import {
  Archive, Search, Download, HardDrive, Clock, FileText,
  AlertTriangle, CheckCircle2,
  Lock, Eye,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

const storageStats = [
  { label: "Total Archived", value: "12.4 TB", sub: "Across all storage tiers", icon: HardDrive, color: "text-primary" },
  { label: "Records Archived", value: "284M+", sub: "Invoices and transactions", icon: FileText, color: "text-success" },
  { label: "Retention Period", value: "10 Years", sub: "BIR-mandated minimum", icon: Clock, color: "text-warning" },
  { label: "Integrity Status", value: "100%", sub: "All checksums verified", icon: CheckCircle2, color: "text-success" },
];

const storageTiers = [
  { tier: "Hot Storage (Active)", desc: "Current year — Immediate access", size: "2.4 TB", records: "28.4M", accessTime: "< 100ms", cost: "High", pct: 19 },
  { tier: "Warm Storage (Recent)", desc: "1-3 years — Fast retrieval", size: "5.8 TB", records: "124M", accessTime: "< 2s", cost: "Medium", pct: 47 },
  { tier: "Cold Storage (Archive)", desc: "3-7 years — Standard retrieval", size: "3.2 TB", records: "98M", accessTime: "< 1 min", cost: "Low", pct: 26 },
  { tier: "Deep Archive (Vault)", desc: "7-10+ years — Long-term", size: "1.0 TB", records: "33.6M", accessTime: "< 4 hrs", cost: "Very Low", pct: 8 },
];

const archiveRecords = [
  { id: "ARC-2026-048291", type: "Sales Invoice Batch", taxpayer: "SM Prime Holdings", period: "May 2026", records: "28,400", size: "284 MB", status: "archived", integrity: "verified", retention: "2036-05-31" },
  { id: "ARC-2026-048290", type: "VAT Filing Report", taxpayer: "Ayala Land, Inc.", period: "Apr 2026", records: "1", size: "12 MB", status: "archived", integrity: "verified", retention: "2036-04-30" },
  { id: "ARC-2026-048289", type: "Audit Trail Export", taxpayer: "NUERS Platform", period: "Q1 2026", records: "184,200", size: "1.2 GB", status: "archived", integrity: "verified", retention: "2036-03-31" },
  { id: "ARC-2025-084120", type: "Annual Sales Report", taxpayer: "Globe Telecom", period: "FY 2025", records: "2.4M", size: "4.8 GB", status: "legal_hold", integrity: "verified", retention: "2035-12-31" },
  { id: "ARC-2025-084119", type: "EIS Submission Log", taxpayer: "PLDT, Inc.", period: "FY 2025", records: "842,000", size: "2.1 GB", status: "archived", integrity: "verified", retention: "2035-12-31" },
];

export function DataArchiveCenter() {
  const [search, setSearch] = useState("");

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Data Retention & Archive Center</h1>
          <p className="text-sm text-muted-foreground">Long-term electronic records management, compliance archiving, and legal hold</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge className="bg-success/15 text-success border-success/30 text-xs">
            <Lock className="h-3 w-3 mr-1" /> WORM Compliant
          </Badge>
          <Button size="sm" className="gap-2">
            <Search className="h-3.5 w-3.5" /> Search Archive
          </Button>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {storageStats.map((s) => (
          <Card key={s.label}>
            <CardContent className="p-4 flex items-center gap-3">
              <s.icon className={`h-8 w-8 ${s.color}`} />
              <div>
                <p className="text-xs text-muted-foreground">{s.label}</p>
                <p className="text-xl font-bold">{s.value}</p>
                <p className="text-[10px] text-muted-foreground">{s.sub}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="storage">
        <TabsList className="h-9">
          <TabsTrigger value="storage" className="text-xs">Storage Tiers</TabsTrigger>
          <TabsTrigger value="records" className="text-xs">Archive Records</TabsTrigger>
          <TabsTrigger value="search" className="text-xs">Compliance Search</TabsTrigger>
          <TabsTrigger value="retention" className="text-xs">Retention Policy</TabsTrigger>
          <TabsTrigger value="legalhold" className="text-xs">Legal Hold</TabsTrigger>
        </TabsList>

        <TabsContent value="storage" className="mt-4 space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {storageTiers.map((tier) => (
              <Card key={tier.tier}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between mb-2">
                    <div>
                      <p className="text-sm font-semibold">{tier.tier}</p>
                      <p className="text-xs text-muted-foreground">{tier.desc}</p>
                    </div>
                    <Badge variant="secondary" className="text-xs">{tier.cost} Cost</Badge>
                  </div>
                  <div className="grid grid-cols-3 gap-2 text-xs mt-3 mb-3">
                    <div className="bg-muted/40 rounded p-2">
                      <p className="text-muted-foreground">Size</p>
                      <p className="font-bold">{tier.size}</p>
                    </div>
                    <div className="bg-muted/40 rounded p-2">
                      <p className="text-muted-foreground">Records</p>
                      <p className="font-bold">{tier.records}</p>
                    </div>
                    <div className="bg-muted/40 rounded p-2">
                      <p className="text-muted-foreground">Access</p>
                      <p className="font-bold">{tier.accessTime}</p>
                    </div>
                  </div>
                  <div className="space-y-1">
                    <div className="flex justify-between text-xs">
                      <span className="text-muted-foreground">Storage utilization</span>
                      <span className="font-medium">{tier.pct}%</span>
                    </div>
                    <Progress value={tier.pct * 4} className="h-2" />
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>

          {/* Storage utilization overall */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold">Total Storage Distribution</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {storageTiers.map((tier) => (
                  <div key={tier.tier} className="space-y-1">
                    <div className="flex justify-between text-xs">
                      <span className="font-medium">{tier.tier}</span>
                      <span className="text-muted-foreground">{tier.size} ({tier.pct}%)</span>
                    </div>
                    <Progress value={tier.pct} className="h-2.5" />
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="records" className="mt-4">
          <Card>
            <CardHeader className="pb-2">
              <div className="flex items-center justify-between gap-2">
                <CardTitle className="text-sm font-semibold">Archived Records</CardTitle>
                <div className="flex items-center gap-2">
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                    <Input placeholder="Search archive..." className="h-8 pl-9 text-xs w-48" value={search} onChange={(e) => setSearch(e.target.value)} />
                  </div>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b bg-muted/30">
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Archive ID</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Type</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Taxpayer</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Period</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Records</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Size</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Integrity</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Retention Until</th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {archiveRecords.map((r, i) => (
                    <tr key={r.id} className={i % 2 === 0 ? "bg-background border-b" : "bg-muted/20 border-b"}>
                      <td className="px-4 py-3 font-mono font-medium text-primary">{r.id}</td>
                      <td className="px-4 py-3">{r.type}</td>
                      <td className="px-4 py-3 font-medium max-w-[140px] truncate">{r.taxpayer}</td>
                      <td className="px-4 py-3 text-muted-foreground">{r.period}</td>
                      <td className="px-4 py-3">{r.records}</td>
                      <td className="px-4 py-3 text-muted-foreground">{r.size}</td>
                      <td className="px-4 py-3">
                        <Badge className="bg-success/15 text-success border-success/30 text-xs">Verified</Badge>
                      </td>
                      <td className="px-4 py-3">
                        {r.status === "legal_hold"
                          ? <Badge variant="destructive" className="text-xs">Legal Hold</Badge>
                          : <Badge className="bg-muted text-muted-foreground text-xs">Archived</Badge>}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{r.retention}</td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1">
                          <Button variant="ghost" size="sm" className="h-7 w-7 p-0"><Eye className="h-3 w-3" /></Button>
                          <Button variant="ghost" size="sm" className="h-7 w-7 p-0"><Download className="h-3 w-3" /></Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="search" className="mt-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold">Compliance Archive Search</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 mb-4">
                <Input placeholder="Invoice / Archive ID" className="h-9 text-sm" />
                <Input placeholder="TIN or Taxpayer Name" className="h-9 text-sm" />
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                  <Input placeholder="Date from" className="h-9 text-sm" type="date" />
                  <span className="hidden text-xs text-muted-foreground sm:inline">–</span>
                  <Input placeholder="Date to" className="h-9 text-sm" type="date" />
                </div>
              </div>
              <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                <Select>
                  <SelectTrigger className="h-9 w-full text-xs sm:w-40">
                    <SelectValue placeholder="Record Type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Types</SelectItem>
                    <SelectItem value="invoice">Invoices</SelectItem>
                    <SelectItem value="receipt">Receipts</SelectItem>
                    <SelectItem value="report">Reports</SelectItem>
                    <SelectItem value="audit">Audit Logs</SelectItem>
                  </SelectContent>
                </Select>
                <Button className="gap-2">
                  <Search className="h-3.5 w-3.5" /> Search Archive
                </Button>
              </div>
              <div className="text-center py-12 text-muted-foreground">
                <Archive className="h-12 w-12 mx-auto mb-3 opacity-30" />
                <p className="text-sm font-medium">Enter search criteria above to retrieve archived records</p>
                <p className="text-xs mt-1">Full-text search across 284M+ archived records with BIR-compliant retrieval</p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="retention" className="mt-4">
          <div className="space-y-3">
            {[
              { type: "Electronic Invoices (EIS)", period: "10 years", basis: "BIR RR No. 9-2021", action: "Auto-archive after 1 year" },
              { type: "Official Receipts", period: "10 years", basis: "BIR RR No. 9-2021", action: "Auto-archive after 1 year" },
              { type: "VAT / Tax Returns", period: "10 years", basis: "NIRC Section 203", action: "Auto-archive after 6 months" },
              { type: "Audit Trail Logs", period: "7 years", basis: "DPA IRR Section 25", action: "Auto-archive after 90 days" },
              { type: "API Access Logs", period: "2 years", basis: "ISO 27001 A.12.4", action: "Auto-archive after 30 days" },
              { type: "Security Event Logs", period: "5 years", basis: "SOC 2 CC7.1", action: "Auto-archive after 90 days" },
              { type: "Employee Records", period: "10 years", basis: "DOLE standards", action: "Manual archive on termination" },
            ].map((r) => (
              <Card key={r.type}>
                <CardContent className="p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0">
                      <FileText className="h-4 w-4 text-muted-foreground shrink-0" />
                      <div className="min-w-0">
                        <p className="text-sm font-medium">{r.type}</p>
                        <p className="text-xs text-muted-foreground">{r.action} — Basis: {r.basis}</p>
                      </div>
                    </div>
                    <Badge className="bg-primary/10 text-primary border-primary/30 text-xs shrink-0">{r.period}</Badge>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        <TabsContent value="legalhold" className="mt-4 space-y-4">
          <div className="flex items-center gap-3 p-3 bg-destructive/5 border border-destructive/30 rounded-lg">
            <AlertTriangle className="h-4 w-4 text-destructive shrink-0" />
            <p className="text-xs">Legal holds suspend normal retention schedules. Records on hold cannot be deleted or modified until the hold is released by authorized legal counsel.</p>
          </div>

          <div className="space-y-3">
            {[
              { id: "LH-2026-001", subject: "Globe Telecom FY2025 Revenue Audit", records: "2.4M records", status: "active", placed: "Mar 15, 2026", by: "BIR Legal Division", reason: "BIR Audit Case No. BIR-2026-AUD-0028" },
              { id: "LH-2025-003", subject: "Former Employee Data Request", records: "24 records", status: "active", placed: "Nov 1, 2025", by: "HR Department", reason: "Labor dispute case — NLRC Case No. NLRC-2025-0841" },
              { id: "LH-2024-008", subject: "BDO Transaction Investigation", records: "128,400 records", status: "released", placed: "Aug 10, 2024", by: "NBI Financial Crimes", reason: "Case closed — NBI-FCD-2024-0042" },
            ].map((h) => (
              <Card key={h.id} className={h.status === "active" ? "border-destructive/40" : ""}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 mb-1">
                        <span className="text-xs font-mono text-muted-foreground">{h.id}</span>
                        {h.status === "active"
                          ? <Badge variant="destructive" className="text-xs">Active Hold</Badge>
                          : <Badge className="bg-success/15 text-success border-success/30 text-xs">Released</Badge>}
                      </div>
                      <p className="text-sm font-semibold">{h.subject}</p>
                      <p className="text-xs text-muted-foreground mt-0.5">{h.records} — Placed by {h.by} on {h.placed}</p>
                      <p className="text-xs text-muted-foreground mt-0.5">Reason: {h.reason}</p>
                    </div>
                    {h.status === "active" && (
                      <Button variant="outline" size="sm" className="h-7 text-xs shrink-0 text-destructive border-destructive/30">
                        Release Hold
                      </Button>
                    )}
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
