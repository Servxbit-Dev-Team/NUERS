import { CheckSquare, QrCode, Square } from "lucide-react";
import {
  birTemplateForDocument,
  formatAmount,
  type DocumentType,
} from "@/lib/invoice-utils";
import { cn } from "@/lib/utils";

export type BirDocumentParty = {
  name: string;
  tin?: string | null;
  address?: string | null;
  email?: string | null;
  vatRegistration?: string | null;
  permitToIssueNumber?: string | null;
  espAccreditationNumber?: string | null;
};

export type BirDocumentItem = {
  description: string;
  quantity?: number;
  unit?: string;
  unitPrice?: number;
  amount: number;
  vatAmount?: number;
  taxType?: string | null;
};

export type BirDocumentTotals = {
  gross: number;
  vatableSales?: number;
  vatExemptSales?: number;
  zeroRatedSales?: number;
  vatAmount?: number;
  discount?: number;
  withholding?: number;
  withholdingTax?: number;
  totalDue: number;
};

type BirDocumentPreviewProps = {
  documentType?: DocumentType | string | null;
  taxType?: string | null;
  documentNumber: string;
  seriesNumber?: string | null;
  issueDate?: string | null;
  seller: BirDocumentParty;
  buyer: BirDocumentParty;
  items: BirDocumentItem[];
  totals: BirDocumentTotals;
  paymentMethod?: string | null;
  referenceNumber?: string | null;
  status?: string | null;
  qrValue?: string | null;
  className?: string;
};

function valueOrDash(value?: string | null) {
  return value && value.trim() ? value : "-";
}

function netOfVat(totals: BirDocumentTotals) {
  return Math.max(0, totals.gross - (totals.vatAmount ?? 0));
}

