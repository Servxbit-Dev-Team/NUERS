import {SalesBenefitForm} from '@/components/invoices/sales-benefit-form';
import {benefitLine,benefitError,type SalesBenefit} from '@/lib/statutory-sales';
import type {InvoicePreferences} from '@/lib/merchant-invoice-settings';
import { SavePendingInvoice } from "@/components/merchant/client-queue";
import { useState, useCallback, useEffect } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import {
  ArrowLeft, Plus, Trash2, Save, Send, CheckCircle2,
  FileText, Receipt, CreditCard, TrendingUp, TrendingDown, Truck,
  Wrench, Shield, Zap, RefreshCw, Info,
  Loader2,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";
import { Progress } from "@/components/ui/progress";
import { taxForDocument } from "@/lib/document-presentation";
import { InvoiceCustomerPicker, type InvoiceCustomer } from "@/components/invoices/invoice-customer-picker";
import { BirDocumentPreview } from "@/components/invoices/bir-document-preview";
import {
  BIR_DOCUMENT_TYPES, DOC_TYPE_META, generateInvoiceNumber, computeLineItem, computeInvoiceTotals,
  formatAmount, toCentavos, fromCentavos, computeInvoiceChecksum, buildQRPayload,
  validateInvoice, type DocumentType, type LineItem, type TaxType,
} from "@/lib/invoice-utils";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

// ─── Doc type icons ────────────────────────────────────────────────────────
const DOC_ICON: Record<DocumentType, React.ElementType> = {
  vat_invoice:        FileText,
  non_vat_invoice:    FileText,
  vat_exempt_invoice: Receipt,
  zero_rated_invoice: Receipt,
  mixed_sales_invoice: FileText,
  payment_receipt:    Receipt,
  sales_invoice:    FileText,
  official_receipt: Receipt,
  credit_memo:      CreditCard,
  debit_memo:       TrendingUp,
  purchase_invoice: TrendingDown,
  delivery_receipt: Truck,
  service_invoice:  Wrench,
};

// ─── Empty line item ───────────────────────────────────────────────────────
let _id = 0;
function emptyLine(n: number, tax: TaxType = "vatable"): LineItem {
  return {
    id: `new-${++_id}`,
    line_number: n,
    item_code: "",
    description: "",
    unit: "pc",
    quantity: 1,
    unit_price: 0,
    discount_pct: 0,
    discount_amount: 0,
    taxable_amount: 0,
    vat_rate: tax === "vatable" ? 12 : 0,
    vat_amount: 0,
    line_total: 0,
    tax_type: tax,
  };
}

// ─── Steps ────────────────────────────────────────────────────────────────
const STEPS = ["Document Type", "Parties", "Line Items", "Tax & Totals", "Sign & Validate", "Preview"];

type BusinessAccountLookup = {
  invoice_preferences?: InvoicePreferences;
  id: string;
  type?: string;
  business_name: string;
  owner_name?: string | null;
  tin?: string | null;
  address?: string | null;
  email?: string | null;
  phone?: string | null;
  rdo_code?: string | null;
  rdo_name?: string | null;
  status?: string | null;
  vat_registered?: boolean | null;
};

const API_TOKEN_KEY = "nuers_api_token";

function authHeaders() {
  const headers = new Headers({ Accept: "application/json" });

  try {
    const token = window.localStorage.getItem(API_TOKEN_KEY);
    if (token) headers.set("Authorization", `Bearer ${token}`);
  } catch {
    // Browser storage can be unavailable in restricted contexts.
  }

  return headers;
}

function normalizeTin(value: string) {
  return value.replace(/\D/g, "");
}

function formatTin(value?: string | null) {
  const digits = normalizeTin(value ?? "").slice(0, 12);
  const groups = [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9), digits.slice(9, 12)].filter(Boolean);
  return groups.join("-");
}

