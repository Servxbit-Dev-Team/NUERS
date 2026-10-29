// Invoice Engine — shared types, constants, and utilities

export type DocumentType =
  | "vat_invoice"
  | "non_vat_invoice"
  | "vat_exempt_invoice"
  | "zero_rated_invoice"
  | "mixed_sales_invoice"
  | "payment_receipt"
  | "sales_invoice"
  | "official_receipt"
  | "credit_memo"
  | "debit_memo"
  | "purchase_invoice"
  | "delivery_receipt"
  | "service_invoice";

export type InvoiceStatus =
  | "issued"
  | "draft"
  | "pending"
  | "validated"
  | "sent"
  | "paid"
  | "partially_paid"
  | "overdue"
  | "cancelled"
  | "voided"
  | "correction_pending";

export type TaxType = "vatable" | "zero_rated" | "vat_exempt" | "non_vat";
export type ExportFormat = "json" | "xml" | "pdf" | "csv";
export type RecurringFrequency = "daily" | "weekly" | "biweekly" | "monthly" | "quarterly" | "annually";

export interface LineItem {
  benefit_eligible?: boolean;
  benefit_details?: {eligible:boolean;original_tax_type:TaxType;rate:number;discount_centavos:number;vat_adjustment_centavos:number;base_centavos:number}|null;
  id: string;
  line_number: number;
  item_code: string;
  description: string;
  unit: string;
  quantity: number;
  unit_price: number;
  discount_pct: number;
  discount_amount: number;
  taxable_amount: number;
  vat_rate: number;
  vat_amount: number;
  line_total: number;
  tax_type: TaxType;
}

export interface Invoice {
  document_view?: import("@/components/invoices/official-receipt").CenterDocument;
  id: string;
  invoice_number: string;
  document_type: DocumentType;
  bir_template_code?: BirTemplateCode | string;
  status: InvoiceStatus;
  version_number: number;
  parent_invoice_id?: string;
  merchant_name: string;
  merchant_tin: string;
  permit_to_issue_number?: string | null;
  esp_accreditation_number?: string | null;
  merchant_address: string;
  buyer_name: string;
  buyer_tin?: string;
  buyer_address?: string;
  buyer_email?: string;
  issue_date: string;
  due_date?: string;
  delivery_date?: string;
  period_start?: string;
  period_end?: string;
  subtotal_amount: number;
  discount_amount: number;
  taxable_amount: number;
  vat_amount: number;
  withholding_tax: number;
  other_charges: number;
  total_amount: number;
  amount_paid: number;
  amount_due: number;
  currency: string;
  vat_registered: boolean;
  vat_rate: number;
  zero_rated_amount: number;
  vat_exempt_amount: number;
  reference_number?: string;
  purchase_order?: string;
  sales_order?: string;
  original_invoice_id?: string;
  correction_reason?: string;
  qr_payload?: string;
  digital_signature?: string;
  signed_at?: string;
  validated_at?: string;
  validation_hash?: string;
  cancellation_reason?: string;
  cancellation_note?: string;
  cancelled_at?: string;
  formats_generated: string[];
  notes?: string;
  terms_and_conditions?: string;
  footer_note?: string;
  line_items?: LineItem[];
  created_at: string;
  updated_at: string;
}

