import { Fragment, useEffect, useMemo, useState, type ElementType } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Clock,
  Copy,
  FileJson,
  Inbox,
  RefreshCw,
  RotateCcw,
  Search,
  Send,
  ShieldAlert,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import {
  getEisTransmissions,
  retryEisTransmission,
  submitEisTransmission,
  validateEisPayload,
  type EisTransmission,
  type EisTransmissionsPayload,
  type EisValidationResult,
} from "@/lib/eis-api";
import { cn } from "@/lib/utils";

const STATUS_META: Record<EisTransmission["status"], { label: string; className: string; icon: ElementType }> = {
  queued: { label: "Queued", className: "text-warning", icon: Inbox },
  submitting: { label: "Submitting", className: "text-primary", icon: Send },
  submitted: { label: "Submitted", className: "text-primary", icon: Send },
  acknowledged: { label: "Acknowledged", className: "text-success", icon: CheckCircle2 },
  rejected: { label: "Rejected", className: "text-destructive", icon: AlertTriangle },
  failed: { label: "Failed", className: "text-destructive", icon: XCircle },
  duplicate: { label: "Duplicate", className: "text-muted-foreground", icon: Copy },
  recovered: { label: "Recovered", className: "text-success", icon: RotateCcw },
  cancelled: { label: "Cancelled", className: "text-muted-foreground", icon: XCircle },
};

const TYPE_LABELS: Record<string, string> = {
  invoice_submission: "Invoice Submission",
  sales_data: "Sales Data",
  correction: "Correction",
  cancellation: "Cancellation",
};

const SAMPLE_PAYLOAD = JSON.stringify({
  is_test: true,
  invoice_number: "EIS-CERT-000001",
  document_type: "sales_invoice",
  issue_date: new Date().toISOString().slice(0, 10),
  seller_tin: "123-456-789-000",
  buyer_tin: "987-654-321-000",
  gross_amount: 11200,
  vatable_sales: 10000,
  vat_amount: 1200,
  total_due: 11200,
  items: [
    { description: "Certification test item", quantity: 1, unit_price: 10000, vat_amount: 1200 },
  ],
}, null, 2);

function StatusLine({ tx }: { tx: EisTransmission }) {
  const meta = STATUS_META[tx.status] ?? STATUS_META.queued;
  const Icon = meta.icon;

  return (
    <span className={cn("flex items-center gap-1 font-medium capitalize", meta.className)}>
      <Icon className={cn("h-3.5 w-3.5", tx.status === "submitting" && "animate-spin")} />
      {meta.label}
    </span>
  );
}

function ValidationPanel({ validation }: { validation: EisValidationResult | null }) {
  if (!validation) {
    return (
      <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
        Validate the JSON payload before queueing it for BIR EIS certification transmission.
      </div>
    );
  }

  const valid = validation.status === "valid";

  return (
    <div className={cn("space-y-2 rounded-md border p-3 text-sm", valid ? "border-success/30 bg-success/5" : "border-destructive/30 bg-destructive/5")}>
      <div className="flex items-center justify-between gap-2">
        <span className={cn("font-semibold", valid ? "text-success" : "text-destructive")}>
          {valid ? "Payload valid for NUERS EIS queue" : "Payload has blocking errors"}
        </span>
        <Badge variant={valid ? "default" : "destructive"}>{validation.invoice_count} document(s)</Badge>
      </div>
      <p className="break-all font-mono text-xs text-muted-foreground">SHA-256: {validation.payload_hash}</p>
      {validation.due_at && <p className="text-xs text-muted-foreground">Transmission due by {validation.due_at}</p>}
      {validation.errors.length > 0 && (
        <div className="space-y-1">
          {validation.errors.map((error) => <p key={error} className="text-xs text-destructive">• {error}</p>)}
        </div>
      )}
      {validation.warnings.length > 0 && (
        <div className="space-y-1">
          {validation.warnings.map((warning) => <p key={warning} className="text-xs text-warning-foreground">• {warning}</p>)}
        </div>
      )}
    </div>
  );
}

