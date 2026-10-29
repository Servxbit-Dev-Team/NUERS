import { useState, useRef } from "react";
import { useNavigate } from "react-router-dom";
import {
  ArrowLeft, Upload, FileJson, FileSpreadsheet, FileCode,
  CheckCircle2, XCircle, AlertTriangle, Clock, Play, Download,
  RefreshCw, Layers, Info, ChevronRight, X,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Progress } from "@/components/ui/progress";
import { Separator } from "@/components/ui/separator";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DOC_TYPE_META, type DocumentType } from "@/lib/invoice-utils";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

// ─── Mock bulk jobs ────────────────────────────────────────────────────────
const MOCK_JOBS = [
  {
    id: "job-001", job_name: "May 2026 Sales Batch", document_type: "sales_invoice" as DocumentType,
    source_format: "csv", total_records: 250, processed: 250, succeeded: 247, failed: 3, skipped: 0,
    status: "completed",
    error_log: [
      { row: 45, field: "buyer_tin", error: "Invalid TIN format" },
      { row: 112, field: "unit_price", error: "Negative value not allowed" },
      { row: 203, field: "buyer_name", error: "Required field missing" },
    ],
    started_at: "2026-05-27 09:00", completed_at: "2026-05-27 09:04",
  },
  {
    id: "job-002", job_name: "Service Invoices — Q1 Clients", document_type: "service_invoice" as DocumentType,
    source_format: "json", total_records: 48, processed: 48, succeeded: 48, failed: 0, skipped: 0,
    status: "completed",
    error_log: [],
    started_at: "2026-05-26 14:00", completed_at: "2026-05-26 14:01",
  },
  {
    id: "job-003", job_name: "Official Receipts Import", document_type: "official_receipt" as DocumentType,
    source_format: "xml", total_records: 120, processed: 67, succeeded: 65, failed: 2, skipped: 0,
    status: "processing",
    error_log: [],
    started_at: "2026-05-29 08:30", completed_at: null,
  },
];

const FORMAT_ICON: Record<string, React.ElementType> = {
  json: FileJson,
  xml:  FileCode,
  csv:  FileSpreadsheet,
};

const STATUS_STYLE: Record<string, { label: string; icon: React.ElementType; color: string }> = {
  queued:                  { label: "Queued",                  icon: Clock,         color: "text-muted-foreground" },
  processing:              { label: "Processing",              icon: RefreshCw,     color: "text-primary"          },
  completed:               { label: "Completed",               icon: CheckCircle2,  color: "text-success"          },
  completed_with_errors:   { label: "Completed w/ Errors",     icon: AlertTriangle, color: "text-warning"          },
  failed:                  { label: "Failed",                  icon: XCircle,       color: "text-destructive"      },
  cancelled:               { label: "Cancelled",               icon: X,             color: "text-muted-foreground" },
};

// ─── CSV template content ──────────────────────────────────────────────────
const CSV_TEMPLATE = `invoice_number,document_type,buyer_name,buyer_tin,buyer_email,issue_date,due_date,item_description,quantity,unit,unit_price,discount_pct,tax_type
SI-2026-000001,sales_invoice,SM Supermalls Inc.,987-654-321-000,payables@sm.com.ph,2026-06-01,2026-07-01,Office Supplies,100,box,250.00,0,vatable
SI-2026-000002,sales_invoice,Robinsons Retail,111-222-333-000,ap@robinsons.com.ph,2026-06-01,2026-07-01,Computer Equipment,5,unit,15000.00,5,vatable
`;

const JSON_TEMPLATE = JSON.stringify([
  {
    document_type: "sales_invoice",
    buyer_name: "SM Supermalls Inc.",
    buyer_tin: "987-654-321-000",
    issue_date: "2026-06-01",
    due_date: "2026-07-01",
    line_items: [
      { description: "Office Supplies", quantity: 100, unit: "box", unit_price: 250.00, tax_type: "vatable" }
    ]
  }
], null, 2);

