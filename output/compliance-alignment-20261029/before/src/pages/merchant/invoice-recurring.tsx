import { useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  ArrowLeft, Plus, RefreshCw, Calendar, Clock, Pause, Play,
  Trash2, Edit, Check, AlertCircle, FileText,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Separator } from "@/components/ui/separator";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { DOC_TYPE_META, type DocumentType, type RecurringFrequency } from "@/lib/invoice-utils";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

// ─── Mock recurring invoices ──────────────────────────────────────────────
const MOCK_RECURRING = [
  {
    id: "rec-001", name: "Monthly Retainer — Tech Solutions PH", document_type: "service_invoice" as DocumentType,
    frequency: "monthly" as RecurringFrequency, day_of_month: 1,
    start_date: "2026-01-01", end_date: null,
    next_run_at: "2026-06-01T00:00:00Z", last_run_at: "2026-05-01T00:00:00Z",
    runs_count: 5, max_runs: null, is_active: true, auto_send: true, auto_validate: true,
    buyer: "Tech Solutions PH", amount: "₱33,600.00",
  },
  {
    id: "rec-002", name: "Weekly Delivery — Quick Mart Chain", document_type: "delivery_receipt" as DocumentType,
    frequency: "weekly" as RecurringFrequency, day_of_week: 1,
    start_date: "2026-04-01", end_date: "2026-12-31",
    next_run_at: "2026-06-02T00:00:00Z", last_run_at: "2026-05-26T00:00:00Z",
    runs_count: 9, max_runs: 39, is_active: true, auto_send: false, auto_validate: true,
    buyer: "Quick Mart Chain", amount: "₱21,780.00",
  },
  {
    id: "rec-003", name: "Quarterly Billing — SM Supermalls", document_type: "sales_invoice" as DocumentType,
    frequency: "quarterly" as RecurringFrequency, day_of_month: 15,
    start_date: "2026-01-01", end_date: null,
    next_run_at: "2026-07-15T00:00:00Z", last_run_at: "2026-04-15T00:00:00Z",
    runs_count: 2, max_runs: null, is_active: false, auto_send: true, auto_validate: true,
    buyer: "SM Supermalls Inc.", amount: "₱120,000.00",
  },
];

// ─── Mock scheduled invoices ──────────────────────────────────────────────
const MOCK_SCHEDULED = [
  { id: "sch-001", document_type: "official_receipt" as DocumentType, buyer: "Robinsons Retail", amount: "₱56,000.00", scheduled_at: "2026-06-05T09:00:00Z", status: "pending" },
  { id: "sch-002", document_type: "sales_invoice" as DocumentType, buyer: "XYZ Enterprises", amount: "₱84,000.00", scheduled_at: "2026-06-10T08:00:00Z", status: "pending" },
  { id: "sch-003", document_type: "service_invoice" as DocumentType, buyer: "TechSmart PH", amount: "₱42,000.00", scheduled_at: "2026-05-30T10:00:00Z", status: "completed" },
  { id: "sch-004", document_type: "purchase_invoice" as DocumentType, buyer: "Supplier ABC", amount: "₱95,000.00", scheduled_at: "2026-06-01T08:30:00Z", status: "failed" },
];

const FREQ_LABELS: Record<RecurringFrequency, string> = {
  daily:     "Daily",
  weekly:    "Weekly",
  biweekly:  "Bi-Weekly",
  monthly:   "Monthly",
  quarterly: "Quarterly",
  annually:  "Annually",
};

const STATUS_STYLE: Record<string, string> = {
  pending:   "text-warning",
  completed: "text-success",
  failed:    "text-destructive",
  cancelled: "text-muted-foreground",
};