export function EisTransmissions() {
  const navigate = useNavigate();
  const [payload, setPayload] = useState<EisTransmissionsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [typeFilter, setTypeFilter] = useState("all");
  const [tab, setTab] = useState("all");
  const [expanded, setExpanded] = useState<string | null>(null);
  const [showSubmit, setShowSubmit] = useState(false);
  const [submitPayload, setSubmitPayload] = useState(SAMPLE_PAYLOAD);
  const [transmissionType, setTransmissionType] = useState("invoice_submission");
  const [environment, setEnvironment] = useState("certification");
  const [apiVersion, setApiVersion] = useState("v2");
  const [validation, setValidation] = useState<EisValidationResult | null>(null);
  const [validating, setValidating] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  async function load(blocking = false) {
    if (blocking) setLoading(true);
    else setRefreshing(true);
    setError(null);

    try {
      setPayload(await getEisTransmissions({
        search,
        status: statusFilter,
        type: typeFilter,
        tab,
      }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to load EIS transmissions.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  useEffect(() => {
    const timeout = window.setTimeout(() => load(false), 250);
    return () => window.clearTimeout(timeout);
  }, [search, statusFilter, typeFilter, tab]);

  useEffect(() => {
    load(true);
    const interval = window.setInterval(() => load(false), 12000);
    return () => window.clearInterval(interval);
  }, []);

  const successRate = useMemo(() => {
    const total = payload?.summary.total ?? 0;
    if (!total) return 0;
    return Math.round(((payload?.summary.acknowledged ?? 0) / total) * 100);
  }, [payload]);

  async function handleValidate() {
    setValidating(true);
    try {
      const result = await validateEisPayload(submitPayload);
      setValidation(result);
      if (result.status === "valid") toast.success("Payload passed NUERS EIS validation.");
      else toast.error("Payload has validation errors.");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to validate payload.");
    } finally {
      setValidating(false);
    }
  }

  async function handleSubmit() {
    setSubmitting(true);
    try {
      const result = await submitEisTransmission({
        payload: submitPayload,
        transmission_type: transmissionType,
        environment,
        api_version: apiVersion,
      });
      toast.success(result.message);
      setShowSubmit(false);
      setValidation(null);
      await load(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to queue EIS transmission.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRetry(id: string) {
    try {
      const result = await retryEisTransmission(id);
      toast.success(result.message);
      await load(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to schedule retry.");
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex items-start gap-3">
          <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-2xl font-bold tracking-tight">BIR EIS Transmissions</h1>
              <Badge className="bg-warning/15 text-warning-foreground border-warning/30">
                <ShieldAlert className="mr-1 h-3.5 w-3.5" />
                Certification Queue
              </Badge>
            </div>
            <p className="max-w-3xl text-sm text-muted-foreground">
              Database-backed invoice payload queue, canonical hash validation, duplicate detection, retry scheduling, and BIR acknowledgement tracking. Certification records are synthetic tests, not live BIR acceptances.
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" className="gap-2" onClick={() => load(false)} disabled={refreshing}>
            <RefreshCw className={cn("h-4 w-4", refreshing && "animate-spin")} />
            Refresh
          </Button>
          <Button size="sm" className="gap-2" onClick={() => setShowSubmit(true)}>
            <Send className="h-4 w-4" />
            New Payload
          </Button>
        </div>
      </div>

      {error && (
        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        {[
          { label: "Total", value: payload?.summary.total ?? 0, icon: FileJson, className: "text-foreground" },
          { label: "Acknowledged", value: payload?.summary.acknowledged ?? 0, icon: CheckCircle2, className: "text-success" },
          { label: "Queued", value: payload?.summary.queued ?? 0, icon: Clock, className: "text-warning" },
          { label: "Failed", value: payload?.summary.failed ?? 0, icon: XCircle, className: "text-destructive" },
          { label: "Duplicates", value: payload?.summary.duplicates ?? 0, icon: Copy, className: "text-muted-foreground" },
          { label: "Retry Queue", value: payload?.summary.retry_queue ?? 0, icon: RotateCcw, className: "text-primary" },
        ].map((item) => (
          <Card key={item.label}>
            <CardContent className="p-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-xs text-muted-foreground">{item.label}</p>
                  <p className={cn("mt-1 text-2xl font-bold", item.className)}>{item.value}</p>
                </div>
                <item.icon className={cn("h-5 w-5", item.className)} />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card>
        <CardContent className="p-4">
          <div className="mb-2 flex items-center justify-between gap-3">
            <p className="text-sm font-semibold">Acknowledgement Rate</p>
            <p className={cn("text-sm font-bold", successRate >= 95 ? "text-success" : successRate > 0 ? "text-warning" : "text-muted-foreground")}>{successRate}%</p>
          </div>
          <Progress value={successRate} className="h-2" />
          <p className="mt-2 text-xs text-muted-foreground">
            Local certification queue is active. Production BIR submission remains gated until EIS CERT, PTT, key material, and BIR credentials are bound.
          </p>
        </CardContent>
      </Card>

      {payload?.retry_queue.length ? (
        <Card className="border-warning/30 bg-warning/5">
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Retry Queue</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {payload.retry_queue.map((item) => (
              <div key={item.id} className="flex flex-col gap-2 rounded-md border bg-background p-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-mono text-xs font-semibold">{item.transmission_id}</p>
                  <p className="text-xs text-muted-foreground">
                    {TYPE_LABELS[item.transmission_type] ?? item.transmission_type} • {item.invoice_count} document(s) • Attempt {item.attempt_number}/{item.max_attempts}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <p className="text-xs text-muted-foreground">{item.scheduled_at}</p>
                  <Button variant="outline" size="sm" className="gap-2" onClick={() => handleRetry(item.transmission_id)}>
                    <RotateCcw className="h-3.5 w-3.5" />
                    Retry
                  </Button>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardContent className="space-y-4 p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input className="pl-9" placeholder="Search transaction, business account, TIN, payload hash..." value={search} onChange={(event) => setSearch(event.target.value)} />
            </div>
            <Select value={typeFilter} onValueChange={setTypeFilter}>
              <SelectTrigger className="w-full lg:w-52"><SelectValue placeholder="Transmission type" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                {Object.entries(TYPE_LABELS).map(([key, label]) => <SelectItem key={key} value={key}>{label}</SelectItem>)}
              </SelectContent>
            </Select>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-full lg:w-52"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                {Object.entries(STATUS_META).map(([key, meta]) => <SelectItem key={key} value={key}>{meta.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>

          <Tabs value={tab} onValueChange={setTab}>
            <TabsList className="grid h-auto w-full grid-cols-2 sm:inline-grid sm:w-auto sm:grid-cols-4">
              <TabsTrigger value="all">All</TabsTrigger>
              <TabsTrigger value="pending">Pending</TabsTrigger>
              <TabsTrigger value="failed">Failed</TabsTrigger>
              <TabsTrigger value="retries">Retries</TabsTrigger>
            </TabsList>
          </Tabs>

          <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  {["TX ID", "Business Account", "Type", "Documents", "Status", "BIR Reference", "Response", "Attempt", "Due", ""].map((heading) => (
                    <th key={heading} className="py-2 pr-3 font-medium">{heading}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={10} className="py-10 text-center text-muted-foreground">Loading EIS transmissions...</td></tr>
                ) : payload?.transmissions.length ? payload.transmissions.map((tx) => {
                  const expandedRow = expanded === tx.id;
                  return (
                    <Fragment key={tx.id}>
                      <tr className="border-b last:border-0 align-top hover:bg-muted/30">
                        <td className="py-3 pr-3">
                          <p className="font-mono text-xs font-semibold">{tx.id}</p>
                          <p className="font-mono text-[11px] text-muted-foreground">{tx.payload_hash.slice(0, 18)}...</p>
                        </td>
                        <td className="py-3 pr-3">
                          <p className="font-medium">{tx.merchant_name ?? "Certification payload"}</p>
                          <p className="text-xs text-muted-foreground">{tx.merchant_tin ?? "TIN from payload"}</p>
                        </td>
                        <td className="py-3 pr-3">{TYPE_LABELS[tx.type] ?? tx.type}</td>
                        <td className="py-3 pr-3">{tx.invoice_count}</td>
                        <td className="py-3 pr-3"><StatusLine tx={tx} /></td>
                        <td className="py-3 pr-3 font-mono text-xs">{tx.bir_reference_number ?? "—"}</td>
                        <td className="py-3 pr-3">
                          {tx.response_code ? <Badge variant={tx.response_code === "200" ? "default" : "secondary"}>{tx.response_code}</Badge> : "—"}
                        </td>
                        <td className="py-3 pr-3">{tx.attempt_number}/{tx.max_attempts}</td>
                        <td className="py-3 pr-3 text-xs text-muted-foreground">{tx.due_at ?? "—"}</td>
                        <td className="py-3">
                          <div className="flex items-center gap-1">
                            <Button variant="ghost" size="icon" onClick={() => setExpanded(expandedRow ? null : tx.id)}>
                              {expandedRow ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                            </Button>
                            {["failed", "rejected", "queued"].includes(tx.status) && (
                              <Button variant="ghost" size="icon" onClick={() => handleRetry(tx.id)}>
                                <RotateCcw className="h-4 w-4" />
                              </Button>
                            )}
                          </div>
                        </td>
                      </tr>
                      {expandedRow && (
                        <tr className="border-b bg-muted/20">
                          <td colSpan={10} className="p-4">
                            <div className="grid gap-4 lg:grid-cols-3">
                              <div>
                                <p className="text-sm font-semibold">Transmission</p>
                                <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                                  <p>Environment: <span className="font-medium text-foreground">{tx.environment}</span></p>
                                  <p>API version: <span className="font-medium text-foreground">{tx.api_version}</span></p>
                                  <p>Request ID: <span className="font-mono text-foreground">{tx.request_id ?? "—"}</span></p>
                                  <p>Duplicate of: <span className="font-mono text-foreground">{tx.duplicate_of ?? "No"}</span></p>
                                </div>
                              </div>
                              <div>
                                <p className="text-sm font-semibold">BIR Response</p>
                                <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                                  <p>Acknowledgement: <span className="font-mono text-foreground">{tx.bir_acknowledgement ?? "—"}</span></p>
                                  <p>Submitted: <span className="text-foreground">{tx.submitted_at ?? "Not submitted live"}</span></p>
                                  <p>Acknowledged: <span className="text-foreground">{tx.acknowledged_at ?? "—"}</span></p>
                                  <p>Latency: <span className="text-foreground">{tx.latency_ms ? `${tx.latency_ms}ms` : "—"}</span></p>
                                </div>
                              </div>
                              <div>
                                <p className="text-sm font-semibold">Message</p>
                                <p className="mt-2 text-xs text-muted-foreground">{tx.response_message ?? "No response message recorded."}</p>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                }) : (
                  <tr>
                    <td colSpan={10} className="py-12 text-center">
                      <div className="mx-auto max-w-md space-y-2">
                        <Inbox className="mx-auto h-10 w-10 text-muted-foreground" />
                        <p className="font-semibold">No EIS transmissions yet</p>
                        <p className="text-sm text-muted-foreground">Queue a certification payload to populate the database-backed transmission monitor.</p>
                      </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Dialog open={showSubmit} onOpenChange={setShowSubmit}>
        <DialogContent className="!flex max-h-[94vh] !w-[calc(100vw-1rem)] !max-w-none flex-col overflow-hidden p-0 sm:!w-[calc(100vw-2rem)] sm:!max-w-none 2xl:!w-[1280px]">
          <DialogHeader className="shrink-0 border-b px-5 py-4 sm:px-6">
            <DialogTitle>Queue BIR EIS Payload</DialogTitle>
            <DialogDescription>
              Validate and queue BIR EIS certification payloads without enabling live production submission.
            </DialogDescription>
          </DialogHeader>
          <div className="grid min-h-0 flex-1 grid-cols-1 gap-0 overflow-y-auto 2xl:grid-cols-[minmax(0,1fr)_400px] 2xl:overflow-hidden">
            <div className="min-w-0 space-y-4 overflow-visible px-5 py-4 sm:px-6 2xl:overflow-y-auto">
              <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div className="min-w-0 space-y-2">
                  <Label className="block text-xs font-semibold uppercase leading-tight tracking-normal text-muted-foreground">Transmission Type</Label>
                  <Select value={transmissionType} onValueChange={setTransmissionType}>
                    <SelectTrigger className="min-h-11 w-full justify-between"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {Object.entries(TYPE_LABELS).map(([key, label]) => <SelectItem key={key} value={key}>{label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="min-w-0 space-y-2">
                  <Label className="block text-xs font-semibold uppercase leading-tight tracking-normal text-muted-foreground">Environment</Label>
                  <Select value={environment} onValueChange={setEnvironment}>
                    <SelectTrigger className="min-h-11 w-full justify-between"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="certification">Certification</SelectItem>
                      <SelectItem value="production">Production</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="min-w-0 space-y-2">
                  <Label className="block text-xs font-semibold uppercase leading-tight tracking-normal text-muted-foreground">API Version</Label>
                  <Select value={apiVersion} onValueChange={setApiVersion}>
                    <SelectTrigger className="min-h-11 w-full justify-between"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="v2">v2</SelectItem>
                      <SelectItem value="v1">v1</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="space-y-2">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                  <Label className="text-xs font-semibold uppercase tracking-normal text-muted-foreground">Canonical JSON Payload</Label>
                  <span className="text-xs text-muted-foreground">Horizontal scroll enabled</span>
                </div>
                <Textarea
                  className="min-h-[240px] resize-y overflow-auto whitespace-pre font-mono text-xs leading-5 sm:min-h-[360px] lg:min-h-[480px]"
                  wrap="off"
                  spellCheck={false}
                  value={submitPayload}
                  onChange={(event) => {
                    setSubmitPayload(event.target.value);
                    setValidation(null);
                  }}
                />
              </div>
            </div>
            <div className="min-w-0 space-y-3 overflow-visible border-t bg-muted/20 px-5 py-4 sm:px-6 2xl:overflow-y-auto 2xl:border-l 2xl:border-t-0">
              <ValidationPanel validation={validation} />
              <div className="rounded-md border bg-muted/20 p-3 text-sm">
                <p className="font-semibold">Production rule</p>
                <p className="mt-1 text-muted-foreground">
                  NUERS will store and queue this payload locally. Actual BIR EIS submission stays blocked until official credentials, EIS CERT, PTT, and key material are registered.
                </p>
              </div>
              <div className="flex flex-col gap-2">
                <Button variant="outline" className="gap-2" onClick={handleValidate} disabled={validating}>
                  <FileJson className={cn("h-4 w-4", validating && "animate-pulse")} />
                  Validate Payload
                </Button>
                <Button className="gap-2" onClick={handleSubmit} disabled={submitting}>
                  <Send className={cn("h-4 w-4", submitting && "animate-pulse")} />
                  Save and Queue
                </Button>
                <Button variant="ghost" asChild>
                  <Link to="/super-admin/eis-readiness">View readiness gate</Link>
                </Button>
              </div>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