// ─── Document type meta ────────────────────────────────────────────────────
export const DOC_TYPE_META: Record<DocumentType, { label: string; short: string; prefix: string; color: string }> = {
  vat_invoice:        { label: "VAT Invoice",              short: "B1",  prefix: "VI",  color: "text-primary"     },
  non_vat_invoice:    { label: "Non-VAT Invoice",          short: "B2",  prefix: "NVI", color: "text-muted-foreground" },
  vat_exempt_invoice: { label: "VAT-Exempt Sales Invoice", short: "B3",  prefix: "VEI", color: "text-success"     },
  zero_rated_invoice: { label: "Zero-Rated Sales Invoice", short: "B4",  prefix: "ZRI", color: "text-chart-3"     },
  mixed_sales_invoice:{ label: "Mixed Sales Invoice",      short: "B5",  prefix: "MSI", color: "text-warning"     },
  payment_receipt:    { label: "Payment Receipt",          short: "B6",  prefix: "PR",  color: "text-chart-2"     },
  sales_invoice:      { label: "Sales Invoice",               short: "SI",  prefix: "SI",  color: "text-primary"     },
  official_receipt:   { label: "Official Receipt",            short: "OR",  prefix: "OR",  color: "text-success"     },
  credit_memo:        { label: "Credit Memo",                 short: "CM",  prefix: "CM",  color: "text-warning"     },
  debit_memo:         { label: "Debit Memo",                  short: "DM",  prefix: "DM",  color: "text-destructive" },
  purchase_invoice:   { label: "Purchase Invoice",            short: "PI",  prefix: "PI",  color: "text-chart-2"     },
  delivery_receipt:   { label: "Delivery Receipt",            short: "DR",  prefix: "DR",  color: "text-chart-3"     },
  service_invoice:    { label: "Service Invoice",             short: "SVI", prefix: "SVI", color: "text-chart-4"     },
};

export type BirTemplateCode = "B1" | "B2" | "B3" | "B4" | "B5" | "B6";

export type BirDocumentTemplate = {
  code: BirTemplateCode;
  documentType: DocumentType;
  heading: string;
  bodyTitle: string;
  label: string;
  tinRegistrationLabel: string;
  warning?: string;
  supplementary?: boolean;
  showVatBreakdown: boolean;
  showInvoiceReference?: boolean;
};

export const BIR_DOCUMENT_TYPES: DocumentType[] = [
  "vat_invoice",
  "non_vat_invoice",
  "vat_exempt_invoice",
  "zero_rated_invoice",
  "mixed_sales_invoice",
  "payment_receipt",
];

export const BIR_TEMPLATES: Record<DocumentType, BirDocumentTemplate> = {
  vat_invoice: {
    code: "B1",
    documentType: "vat_invoice",
    heading: "VAT INVOICE",
    bodyTitle: "INVOICE",
    label: "VAT Invoice",
    tinRegistrationLabel: "VAT REG TIN",
    showVatBreakdown: true,
  },
  non_vat_invoice: {
    code: "B2",
    documentType: "non_vat_invoice",
    heading: "NON-VAT INVOICE",
    bodyTitle: "INVOICE",
    label: "Non-VAT Invoice",
    tinRegistrationLabel: "NON-VAT REG TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    showVatBreakdown: false,
  },
  vat_exempt_invoice: {
    code: "B3",
    documentType: "vat_exempt_invoice",
    heading: "VAT INVOICE - VAT EXEMPT SALE",
    bodyTitle: "INVOICE VAT-EXEMPT SALE",
    label: "VAT-Exempt Sales Invoice",
    tinRegistrationLabel: "VAT REG TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    showVatBreakdown: true,
  },
  zero_rated_invoice: {
    code: "B4",
    documentType: "zero_rated_invoice",
    heading: "VAT INVOICE - ZERO-RATED SALE",
    bodyTitle: "INVOICE ZERO-RATED SALE",
    label: "Zero-Rated Sales Invoice",
    tinRegistrationLabel: "VAT REG TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    showVatBreakdown: true,
  },
  mixed_sales_invoice: {
    code: "B5",
    documentType: "mixed_sales_invoice",
    heading: "NON-VAT INVOICE MIXED SALES TRANSACTION",
    bodyTitle: "INVOICE - MIXED SALES TRANSACTION",
    label: "Mixed Sales Invoice",
    tinRegistrationLabel: "NON-VAT REG TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    showVatBreakdown: false,
  },
  payment_receipt: {
    code: "B6",
    documentType: "payment_receipt",
    heading: "PAYMENT RECEIPT",
    bodyTitle: "PAYMENT RECEIPT",
    label: "Payment Receipt",
    tinRegistrationLabel: "TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    supplementary: true,
    showInvoiceReference: true,
    showVatBreakdown: false,
  },
  sales_invoice: {
    code: "B1",
    documentType: "vat_invoice",
    heading: "VAT INVOICE",
    bodyTitle: "INVOICE",
    label: "VAT Invoice",
    tinRegistrationLabel: "VAT REG TIN",
    showVatBreakdown: true,
  },
  official_receipt: {
    code: "B6",
    documentType: "payment_receipt",
    heading: "PAYMENT RECEIPT",
    bodyTitle: "PAYMENT RECEIPT",
    label: "Payment Receipt",
    tinRegistrationLabel: "TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    supplementary: true,
    showInvoiceReference: true,
    showVatBreakdown: false,
  },
  credit_memo: {
    code: "B1",
    documentType: "vat_invoice",
    heading: "VAT INVOICE",
    bodyTitle: "CREDIT MEMO",
    label: "Credit Memo",
    tinRegistrationLabel: "VAT REG TIN",
    showVatBreakdown: true,
  },
  debit_memo: {
    code: "B1",
    documentType: "vat_invoice",
    heading: "VAT INVOICE",
    bodyTitle: "DEBIT MEMO",
    label: "Debit Memo",
    tinRegistrationLabel: "VAT REG TIN",
    showVatBreakdown: true,
  },
  purchase_invoice: {
    code: "B1",
    documentType: "vat_invoice",
    heading: "VAT INVOICE",
    bodyTitle: "PURCHASE INVOICE",
    label: "Purchase Invoice",
    tinRegistrationLabel: "VAT REG TIN",
    showVatBreakdown: true,
  },
  delivery_receipt: {
    code: "B6",
    documentType: "payment_receipt",
    heading: "PAYMENT RECEIPT",
    bodyTitle: "DELIVERY RECEIPT",
    label: "Delivery Receipt",
    tinRegistrationLabel: "TIN",
    warning: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX",
    supplementary: true,
    showInvoiceReference: true,
    showVatBreakdown: false,
  },
  service_invoice: {
    code: "B1",
    documentType: "vat_invoice",
    heading: "VAT INVOICE",
    bodyTitle: "SERVICE INVOICE",
    label: "Service Invoice",
    tinRegistrationLabel: "VAT REG TIN",
    showVatBreakdown: true,
  },
};