export function InvoiceBulk() {
  const navigate = useNavigate();
  const fileRef = useRef<HTMLInputElement>(null);
  const [jobs, setJobs] = useState(MOCK_JOBS);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [jobName, setJobName] = useState("");
  const [docType, setDocType] = useState<DocumentType>("sales_invoice");
  const [sourceFormat, setSourceFormat] = useState("csv");
  const [autoValidate, setAutoValidate] = useState(true);
  const [autoSign, setAutoSign] = useState(true);
  const [autoSend, setAutoSend] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [progress, setProgress] = useState(0);
  const [selectedJob, setSelectedJob] = useState<typeof MOCK_JOBS[0] | null>(null);

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    if (!f) return;
    setSelectedFile(f);
    if (!jobName) setJobName(f.name.replace(/\.[^.]+$/, ""));
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    const f = e.dataTransfer.files[0];
    if (!f) return;
    setSelectedFile(f);
    if (!jobName) setJobName(f.name.replace(/\.[^.]+$/, ""));
  };

  const handleProcess = async () => {
    if (!selectedFile || !jobName) { toast.error("File and job name are required"); return; }
    setProcessing(true);
    setProgress(0);
    const total = 100;
    for (let i = 0; i <= total; i += 5) {
      await new Promise(r => setTimeout(r, 80));
      setProgress(i);
    }
    const newJob = {
      id: `job-${Date.now()}`,
      job_name: jobName,
      document_type: docType,
      source_format: sourceFormat,
      total_records: 42,
      processed: 42,
      succeeded: 41,
      failed: 1,
      skipped: 0,
      status: "completed_with_errors",
      error_log: [{ row: 15, field: "buyer_tin", error: "Invalid TIN format: 12-34-56" }],
      started_at: new Date().toLocaleString("en-PH"),
      completed_at: new Date().toLocaleString("en-PH"),
    };
    setJobs(prev => [newJob, ...prev]);
    setProcessing(false);
    setProgress(0);
    setSelectedFile(null);
    setJobName("");
    if (fileRef.current) fileRef.current.value = "";
    toast.success(`Bulk job "${jobName}" completed. 41 succeeded, 1 failed.`);
  };

  const downloadTemplate = (format: string) => {
    const content = format === "csv" ? CSV_TEMPLATE : format === "json" ? JSON_TEMPLATE : `<?xml version="1.0"?><Invoices><Invoice><document_type>sales_invoice</document_type></Invoice></Invoices>`;
    const mime = format === "csv" ? "text/csv" : format === "json" ? "application/json" : "application/xml";
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url; a.download = `invoice_template.${format}`; a.click();
    URL.revokeObjectURL(url);
    toast.success(`${format.toUpperCase()} template downloaded`);
  };

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate("/merchant/invoices")} className="gap-1">
          <ArrowLeft className="h-4 w-4" /> Back
        </Button>
        <div>
          <h1 className="text-xl font-bold text-foreground">Bulk Invoice Processing</h1>
          <p className="text-xs text-muted-foreground">Upload and process invoices in batch via JSON, XML, or CSV</p>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {[
          { label: "Total Jobs", val: jobs.length, icon: Layers, color: "text-primary" },
          { label: "Invoices Processed", val: jobs.reduce((s, j) => s + j.processed, 0), icon: CheckCircle2, color: "text-success" },
          { label: "Total Failures", val: jobs.reduce((s, j) => s + j.failed, 0), icon: XCircle, color: "text-destructive" },
          { label: "Success Rate", val: `${Math.round(jobs.reduce((s, j) => s + j.succeeded, 0) / Math.max(1, jobs.reduce((s, j) => s + j.processed, 0)) * 100)}%`, icon: CheckCircle2, color: "text-success" },
        ].map(s => (
          <Card key={s.label}>
            <CardContent className="p-4 flex items-center gap-3">
              <s.icon className={`h-8 w-8 p-2 rounded-lg bg-muted ${s.color}`} />
              <div>
                <p className="text-xl font-bold text-foreground">{s.val}</p>
                <p className="text-[10px] text-muted-foreground">{s.label}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="upload">
        <TabsList className="h-8">
          <TabsTrigger value="upload" className="text-xs">New Batch</TabsTrigger>
          <TabsTrigger value="jobs" className="text-xs">Job History</TabsTrigger>
          <TabsTrigger value="templates" className="text-xs">File Templates</TabsTrigger>
        </TabsList>

        {/* ── New Batch ──────────────────────────────────────────────── */}
        <TabsContent value="upload" className="mt-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="px-5 pt-4 pb-3">
                <CardTitle className="text-sm">Upload File</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-5 space-y-4">
                <div className="space-y-1">
                  <Label className="text-xs">Job Name *</Label>
                  <Input value={jobName} onChange={e => setJobName(e.target.value)} className="text-xs h-8" placeholder="e.g. May 2026 Sales Batch" />
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <Label className="text-xs">Document Type</Label>
                    <Select value={docType} onValueChange={v => setDocType(v as DocumentType)}>
                      <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        {(Object.entries(DOC_TYPE_META) as [DocumentType, typeof DOC_TYPE_META[DocumentType]][]).map(([k, v]) => (
                          <SelectItem key={k} value={k}>{v.short} — {v.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs">Source Format</Label>
                    <Select value={sourceFormat} onValueChange={setSourceFormat}>
                      <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="csv">CSV</SelectItem>
                        <SelectItem value="json">JSON</SelectItem>
                        <SelectItem value="xml">XML</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {/* Drop zone */}
                <div
                  onDrop={handleDrop}
                  onDragOver={e => e.preventDefault()}
                  onClick={() => fileRef.current?.click()}
                  className="border-2 border-dashed border-border rounded-lg p-8 text-center cursor-pointer hover:border-primary/50 hover:bg-muted/30 transition-colors"
                >
                  <input ref={fileRef} type="file" className="hidden" accept=".csv,.json,.xml" onChange={handleFileSelect} />
                  {selectedFile ? (
                    <div className="flex flex-col items-center gap-2">
                      {(() => { const FIcon = FORMAT_ICON[selectedFile.name.split(".").pop() ?? ""] ?? FileJson; return <FIcon className="h-10 w-10 text-primary" />; })()}
                      <p className="text-xs font-medium text-foreground">{selectedFile.name}</p>
                      <p className="text-[10px] text-muted-foreground">{(selectedFile.size / 1024).toFixed(1)} KB</p>
                      <Button variant="outline" size="sm" className="h-6 text-[10px]" onClick={e => { e.stopPropagation(); setSelectedFile(null); }}>
                        <X className="h-3 w-3 mr-1" /> Remove
                      </Button>
                    </div>
                  ) : (
                    <>
                      <Upload className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                      <p className="text-xs font-medium text-foreground">Drop file here or click to browse</p>
                      <p className="text-[10px] text-muted-foreground mt-1">Supports JSON, XML, CSV</p>
                    </>
                  )}
                </div>

                {processing && (
                  <div className="space-y-1.5">
                    <div className="flex justify-between text-xs text-muted-foreground">
                      <span>Processing…</span>
                      <span>{progress}%</span>
                    </div>
                    <Progress value={progress} className="h-2" />
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="px-5 pt-4 pb-3">
                <CardTitle className="text-sm">Processing Options</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-5 space-y-4">
                {[
                  { label: "Auto-Validate", desc: "Run validation checks on each invoice", val: autoValidate, set: setAutoValidate },
                  { label: "Auto-Sign", desc: "Apply SHA-256 digital signature automatically", val: autoSign, set: setAutoSign },
                  { label: "Auto-Send", desc: "Email invoices to buyers on completion", val: autoSend, set: setAutoSend },
                ].map(o => (
                  <div key={o.label} className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-xs font-medium text-foreground">{o.label}</p>
                      <p className="text-[10px] text-muted-foreground">{o.desc}</p>
                    </div>
                    <Switch checked={o.val} onCheckedChange={o.set} />
                  </div>
                ))}
                <Separator />
                <div className="p-3 bg-muted/40 rounded-lg space-y-1.5 text-[10px] text-muted-foreground">
                  <p className="font-semibold text-foreground text-xs flex items-center gap-1.5"><Info className="h-3.5 w-3.5" /> Processing Notes</p>
                  <p>• Duplicate invoice numbers will be skipped</p>
                  <p>• Invalid rows are logged with row number and field</p>
                  <p>• Max 10,000 records per batch</p>
                  <p>• Processing time: ~1-2 min per 1,000 records</p>
                  <p>• All generated invoices appear in your invoice list</p>
                </div>
                <Button
                  className="w-full gap-1.5"
                  onClick={handleProcess}
                  disabled={!selectedFile || !jobName || processing}
                >
                  {processing ? (
                    <><RefreshCw className="h-3.5 w-3.5 animate-spin" /> Processing…</>
                  ) : (
                    <><Play className="h-3.5 w-3.5" /> Start Processing</>
                  )}
                </Button>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* ── Job History ────────────────────────────────────────────── */}
        <TabsContent value="jobs" className="mt-4 space-y-3">
          {jobs.map(job => {
            const s = STATUS_STYLE[job.status] ?? STATUS_STYLE.queued;
            const pct = job.total_records > 0 ? Math.round(job.processed / job.total_records * 100) : 0;
            const FmtIcon = FORMAT_ICON[job.source_format] ?? FileJson;
            return (
              <Card key={job.id}>
                <CardContent className="p-5">
                  <div className="flex items-start gap-3">
                    <FmtIcon className="h-8 w-8 p-1.5 rounded bg-muted text-muted-foreground flex-shrink-0" />
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="text-sm font-semibold text-foreground">{job.job_name}</p>
                        <s.icon className={cn("h-3.5 w-3.5", s.color, job.status === "processing" && "animate-spin")} />
                        <span className={cn("text-xs font-medium", s.color)}>{s.label}</span>
                        <Badge variant="outline" className="text-[9px] uppercase">{job.source_format}</Badge>
                      </div>
                      <div className="flex flex-wrap gap-4 mt-1.5 text-xs text-muted-foreground">
                        <span><span className="text-success font-medium">{job.succeeded}</span> succeeded</span>
                        <span><span className="text-destructive font-medium">{job.failed}</span> failed</span>
                        {job.skipped > 0 && <span><span className="font-medium">{job.skipped}</span> skipped</span>}
                        <span>{job.total_records} total</span>
                        <span>{job.started_at}</span>
                      </div>
                      {job.status === "processing" && (
                        <div className="mt-2">
                          <Progress value={pct} className="h-1.5" />
                          <p className="text-[10px] text-muted-foreground mt-1">{job.processed} / {job.total_records} processed</p>
                        </div>
                      )}
                      {job.error_log.length > 0 && selectedJob?.id !== job.id && (
                        <button
                          className="mt-2 text-[10px] text-destructive hover:underline flex items-center gap-1"
                          onClick={() => setSelectedJob(selectedJob?.id === job.id ? null : job)}
                        >
                          <AlertTriangle className="h-3 w-3" /> {job.error_log.length} error{job.error_log.length > 1 ? "s" : ""} — click to view
                        </button>
                      )}
                      {selectedJob?.id === job.id && (
                        <div className="mt-3 border rounded-lg overflow-hidden">
                          <div className="flex items-center justify-between px-3 py-2 bg-destructive/10 border-b border-destructive/20">
                            <p className="text-xs font-medium text-destructive">Error Log</p>
                            <Button variant="ghost" size="sm" className="h-5 w-5 p-0" onClick={() => setSelectedJob(null)}>
                              <X className="h-3 w-3" />
                            </Button>
                          </div>
                          <table className="w-full text-[10px]">
                            <thead><tr className="border-b bg-muted/40"><th className="px-3 py-1.5 text-left text-muted-foreground">Row</th><th className="px-3 py-1.5 text-left text-muted-foreground">Field</th><th className="px-3 py-1.5 text-left text-muted-foreground">Error</th></tr></thead>
                            <tbody>
                              {job.error_log.map((e, i) => (
                                <tr key={i} className="border-b last:border-0">
                                  <td className="px-3 py-1.5 font-mono text-muted-foreground">#{e.row}</td>
                                  <td className="px-3 py-1.5 font-mono text-foreground">{e.field}</td>
                                  <td className="px-3 py-1.5 text-destructive">{e.error}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                    <div className="flex gap-2 flex-shrink-0">
                      <Button variant="outline" size="sm" className="h-7 gap-1 text-xs" onClick={() => toast.info("Downloading results…")}>
                        <Download className="h-3 w-3" /> Results
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </TabsContent>

        {/* ── File Templates ─────────────────────────────────────────── */}
        <TabsContent value="templates" className="mt-4">
          <div className="grid gap-4 sm:grid-cols-3">
            {[
              {
                format: "csv",
                icon: FileSpreadsheet,
                title: "CSV Template",
                desc: "Comma-separated values. Best for Excel users. One invoice per row with line items repeated.",
                color: "text-success",
              },
              {
                format: "json",
                icon: FileJson,
                title: "JSON Template",
                desc: "Structured JSON array. Supports nested line items. Best for developers and API integrations.",
                color: "text-primary",
              },
              {
                format: "xml",
                icon: FileCode,
                title: "XML Template",
                desc: "BIR-compliant XML structure. Required for government submissions and official integrations.",
                color: "text-chart-2",
              },
            ].map(t => (
              <Card key={t.format}>
                <CardContent className="p-5 space-y-3">
                  <t.icon className={`h-8 w-8 ${t.color}`} />
                  <div>
                    <p className="text-sm font-semibold text-foreground">{t.title}</p>
                    <p className="text-xs text-muted-foreground mt-1">{t.desc}</p>
                  </div>
                  <Button variant="outline" size="sm" className="w-full gap-1.5" onClick={() => downloadTemplate(t.format)}>
                    <Download className="h-3.5 w-3.5" /> Download Template
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
          <Card className="mt-4">
            <CardContent className="p-5">
              <p className="text-sm font-semibold text-foreground mb-3 flex items-center gap-2">
                <Info className="h-4 w-4 text-primary" /> Required Fields (all formats)
              </p>
              <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                {[
                  { field: "document_type", desc: "Invoice type code (e.g. sales_invoice)" },
                  { field: "buyer_name",    desc: "Legal name of the buyer" },
                  { field: "issue_date",    desc: "YYYY-MM-DD format" },
                  { field: "item_description", desc: "Line item description" },
                  { field: "quantity",      desc: "Numeric, up to 4 decimal places" },
                  { field: "unit_price",    desc: "Peso value (exclusive of VAT if vatable)" },
                  { field: "tax_type",      desc: "vatable | zero_rated | vat_exempt" },
                  { field: "unit",          desc: "pc, box, kg, ltr, hr, day, mo, set, svc" },
                ].map(f => (
                  <div key={f.field} className="flex gap-2 text-xs">
                    <ChevronRight className="h-3.5 w-3.5 text-primary flex-shrink-0 mt-0.5" />
                    <div>
                      <p className="font-mono font-medium text-foreground">{f.field}</p>
                      <p className="text-muted-foreground">{f.desc}</p>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