export function InvoiceRecurring() {
  const navigate = useNavigate();
  const [recurring, setRecurring] = useState(MOCK_RECURRING);
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({
    name: "",
    document_type: "service_invoice" as DocumentType,
    frequency: "monthly" as RecurringFrequency,
    day_of_month: "1",
    buyer: "",
    auto_send: false,
    auto_validate: true,
  });

  const toggleActive = (id: string) => {
    setRecurring(prev => prev.map(r => r.id === id ? { ...r, is_active: !r.is_active } : r));
    const item = recurring.find(r => r.id === id);
    toast.success(`Recurring invoice ${item?.is_active ? "paused" : "resumed"}`);
  };

  const handleCreate = () => {
    if (!form.name || !form.buyer) { toast.error("Name and buyer are required"); return; }
    setCreating(false);
    toast.success(`Recurring template "${form.name}" created`);
  };

  const nextRunLabel = (dt: string) => {
    const d = new Date(dt);
    const diff = Math.ceil((d.getTime() - Date.now()) / 86400000);
    if (diff <= 0) return "Due now";
    if (diff === 1) return "Tomorrow";
    return `in ${diff} days`;
  };

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate("/merchant/invoices")} className="gap-1">
          <ArrowLeft className="h-4 w-4" /> Back
        </Button>
        <div className="flex-1">
          <h1 className="text-xl font-bold text-foreground">Recurring & Scheduled Invoices</h1>
          <p className="text-xs text-muted-foreground">Automate invoice generation on schedule</p>
        </div>
        <Dialog open={creating} onOpenChange={setCreating}>
          <DialogTrigger asChild>
            <Button size="sm" className="gap-1.5">
              <Plus className="h-3.5 w-3.5" /> New Template
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle className="text-sm">Create Recurring Invoice Template</DialogTitle>
            </DialogHeader>
            <div className="space-y-4 mt-2">
              <div className="space-y-1">
                <Label className="text-xs">Template Name *</Label>
                <Input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="text-xs h-8" placeholder="e.g. Monthly Retainer — Client Name" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label className="text-xs">Document Type</Label>
                  <Select value={form.document_type} onValueChange={v => setForm(f => ({ ...f, document_type: v as DocumentType }))}>
                    <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {(Object.entries(DOC_TYPE_META) as [DocumentType, typeof DOC_TYPE_META[DocumentType]][]).map(([k, v]) => (
                        <SelectItem key={k} value={k}>{v.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Frequency</Label>
                  <Select value={form.frequency} onValueChange={v => setForm(f => ({ ...f, frequency: v as RecurringFrequency }))}>
                    <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {(Object.entries(FREQ_LABELS) as [RecurringFrequency, string][]).map(([k, v]) => (
                        <SelectItem key={k} value={k}>{v}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              {(form.frequency === "monthly" || form.frequency === "quarterly" || form.frequency === "annually") && (
                <div className="space-y-1">
                  <Label className="text-xs">Day of Month</Label>
                  <Input type="number" min={1} max={28} value={form.day_of_month} onChange={e => setForm(f => ({ ...f, day_of_month: e.target.value }))} className="text-xs h-8 w-24" />
                </div>
              )}
              <div className="space-y-1">
                <Label className="text-xs">Buyer Name *</Label>
                <Input value={form.buyer} onChange={e => setForm(f => ({ ...f, buyer: e.target.value }))} className="text-xs h-8" />
              </div>
              <Separator />
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-xs font-medium text-foreground">Auto-Validate</p>
                    <p className="text-[10px] text-muted-foreground">Validate invoice automatically on generation</p>
                  </div>
                  <Switch checked={form.auto_validate} onCheckedChange={v => setForm(f => ({ ...f, auto_validate: v }))} />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-xs font-medium text-foreground">Auto-Send</p>
                    <p className="text-[10px] text-muted-foreground">Email invoice to buyer automatically</p>
                  </div>
                  <Switch checked={form.auto_send} onCheckedChange={v => setForm(f => ({ ...f, auto_send: v }))} />
                </div>
              </div>
              <div className="flex justify-end gap-2">
                <Button variant="outline" size="sm" onClick={() => setCreating(false)}>Cancel</Button>
                <Button size="sm" onClick={handleCreate}>Create Template</Button>
              </div>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {[
          { label: "Active Templates",    val: recurring.filter(r => r.is_active).length,                        icon: RefreshCw, color: "text-success" },
          { label: "Paused Templates",    val: recurring.filter(r => !r.is_active).length,                       icon: Pause,     color: "text-warning" },
          { label: "Scheduled (pending)", val: MOCK_SCHEDULED.filter(s => s.status === "pending").length,         icon: Calendar,  color: "text-primary" },
          { label: "Total Runs (MTD)",    val: recurring.reduce((s, r) => s + r.runs_count, 0),                   icon: FileText,  color: "text-chart-2" },
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

      <Tabs defaultValue="recurring">
        <TabsList className="h-8">
          <TabsTrigger value="recurring" className="text-xs">Recurring Templates</TabsTrigger>
          <TabsTrigger value="scheduled" className="text-xs">Scheduled Invoices</TabsTrigger>
        </TabsList>

        {/* ── Recurring templates ────────────────────────────────────── */}
        <TabsContent value="recurring" className="mt-4 space-y-3">
          {recurring.map(r => {
            const meta = DOC_TYPE_META[r.document_type];
            return (
              <Card key={r.id} className={cn(!r.is_active && "opacity-60")}>
                <CardContent className="p-5">
                  <div className="flex items-start gap-4">
                    <div className={cn("p-2.5 rounded-lg bg-muted", meta.color)}>
                      <RefreshCw className="h-4 w-4" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="text-sm font-semibold text-foreground">{r.name}</p>
                        <Badge variant={r.is_active ? "default" : "secondary"} className="text-[10px]">
                          {r.is_active ? "Active" : "Paused"}
                        </Badge>
                        <Badge variant="outline" className={cn("text-[10px]", meta.color)}>{meta.short}</Badge>
                      </div>
                      <div className="flex flex-wrap gap-4 mt-2 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                          <RefreshCw className="h-3 w-3" />
                          {FREQ_LABELS[r.frequency]}
                          {r.day_of_month && ` (Day ${r.day_of_month})`}
                        </span>
                        <span className="flex items-center gap-1">
                          <Clock className="h-3 w-3" />
                          Next: {nextRunLabel(r.next_run_at)}
                        </span>
                        <span>{r.runs_count} runs{r.max_runs ? ` / ${r.max_runs}` : ""}</span>
                        <span className="font-medium text-foreground">{r.amount} / run</span>
                        {r.auto_send && <Badge variant="outline" className="text-[9px]">Auto-Send</Badge>}
                        {r.auto_validate && <Badge variant="outline" className="text-[9px]">Auto-Validate</Badge>}
                      </div>
                      {r.max_runs && (
                        <div className="mt-2">
                          <div className="flex justify-between text-[10px] text-muted-foreground mb-1">
                            <span>Progress</span><span>{r.runs_count} / {r.max_runs}</span>
                          </div>
                          <div className="h-1 bg-muted rounded-full overflow-hidden">
                            <div className="h-full bg-primary rounded-full" style={{ width: `${(r.runs_count / r.max_runs) * 100}%` }} />
                          </div>
                        </div>
                      )}
                    </div>
                    <div className="flex gap-2 flex-shrink-0">
                      <Button variant="outline" size="sm" className="h-7 w-7 p-0" onClick={() => toast.info("Edit template…")}>
                        <Edit className="h-3.5 w-3.5" />
                      </Button>
                      <Button variant="outline" size="sm" className="h-7 w-7 p-0" onClick={() => toggleActive(r.id)}>
                        {r.is_active ? <Pause className="h-3.5 w-3.5" /> : <Play className="h-3.5 w-3.5" />}
                      </Button>
                      <Button variant="outline" size="sm" className="h-7 w-7 p-0 text-destructive hover:text-destructive" onClick={() => toast.error("Delete template?")}>
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </TabsContent>

        {/* ── Scheduled invoices ─────────────────────────────────────── */}
        <TabsContent value="scheduled" className="mt-4">
          <Card>
            <CardHeader className="px-5 pt-4 pb-2 flex-row items-center justify-between">
              <CardTitle className="text-sm">Scheduled One-Time Invoices</CardTitle>
              <Button size="sm" variant="outline" className="gap-1.5 h-7 text-xs" onClick={() => navigate("/merchant/invoices/create")}>
                <Plus className="h-3 w-3" /> Schedule Invoice
              </Button>
            </CardHeader>
            <CardContent className="px-5 pb-4">
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b">
                      {["Document Type","Buyer","Amount","Scheduled For","Status",""].map(h => (
                        <th key={h} className="pb-2 text-left font-medium text-muted-foreground pr-4">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {MOCK_SCHEDULED.map(s => {
                      const meta = DOC_TYPE_META[s.document_type];
                      const d = new Date(s.scheduled_at);
                      return (
                        <tr key={s.id} className="border-b last:border-0">
                          <td className="py-3 pr-4">
                            <div className="flex items-center gap-1.5">
                              <span className={cn("text-xs font-medium", meta.color)}>{meta.short}</span>
                              <span className="text-muted-foreground">{meta.label}</span>
                            </div>
                          </td>
                          <td className="py-3 pr-4 text-foreground font-medium">{s.buyer}</td>
                          <td className="py-3 pr-4 font-mono font-semibold tabular-nums">{s.amount}</td>
                          <td className="py-3 pr-4 text-muted-foreground">
                            {d.toLocaleDateString("en-PH", { month: "short", day: "numeric" })} {d.toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit" })}
                          </td>
                          <td className="py-3 pr-4">
                            <span className={cn("font-medium capitalize flex items-center gap-1", STATUS_STYLE[s.status])}>
                              {s.status === "completed" && <Check className="h-3 w-3" />}
                              {s.status === "failed" && <AlertCircle className="h-3 w-3" />}
                              {s.status === "pending" && <Clock className="h-3 w-3" />}
                              {s.status}
                            </span>
                          </td>
                          <td className="py-3">
                            {s.status === "pending" && (
                              <Button variant="ghost" size="sm" className="h-7 text-xs text-destructive hover:text-destructive" onClick={() => toast.info("Schedule cancelled")}>Cancel</Button>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