export function birTemplateForDocument(type?: string | null, taxType?: string | null): BirDocumentTemplate {
  const normalized = normalizeDocumentType(type, taxType);
  return BIR_TEMPLATES[normalized] ?? BIR_TEMPLATES.vat_invoice;
}

export function normalizeDocumentType(type?: string | null, taxType?: string | null): DocumentType {
  const raw = String(type ?? "").trim() as DocumentType;
  if (raw && raw in DOC_TYPE_META) {
    if (raw === "sales_invoice") return inferBirDocumentType({ tax_type: taxType ?? "vatable", vat_registered: true });
    if (raw === "official_receipt") return "payment_receipt";
    return raw;
  }

  return inferBirDocumentType({ tax_type: taxType ?? "vatable", vat_registered: true });
}

export function inferBirDocumentType(input: {
  tax_type?: string | null;
  transaction_type?: string | null;
  vat_registered?: boolean | null;
  vatable_sales?: number | null;
  vat_exempt_sales?: number | null;
  zero_rated_sales?: number | null;
  item_tax_types?: Array<string | null | undefined>;
}): DocumentType {
  const transactionType = String(input.transaction_type ?? "").toLowerCase();
  const taxType = String(input.tax_type ?? "").toLowerCase();
  const itemTaxTypes = (input.item_tax_types ?? []).map((type) => String(type ?? "").toLowerCase()).filter(Boolean);
  const uniqueTaxes = new Set(itemTaxTypes);

  if (["payment", "receipt", "collection", "settlement"].some((word) => transactionType.includes(word))) {
    return "payment_receipt";
  }

  if (uniqueTaxes.size > 1) {
    return "mixed_sales_invoice";
  }

  if (taxType === "zero_rated" || (input.zero_rated_sales ?? 0) > 0) {
    return "zero_rated_invoice";
  }

  if (taxType === "vat_exempt" || (input.vat_exempt_sales ?? 0) > 0) {
    return "vat_exempt_invoice";
  }

  if (taxType === "non_vat" || input.vat_registered === false) {
    return "non_vat_invoice";
  }

  if ((input.vatable_sales ?? 0) > 0 || taxType === "vatable" || taxType === "") {
    return "vat_invoice";
  }

  return "vat_invoice";
}