function paymentLabel(value?: string | null) {
  return (value ?? "cash").replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function BirDocumentPreview({
  documentType,
  taxType,
  documentNumber,
  seriesNumber,
  issueDate,
  seller,
  buyer,
  items,
  totals,
  paymentMethod,
  referenceNumber,
  status,
  qrValue,
  className,
}: BirDocumentPreviewProps) {
  const template = birTemplateForDocument(documentType, taxType);
  const isPayment = template.documentType === "payment_receipt";
  const lines = items.length > 0 ? items : [{
    description: isPayment ? "Payment received" : "Sales transaction",
    quantity: 1,
    unit: "txn",
    unitPrice: totals.totalDue,
    amount: totals.totalDue,
  }];
  const lowerPayment = paymentLabel(paymentMethod).toLowerCase();
  const cashChecked = !lowerPayment.includes("card");
  const chargeChecked = lowerPayment.includes("card") || lowerPayment.includes("online") || lowerPayment.includes("bank");
  const withholding = totals.withholdingTax ?? totals.withholding ?? 0;

  return (
    <div className={cn("overflow-hidden rounded-xl border-2 border-slate-900 bg-white text-slate-950 shadow-sm", className)}>
      <div className="grid gap-4 border-b-2 border-slate-900 bg-slate-50 p-5 md:grid-cols-[1fr_auto]">
        <div className="space-y-1">
          <p className="text-xl font-black tracking-wide">{seller.name || "Registered Business Name"}</p>
          <p className="text-xs font-semibold uppercase tracking-wide">
            {template.tinRegistrationLabel}: <span className="font-mono">{valueOrDash(seller.tin)}</span>
          </p>
          <p className="text-xs">{valueOrDash(seller.address)}</p>
          {seller.email && <p className="text-xs">{seller.email}</p>}
        </div>
        <div className="text-left md:text-right">
          <p className="text-[10px] font-bold uppercase tracking-[0.25em] text-slate-500">RMC 77-2024 Annex A</p>
          <p className="mt-1 text-2xl font-black tracking-tight">{template.heading}</p>
          <p className="text-xs font-semibold text-slate-600">{template.label}</p>
          {status && <p className="mt-1 inline-flex rounded-full border border-slate-300 px-2 py-0.5 text-[10px] font-bold uppercase">{status}</p>}
        </div>
      </div>

      <div className="p-5">
        <div className="grid gap-4 md:grid-cols-[1fr_auto]">
          <div>
            <p className="text-2xl font-black uppercase tracking-wide">{template.bodyTitle}</p>
            <div className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
              <p><span className="font-semibold">Sold To:</span> {valueOrDash(buyer.name)}</p>
              <p><span className="font-semibold">Buyer TIN:</span> <span className="font-mono">{valueOrDash(buyer.tin)}</span></p>
              <p className="sm:col-span-2"><span className="font-semibold">Business Address:</span> {valueOrDash(buyer.address)}</p>
              {buyer.email && <p className="sm:col-span-2"><span className="font-semibold">Email:</span> {buyer.email}</p>}
            </div>
          </div>
          <div className="min-w-[220px] rounded-lg border border-slate-300 p-3 text-xs">
            <p><span className="font-semibold">Serial No.:</span> <span className="font-mono">{documentNumber}</span></p>
            <p><span className="font-semibold">Series No.:</span> <span className="font-mono">{seriesNumber || "-"}</span></p>
            <p><span className="font-semibold">Transaction Date:</span> {issueDate || "-"}</p>
            <div className="mt-2 flex flex-wrap gap-3">
              <span className="inline-flex items-center gap-1">{cashChecked ? <CheckSquare className="h-3.5 w-3.5" /> : <Square className="h-3.5 w-3.5" />} Cash Sales</span>
              <span className="inline-flex items-center gap-1">{chargeChecked ? <CheckSquare className="h-3.5 w-3.5" /> : <Square className="h-3.5 w-3.5" />} Charge Sales</span>
            </div>
          </div>
        </div>

        {template.warning && (
          <div className="mt-4 rounded-lg border-2 border-slate-900 bg-slate-100 px-4 py-2 text-center text-xs font-black uppercase tracking-wide">
            {template.warning}
          </div>
        )}

        <div className="mt-5 overflow-x-auto">
          <table className="w-full min-w-[680px] border-collapse text-xs">
            <thead>
              <tr className="border-y-2 border-slate-900 bg-slate-100">
                <th className="px-3 py-2 text-left">Description</th>
                <th className="px-3 py-2 text-center">Quantity</th>
                <th className="px-3 py-2 text-center">Unit</th>
                <th className="px-3 py-2 text-right">Unit Price / Cost</th>
                <th className="px-3 py-2 text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((item, index) => (
                <tr key={`${item.description}-${index}`} className="border-b border-slate-200">
                  <td className="px-3 py-2 font-medium">{item.description || "-"}</td>
                  <td className="px-3 py-2 text-center tabular-nums">{(item.quantity ?? 1).toLocaleString("en-PH")}</td>
                  <td className="px-3 py-2 text-center">{item.unit || "pc"}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatAmount(item.unitPrice ?? item.amount)}</td>
                  <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatAmount(item.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-5 grid gap-4 lg:grid-cols-[1fr_360px]">
          <div className="rounded-lg border border-dashed border-slate-400 p-4 text-xs">
            {isPayment ? (
              <div className="space-y-2">
                <p><span className="font-semibold">Payment for:</span> {referenceNumber || "Invoice settlement / account payment"}</p>
                <p><span className="font-semibold">Payment Method:</span> {paymentLabel(paymentMethod)}</p>
                <p><span className="font-semibold">Invoice Reference No.:</span> {referenceNumber || "-"}</p>
              </div>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2">
                <p><span className="font-semibold">P.O. / S.O. Reference:</span> {referenceNumber || "-"}</p>
                <p><span className="font-semibold">Business Style:</span> {seller.vatRegistration || template.tinRegistrationLabel}</p>
                <p><span className="font-semibold">SC/PWD/NAAC/MOV/SP:</span> N/A</p>
                <p><span className="font-semibold">Signature:</span> __________________</p>
              </div>
            )}
          </div>

          <div className="rounded-lg border-2 border-slate-900 p-4 text-xs">
            {isPayment ? (
              <div className="flex justify-between text-base font-black">
                <span>Total Paid Amount</span>
                <span>{formatAmount(totals.totalDue)}</span>
              </div>
            ) : template.documentType === "non_vat_invoice" || template.documentType === "mixed_sales_invoice" ? (
              <div className="space-y-2">
                {template.documentType === "mixed_sales_invoice" && (
                  <>
                    <Row label="Exempt Sales" value={totals.vatExemptSales ?? 0} />
                    <Row label="Sales Subject To Percentage Tax" value={Math.max(0, totals.gross - (totals.vatExemptSales ?? 0))} />
                  </>
                )}
                <Row label="Total Sales" value={totals.gross} />
                <Row label="Less: Discount" value={totals.discount ?? 0} negative />
                <Row label="Less: Withholding Tax" value={withholding} negative />
                <TotalRow label="TOTAL AMOUNT DUE" value={totals.totalDue} />
              </div>
            ) : (
              <div className="space-y-2">
                <Row label={template.documentType === "vat_exempt_invoice" ? "Total VAT-Exempt Sales" : template.documentType === "zero_rated_invoice" ? "Total Zero-rated Sales" : "Total Sales (VAT Inclusive)"} value={totals.gross} />
                <Row label="Less: VAT" value={totals.vatAmount ?? 0} negative />
                <Row label="Amount: Net of VAT" value={netOfVat(totals)} />
                <Row label="Less: Discount" value={totals.discount ?? 0} negative />
                <Row label="Add: VAT" value={totals.vatAmount ?? 0} />
                <Row label="Less: Withholding Tax" value={withholding} negative />
                <TotalRow label="TOTAL AMOUNT DUE" value={totals.totalDue} />
              </div>
            )}
          </div>
        </div>

        {template.showVatBreakdown && (
          <div className="mt-4 grid gap-2 rounded-lg border border-slate-300 bg-slate-50 p-3 text-xs sm:grid-cols-4">
            <Row label="VATable Sales" value={totals.vatableSales ?? 0} compact />
            <Row label="VAT Amount" value={totals.vatAmount ?? 0} compact />
            <Row label="Zero-Rated Sales" value={totals.zeroRatedSales ?? 0} compact />
            <Row label="VAT-Exempt Sales" value={totals.vatExemptSales ?? 0} compact />
          </div>
        )}

        <div className="mt-5 grid gap-4 text-[10px] text-slate-600 md:grid-cols-[1fr_auto]">
          <div className="space-y-1">
            <p>PTI Electronic Invoice: {seller.permitToIssueNumber || "Not recorded — issuance authority pending"}</p>
            <p>ESP Accreditation: {seller.espAccreditationNumber || "Not recorded"}</p>
            <p>This electronic document is recorded in the NUERS ledger and subject to BIR EIS transmission readiness controls.</p>
          </div>
          <div className="flex items-center gap-2 rounded-lg border border-slate-300 p-2">
            <QrCode className="h-8 w-8" />
            <div>
              <p className="font-bold uppercase">QR Verification</p>
              <p className="max-w-[220px] truncate font-mono">{qrValue || documentNumber}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function Row({ label, value, negative = false, compact = false }: { label: string; value: number; negative?: boolean; compact?: boolean }) {
  return (
    <div className={cn("flex justify-between gap-3", compact && "block sm:flex")}>
      <span className="text-slate-600">{label}</span>
      <span className={cn("font-semibold tabular-nums", negative && value > 0 && "text-red-700")}>
        {negative && value > 0 ? `(${formatAmount(value)})` : formatAmount(value)}
      </span>
    </div>
  );
}

function TotalRow({ label, value }: { label: string; value: number }) {
  return (
    <div className="mt-2 flex justify-between gap-3 border-t-2 border-slate-900 pt-2 text-base font-black">
      <span>{label}</span>
      <span className="tabular-nums">{formatAmount(value)}</span>
    </div>
  );
}