export function InvoiceCreate() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const requestedType = searchParams.get("type") as DocumentType | null;
  const initialType = requestedType && requestedType !== "debit_memo" && requestedType in DOC_TYPE_META ? requestedType : "vat_invoice";

  const [step, setStep] = useState(0);
  const [saving, setSaving] = useState(false);
  const [pendingWalletPayload,setPendingWalletPayload]=useState<Record<string,unknown>|null>(null);
  const [saveRequestId] = useState(crypto.randomUUID());
  const [signing, setSigning] = useState(false);

  // ── Form state ─────────────────────────────────────────────────────────
  const [docType, setDocType] = useState<DocumentType>(initialType);
  const [invoiceNumber, setInvoiceNumber] = useState(() => generateInvoiceNumber(initialType));

  // Party fields
  const [merchantName, setMerchantName] = useState("");
  const [merchantTin, setMerchantTin] = useState("");
  const [merchantAddress, setMerchantAddress] = useState("");
  const [merchantVatRegistered, setMerchantVatRegistered] = useState<boolean | null>(null);
  const [sellerLoading, setSellerLoading] = useState(true);
  const [sellerMessage, setSellerMessage] = useState("Loading logged-in Business Account...");
  const [customerProfile, setCustomerProfile] = useState<InvoiceCustomer|null>(null);
  const [newBuyer, setNewBuyer] = useState(false);
  const [buyerPhone, setBuyerPhone] = useState("");
  const [buyerType, setBuyerType] = useState("retail");
  const [customerLoading, setCustomerLoading] = useState(false);
  const [buyerName, setBuyerName] = useState("");
  const [buyerTin, setBuyerTin] = useState("");
  const [buyerAddress, setBuyerAddress] = useState("");
  const [buyerEmail, setBuyerEmail] = useState("");


  // Dates
  const today = new Date().toISOString().split("T")[0];
  const [issueDate, setIssueDate] = useState(today);
  const [dueDate, setDueDate] = useState("");
  const [deliveryDate, setDeliveryDate] = useState("");
  const [periodStart, setPeriodStart] = useState("");
  const [periodEnd, setPeriodEnd] = useState("");

  // References
  const [refNumber, setRefNumber] = useState("");
  const [poNumber, setPoNumber] = useState("");
  const [soNumber, setSoNumber] = useState("");
  const [correctionReason, setCorrectionReason] = useState("");
  const [originalInvoice, setOriginalInvoice] = useState(searchParams.get("original") || "");

  // Line items
  const [salesBenefit,setSalesBenefit]=useState<SalesBenefit|null>(null);
  const [rawLines, setLines] = useState<LineItem[]>([emptyLine(1, taxForDocument(initialType))]);

  // Tax/charges
  const [withholdingPct, setWithholdingPct] = useState(0);
  const [cwt, setCwt] = useState({base:"",classification:"",atc:"",evidence_reference:"",confirmed:false});
  const [increaseCorrection,setIncreaseCorrection]=useState(false);
  const [manualRecoveryId,setManualRecoveryId]=useState("");
  const otherCharges = 0;
  const [preferences,setPreferences]=useState<InvoicePreferences|null>(null);
  const [pricesIncludeVat,setPricesIncludeVat]=useState(true);
  const [footerNote,setFooterNote]=useState("");
  const [notes, setNotes] = useState("");
  const [terms, setTerms] = useState("Payment due within 30 days of invoice date.");

  const lines=rawLines.map(l=>benefitLine(l,salesBenefit,pricesIncludeVat));

  // Signing
  const [signature, setSignature] = useState("");
  const [qrPayload, setQrPayload] = useState("");
  const [validationHash, setValidationHash] = useState("");
  const [isSigned, setIsSigned] = useState(false);
  const [isValidated, setIsValidated] = useState(false);

  // Formats
  const [fmtPdf, setFmtPdf] = useState(true);
  const [fmtJson, setFmtJson] = useState(true);
  const [fmtXml, setFmtXml] = useState(false);
  const [fmtCsv, setFmtCsv] = useState(false);

  useEffect(() => {
    let active = true;

    async function loadCurrentSeller() {
      setSellerLoading(true);
      setSellerMessage("Loading logged-in Business Account...");

      try {
        const response = await apiFetch("/api/business-accounts/current", {
          headers: authHeaders(),
          cache: "no-store",
        });
        const payload = await readJsonResponse<{ data: BusinessAccountLookup }>(response, "Unable to load logged-in Business Account.");

        const account = payload.data as BusinessAccountLookup;

        if (!active) return;

        setMerchantName(account.business_name || "");
        setMerchantTin(formatTin(account.tin));
        setMerchantAddress(account.address || "");
        setMerchantVatRegistered(typeof account.vat_registered === "boolean" ? account.vat_registered : null);
        const prefs=account.invoice_preferences;
        if(prefs){
          setPreferences(prefs);setPricesIncludeVat(prefs.price_display==='inclusive');setNotes(prefs.additional_notes);setFooterNote(prefs.footer_text);setTerms(prefs.terms_and_conditions);
          const type=(requestedType&&requestedType in DOC_TYPE_META?requestedType:prefs.default_document_type) as DocumentType;
          setDocType(type);setInvoiceNumber(generateInvoiceNumber(type));
          const forced=taxForDocument(type),tax=account.vat_registered?forced:(forced==='vatable'?'non_vat':forced);
          setLines([emptyLine(1,tax)]);
          const current=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Manila'});setIssueDate(prefs.invoice_date==='current'?current:'');
          if(prefs.invoice_date==='current'){const due=new Date(current+'T12:00:00+08:00');due.setDate(due.getDate()+prefs.payment_terms_days);setDueDate(due.toLocaleDateString('en-CA',{timeZone:'Asia/Manila'}));}
        }

        setSellerMessage(account.tin ? "Seller loaded from your logged-in Business Account." : "Seller loaded. TIN is still pending in the Business Account registry.");
      } catch (error) {
        if (!active) return;
        setSellerMessage(error instanceof Error ? error.message : "Unable to load logged-in Business Account.");
      } finally {
        if (active) setSellerLoading(false);
      }
    }

    loadCurrentSeller();

    return () => {
      active = false;
    };
  }, []);

  function selectCustomer(customer:InvoiceCustomer) {
    setCustomerProfile(customer);setNewBuyer(false);setBuyerName(customer.name);setBuyerTin(customer.tin || "");setBuyerAddress(customer.address || "");setBuyerEmail(customer.email || "");setBuyerPhone(customer.phone || "");setBuyerType(customer.type);
  }
  function addNewBuyer(name:string) {
    setCustomerProfile(null);setNewBuyer(true);setBuyerName(name);setBuyerTin("");setBuyerAddress("");setBuyerEmail("");setBuyerPhone("");setBuyerType("retail");
  }
  useEffect(() => {
    const customerId = searchParams.get("customer");
    if (!customerId) return;
    const controller=new AbortController();setCustomerLoading(true);
    apiFetch(`/api/merchant/customers/${encodeURIComponent(customerId)}`,{signal:controller.signal}).then(r=>readJsonResponse<{data:InvoiceCustomer}>(r)).then(({data})=>{
      if(controller.signal.aborted)return;
      if(data.status!=="active")throw new Error("This customer profile is inactive.");
      selectCustomer(data);
    }).catch(e=>{if(!controller.signal.aborted)toast.error(e.message || "Could not load customer profile.")}).finally(()=>{if(!controller.signal.aborted)setCustomerLoading(false)});
    return()=>controller.abort();
  },[searchParams]);

  // ── Re-generate invoice number when doc type changes ──────────────────
  const handleDocTypeChange = (t: DocumentType) => {
    setDocType(t);
    setInvoiceNumber(generateInvoiceNumber(t));
    const forcedTax = ({
      vat_invoice: "vatable",
      non_vat_invoice: "non_vat",
      vat_exempt_invoice: "vat_exempt",
      zero_rated_invoice: "zero_rated",
    } as Partial<Record<DocumentType, TaxType>>)[t];

    if (forcedTax) {
      setLines((current) => current.map((line) => {
        const updated = { ...line, tax_type: forcedTax, vat_rate: forcedTax === "vatable" ? 12 : 0 };
        return { ...updated, ...computeLineItem(updated.unit_price, updated.quantity, updated.discount_pct, updated.vat_rate, updated.tax_type,pricesIncludeVat) };
      }));
    }
  };

  // ── Recompute a single line ────────────────────────────────────────────
  const updateLine = useCallback((id: string, patch: Partial<LineItem>) => {
    setLines(prev => prev.map(l => {
      if (l.id !== id) return l;
      const updated = { ...l, ...patch };
      const computed = computeLineItem(
        updated.unit_price, updated.quantity, updated.discount_pct,
        updated.vat_rate, updated.tax_type,pricesIncludeVat
      );
      return { ...updated, ...computed };
    }));
  }, [pricesIncludeVat]);

  const addLine = () => setLines(prev => [...prev, emptyLine(prev.length + 1, merchantVatRegistered===false?'non_vat':taxForDocument(docType))]);
  const removeLine = (id: string) => setLines(prev => prev.filter(l => l.id !== id).map((l, i) => ({ ...l, line_number: i + 1 })));

  // ── Totals ─────────────────────────────────────────────────────────────
  const statutoryDiscount=lines.reduce((s,l)=>s+(l.benefit_details?.discount_centavos||0),0);
  const vatAdjustment=lines.reduce((s,l)=>s+(l.benefit_details?.vat_adjustment_centavos||0),0);
  const baseTotals = computeInvoiceTotals(lines, 0, toCentavos(otherCharges));
  const totals = {...baseTotals, withholding: Math.round(toCentavos(Number(cwt.base||0))*withholdingPct/100)};

  // ── Sign & validate ────────────────────────────────────────────────────
  const handleSign = async () => {
    setSigning(true);
    const partial = {
      invoice_number: invoiceNumber,
      total_amount: totals.total,
      issue_date: issueDate,
      merchant_tin: merchantTin,
      buyer_tin: buyerTin || undefined,
    };
    try {
      const sig = await computeInvoiceChecksum(partial);
      const qr = buildQRPayload({ ...partial, document_type: docType, validation_hash: sig.slice(0, 16) });
      setSignature(sig);setQrPayload(qr);setValidationHash(sig.slice(0, 16));setIsSigned(true);
      toast.success("Preview checksum generated");
    } catch(error) {toast.error(error instanceof Error?error.message:"Unable to compute checksum.");}
    finally {setSigning(false);}

  };

  useEffect(() => { setIsValidated(false); setIsSigned(false); setSignature(""); setQrPayload(""); setValidationHash(""); }, [rawLines, salesBenefit, docType, merchantName, merchantTin, merchantAddress, buyerName, buyerTin, buyerAddress, buyerEmail, buyerPhone, buyerType, issueDate, withholdingPct, cwt, manualRecoveryId, originalInvoice, refNumber, poNumber, soNumber, merchantVatRegistered]);

  const handleValidate = () => {
    const error=benefitError(salesBenefit,rawLines,issueDate);if(error){toast.error(error);return;}
    if(preferences?.require_customer_tin&&!buyerTin.trim()){toast.error("Your Invoice Settings require a customer TIN.");return;}
    if(preferences&&!preferences.allow_zero_rated&&lines.some(l=>l.tax_type==="zero_rated")){toast.error("Enable eligible zero-rated transactions in Invoice Settings first.");return;}
    if (merchantVatRegistered === false && lines.some(line => line.tax_type === "vatable")) { toast.error("This seller is recorded as Non-VAT. Select the correct document and line tax classification."); return; }
    const result = validateInvoice(
      { buyer_name: buyerName, merchant_tin: merchantTin, total_amount: totals.total, issue_date: issueDate, document_type: docType, original_invoice_id: originalInvoice || undefined, reference_number: refNumber || undefined },
      lines
    );
    if (!result.valid) {
      result.errors.forEach(e => toast.error(e));
      return;
    }
    result.warnings.forEach(w => toast.warning(w));
    setIsValidated(true);
    toast.success("Local form checks passed. Server validation and issuance authority are still required.");
  };

  const handleSave = async (send = false) => {
    setSaving(true);

    try {
      if(preferences?.require_customer_tin&&!buyerTin.trim())throw new Error("Your Invoice Settings require a customer TIN.");
      if (send || isValidated) {
        const result = validateInvoice(
          {
            buyer_name: buyerName,
            merchant_tin: merchantTin,
            total_amount: totals.total,
            issue_date: issueDate,
            document_type: docType,
            original_invoice_id: originalInvoice || undefined,
            reference_number: refNumber || undefined,
          },
          lines
        );

        if (!result.valid) {
          result.errors.forEach(e => toast.error(e));
          setSaving(false);
          return;
        }
      }

      const headers = authHeaders();
      headers.set("Content-Type", "application/json");

      const formats = [
        fmtPdf ? "pdf" : null,
        fmtJson ? "json" : null,
        fmtXml ? "xml" : null,
        fmtCsv ? "csv" : null,
      ].filter(Boolean);

      const invoicePayload = {
          request_id: saveRequestId,
          invoice_number: invoiceNumber,
          document_type: docType,
          status: send ? "issued" : "draft",
          buyer_name: buyerName,
          customer_id: customerProfile?.id,
          customer_revision: customerProfile?.revision,
          buyer_customer_type: buyerType,
          buyer_phone: buyerPhone || null,
          buyer_tin: buyerTin,
          buyer_address: buyerAddress,
          buyer_email: buyerEmail,
          issue_date: issueDate,
          due_date: dueDate || null,
          delivery_date: deliveryDate || null,
          period_start: periodStart || null,
          period_end: periodEnd || null,
          reference_number: refNumber || null,
          purchase_order: poNumber || null,
          sales_order: soNumber || null,
          original_invoice_id: originalInvoice || null,
          correction_reason: correctionReason || null,
          sales_benefit: salesBenefit,
          subtotal_amount: totals.subtotal,
          discount_amount: totals.discount,
          taxable_amount: totals.taxable,
          vat_amount: totals.vat,
          withholding_tax: totals.withholding,
          withholding_pct: withholdingPct,
          withholding_details: withholdingPct>0 ? {withholding_agent:cwt.confirmed,classification:cwt.classification,atc:cwt.atc,rate:withholdingPct,base:Number(cwt.base),amount:totals.withholding/100,evidence_reference:cwt.evidence_reference} : null,
          manual_recovery_id: manualRecoveryId || null,
          other_charges: totals.other_charges,
          total_amount: totals.total,
          amount_paid: 0,
          amount_due: Math.max(0, totals.total - totals.withholding),
          zero_rated_amount: totals.zero_rated,
          vat_exempt_amount: totals.vat_exempt,
          notes,
          terms_and_conditions: terms,
          footer_note: footerNote,
          prices_include_vat: pricesIncludeVat,
          formats_generated: formats.length ? formats : ["pdf", "json"],
          qr_payload: qrPayload || null,
          digital_signature: null,
          validation_hash: validationHash || null,
          line_items: lines.map(line => ({
            line_number: line.line_number,
            item_code: line.item_code,
            description: line.description,
            unit: line.unit,
            quantity: line.quantity,
            unit_price: line.unit_price,
            discount_pct: line.discount_pct,
            discount_amount: line.discount_amount,
            taxable_amount: line.taxable_amount,
            benefit_eligible: !!salesBenefit && !!line.benefit_eligible,
            vat_rate: (line.benefit_details?.original_tax_type||line.tax_type)==='vatable'?12:0,
            vat_amount: line.vat_amount,
            line_total: line.line_total,
            tax_type: line.benefit_details?.original_tax_type||line.tax_type,
          })),
        };
      const response = await apiFetch("/api/business-invoices",{method:"POST",headers,body:JSON.stringify(invoicePayload)});
      if(response.status===402){setPendingWalletPayload(invoicePayload);return;}

      const saved = await readJsonResponse<{customer?:{created:boolean;code:string}}>(response, "Unable to save invoice to the database.");
      if (saved.customer?.created) toast.success(`New buyer saved to Customers (${saved.customer.code}).`);

      toast.success(send ? `Invoice ${invoiceNumber} issued locally; BIR submission remains subject to authorization.` : `Invoice ${invoiceNumber} saved to database.`);
      navigate("/merchant/invoices");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to save invoice to the database.");
    } finally {
      setSaving(false);
    }
  };

  const showPeriod = docType === "service_invoice";
  const showDelivery = docType === "delivery_receipt";
  const showCorrection = docType === "credit_memo" || increaseCorrection;

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Button variant="ghost" size="sm" onClick={() => navigate("/merchant/invoices")} className="gap-1">
          <ArrowLeft className="h-4 w-4" /> Back
        </Button>
        <div className="flex-1">
          <h1 className="text-xl font-bold text-foreground">New {DOC_TYPE_META[docType].label}</h1>
          <p className="text-xs text-muted-foreground font-mono">{invoiceNumber}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => handleSave(false)} disabled={saving} className="gap-1.5">
            <Save className="h-3.5 w-3.5" /> Save Draft
          </Button>
          <Button size="sm" onClick={() => handleSave(true)} disabled={saving} className="gap-1.5">
            <Send className="h-3.5 w-3.5" /> {saving ? "Saving…" : "Save & Send"}
          </Button>
        </div>
      </div>

      {/* Progress */}
      <div className="space-y-2">
        <div className="flex justify-between">
          {STEPS.map((s, i) => (
            <button
              key={s}
              onClick={() => setStep(i)}
              className={cn(
                "text-xs font-medium transition-colors",
                i === step ? "text-foreground" : i < step ? "text-muted-foreground" : "text-muted-foreground/50"
              )}
            >
              {i + 1}. {s}
            </button>
          ))}
        </div>
        <Progress value={((step + 1) / STEPS.length) * 100} className="h-1.5" />
      </div>

      {/* Step 0 — Document Type */}
      {step === 0 && (
        <Card>
          <CardHeader className="px-5 pt-5 pb-3">
            <CardTitle className="text-sm">Select Document Type</CardTitle>
          </CardHeader>
          <CardContent className="px-5 pb-5 space-y-4">
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              {BIR_DOCUMENT_TYPES.filter(type=>type!=="debit_memo").map((type) => {
                const meta = DOC_TYPE_META[type];
                const Icon = DOC_ICON[type];
                return (
                  <button
                    key={type}
                    disabled={type==="zero_rated_invoice"&&!!preferences&&!preferences.allow_zero_rated} onClick={() => handleDocTypeChange(type)}
                    className={cn(
                      "flex flex-col items-start p-4 rounded-lg border text-left transition-all hover:border-primary/50",
                      docType === type ? "border-primary bg-primary/5" : "border-border"
                    )}
                  >
                    <Icon className={`h-5 w-5 mb-2 ${meta.color}`} />
                    <p className="text-xs font-semibold text-foreground">{meta.label}</p>
                    <p className="text-[10px] text-muted-foreground mt-0.5">{meta.prefix}-YYYY-XXXXXX</p>
                    {docType === type && (
                      <CheckCircle2 className="h-3.5 w-3.5 text-primary mt-2" />
                    )}
                  </button>
                );
              })}
            </div>
            <div className="flex items-center gap-3 pt-2">
              <div className="flex-1 space-y-1">
                <Label className="text-xs">Invoice Number</Label>
                <Input value={invoiceNumber} onChange={e => setInvoiceNumber(e.target.value)} className="font-mono text-xs h-8" />
              </div>
              <div className="flex-1 space-y-1">
                <Label className="text-xs">Issue Date</Label>
                <Input type="date" value={issueDate} onChange={e => setIssueDate(e.target.value)} className="text-xs h-8" />
              </div>
              {!showDelivery && (
                <div className="flex-1 space-y-1">
                  <Label className="text-xs">Due Date</Label>
                  <Input type="date" value={dueDate} onChange={e => setDueDate(e.target.value)} className="text-xs h-8" />
                </div>
              )}
              {showDelivery && (
                <div className="flex-1 space-y-1">
                  <Label className="text-xs">Delivery Date</Label>
                  <Input type="date" value={deliveryDate} onChange={e => setDeliveryDate(e.target.value)} className="text-xs h-8" />
                </div>
              )}
            </div>
            {showPeriod && (
              <div className="flex gap-3">
                <div className="flex-1 space-y-1">
                  <Label className="text-xs">Service Period Start</Label>
                  <Input type="date" value={periodStart} onChange={e => setPeriodStart(e.target.value)} className="text-xs h-8" />
                </div>
                <div className="flex-1 space-y-1">
                  <Label className="text-xs">Service Period End</Label>
                  <Input type="date" value={periodEnd} onChange={e => setPeriodEnd(e.target.value)} className="text-xs h-8" />
                </div>
              </div>
            )}
            {docType !== "credit_memo" && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={increaseCorrection} onChange={e=>{setIncreaseCorrection(e.target.checked);if(!e.target.checked){setOriginalInvoice("");setCorrectionReason("")}}}/>Additional invoice for an increase to an earlier invoice</label>}
            <p className="text-xs text-muted-foreground">For a decrease, use a credit memo. For an increase, issue a new invoice for the additional amount and reference the original. For CWT-only changes, use Compliance Operations.</p>
            {showCorrection && (
              <div className="space-y-3 border rounded-lg p-4 bg-warning/5 border-warning/30">
                <p className="text-xs font-medium text-warning flex items-center gap-1.5">
                  <Info className="h-3.5 w-3.5" />
                  {docType === "credit_memo" ? "Credit Memo requires reference to original invoice" : "Additional invoice referencing the original"}
                </p>
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <Label className="text-xs">Original Invoice #</Label>
                    <Input value={originalInvoice} onChange={e => setOriginalInvoice(e.target.value)} className="text-xs h-8" placeholder="SI-2026-XXXXXX" />
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs">Reference Number</Label>
                    <Input value={refNumber} onChange={e => setRefNumber(e.target.value)} className="text-xs h-8" />
                  </div>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Correction / Reason</Label>
                  <Input value={correctionReason} onChange={e => setCorrectionReason(e.target.value)} className="text-xs h-8" placeholder="e.g. Returned goods, pricing error…" />
                </div>
              </div>
            )}
            <div className="flex justify-end">
              <Button size="sm" onClick={() => setStep(1)}>Next: Parties</Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Step 1 — Parties */}
      {step === 1 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader className="px-5 pt-5 pb-3">
              <div className="flex items-center justify-between gap-3">
                <CardTitle className="text-sm">Seller / Business Account</CardTitle>
                <Badge variant={sellerLoading ? "secondary" : "outline"} className="gap-1 text-[10px]">
                  {sellerLoading ? <Loader2 className="h-3 w-3 animate-spin" /> : <CheckCircle2 className="h-3 w-3" />}
                  Logged-in account
                </Badge>
              </div>
            </CardHeader>
            <CardContent className="px-5 pb-5 space-y-3">
              <p className={cn("rounded-lg border px-3 py-2 text-xs", merchantTin ? "border-success/20 bg-success/5 text-success" : "border-warning/30 bg-warning/5 text-warning")}>
                {sellerMessage}
              </p>
              {[
                { label: "Business Name", val: merchantName, set: setMerchantName },
                { label: "TIN", val: merchantTin, set: setMerchantTin, mono: true },
                { label: "Address", val: merchantAddress, set: setMerchantAddress },
              ].map(f => (
                <div key={f.label} className="space-y-1">
                  <Label className="text-xs">{f.label}</Label>
                  <Input
                    value={f.val}
                    onChange={e => f.set(e.target.value)}
                    className={cn("h-8 bg-muted/40 text-xs", f.mono && "font-mono")}
                    readOnly
                  />
                </div>
              ))}
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label className="text-xs">P.O. Number</Label>
                  <Input value={poNumber} onChange={e => setPoNumber(e.target.value)} className="text-xs h-8" />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">S.O. Number</Label>
                  <Input value={soNumber} onChange={e => setSoNumber(e.target.value)} className="text-xs h-8" />
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="px-5 pt-5 pb-3">
              <div className="flex items-center justify-between gap-3">
                <CardTitle className="text-sm">Buyer / Customer</CardTitle>
                {customerProfile && <Badge variant="secondary" className="text-xs">Saved customer</Badge>}
              </div>
            </CardHeader>
            <CardContent className="px-5 pb-5 space-y-4">
              <div className="space-y-2">
                <Label className="text-sm">Business / Person Name *</Label>
                <InvoiceCustomerPicker selected={customerProfile} newName={newBuyer?buyerName:""} onSelect={selectCustomer} onNew={addNewBuyer} disabled={saving||customerLoading}/>
                {customerLoading && <p className="text-sm text-muted-foreground" role="status">Loading customer profile…</p>}
                {customerProfile && <p className="text-xs text-muted-foreground">{customerProfile.code} · <Link to="/merchant/customers" target="_blank" rel="noopener noreferrer" className="text-blue-600 underline">Manage customers</Link></p>}
              </div>
              {newBuyer && <div className="space-y-2"><Label htmlFor="invoice-new-buyer-name" className="text-sm">New Business / Person Name *</Label><Input id="invoice-new-buyer-name" value={buyerName} onChange={e=>setBuyerName(e.target.value)} maxLength={255} className="min-h-10 text-sm" placeholder="Enter the buyer’s full name"/><p className="text-xs text-muted-foreground">This buyer will be saved to Customers with the invoice. Existing matches are reused; conflicting profiles require selection.</p></div>}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="space-y-2"><Label htmlFor="invoice-buyer-tin" className="text-sm">Buyer TIN (if applicable)</Label><Input id="invoice-buyer-tin" value={buyerTin} onChange={e=>setBuyerTin(e.target.value)} readOnly={Boolean(customerProfile)} className="min-h-10 font-mono text-sm" placeholder="000-000-000-000" maxLength={15}/></div>
                <div className="space-y-2"><Label htmlFor="invoice-buyer-type" className="text-sm">Customer Type</Label><select id="invoice-buyer-type" value={buyerType} disabled={Boolean(customerProfile)} onChange={e=>setBuyerType(e.target.value)} className="w-full min-h-10 rounded-md border bg-background px-3 text-sm"><option value="retail">Retail / Individual</option><option value="business">B2B / Business</option><option value="walk_in">Walk-in</option></select></div>
              </div>
              <div className="space-y-2"><Label htmlFor="invoice-buyer-address" className="text-sm">Buyer Address</Label><Input id="invoice-buyer-address" value={buyerAddress} onChange={e=>setBuyerAddress(e.target.value)} maxLength={1000} className="min-h-10 text-sm" placeholder="Buyer’s invoice address"/></div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3"><div className="space-y-2"><Label htmlFor="invoice-buyer-email" className="text-sm">Buyer Email</Label><Input id="invoice-buyer-email" type="email" value={buyerEmail} onChange={e=>setBuyerEmail(e.target.value)} maxLength={255} className="min-h-10 text-sm" placeholder="customer@example.com"/></div><div className="space-y-2"><Label htmlFor="invoice-buyer-phone" className="text-sm">Buyer Phone (optional)</Label><Input id="invoice-buyer-phone" value={buyerPhone} onChange={e=>setBuyerPhone(e.target.value)} maxLength={30} className="min-h-10 text-sm" placeholder="Mobile / phone number"/></div></div>
              <p className="text-xs leading-relaxed text-muted-foreground">{customerProfile?"Address and contact edits apply to this invoice only. Update the customer directory separately when permanent details change.":"Saving an invoice or draft adds a new buyer profile. No customer login or email notification is created."} Buyer details do not establish BIR registration.</p>
            </CardContent>
          </Card>
          <div className="lg:col-span-2 flex justify-between">
            <Button variant="outline" size="sm" onClick={() => setStep(0)}>Back</Button>
            <Button size="sm" onClick={() => setStep(2)} disabled={!buyerName.trim()||!!preferences?.require_customer_tin&&!buyerTin.trim()}>Next: Line Items</Button>
          </div>
        </div>
      )}

      {/* Step 2 — Line Items */}
      {step === 2 && (
        <Card>
          <CardHeader className="px-5 pt-5 pb-3 flex-row items-center justify-between">
            <CardTitle className="text-sm">Line Items</CardTitle>
            <Button size="sm" variant="outline" onClick={addLine} className="gap-1 h-7 text-xs">
              <Plus className="h-3 w-3" /> Add Line
            </Button>
          </CardHeader>
          <CardContent className="px-5 pb-5">
            <SalesBenefitForm value={salesBenefit} onChange={setSalesBenefit}/>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b">
                    {["#","Code","Description","Unit","Qty","Unit Price","Disc%","Tax Type","VAT%","Line Total",""].map(h => (
                      <th key={h} className="pb-2 text-left font-medium text-muted-foreground pr-2 whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {lines.map(line => (
                    <tr key={line.id} className="border-b last:border-0">
                      <td className="py-2 pr-2 text-muted-foreground">{line.line_number}</td>
                      <td className="py-2 pr-2">
                        <Input value={line.item_code} onChange={e => updateLine(line.id, { item_code: e.target.value })} className="h-7 w-20 text-xs font-mono" placeholder="CODE" />
                      </td>
                      <td className="py-2 pr-2">
                        <Input value={line.description} onChange={e => updateLine(line.id, { description: e.target.value })} className="h-7 w-48 text-xs" placeholder="Description *" />{salesBenefit&&<label className="flex items-center gap-2 mt-2 text-xs"><input type="checkbox" checked={!!line.benefit_eligible} onChange={e=>updateLine(line.id,{benefit_eligible:e.target.checked,discount_pct:0})}/>Eligible beneficiary item</label>}
                      </td>
                      <td className="py-2 pr-2">
                        <Select value={line.unit} onValueChange={v => updateLine(line.id, { unit: v })}>
                          <SelectTrigger className="h-7 w-16 text-xs"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {["pc","box","kg","ltr","hr","day","mo","set","svc"].map(u => (
                              <SelectItem key={u} value={u}>{u}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </td>
                      <td className="py-2 pr-2">
                        <Input type="number" value={line.quantity} min={0} step={0.01} onChange={e => updateLine(line.id, { quantity: +e.target.value })} className="h-7 w-16 text-xs text-right" />
                      </td>
                      <td className="py-2 pr-2">
                        <Input type="number" value={fromCentavos(line.unit_price)} min={0} step={0.01} onChange={e => updateLine(line.id, { unit_price: toCentavos(+e.target.value) })} className="h-7 w-24 text-xs text-right" />
                      </td>
                      <td className="py-2 pr-2">
                        <Input type="number" value={line.discount_pct} disabled={!!salesBenefit && !!line.benefit_eligible} min={0} max={100} step={0.01} onChange={e => updateLine(line.id, { discount_pct: +e.target.value })} className="h-7 w-14 text-xs text-right" />
                      </td>
                      <td className="py-2 pr-2">
                        <Select value={line.benefit_details?.original_tax_type||line.tax_type} onValueChange={v => updateLine(line.id, { tax_type: v as TaxType })}>
                          <SelectTrigger className="h-7 w-24 text-xs"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="vatable">Vatable</SelectItem>
                            <SelectItem value="zero_rated">Zero-rated</SelectItem>
                            <SelectItem value="vat_exempt">VAT-exempt</SelectItem>
                            <SelectItem value="non_vat">Non-VAT</SelectItem>
                          </SelectContent>
                        </Select>
                      </td>
                      <td className="py-2 pr-2">
                        <Input type="number" value={line.vat_rate} min={0} max={100} onChange={e => updateLine(line.id, { vat_rate: +e.target.value })} className="h-7 w-14 text-xs text-right" disabled={line.tax_type !== "vatable"} />
                      </td>
                      <td className="py-2 pr-2 text-right font-semibold tabular-nums whitespace-nowrap">
                        {formatAmount(line.line_total)}
                      </td>
                      <td className="py-2">
                        <Button variant="ghost" size="sm" className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive" onClick={() => removeLine(line.id)} disabled={lines.length === 1}>
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex justify-between mt-4 pt-2">
              <Button variant="outline" size="sm" onClick={() => setStep(1)}>Back</Button>
              <Button size="sm" onClick={() => setStep(3)} disabled={lines.every(l => !l.description)}>Next: Tax & Totals</Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Step 3 — Tax & Totals */}
      {step === 3 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader className="px-5 pt-5 pb-3">
              <CardTitle className="text-sm">Tax Configuration</CardTitle>
            </CardHeader>
            <CardContent className="px-5 pb-5 space-y-4">
              <div className="space-y-1">
                <Label className="text-xs">Buyer-confirmed CWT rate (%)</Label>
                <Input type="number" value={withholdingPct} min={0} max={100} step={0.01} onChange={e => setWithholdingPct(+e.target.value)} className="text-xs h-8 w-32" />
              </div>
              {withholdingPct>0 && <div className="space-y-3 border rounded-lg p-4">
                {([['base','CWT tax base (PHP)'],['classification','Buyer withholding-agent classification'],['atc','Alphanumeric Tax Code (ATC)'],['evidence_reference','Buyer / payor confirmation reference']] as const).map(([key,label])=><label className="block text-sm" key={key}>{label}<Input value={cwt[key]} type={key==='base'?'number':'text'} min={0} step="0.01" onChange={e=>setCwt({...cwt,[key]:e.target.value})}/></label>)}
                <label className="flex gap-2 text-sm"><input type="checkbox" checked={cwt.confirmed} onChange={e=>setCwt({...cwt,confirmed:e.target.checked})}/>Buyer/payor confirmed the CWT basis, rate and classification.</label><p className="text-xs text-muted-foreground">CWT is shown separately. It reduces the net payable, without reducing sales or VAT.</p></div>}
              <div className="space-y-1"><Label>Manual recovery record ID (optional)</Label><Input value={manualRecoveryId} onChange={e=>setManualRecoveryId(e.target.value)} placeholder="Copy the ID from Compliance Operations"/><p className="text-xs text-muted-foreground">For outage replacements, also enter the original manual invoice number in Reference Number. Amount, branch and buyer must match.</p></div>
              <div className="space-y-1">
                <Label className="text-xs">Other Charges (₱)</Label>
                <Input type="number" value={otherCharges} disabled aria-label="Add charges as classified line items" className="text-xs h-8 w-32" /><p className="text-xs text-muted-foreground">Add extra charges as line items with their tax classification.</p>
              </div>
              <Separator />
              <div className="space-y-1">
                <Label className="text-xs">Notes / Remarks</Label>
                <Textarea value={notes} onChange={e => setNotes(e.target.value)} className="text-xs min-h-[72px]" placeholder="Optional notes…" />
              </div>
              <div className="space-y-1">
                <Label className="text-xs">Terms & Conditions</Label>
                <Textarea value={terms} onChange={e => setTerms(e.target.value)} className="text-xs min-h-[60px]" />
              </div>

              <Separator />
              <p className="text-xs font-semibold text-foreground">Output Formats</p>
              <div className="grid grid-cols-2 gap-3">
                {[
                  { label: "PDF", val: fmtPdf, set: setFmtPdf },
                  { label: "JSON", val: fmtJson, set: setFmtJson },
                  { label: "XML", val: fmtXml, set: setFmtXml },
                  { label: "CSV", val: fmtCsv, set: setFmtCsv },
                ].map(f => (
                  <div key={f.label} className="flex items-center justify-between">
                    <Label className="text-xs">{f.label}</Label>
                    <Switch checked={f.val} onCheckedChange={f.set} />
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="px-5 pt-5 pb-3">
              <CardTitle className="text-sm">Tax Breakdown & Totals</CardTitle>
            </CardHeader>
            <CardContent className="px-5 pb-5">
              <div className="space-y-2 text-xs">
                {[
                  { label: "Gross Sales",       val: totals.subtotal,   bold: false },
                  { label: "Less: Discounts",    val: -totals.discount,  bold: false, neg: true },
                  { label: "Less: VAT Adjustment", val: -vatAdjustment, bold:false, neg:true },
                  { label: "Sales After Discounts", val: totals.total - totals.vat, bold: false },
                  null,
                  { label: "Taxable Sales",      val: totals.taxable,    bold: false },
                  { label: "Zero-Rated Sales",   val: totals.zero_rated, bold: false },
                  { label: "VAT-Exempt Sales",   val: totals.vat_exempt, bold: false },
                  null,
                  { label: "VAT Output (12%)",   val: totals.vat,        bold: false },
                  { label: "Other Charges",      val: totals.other_charges, bold: false },
                  { label: "Less: Withholding",  val: -totals.withholding, bold: false, neg: true },
                  null,
                  { label: "TOTAL AMOUNT DUE",   val: totals.total - totals.withholding, bold: true },
                ].map((row, i) => {
                  if (!row) return <Separator key={`sep-${i}`} className="my-1" />;
                  return (
                    <div key={row.label} className={cn("flex justify-between", row.bold && "font-bold text-sm border-t pt-2 mt-1")}>
                      <span className={cn("text-muted-foreground", row.bold && "text-foreground")}>{row.label}</span>
                      <span className={cn("tabular-nums font-mono", row.neg && row.val < 0 ? "text-destructive" : "", row.bold && "text-foreground")}>
                        {formatAmount(Math.abs(row.val))}
                        {row.val < 0 && row.neg && <span className="ml-0.5 text-destructive">(DR)</span>}
                      </span>
                    </div>
                  );
                })}
              </div>

              <div className="mt-4 p-3 bg-muted/40 rounded-lg space-y-1 text-[10px] text-muted-foreground">
                <p className="font-semibold text-foreground text-xs">VAT Summary</p>
                <div className="flex justify-between"><span>Vatable Sales</span><span className="tabular-nums">{formatAmount(totals.taxable)}</span></div>
                <div className="flex justify-between"><span>VAT Amount</span><span className="tabular-nums">{formatAmount(totals.vat)}</span></div>
                <div className="flex justify-between"><span>Zero-Rated</span><span className="tabular-nums">{formatAmount(totals.zero_rated)}</span></div>
                <div className="flex justify-between"><span>VAT-Exempt</span><span className="tabular-nums">{formatAmount(totals.vat_exempt)}</span></div>
              </div>
            </CardContent>
          </Card>
          <div className="lg:col-span-2 flex justify-between">
            <Button variant="outline" size="sm" onClick={() => setStep(2)}>Back</Button>
            <Button size="sm" onClick={() => setStep(4)}>Next: Sign & Validate</Button>
          </div>
        </div>
      )}

      {/* Step 4 — Sign & Validate */}
      {step === 4 && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader className="px-5 pt-5 pb-3">
              <CardTitle className="text-sm flex items-center gap-2">
                <Shield className="h-4 w-4 text-primary" />
                Document Checksum
              </CardTitle>
            </CardHeader>
            <CardContent className="px-5 pb-5 space-y-4">
              <p className="text-xs text-muted-foreground">
                This preview checksum helps compare document values. BIR signing and acceptance remain pending the verified API integration.
              </p>
              <Button onClick={handleSign} disabled={signing || isSigned} className="gap-1.5 w-full">
                {signing ? <><RefreshCw className="h-3.5 w-3.5 animate-spin" /> Computing…</> : isSigned ? <><CheckCircle2 className="h-3.5 w-3.5" /> Checksum ready</> : <><Shield className="h-3.5 w-3.5" /> Compute Checksum</>}
              </Button>
              {isSigned && (
                <div className="space-y-2">
                  <div className="space-y-1">
                    <Label className="text-[10px] text-muted-foreground">Preview Checksum (SHA-256)</Label>
                    <p className="font-mono text-[10px] bg-muted p-2 rounded break-all text-foreground">{signature}</p>
                  </div>
                  <div className="space-y-1">
                    <Label className="text-[10px] text-muted-foreground">Validation Hash (short)</Label>
                    <p className="font-mono text-xs bg-muted px-2 py-1 rounded text-foreground">{validationHash}</p>
                  </div>
                  <div className="space-y-1">
                    <Label className="text-[10px] text-muted-foreground">QR Payload</Label>
                    <p className="font-mono text-[10px] bg-muted p-2 rounded break-all text-foreground">{qrPayload}</p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="px-5 pt-5 pb-3">
              <CardTitle className="text-sm flex items-center gap-2">
                <CheckCircle2 className="h-4 w-4 text-success" />
                Local Form Checks
              </CardTitle>
            </CardHeader>
            <CardContent className="px-5 pb-5 space-y-4">
              <p className="text-xs text-muted-foreground">
                Validates completeness: required fields, line items, tax computation, and document-type–specific rules.
              </p>
              <Button onClick={handleValidate} disabled={isValidated} variant={isValidated ? "outline" : "default"} className="gap-1.5 w-full">
                {isValidated ? <><CheckCircle2 className="h-3.5 w-3.5 text-success" /> Local checks passed</> : <><Zap className="h-3.5 w-3.5" /> Run Validation</>}
              </Button>
              {isValidated && (
                <div className="p-3 bg-success/10 border border-success/30 rounded-lg">
                  <p className="text-xs font-medium text-success flex items-center gap-1.5">
                    <CheckCircle2 className="h-3.5 w-3.5" />
                    Local form checks passed
                  </p>
                  <ul className="mt-2 space-y-1 text-[10px] text-muted-foreground">
                    <li>✓ Required fields complete</li>
                    <li>✓ Line items valid</li>
                    <li>Server recomputes and verifies financial amounts on save</li>
                    <li>✓ Document type rules satisfied</li>
                    <li>Number uniqueness and authorized series are checked by the server</li>
                  </ul>
                </div>
              )}
              <Separator />
              <div className="space-y-2 text-xs">
                <p className="font-medium text-foreground">Invoice Version</p>
                <p className="text-muted-foreground">Unsaved draft — no issued version exists</p>
                <p className="text-muted-foreground">Timestamp: {new Date().toLocaleString("en-PH")}</p>
              </div>
            </CardContent>
          </Card>

          <div className="lg:col-span-2 flex justify-between">
            <Button variant="outline" size="sm" onClick={() => setStep(3)}>Back</Button>
            <Button size="sm" onClick={() => setStep(5)}>Next: Preview</Button>
          </div>
        </div>
      )}

      {/* Step 5 — Preview */}
      {step === 5 && (
        <Card>
          <CardHeader className="px-5 pt-5 pb-3 flex-row items-center justify-between">
            <CardTitle className="text-sm">Invoice Preview</CardTitle>
            <span className="text-sm text-muted-foreground">Unsaved draft preview</span>
          </CardHeader>
          <CardContent className="px-5 pb-5">
            <BirDocumentPreview salesBenefit={salesBenefit} statutoryDiscount={statutoryDiscount} vatAdjustment={vatAdjustment}
              documentType={docType}
              documentNumber={invoiceNumber}
              issueDate={issueDate}
              seller={{
                name: merchantName,
                tin: merchantTin,
                address: merchantAddress,
                vatRegistered: merchantVatRegistered,
              }}
              buyer={{
                name: buyerName,
                tin: buyerTin,
                address: buyerAddress,
                email: buyerEmail,
              }}
              items={lines.map((line) => ({
                description: line.description || "Line item",
                quantity: line.quantity,
                unit: line.unit,
                unitPrice: line.unit_price,
                amount: line.line_total,
                vatAmount: line.vat_amount,
              }))}
              totals={{
                gross: totals.subtotal,
                vatableSales: totals.taxable,
                vatExemptSales: totals.vat_exempt,
                zeroRatedSales: totals.zero_rated,
                vatAmount: totals.vat,
                discount: totals.discount,
                withholdingTax: totals.withholding,
                otherCharges: totals.other_charges,
                totalDue: totals.total - totals.withholding,
              }}
              referenceNumber={refNumber || poNumber || soNumber}
              status="draft"
              qrValue={validationHash || qrPayload || invoiceNumber}
            />

            <div className="flex justify-between mt-4">
              <Button variant="outline" size="sm" onClick={() => setStep(4)}>Back</Button>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={() => handleSave(false)} disabled={saving}>
                  <Save className="h-3.5 w-3.5 mr-1.5" /> Save Draft
                </Button>
                <Button size="sm" onClick={() => handleSave(true)} disabled={saving}>
                  <Send className="h-3.5 w-3.5 mr-1.5" /> {saving ? "Saving…" : "Finalize & Send"}
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
      <SavePendingInvoice payload={pendingWalletPayload} onClose={()=>setPendingWalletPayload(null)}/>
    </div>
  );
}