export const STATUS_META: Record<InvoiceStatus, { label: string; variant: "default" | "secondary" | "destructive" | "outline" }> = {
  draft:               { label: "Draft",              variant: "secondary"    },
  pending:             { label: "Pending",            variant: "outline"      },
  validated:           { label: "Validated",          variant: "default"      },
  issued:              { label: "Issued", variant: "default" },
  sent:                { label: "Sent",               variant: "default"      },
  paid:                { label: "Paid",               variant: "default"      },
  partially_paid:      { label: "Partially Paid",     variant: "outline"      },
  overdue:             { label: "Overdue",            variant: "destructive"  },
  cancelled:           { label: "Cancelled",          variant: "destructive"  },
  voided:              { label: "Voided",             variant: "destructive"  },
  correction_pending:  { label: "Correction Pending", variant: "outline"      },
};

// ─── Number generation ─────────────────────────────────────────────────────
/** Local draft reference only. The server allocates the registered serial at issuance. */
export function generateInvoiceNumber(_type: DocumentType): string {
  return `DRAFT-${crypto.randomUUID()}`;
}

// ─── Tax computation ───────────────────────────────────────────────────────
export function computeLineItem(
  unit_price: number,
  quantity: number,
  discount_pct: number,
  vat_rate: number,
  tax_type: TaxType,
  pricesIncludeVat = true
): Pick<LineItem, "discount_amount" | "taxable_amount" | "vat_amount" | "line_total"> {
  const gross = Math.round(unit_price * quantity);
  const discount_amount = Math.round(gross * discount_pct / 100);
  const net = gross - discount_amount;
  const taxable_amount = tax_type === "vatable" ? (pricesIncludeVat ? Math.round(net / (1 + vat_rate / 100)) : net) : 0;
  const vat_amount = tax_type === "vatable" ? (pricesIncludeVat ? net - taxable_amount : Math.round(taxable_amount * vat_rate / 100)) : 0;
  return { discount_amount, taxable_amount, vat_amount, line_total: net + (pricesIncludeVat ? 0 : vat_amount) };
}

export function computeInvoiceTotals(items: LineItem[], withholding_pct = 0, other_charges = 0) {
  const subtotal = items.reduce((s, i) => s + Math.round(i.unit_price * i.quantity), 0);
  const discount = items.reduce((s, i) => s + i.discount_amount, 0);
  const taxable  = items.reduce((s, i) => s + i.taxable_amount, 0);
  const vat      = items.reduce((s, i) => s + i.vat_amount, 0);
  const zero_rated = items.filter(i => i.tax_type === "zero_rated").reduce((s, i) => s + i.line_total, 0);
  const vat_exempt = items.filter(i => i.tax_type === "vat_exempt" || i.tax_type === "non_vat").reduce((s, i) => s + i.line_total, 0);
  const withholding = Math.round((taxable + zero_rated + vat_exempt) * withholding_pct / 100);
  const total = items.reduce((sum, line) => sum + line.line_total, 0) + other_charges;
  return { subtotal, discount, taxable, vat, zero_rated, vat_exempt, withholding, other_charges, total };
}

