import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { FileText } from "lucide-react";
import "./electronic-document.css";

export type CenterRow = { id: string; source: "invoice" | "receipt"; number: string; business: string; tin: string; document_type: string; record_status: string; status: "valid" | "pending" | "cancelled"; category: string; issued_at: string; amount: number };
export type CenterDocument = CenterRow & {
  seller_address: string | null; buyer_name: string; buyer_tin: string | null; buyer_address: string | null; reference: string | null;
  payment_method: string | null; branch: string | null; vat_registered: boolean | string | null;
  items: { description: string | null; quantity: number | null; unit?: string | null; unit_price: number | null; amount: number | null; amount_derived?: boolean; tax_type?: string | null }[];
  subtotal: number | null; discount: number | null; vatable: number | null; vat: number | null; withholding: number | null; other_charges: number | null;
  permit_number: string | null; accreditation_number: string | null; verification_url: string | null; authority_status: string;
  currency?: string; seller_business_style?: string | null; buyer_business_style?: string | null; vat_exempt?: number | null; zero_rated?: number | null; net_payable?: number | null; amount_paid?: number | null; balance_due?: number | null; date_precision?: string; bir_status?: string; financial_validation_passed?: boolean | null; settlement_validation_passed?: boolean | null; source_notice?: string; original_invoice_id?: string | null;
  history?: { id: string; event: string; occurred_at: string; reason: string | null; actor: string | null }[];
};
export const money = (n: number | string | null | undefined) => n == null ? "Not recorded" : Number(n).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export function documentLabel(type: string) { return ({ vat_invoice: "VAT Invoice", non_vat_invoice: "Non-VAT Invoice", vat_exempt_invoice: "VAT-Exempt Sales Invoice", zero_rated_invoice: "Zero-Rated Sales Invoice", mixed_sales_invoice: "Mixed Sales Invoice", sales_invoice: "Sales Invoice", service_invoice: "Service Invoice", payment_receipt: "Payment Receipt", official_receipt: "Official Receipt (Supplementary)", credit_memo: "Credit Note", credit_note: "Credit Note", debit_memo: "Debit Note", debit_note: "Debit Note" } as Record<string, string>)[type] || type.replaceAll("_", " ").replace(/\b\w/g, c => c.toUpperCase()); }
export function documentDate(value: string, short = false) {
  if (!value) return "Not recorded";
  const dayOnly = /^\d{4}-\d{2}-\d{2}$/.test(value);
  const parsed = dayOnly ? value + "T12:00:00+08:00" : value.includes("T") ? value : value.replace(" ", "T") + "+08:00";
  const date = new Date(parsed);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-PH", { timeZone: "Asia/Manila", month: "short", day: "numeric", year: "numeric", ...(!short && !dayOnly ? { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false } : {}) });
}
function amountWords(amount: number) {
  const small = ["", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten", "eleven", "twelve", "thirteen", "fourteen", "fifteen", "sixteen", "seventeen", "eighteen", "nineteen"];
  const tens = ["", "", "twenty", "thirty", "forty", "fifty", "sixty", "seventy", "eighty", "ninety"];
  const part = (n: number): string => n < 20 ? small[n] : n < 100 ? `${tens[Math.floor(n / 10)]} ${small[n % 10]}`.trim() : `${small[Math.floor(n / 100)]} hundred ${part(n % 100)}`.trim();
  const centsTotal = Math.round(Math.abs(amount) * 100); let whole = Math.floor(centsTotal / 100); const words = []; const cents = centsTotal % 100;
  for (const unit of ["", "thousand", "million", "billion", "trillion"]) { const n = whole % 1000; if (n) words.unshift(`${part(n)} ${unit}`.trim()); whole = Math.floor(whole / 1000); }
  return `${amount < 0 ? "minus " : ""}${words.join(" ") || "zero"} pesos${cents ? ` and ${cents}/100` : ""} only`.toUpperCase();
}
const evidenceLabels: Record<string,string> = { acknowledged:"Verified production acknowledgment",not_submitted:"Not submitted",blocked:"Blocked — evidence pending",queued:"Queued locally",uncertain:"Uncertain — inquiry required",needs_review:"Needs review",unverified:"Unverified response",test:"Certification record only",not_verified:"Not verified" };

export function OfficialReceipt({ document: d, staticQr = "" }: { document: CenterDocument; staticQr?: string }) {
  const [qr, setQr] = useState(staticQr);
  useEffect(() => { let active = true; setQr(""); if (d.verification_url) QRCode.toDataURL(d.verification_url, { margin: 0, width: 220, errorCorrectionLevel: "M" }).then(value => { if (active) setQr(value); }).catch(() => { if(active) setQr(""); }); return () => { active = false; }; }, [d.verification_url]);
  const draft = d.record_status === "draft";
  const payment = ["payment_receipt", "official_receipt"].includes(d.document_type);
  const warning = payment || ["non_vat_invoice","vat_exempt_invoice"].includes(d.document_type) || (d.document_type === "mixed_sales_invoice" && d.vat_registered === false);
  const fmt = (v: number | null | undefined) => v == null ? "Not recorded" : `${d.currency === "PHP" || !d.currency ? "₱" : d.currency} ${money(v)}`;
  const registered = d.vat_registered === true || d.vat_registered ===  "1" ? "VAT Registered" : d.vat_registered === false || d.vat_registered === "0" ? "Non-VAT Registered" : "VAT registration not recorded";
  const documentTotal = payment ? "Recorded Payment Amount" : "Document Total";
  return <div className="official-receipt-container"><article className="official-receipt" aria-label={`${documentLabel(d.document_type)} ${d.number}`}>
    <div className="or-sun" aria-hidden="true" />
    <header className="or-header"><img className="or-logo" src="/auth-assets/nuers-digital-logo.png" alt="NUERS National Unified Electronic Receipt System" /><div className="or-ribbon"><strong>{payment ? "ELECTRONIC RECEIPT" : ["credit_memo","debit_memo","credit_note","debit_note"].includes(d.document_type) ? "ELECTRONIC ADJUSTMENT" : "ELECTRONIC INVOICE"}</strong><span>{documentLabel(d.document_type).toUpperCase()}{draft ? " · DRAFT" : " · RECORDED COPY"}</span></div></header>
    {draft && <div className="or-source-notice"><strong>DRAFT — NOT AN ISSUED DOCUMENT</strong>{d.source_notice || "Preview only; not an issued document."}</div>}
    <section className="or-parties"><div className="or-seller"><h2>{d.business || "Seller name not recorded"}</h2><p>{d.seller_address || "Address not recorded"}</p><p>TIN: {d.tin || "Not recorded"}</p>{d.seller_business_style && <p>Business Style: {d.seller_business_style}</p>}<p>Seller branch: {d.branch || "Not recorded"}</p><p>{registered}</p></div><dl className="or-meta">{[["Document No.", d.number], [d.date_precision === "day" || /^\d{4}-\d{2}-\d{2}$/.test(d.issued_at) ? "Business Date" : "Source Date (PHT)", documentDate(d.issued_at)], ["Reference", d.reference || "Not recorded"], ["Document Type", documentLabel(d.document_type)], ["Payment Method", d.payment_method?.replaceAll("_", " ") || "Not recorded"], ["Source Status", d.record_status.replaceAll("_", " ")]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>
    <section className="or-buyer"><div><h3>{payment ? "RECEIVED FROM" : "SOLD TO"}</h3><dl>{[["Customer Name", d.buyer_name || "Not recorded"], ["Address", d.buyer_address || "Not recorded"], ["TIN", d.buyer_tin || "Not recorded"], ...(d.buyer_business_style ? [["Business Style", d.buyer_business_style]] : [])].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></div><div className="or-thanks"><strong>Salamat po!</strong><i /><span>TOGETHER FOR A PROGRESSIVE PHILIPPINES</span></div></section>
    {warning && <div className="or-tax-warning">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX</div>}
    <table className="or-items"><thead><tr><th>#</th><th>DESCRIPTION / UNIT</th><th>QTY</th><th>UNIT PRICE</th><th>AMOUNT</th></tr></thead><tbody>{d.items.length ? d.items.map((item, i) => <tr key={i}><td>{i + 1}</td><td>{item.description || "Not recorded"}{item.unit && <small>Unit: {item.unit}</small>}{item.amount_derived && <small>Amount calculated from recorded quantity × unit price</small>}</td><td>{item.quantity == null ? "Not recorded" : Number(item.quantity)}</td><td>{fmt(item.unit_price)}</td><td>{fmt(item.amount)}</td></tr>) : <tr><td colSpan={5}>Item details not recorded in the source document.</td></tr>}</tbody></table>
    <div className="or-financials"><section className="or-words"><h3>{documentTotal} in Words</h3><p>{d.currency && d.currency !== "PHP" ? "See the recorded currency amount." : amountWords(Number(d.amount))}</p></section><section className="or-totals">{(payment ? [["Recorded Payment Amount",d.amount]] : [["Recorded Subtotal", d.subtotal], ["Less: Discount", d.discount], ...(d.other_charges !== 0 ? [["Other Charges",d.other_charges]] : []), ["Less: Withholding Tax", d.withholding], ...(d.net_payable != null && d.net_payable !== d.amount ? [["Net Payable Before Settlements", d.net_payable]] : []), ...(d.amount_paid != null && d.amount_paid !== 0 ? [["Recorded Settlements", d.amount_paid]] : []), ...(d.balance_due != null && d.balance_due !== d.net_payable ? [["Recorded Balance Due",d.balance_due]] : [])]).map(([label, value]) => <div key={String(label)}><span>{label}</span><span>{fmt(value as number | null)}</span></div>)}<div className="or-grand-total"><strong>{documentTotal.toUpperCase()}</strong><strong>{fmt(d.amount)}</strong></div></section></div>
    {!payment && <section className="or-tax-breakdown" aria-label="Recorded tax breakdown">{[["VATable Sales",d.vatable],["VAT Amount",d.vat],["VAT-Exempt Sales",d.vat_exempt],["Zero-Rated Sales",d.zero_rated]].map(([label,value])=><div key={String(label)}>{label}<strong>{fmt(value as number | null)}</strong></div>)}</section>}
    {d.financial_validation_passed === false && <p className="or-source-notice or-financial-warning">Source amounts or line items need reconciliation. Values shown are preserved originals and have not been silently corrected.</p>}
    {d.settlement_validation_passed === false && <p className="or-source-notice or-financial-warning">Recorded balance does not reconcile with the source total, withholding and settlements. Review original payment evidence; no paid status has been inferred.</p>}
    <div className="or-validation-row"><section className="or-qr">{qr ? <><img src={qr} alt={`NUERS record lookup QR for ${d.number}`} /><p>NUERS record lookup<br /><strong>Does not establish BIR acceptance</strong></p></> : <><FileText /><p>Verification link not available.<br /><strong>No record number or digest is presented as a verification QR.</strong></p></>}<small>Retain the original document and its evidence.</small></section><section className="or-validation or-unverified"><h3>RECORD EVIDENCE</h3><dl><div><dt>Source Status</dt><dd>{d.record_status.replaceAll("_", " ")}</dd></div><div><dt>BIR Acceptance</dt><dd>{evidenceLabels[d.bir_status || "not_verified"] || "Not verified"}</dd></div><div><dt>PTI Reference</dt><dd>{d.permit_number || "Not recorded"}</dd></div><div><dt>ESP Reference</dt><dd>{d.accreditation_number || "Not recorded"}</dd></div></dl></section></div>
    <p className="or-source-note">{payment ? "Supplementary payment record. Does not create additional primary invoice sales." : "Invoice issuance and BIR transmission are separate controls."} Recorded permit references alone do not establish current approval.</p>
    <div className="or-skyline" aria-hidden="true"><img src="/invoice-assets/receipt-reference.png" alt="" /></div>
    <footer className="or-footer"><section>DIGITAL TRANSACTIONS.<br />A STRONGER PHILIPPINES.<i /></section></footer>
  </article></div>;
}
