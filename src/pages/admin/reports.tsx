import {exportReport as openReportExport} from "@/components/reports/export-report";
import { useState } from "react";
import { Download, FileText, RefreshCw } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SALE_CATEGORIES, saleLabel } from "@/lib/statutory-sales";

const reports = [
  { id: "transactions", name: "Transaction register", detail: "Recorded live transactions and NUERS document status.", path: "/api/transaction-workspace/export", dated: true },
  { id: "documents", name: "Electronic invoices & receipts", detail: "Document register including manual and statutory discounts.", path: "/api/invoice-center/export", dated: true },
  { id: "sales", name: "Summary list of sales", detail: "Internal sales report, including senior citizen, PWD, national athlete / coach and solo parent categories.", path: "/api/operations-report/export?mode=sales", dated: true },
  { id: "reconciliation", name: "Reconciliation & exceptions", detail: "Recorded exceptions and review decisions.", path: "/api/operations-report/export?mode=reconciliation", dated: true },
  { id: "businesses", name: "Registered merchants", detail: "Current authorized business registry. Transaction filters do not apply.", path: "/api/business-registry/export", dated: false },
  { id: "audit", name: "Complete audit journal", detail: "Full journal and current integrity result. Transaction filters do not apply.", path: "/api/live-monitoring/audit?download=1", dated: false },
];

export function AdminReports() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [category, setCategory] = useState("all");
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const [completed, setCompleted] = useState("");
  async function download(report:typeof reports[number]){setError('');if(report.dated&&from&&to&&to<from){setError('End date must be on or after the start date.');return;}setBusy(report.id);try{const [path,query]=report.path.split('?');const params=new URLSearchParams(query);if(report.dated){if(from)params.set('from',from);if(to)params.set('to',to);if(category!=='all')params.set('sale_category',category);}if(await openReportExport({path:path+'?'+params,title:report.name,scope:report.detail+' All matching authorized records across all pages.',filters:Object.fromEntries(params)}))setCompleted(report.name+' downloaded.');}finally{setBusy('')}}
  return <div className="space-y-5"><header><h1 className="text-2xl font-bold text-[#092451]">Reports & Exports</h1><p className="text-sm text-muted-foreground">Generate reports from authorized saved records. Exports preserve the selected transaction filters.</p></header>
    <Card><CardContent className="grid gap-4 pt-5 sm:grid-cols-3"><div><Label htmlFor="report-from">Business date from</Label><Input id="report-from" type="date" value={from} onChange={e => setFrom(e.target.value)} /></div><div><Label htmlFor="report-to">Business date to</Label><Input id="report-to" type="date" value={to} onChange={e => setTo(e.target.value)} /></div><div><Label htmlFor="report-category">Sale category</Label><select id="report-category" className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={category} onChange={e => setCategory(e.target.value)}><option value="all">All sale categories</option>{Object.keys(SALE_CATEGORIES).map(c => <option key={c} value={c}>{saleLabel(c)}</option>)}</select></div></CardContent></Card>
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-red-700">{error}</p>}{completed && <p role="status" className="rounded-lg bg-green-50 p-3 text-green-800">{completed}</p>}
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{reports.map(report => <Card key={report.id}><CardHeader><CardTitle className="flex items-center gap-2 text-base"><FileText className="size-5 text-blue-600" />{report.name}</CardTitle></CardHeader><CardContent className="space-y-4"><p className="min-h-12 text-sm text-muted-foreground">{report.detail}</p><Button variant="outline" disabled={!!busy} onClick={() => void download(report)}>{busy === report.id ? <RefreshCw className="size-4 animate-spin" /> : <Download className="size-4" />}Export PDF / CSV</Button></CardContent></Card>)}</div>
    <p className="text-sm text-muted-foreground">These are internal NUERS reports. Official BIR filing, scheduled report email and automated regulatory submission require separately configured and verified workflows. Sandbox records remain in the separate Sandbox view.</p>
  </div>;
}