// ─── Currency formatting ───────────────────────────────────────────────────
export function formatAmount(centavos: number, currency = "PHP"): string {
  const symbol = currency === "PHP" ? "₱" : currency;
  return `${symbol}${(centavos / 100).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function toCentavos(pesos: number): number {
  return Math.round(pesos * 100);
}

export function fromCentavos(centavos: number): number {
  return centavos / 100;
}

// Preview checksum only; official signing belongs to the verified BIR adapter.
export async function computeInvoiceChecksum(invoice: Partial<Invoice>): Promise<string> {
  const payload = JSON.stringify({
    invoice_number: invoice.invoice_number,
    total_amount: invoice.total_amount,
    issue_date: invoice.issue_date,
    merchant_tin: invoice.merchant_tin,
    buyer_tin: invoice.buyer_tin,
  });
  if (typeof window !== "undefined" && window.crypto?.subtle) {
    const enc = new TextEncoder();
    const buf = await window.crypto.subtle.digest("SHA-256", enc.encode(payload));
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, "0")).join("");
  }
  throw new Error("Secure checksum generation is unavailable in this browser.");
}

// ─── QR payload ───────────────────────────────────────────────────────────
export function buildQRPayload(invoice: Partial<Invoice>): string {
  return JSON.stringify({
    n: invoice.invoice_number,
    t: invoice.document_type,
    d: invoice.issue_date,
    a: invoice.total_amount,
    m: invoice.merchant_tin,
    v: invoice.validation_hash?.slice(0, 16) || "",
  });
}

// ─── Validation ───────────────────────────────────────────────────────────
export interface ValidationResult {
  valid: boolean;
  errors: string[];
  warnings: string[];
}

export function validateInvoice(invoice: Partial<Invoice>, items: LineItem[]): ValidationResult {
  const errors: string[] = [];
  const warnings: string[] = [];

  if (!invoice.buyer_name?.trim()) errors.push("Buyer name is required.");
  if (!invoice.merchant_tin?.trim()) errors.push("Business account TIN is required.");
  if (items.length === 0) errors.push("At least one line item is required.");
  if ((invoice.total_amount ?? 0) <= 0) errors.push("Total amount must be greater than zero.");
  if (!invoice.issue_date) errors.push("Issue date is required.");

  if (invoice.document_type === "credit_memo" && !invoice.original_invoice_id && !invoice.reference_number)
    warnings.push("Credit memo should reference an original invoice.");
  if (invoice.document_type === "debit_memo" && !invoice.original_invoice_id && !invoice.reference_number)
    warnings.push("Debit memo should reference an original invoice.");

  return { valid: errors.length === 0, errors, warnings };
}

// ─── Export generators (client-side) ─────────────────────────────────────

export function exportToJSON(invoice: Invoice): void {
  const blob = new Blob([JSON.stringify(invoice, null, 2)], { type: "application/json" });
  triggerDownload(blob, `${invoice.invoice_number}.json`);
}

export function exportToCSV(invoice: Invoice): void {
  const headers = ["Line#", "Code", "Description", "Unit", "Qty", "Unit Price", "Discount%", "VAT Rate", "Tax Type", "Line Total"];
  const rows = (invoice.line_items ?? []).map(i => [
    i.line_number, i.item_code, i.description, i.unit,
    i.quantity, fromCentavos(i.unit_price), i.discount_pct,
    i.vat_rate, i.tax_type, fromCentavos(i.line_total)
  ]);
  const csv = [headers, ...rows].map(r => r.map(v => `"${v}"`).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  triggerDownload(blob, `${invoice.invoice_number}.csv`);
}

export function exportToXML(invoice: Invoice): void {
  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<Invoice>
  <InvoiceNumber>${invoice.invoice_number}</InvoiceNumber>
  <DocumentType>${invoice.document_type}</DocumentType>
  <IssueDate>${invoice.issue_date}</IssueDate>
  <Status>${invoice.status}</Status>
  <Merchant>
    <Name>${escXML(invoice.merchant_name)}</Name>
    <TIN>${invoice.merchant_tin}</TIN>
    <Address>${escXML(invoice.merchant_address)}</Address>
  </Merchant>
  <Buyer>
    <Name>${escXML(invoice.buyer_name)}</Name>
    <TIN>${invoice.buyer_tin ?? ""}</TIN>
    <Address>${escXML(invoice.buyer_address ?? "")}</Address>
  </Buyer>
  <Amounts currency="${invoice.currency}">
    <Subtotal>${fromCentavos(invoice.subtotal_amount)}</Subtotal>
    <Discount>${fromCentavos(invoice.discount_amount)}</Discount>
    <Taxable>${fromCentavos(invoice.taxable_amount)}</Taxable>
    <VAT>${fromCentavos(invoice.vat_amount)}</VAT>
    <Total>${fromCentavos(invoice.total_amount)}</Total>
  </Amounts>
  <LineItems>
${(invoice.line_items ?? []).map(i => `    <Item>
      <Line>${i.line_number}</Line>
      <Code>${escXML(i.item_code)}</Code>
      <Description>${escXML(i.description)}</Description>
      <Unit>${i.unit}</Unit>
      <Qty>${i.quantity}</Qty>
      <UnitPrice>${fromCentavos(i.unit_price)}</UnitPrice>
      <VATRate>${i.vat_rate}</VATRate>
      <TaxType>${i.tax_type}</TaxType>
      <Total>${fromCentavos(i.line_total)}</Total>
    </Item>`).join("\n")}
  </LineItems>
  <DigitalSignature>${invoice.digital_signature ?? ""}</DigitalSignature>
  <QRPayload>${invoice.qr_payload ?? ""}</QRPayload>
</Invoice>`;
  const blob = new Blob([xml], { type: "application/xml" });
  triggerDownload(blob, `${invoice.invoice_number}.xml`);
}

