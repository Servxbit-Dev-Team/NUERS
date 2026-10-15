import { useEffect, useMemo, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import {
  ArrowLeft, Download, Send, Shield, QrCode, CheckCircle2,
  Clock, AlertTriangle, XCircle, Edit, Copy, FileText,
  History, Printer, ChevronRight, RotateCcw, Ban,
  FileJson, FileSpreadsheet, FileCode, Loader2, Database,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import {
  DOC_TYPE_META, STATUS_META, formatAmount, exportToJSON, exportToCSV, exportToXML,
  type Invoice, type InvoiceStatus,
} from "@/lib/invoice-utils";
import { BirDocumentPreview } from "@/components/invoices/bir-document-preview";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

const API_TOKEN_KEY = "nuers_api_token";

function authHeaders() {
  const headers = new Headers({ Accept: "application/json" });

  try {
    const token = window.localStorage.getItem(API_TOKEN_KEY);
    if (token) headers.set("Authorization", `Bearer ${token}`);
  } catch {
    // Local storage can be unavailable in restricted browser contexts.
  }

  return headers;
}

// ─── Status icon map ──────────────────────────────────────────────────────
const StatusIcon: Record<InvoiceStatus, React.ElementType> = {
  draft:               FileText,
  pending:             Clock,
  validated:           CheckCircle2,
  sent:                Send,
  paid:                CheckCircle2,
  partially_paid:      Clock,
  overdue:             AlertTriangle,
  cancelled:           XCircle,
  voided:              XCircle,
  correction_pending:  AlertTriangle,
};

export function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [cancelReason, setCancelReason] = useState("");
  const [cancelling, setCancelling] = useState(false);
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function loadInvoice() {
      setLoading(true);
      setError("");

      try {
        const response = await apiFetch(`/api/business-invoices/${encodeURIComponent(id ?? "")}`, {
          headers: authHeaders(),
          cache: "no-store",
        });
        const payload = await readJsonResponse<{ invoice: Invoice }>(response, "Unable to load this Business Account invoice.");

        if (active) setInvoice(payload.invoice);
      } catch (err) {
        if (active) {
          setInvoice(null);
          setError(err instanceof Error ? err.message : "Unable to load this Business Account invoice.");
        }
      } finally {
        if (active) setLoading(false);
      }
    }

    loadInvoice();

    return () => {
      active = false;
    };
  }, [id]);

  const versions = useMemo(() => {
    if (!invoice) return [];

    return [
      { version: 1, change_type: "created", changed_by: invoice.merchant_name, summary: "Invoice created in NUERS", ts: invoice.created_at },
      invoice.validated_at ? { version: 2, change_type: "validated", changed_by: "NUERS validation engine", summary: "Invoice payload validated", ts: invoice.validated_at } : null,
      invoice.signed_at ? { version: 3, change_type: "signed", changed_by: "NUERS signature service", summary: "Digital signature generated", ts: invoice.signed_at } : null,
      invoice.cancelled_at ? { version: 4, change_type: "cancelled", changed_by: invoice.merchant_name, summary: invoice.cancellation_reason || "Invoice cancelled", ts: invoice.cancelled_at } : null,
    ].filter(Boolean) as Array<{ version: number; change_type: string; changed_by: string; summary: string; ts?: string }>;
  }, [invoice]);

  if (loading) {
    return (
      <div className="flex min-h-[360px] items-center justify-center">
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" />
          Loading invoice from database...
        </div>
      </div>
    );
  }

  if (!invoice) {
    return (
      <div className="mx-auto flex max-w-md flex-col items-center justify-center gap-3 py-16 text-center">
        <Database className="h-10 w-10 text-muted-foreground" />
        <h1 className="text-lg font-semibold text-foreground">Invoice not found for this Business Account</h1>
        <p className="text-sm text-muted-foreground">{error || "This invoice either does not exist or belongs to another Business Account."}</p>
        <Button size="sm" onClick={() => navigate("/merchant/invoices")}>Back to Invoices</Button>
      </div>
    );
  }

  const meta = DOC_TYPE_META[invoice.document_type];
  const statusMeta = STATUS_META[invoice.status];
  const SIcon = StatusIcon[invoice.status];

  const handleCancel = async () => {
    setCancelling(true);
    await new Promise(r => setTimeout(r, 1000));
    setCancelling(false);
    toast.success(`Invoice ${invoice.invoice_number} cancellation submitted.`);
    navigate("/merchant/invoices");
  };

  const handleCorrection = () => {
    navigate(`/merchant/invoices/create?type=${invoice.document_type === "sales_invoice" ? "credit_memo" : invoice.document_type}`);
    toast.info("Creating correction document…");
  };

  return (
    <div className="mx-auto max-w-5xl space-y-5 px-3 sm:px-4 lg:px-0">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
        <Button variant="ghost" size="sm" onClick={() => navigate("/merchant/invoices")} className="mt-0.5 w-fit shrink-0 gap-1">
          <ArrowLeft className="h-4 w-4" /> Back
        </Button>
        <div className="min-w-0 flex-1">
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <h1 className="max-w-full break-all font-mono text-2xl font-bold leading-tight text-foreground sm:text-xl md:text-2xl">
              {invoice.invoice_number}
            </h1>
            <Badge variant={statusMeta.variant} className="shrink-0 gap-1 text-xs">
              <SIcon className="h-3 w-3" />
              {statusMeta.label}
            </Badge>
            <Badge variant="outline" className={cn("shrink-0 text-xs", meta.color)}>{meta.label}</Badge>
            {invoice.digital_signature && (
              <Badge variant="outline" className="shrink-0 gap-1 text-xs text-success border-success/40">
                <Shield className="h-2.5 w-2.5" /> Signed
              </Badge>
            )}
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            Issued {invoice.issue_date} • Version {invoice.version_number} • {invoice.currency}
          </p>
        </div>
        <div className="flex w-full flex-wrap gap-2 sm:w-auto sm:justify-end">
          <Button variant="outline" size="sm" className="h-8 flex-1 gap-1 text-xs sm:flex-none" onClick={() => { exportToJSON(invoice); toast.success("Exported JSON"); }}>
            <FileJson className="h-3.5 w-3.5" /> JSON
          </Button>
          <Button variant="outline" size="sm" className="h-8 flex-1 gap-1 text-xs sm:flex-none" onClick={() => { exportToXML(invoice); toast.success("Exported XML"); }}>
            <FileCode className="h-3.5 w-3.5" /> XML
          </Button>
          <Button variant="outline" size="sm" className="h-8 flex-1 gap-1 text-xs sm:flex-none" onClick={() => { exportToCSV(invoice); toast.success("Exported CSV"); }}>
            <FileSpreadsheet className="h-3.5 w-3.5" /> CSV
          </Button>
          <Button variant="outline" size="sm" className="h-8 flex-1 gap-1 text-xs sm:flex-none" onClick={() => toast.info("Printing PDF…")}>
            <Printer className="h-3.5 w-3.5" /> PDF
          </Button>
          {!["cancelled", "voided", "paid"].includes(invoice.status) && (
            <Button size="sm" className="h-8 flex-1 gap-1 text-xs sm:flex-none" onClick={() => navigate(`/merchant/invoices/${id}/edit`)}>
              <Edit className="h-3.5 w-3.5" /> Edit
            </Button>
          )}
        </div>
      </div>

      <Tabs defaultValue="document">
        <div className="-mx-3 overflow-x-auto px-3 pb-1 sm:mx-0 sm:px-0">
          <TabsList className="h-auto min-w-max justify-start gap-1 p-1">
            <TabsTrigger value="document" className="h-8 whitespace-nowrap px-3 text-xs">Document</TabsTrigger>
            <TabsTrigger value="tax" className="h-8 whitespace-nowrap px-3 text-xs">Tax Breakdown</TabsTrigger>
            <TabsTrigger value="signature" className="h-8 whitespace-nowrap px-3 text-xs">Signature & QR</TabsTrigger>
            <TabsTrigger value="history" className="h-8 whitespace-nowrap px-3 text-xs">Version History</TabsTrigger>
            <TabsTrigger value="actions" className="h-8 whitespace-nowrap px-3 text-xs">Actions</TabsTrigger>
          </TabsList>
        </div>

        {/* ── Document ─────────────────────────────────────────────────── */}
        <TabsContent value="document" className="mt-4 space-y-4">
          <BirDocumentPreview
            documentType={invoice.document_type}
            documentNumber={invoice.invoice_number}
            seriesNumber={invoice.invoice_number}
            issueDate={invoice.issue_date}
            seller={{
              name: invoice.merchant_name,
              tin: invoice.merchant_tin,
        permitToIssueNumber: invoice.permit_to_issue_number,
        espAccreditationNumber: invoice.esp_accreditation_number,
              address: invoice.merchant_address,
              vatRegistration: invoice.vat_registered ? "VAT Registered" : "Non-VAT Registered",
            }}
            buyer={{
              name: invoice.buyer_name,
              tin: invoice.buyer_tin,
              address: invoice.buyer_address,
              email: invoice.buyer_email,
            }}
            items={(invoice.line_items ?? []).map((line) => ({
              description: line.description,
              quantity: line.quantity,
              unit: line.unit,
              unitPrice: line.unit_price,
              amount: line.line_total,
              taxType: line.tax_type,
            }))}
            totals={{
              gross: invoice.total_amount,
              vatableSales: invoice.taxable_amount,
              vatExemptSales: invoice.vat_exempt_amount,
              zeroRatedSales: invoice.zero_rated_amount,
              vatAmount: invoice.vat_amount,
              discount: invoice.discount_amount,
              withholdingTax: invoice.withholding_tax,
              totalDue: invoice.total_amount - invoice.withholding_tax,
            }}
            paymentMethod={invoice.amount_paid > 0 ? "paid" : "charge"}
            referenceNumber={invoice.reference_number || invoice.purchase_order || invoice.sales_order}
            status={invoice.status}
            qrValue={invoice.qr_payload || invoice.validation_hash || invoice.invoice_number}
          />

          {/* Parties */}
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-xs text-muted-foreground uppercase tracking-wide">Seller</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-1 text-xs">
                <p className="font-semibold text-foreground">{invoice.merchant_name}</p>
                <p className="font-mono text-muted-foreground">TIN: {invoice.merchant_tin}</p>
                <p className="text-muted-foreground">{invoice.merchant_address}</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-xs text-muted-foreground uppercase tracking-wide">Buyer</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-1 text-xs">
                <p className="font-semibold text-foreground">{invoice.buyer_name}</p>
                {invoice.buyer_tin && <p className="font-mono text-muted-foreground">TIN: {invoice.buyer_tin}</p>}
                {invoice.buyer_address && <p className="text-muted-foreground">{invoice.buyer_address}</p>}
                {invoice.buyer_email && <p className="text-muted-foreground">{invoice.buyer_email}</p>}
              </CardContent>
            </Card>
          </div>

          {/* Dates & refs */}
          <Card>
            <CardContent className="px-5 py-4">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                {[
                  { label: "Issue Date", val: invoice.issue_date },
                  { label: "Due Date", val: invoice.due_date ?? "—" },
                  { label: "Reference #", val: invoice.reference_number || "—" },
                  { label: "P.O. Number", val: invoice.purchase_order || "—" },
                ].map(f => (
                  <div key={f.label}>
                    <p className="text-muted-foreground mb-0.5">{f.label}</p>
                    <p className="font-medium text-foreground">{f.val}</p>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          {/* Line items */}
          <Card>
            <CardHeader className="px-5 pt-4 pb-2">
              <CardTitle className="text-sm">Line Items</CardTitle>
            </CardHeader>
            <CardContent className="px-5 pb-4">
              {(invoice.line_items?.length ?? 0) > 0 ? (
                <div className="overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="border-b">
                        {["#","Code","Description","Unit","Qty","Unit Price","Disc","Tax Type","VAT","Total"].map(h => (
                          <th key={h} className="pb-2 text-left font-medium text-muted-foreground pr-3">{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {invoice.line_items!.map(l => (
                        <tr key={l.id} className="border-b last:border-0">
                          <td className="py-2 pr-3 text-muted-foreground">{l.line_number}</td>
                          <td className="py-2 pr-3 font-mono text-muted-foreground">{l.item_code}</td>
                          <td className="py-2 pr-3 text-foreground font-medium">{l.description}</td>
                          <td className="py-2 pr-3 text-muted-foreground">{l.unit}</td>
                          <td className="py-2 pr-3 tabular-nums">{l.quantity}</td>
                          <td className="py-2 pr-3 tabular-nums">{formatAmount(l.unit_price)}</td>
                          <td className="py-2 pr-3">{l.discount_pct > 0 ? `${l.discount_pct}%` : "—"}</td>
                          <td className="py-2 pr-3">
                            <Badge variant="outline" className="text-[9px]">{l.tax_type.replace("_", " ")}</Badge>
                          </td>
                          <td className="py-2 pr-3 tabular-nums">{formatAmount(l.vat_amount)}</td>
                          <td className="py-2 tabular-nums font-semibold">{formatAmount(l.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-xs text-muted-foreground italic">No line items recorded.</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Tax Breakdown ─────────────────────────────────────────────── */}
        <TabsContent value="tax" className="mt-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-sm">Amount Summary</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-2 text-xs">
                {[
                  { label: "Gross Sales",           val: invoice.subtotal_amount,             muted: true },
                  { label: "Less: Discounts",        val: -invoice.discount_amount,            muted: true },
                  { label: "Taxable Sales",          val: invoice.taxable_amount,              muted: true },
                  { label: "Zero-Rated Sales",       val: invoice.zero_rated_amount,           muted: true },
                  { label: "VAT-Exempt Sales",       val: invoice.vat_exempt_amount,           muted: true },
                  { label: "VAT Output Tax (12%)",   val: invoice.vat_amount,                  muted: true },
                  { label: "Less: Withholding Tax",  val: -invoice.withholding_tax,            muted: true },
                  { label: "Other Charges",          val: invoice.other_charges,               muted: true },
                  { label: "TOTAL AMOUNT DUE",       val: invoice.total_amount - invoice.withholding_tax, muted: false },
                ].map((row, i) => (
                  <div key={i} className={cn("flex justify-between", !row.muted && "font-bold text-sm border-t pt-2 mt-1")}>
                    <span className={row.muted ? "text-muted-foreground" : "text-foreground"}>{row.label}</span>
                    <span className={cn("tabular-nums font-mono", row.val < 0 ? "text-destructive" : "", !row.muted && "text-foreground")}>
                      {row.val < 0 ? `(${formatAmount(Math.abs(row.val))})` : formatAmount(row.val)}
                    </span>
                  </div>
                ))}
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-sm">VAT Summary per BIR Format</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-3 text-xs">
                {[
                  { label: "Total Vatable Sales (inclusive of VAT)", val: invoice.taxable_amount + invoice.vat_amount },
                  { label: "Total Vatable Sales (exclusive of VAT)", val: invoice.taxable_amount },
                  { label: "VAT Amount",                              val: invoice.vat_amount },
                  { label: "Total Zero-Rated Sales",                  val: invoice.zero_rated_amount },
                  { label: "Total VAT-Exempt Sales",                  val: invoice.vat_exempt_amount },
                  { label: "Total Amount",                            val: invoice.total_amount },
                ].map(row => (
                  <div key={row.label} className="flex justify-between">
                    <span className="text-muted-foreground">{row.label}</span>
                    <span className="tabular-nums font-mono text-foreground">{formatAmount(row.val)}</span>
                  </div>
                ))}
                <Separator />
                <p className="text-[10px] text-muted-foreground">
                  In compliance with BIR RR 16-2005 and TRAIN Law (RA 10963).
                </p>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* ── Signature & QR ────────────────────────────────────────────── */}
        <TabsContent value="signature" className="mt-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-sm flex items-center gap-2">
                  <Shield className="h-4 w-4 text-success" /> Digital Signature
                </CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-3 text-xs">
                {invoice.digital_signature ? (
                  <>
                    <div className="flex items-center gap-2 p-2 bg-success/10 border border-success/30 rounded">
                      <CheckCircle2 className="h-3.5 w-3.5 text-success flex-shrink-0" />
                      <p className="text-success font-medium">Invoice digitally signed</p>
                    </div>
                    <div className="space-y-1">
                      <p className="text-muted-foreground">Algorithm</p>
                      <p className="font-mono text-foreground">SHA-256</p>
                    </div>
                    <div className="space-y-1">
                      <p className="text-muted-foreground">Signature Hash</p>
                      <p className="font-mono bg-muted p-2 rounded break-all text-[10px]">{invoice.digital_signature}</p>
                    </div>
                    {invoice.signed_at && (
                      <div className="space-y-1">
                        <p className="text-muted-foreground">Signed At</p>
                        <p className="text-foreground">{new Date(invoice.signed_at).toLocaleString("en-PH")}</p>
                      </div>
                    )}
                    <div className="space-y-1">
                      <p className="text-muted-foreground">Validation Hash</p>
                      <p className="font-mono text-foreground text-sm">{invoice.validation_hash}</p>
                    </div>
                  </>
                ) : (
                  <p className="text-muted-foreground italic">This invoice has not been signed yet.</p>
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-sm flex items-center gap-2">
                  <QrCode className="h-4 w-4 text-primary" /> QR Code
                </CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-3 text-xs">
                {invoice.qr_payload ? (
                  <>
                    <div className="flex justify-center p-6 bg-muted/40 rounded-lg">
                      <QrCode className="h-24 w-24 text-foreground" />
                    </div>
                    <div className="space-y-1">
                      <p className="text-muted-foreground">QR Payload</p>
                      <p className="font-mono bg-muted p-2 rounded break-all text-[10px]">{invoice.qr_payload}</p>
                    </div>
                    <Button variant="outline" size="sm" className="w-full gap-1.5 h-7 text-xs"
                      onClick={() => { navigator.clipboard.writeText(invoice.qr_payload!); toast.success("QR payload copied"); }}>
                      <Copy className="h-3 w-3" /> Copy QR Payload
                    </Button>
                  </>
                ) : (
                  <p className="text-muted-foreground italic">QR code not yet generated.</p>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* ── Version History ───────────────────────────────────────────── */}
        <TabsContent value="history" className="mt-4">
          <Card>
            <CardHeader className="px-5 pt-4 pb-2">
              <CardTitle className="text-sm flex items-center gap-2">
                <History className="h-4 w-4" /> Version & Audit Trail
              </CardTitle>
            </CardHeader>
            <CardContent className="px-5 pb-4">
              <div className="space-y-0">
                {versions.map((v, i) => (
                  <div key={v.version} className="flex gap-3">
                    <div className="flex flex-col items-center">
                      <div className="h-6 w-6 rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center text-[10px] font-bold text-primary">
                        {v.version}
                      </div>
                      {i < versions.length - 1 && <div className="w-px flex-1 bg-border my-1" />}
                    </div>
                    <div className="pb-4 flex-1">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <Badge variant="outline" className="text-[10px] capitalize">{v.change_type}</Badge>
                          <span className="text-xs text-muted-foreground">{v.changed_by}</span>
                        </div>
                        <span className="text-[10px] text-muted-foreground">{v.ts ? new Date(v.ts).toLocaleString("en-PH") : "—"}</span>
                      </div>
                      <p className="text-xs text-foreground mt-1">{v.summary}</p>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Actions ───────────────────────────────────────────────────── */}
        <TabsContent value="actions" className="mt-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {[
              {
                title: "Send to Buyer",
                desc: "Email invoice to buyer's registered address",
                icon: Send, color: "text-primary",
                action: () => toast.success(`Invoice sent to ${invoice.buyer_email || "buyer"}`),
                disabled: invoice.status === "cancelled",
              },
              {
                title: "Mark as Paid",
                desc: "Record full payment for this invoice",
                icon: CheckCircle2, color: "text-success",
                action: () => toast.success("Invoice marked as paid"),
                disabled: invoice.status === "paid" || invoice.status === "cancelled",
              },
              {
                title: "Create Correction",
                desc: "Issue Credit Memo or Debit Memo against this invoice",
                icon: RotateCcw, color: "text-warning",
                action: handleCorrection,
                disabled: invoice.status === "draft",
              },
              {
                title: "Download PDF",
                desc: "Generate and download printable PDF",
                icon: Printer, color: "text-foreground",
                action: () => toast.info("Generating PDF…"),
                disabled: false,
              },
              {
                title: "Duplicate Invoice",
                desc: "Create a new invoice using this as template",
                icon: Copy, color: "text-chart-2",
                action: () => navigate(`/merchant/invoices/create?type=${invoice.document_type}`),
                disabled: false,
              },
              {
                title: "Export All Formats",
                desc: "Download JSON, XML, and CSV simultaneously",
                icon: Download, color: "text-chart-3",
                action: () => { exportToJSON(invoice); exportToCSV(invoice); toast.success("All formats exported"); },
                disabled: false,
              },
            ].map(a => (
              <button
                key={a.title}
                onClick={a.action}
                disabled={a.disabled}
                className="flex items-start gap-3 p-4 border rounded-lg text-left hover:bg-muted/30 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <a.icon className={`h-5 w-5 mt-0.5 flex-shrink-0 ${a.color}`} />
                <div>
                  <p className="text-xs font-semibold text-foreground">{a.title}</p>
                  <p className="text-[10px] text-muted-foreground mt-0.5">{a.desc}</p>
                </div>
                <ChevronRight className="h-3.5 w-3.5 text-muted-foreground ml-auto mt-0.5" />
              </button>
            ))}
          </div>

          {/* Cancellation workflow */}
          {!["cancelled", "voided", "paid"].includes(invoice.status) && (
            <Card className="mt-4 border-destructive/30">
              <CardHeader className="px-5 pt-4 pb-2">
                <CardTitle className="text-sm text-destructive flex items-center gap-2">
                  <Ban className="h-4 w-4" /> Invoice Cancellation
                </CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-4 space-y-3">
                <p className="text-xs text-muted-foreground">
                  Cancellation requires a reason and will create an immutable cancellation record. This action cannot be undone.
                </p>
                <div className="space-y-1">
                  <Label className="text-xs">Cancellation Reason *</Label>
                  <Textarea
                    value={cancelReason}
                    onChange={e => setCancelReason(e.target.value)}
                    placeholder="e.g. Duplicate invoice, buyer request, order cancelled…"
                    className="text-xs min-h-[72px]"
                  />
                </div>
                <AlertDialog>
                  <AlertDialogTrigger asChild>
                    <Button variant="destructive" size="sm" className="gap-1.5" disabled={!cancelReason.trim()}>
                      <Ban className="h-3.5 w-3.5" /> Submit Cancellation Request
                    </Button>
                  </AlertDialogTrigger>
                  <AlertDialogContent>
                    <AlertDialogHeader>
                      <AlertDialogTitle>Cancel Invoice {invoice.invoice_number}?</AlertDialogTitle>
                      <AlertDialogDescription>
                        This will void the invoice and create a permanent cancellation record. Reason: <strong>{cancelReason}</strong>
                      </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                      <AlertDialogCancel>No, keep invoice</AlertDialogCancel>
                      <AlertDialogAction onClick={handleCancel} className="bg-destructive hover:bg-destructive/90">
                        {cancelling ? "Processing…" : "Yes, cancel invoice"}
                      </AlertDialogAction>
                    </AlertDialogFooter>
                  </AlertDialogContent>
                </AlertDialog>
              </CardContent>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