function escXML(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function triggerDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

// ─── Mock data for UI demo ─────────────────────────────────────────────────
export const MOCK_INVOICES: Invoice[] = [
  {
    id: "inv-001", invoice_number: "SI-2026-001001", document_type: "sales_invoice",
    status: "validated", version_number: 1,
    merchant_name: "ABC Trading Co.", merchant_tin: "123-456-789-000", merchant_address: "123 Rizal Ave, Manila",
    buyer_name: "SM Supermalls Inc.", buyer_tin: "987-654-321-000", buyer_address: "SM Mall of Asia, Pasay",
    buyer_email: "payables@sm.com.ph",
    issue_date: "2026-05-27", due_date: "2026-06-26",
    subtotal_amount: 10714286, discount_amount: 0, taxable_amount: 10714286,
    vat_amount: 1285714, withholding_tax: 0, other_charges: 0,
    total_amount: 12000000, amount_paid: 0, amount_due: 12000000,
    currency: "PHP", vat_registered: true, vat_rate: 12,
    zero_rated_amount: 0, vat_exempt_amount: 0,
    formats_generated: ["pdf", "json"], validation_hash: "a1b2c3d4e5f6",
    qr_payload: '{"n":"SI-2026-001001","t":"sales_invoice"}',
    digital_signature: "3b4d6e8f2a1c",
    signed_at: "2026-05-27T08:00:00Z", validated_at: "2026-05-27T08:01:00Z",
    notes: "30 days credit terms",
    line_items: [
      { id: "li-1", line_number: 1, item_code: "ITEM-001", description: "Office Supplies Bundle", unit: "box", quantity: 50, unit_price: 24000, discount_pct: 0, discount_amount: 0, taxable_amount: 1200000, vat_rate: 12, vat_amount: 144000, line_total: 1200000, tax_type: "vatable" },
      { id: "li-2", line_number: 2, item_code: "ITEM-002", description: "Computer Equipment", unit: "unit", quantity: 10, unit_price: 875000, discount_pct: 0, discount_amount: 0, taxable_amount: 8750000, vat_rate: 12, vat_amount: 1050000, line_total: 8750000, tax_type: "vatable" },
    ],
    created_at: "2026-05-27T08:00:00Z", updated_at: "2026-05-27T08:01:00Z",
  },
  {
    id: "inv-002", invoice_number: "OR-2026-001002", document_type: "official_receipt",
    status: "paid", version_number: 1,
    merchant_name: "ABC Trading Co.", merchant_tin: "123-456-789-000", merchant_address: "123 Rizal Ave, Manila",
    buyer_name: "Robinsons Retail Holdings", buyer_tin: "111-222-333-000", buyer_address: "Robinsons Galleria, QC",
    buyer_email: "ap@robinsons.com.ph",
    issue_date: "2026-05-26",
    subtotal_amount: 5600000, discount_amount: 0, taxable_amount: 5000000,
    vat_amount: 600000, withholding_tax: 0, other_charges: 0,
    total_amount: 5600000, amount_paid: 5600000, amount_due: 0,
    currency: "PHP", vat_registered: true, vat_rate: 12,
    zero_rated_amount: 0, vat_exempt_amount: 0,
    formats_generated: ["pdf", "json", "xml"],
    validation_hash: "b2c3d4e5f6a7",
    qr_payload: '{"n":"OR-2026-001002","t":"official_receipt"}',
    digital_signature: "5c6d7e8f2a3b",
    signed_at: "2026-05-26T10:00:00Z", validated_at: "2026-05-26T10:01:00Z",
    line_items: [],
    created_at: "2026-05-26T10:00:00Z", updated_at: "2026-05-26T10:01:00Z",
  },
  {
    id: "inv-003", invoice_number: "CM-2026-001003", document_type: "credit_memo",
    status: "validated", version_number: 1,
    merchant_name: "ABC Trading Co.", merchant_tin: "123-456-789-000", merchant_address: "123 Rizal Ave, Manila",
    buyer_name: "XYZ Enterprises", buyer_tin: "234-567-890-000", buyer_address: "Makati CBD",
    buyer_email: "finance@xyz.com.ph",
    issue_date: "2026-05-25", original_invoice_id: "inv-001",
    reference_number: "SI-2026-000899",
    correction_reason: "Returned goods — defective items",
    subtotal_amount: -1200000, discount_amount: 0, taxable_amount: -1071429,
    vat_amount: -128571, withholding_tax: 0, other_charges: 0,
    total_amount: -1200000, amount_paid: 0, amount_due: -1200000,
    currency: "PHP", vat_registered: true, vat_rate: 12,
    zero_rated_amount: 0, vat_exempt_amount: 0,
    formats_generated: ["pdf"],
    validation_hash: "c3d4e5f6a7b8",
    digital_signature: "7e8f9a0b1c2d",
    signed_at: "2026-05-25T14:00:00Z", validated_at: "2026-05-25T14:01:00Z",
    line_items: [],
    created_at: "2026-05-25T14:00:00Z", updated_at: "2026-05-25T14:01:00Z",
  },
  {
    id: "inv-004", invoice_number: "SVI-2026-001004", document_type: "service_invoice",
    status: "sent", version_number: 1,
    merchant_name: "ABC Trading Co.", merchant_tin: "123-456-789-000", merchant_address: "123 Rizal Ave, Manila",
    buyer_name: "Tech Solutions PH", buyer_tin: "345-678-901-000", buyer_address: "BGC, Taguig",
    buyer_email: "billing@techsolutions.ph",
    issue_date: "2026-05-28", due_date: "2026-06-28",
    period_start: "2026-05-01", period_end: "2026-05-31",
    subtotal_amount: 3360000, discount_amount: 0, taxable_amount: 3000000,
    vat_amount: 360000, withholding_tax: 100000, other_charges: 0,
    total_amount: 3360000, amount_paid: 0, amount_due: 3260000,
    currency: "PHP", vat_registered: true, vat_rate: 12,
    zero_rated_amount: 0, vat_exempt_amount: 0,
    formats_generated: ["pdf", "json"],
    validation_hash: "d4e5f6a7b8c9",
    digital_signature: "9a0b1c2d3e4f",
    signed_at: "2026-05-28T09:00:00Z", validated_at: "2026-05-28T09:01:00Z",
    notes: "IT Managed Services — May 2026",
    line_items: [],
    created_at: "2026-05-28T09:00:00Z", updated_at: "2026-05-28T09:01:00Z",
  },
  {
    id: "inv-005", invoice_number: "DR-2026-001005", document_type: "delivery_receipt",
    status: "draft", version_number: 1,
    merchant_name: "ABC Trading Co.", merchant_tin: "123-456-789-000", merchant_address: "123 Rizal Ave, Manila",
    buyer_name: "Quick Mart Chain", buyer_tin: "456-789-012-000", buyer_address: "Multiple Locations, NCR",
    buyer_email: "receiving@quickmart.ph",
    issue_date: "2026-05-29", delivery_date: "2026-05-30",
    subtotal_amount: 2240000, discount_amount: 112000, taxable_amount: 1900000,
    vat_amount: 228000, withholding_tax: 0, other_charges: 50000,
    total_amount: 2178000, amount_paid: 0, amount_due: 2178000,
    currency: "PHP", vat_registered: true, vat_rate: 12,
    zero_rated_amount: 0, vat_exempt_amount: 0,
    formats_generated: [],
    line_items: [],
    created_at: "2026-05-29T07:00:00Z", updated_at: "2026-05-29T07:00:00Z",
  },
];
