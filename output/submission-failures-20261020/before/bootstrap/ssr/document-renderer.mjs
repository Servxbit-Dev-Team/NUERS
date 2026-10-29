// scripts/document-renderer.tsx
import { renderToStaticMarkup } from "react-dom/server";
import { readFileSync } from "node:fs";
import path from "node:path";
import QRCode2 from "qrcode";

// src/lib/statutory-sales.ts
var SALE_CATEGORIES = { regular: "Regular sale", senior_citizen: "Senior citizen", pwd: "PWD", national_athlete_coach: "National athlete / coach", solo_parent: "Solo parent" };
var saleLabel = (v) => SALE_CATEGORIES[v] || "Regular sale";

// src/components/invoices/official-receipt.tsx
import { useEffect, useState } from "react";
import QRCode from "qrcode";
import { FileText } from "lucide-react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
var money = (n) => n == null ? "Not recorded" : Number(n).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function documentLabel(type) {
  return { vat_invoice: "VAT Invoice", non_vat_invoice: "Non-VAT Invoice", vat_exempt_invoice: "VAT-Exempt Sales Invoice", zero_rated_invoice: "Zero-Rated Sales Invoice", mixed_sales_invoice: "Mixed Sales Invoice", sales_invoice: "Sales Invoice", service_invoice: "Service Invoice", payment_receipt: "Payment Receipt", official_receipt: "Official Receipt (Supplementary)", credit_memo: "Credit Note", credit_note: "Credit Note", debit_memo: "Debit Note", debit_note: "Debit Note" }[type] || type.replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase());
}
function documentDate(value, short = false) {
  if (!value) return "Not recorded";
  const dayOnly = /^\d{4}-\d{2}-\d{2}$/.test(value);
  const parsed = dayOnly ? value + "T12:00:00+08:00" : value.includes("T") ? value : value.replace(" ", "T") + "+08:00";
  const date = new Date(parsed);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-PH", { timeZone: "Asia/Manila", month: "short", day: "numeric", year: "numeric", ...!short && !dayOnly ? { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false } : {} });
}
function amountWords(amount) {
  const small = ["", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten", "eleven", "twelve", "thirteen", "fourteen", "fifteen", "sixteen", "seventeen", "eighteen", "nineteen"];
  const tens = ["", "", "twenty", "thirty", "forty", "fifty", "sixty", "seventy", "eighty", "ninety"];
  const part = (n) => n < 20 ? small[n] : n < 100 ? `${tens[Math.floor(n / 10)]} ${small[n % 10]}`.trim() : `${small[Math.floor(n / 100)]} hundred ${part(n % 100)}`.trim();
  const centsTotal = Math.round(Math.abs(amount) * 100);
  let whole = Math.floor(centsTotal / 100);
  const words = [];
  const cents = centsTotal % 100;
  for (const unit of ["", "thousand", "million", "billion", "trillion"]) {
    const n = whole % 1e3;
    if (n) words.unshift(`${part(n)} ${unit}`.trim());
    whole = Math.floor(whole / 1e3);
  }
  return `${amount < 0 ? "minus " : ""}${words.join(" ") || "zero"} pesos${cents ? ` and ${cents}/100` : ""} only`.toUpperCase();
}
var evidenceLabels = { acknowledged: "Verified production acknowledgment", not_submitted: "Not submitted", blocked: "Blocked \u2014 evidence pending", queued: "Queued locally", uncertain: "Uncertain \u2014 inquiry required", needs_review: "Needs review", unverified: "Unverified response", test: "Certification record only", not_verified: "Not verified" };
function OfficialReceipt({ document: d, staticQr = "" }) {
  const [qr, setQr] = useState(staticQr);
  useEffect(() => {
    let active = true;
    setQr("");
    if (d.verification_url) QRCode.toDataURL(d.verification_url, { margin: 0, width: 220, errorCorrectionLevel: "M" }).then((value) => {
      if (active) setQr(value);
    }).catch(() => {
      if (active) setQr("");
    });
    return () => {
      active = false;
    };
  }, [d.verification_url]);
  const draft = d.record_status === "draft";
  const payment = ["payment_receipt", "official_receipt"].includes(d.document_type);
  const warning = payment || ["non_vat_invoice", "vat_exempt_invoice"].includes(d.document_type) || d.document_type === "mixed_sales_invoice" && d.vat_registered === false;
  const fmt = (v) => v == null ? "Not recorded" : `${d.currency === "PHP" || !d.currency ? "\u20B1" : d.currency} ${money(v)}`;
  const registered = d.vat_registered === true || d.vat_registered === "1" ? "VAT Registered" : d.vat_registered === false || d.vat_registered === "0" ? "Non-VAT Registered" : "VAT registration not recorded";
  const p = d.presentation, template = p ? payment ? p.receipt_template : p.invoice_template : null;
  const businessLogo = p?.show_logo && p.logo_url && /^\/(?:public\/)?uploads\/(?:business-logos|invoice-logos)\/[a-f0-9-]+\.png$/i.test(p.logo_url) ? p.logo_url : null;
  const customStyle = p ? { fontFamily: { Inter: "Inter, Arial, sans-serif", Arial: "Arial, sans-serif", Georgia: "Georgia, serif" }[p.font_family] || "Arial, sans-serif", "--doc-primary": p.primary_color, "--doc-secondary": p.secondary_color } : void 0;
  const documentTotal = payment ? "Recorded Payment Amount" : "Document Total";
  return /* @__PURE__ */ jsx("div", { className: "official-receipt-container", children: /* @__PURE__ */ jsxs("article", { className: `official-receipt ${d.sales_benefit ? "or-benefit" : ""} ${p ? `or-custom or-template-${template}` : ""}`, style: customStyle, "aria-label": `${documentLabel(d.document_type)} ${d.number}`, children: [
    /* @__PURE__ */ jsx("div", { className: "or-sun", "aria-hidden": "true" }),
    /* @__PURE__ */ jsxs("header", { className: "or-header", children: [
      businessLogo ? /* @__PURE__ */ jsx("img", { className: "or-logo", src: businessLogo, alt: `${d.business} logo` }) : !p ? /* @__PURE__ */ jsx("img", { className: "or-logo", src: "/auth-assets/nuers-digital-logo.png", alt: "NUERS National Unified Electronic Receipt System" }) : /* @__PURE__ */ jsx("div", { className: "or-business-brand", children: d.business }),
      /* @__PURE__ */ jsxs("div", { className: "or-ribbon", children: [
        /* @__PURE__ */ jsx("strong", { children: payment ? "ELECTRONIC RECEIPT" : ["credit_memo", "debit_memo", "credit_note", "debit_note"].includes(d.document_type) ? "ELECTRONIC ADJUSTMENT" : "ELECTRONIC INVOICE" }),
        /* @__PURE__ */ jsxs("span", { children: [
          documentLabel(d.document_type).toUpperCase(),
          draft ? " \xB7 DRAFT" : d.record_mode === "source_capture" ? " \xB7 EXTERNAL SOURCE COPY" : " \xB7 RECORDED COPY"
        ] })
      ] })
    ] }),
    draft && /* @__PURE__ */ jsxs("div", { className: "or-source-notice", children: [
      /* @__PURE__ */ jsx("strong", { children: "DRAFT \u2014 NOT AN ISSUED DOCUMENT" }),
      d.source_notice || "Preview only; not an issued document."
    ] }),
    /* @__PURE__ */ jsxs("section", { className: "or-parties", children: [
      /* @__PURE__ */ jsxs("div", { className: "or-seller", children: [
        /* @__PURE__ */ jsx("h2", { children: d.business || "Seller name not recorded" }),
        /* @__PURE__ */ jsx("p", { children: d.seller_address || "Address not recorded" }),
        /* @__PURE__ */ jsxs("p", { children: [
          "TIN: ",
          d.tin || "Not recorded"
        ] }),
        p?.seller_phone && /* @__PURE__ */ jsxs("p", { children: [
          "Tel: ",
          p.seller_phone
        ] }),
        p?.seller_email && /* @__PURE__ */ jsxs("p", { children: [
          "Email: ",
          p.seller_email
        ] }),
        p?.show_website && p.seller_website && /* @__PURE__ */ jsx("p", { children: p.seller_website }),
        d.seller_business_style && /* @__PURE__ */ jsxs("p", { children: [
          "Business Style: ",
          d.seller_business_style
        ] }),
        /* @__PURE__ */ jsxs("p", { children: [
          "Seller branch: ",
          d.branch || "Not recorded"
        ] }),
        /* @__PURE__ */ jsx("p", { children: registered })
      ] }),
      /* @__PURE__ */ jsx("dl", { className: "or-meta", children: [["Document No.", d.number], [d.date_precision === "day" || /^\d{4}-\d{2}-\d{2}$/.test(d.issued_at) ? "Business Date" : "Source Date (PHT)", documentDate(d.issued_at)], ["Reference", d.reference || "Not recorded"], ["Document Type", documentLabel(d.document_type)], ["Payment Method", d.payment_method?.replaceAll("_", " ") || "Not recorded"], ["Source Status", d.record_status.replaceAll("_", " ")]].map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
        /* @__PURE__ */ jsx("dt", { children: label }),
        /* @__PURE__ */ jsx("dd", { children: value })
      ] }, label)) })
    ] }),
    /* @__PURE__ */ jsxs("section", { className: "or-buyer", children: [
      /* @__PURE__ */ jsxs("div", { children: [
        /* @__PURE__ */ jsx("h3", { children: payment ? "RECEIVED FROM" : "SOLD TO" }),
        /* @__PURE__ */ jsx("dl", { children: [["Customer Name", d.buyer_name || "Not recorded"], ["Address", d.buyer_address || "Not recorded"], ["TIN", d.buyer_tin || "Not recorded"], ...d.buyer_business_style ? [["Business Style", d.buyer_business_style]] : []].map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("dt", { children: label }),
          /* @__PURE__ */ jsx("dd", { children: value })
        ] }, label)) })
      ] }),
      /* @__PURE__ */ jsxs("div", { className: "or-thanks", children: [
        /* @__PURE__ */ jsx("strong", { children: "Salamat po!" }),
        /* @__PURE__ */ jsx("i", {}),
        /* @__PURE__ */ jsx("span", { children: "TOGETHER FOR A PROGRESSIVE PHILIPPINES" })
      ] })
    ] }),
    d.sales_benefit && /* @__PURE__ */ jsxs("section", { className: "or-source-notice", children: [
      /* @__PURE__ */ jsxs("strong", { children: [
        saleLabel(d.sale_category),
        " sale \xB7 ",
        d.sales_benefit.category === "solo_parent" ? "10%" : "20%",
        " eligible discount"
      ] }),
      /* @__PURE__ */ jsxs("p", { children: [
        "Beneficiary: ",
        d.sales_benefit.beneficiary_name,
        " \xB7 ID: ",
        d.sales_benefit.id_number
      ] }),
      /* @__PURE__ */ jsxs("p", { children: [
        "Evidence: ",
        d.sales_benefit.evidence_reference
      ] }),
      /* @__PURE__ */ jsx("p", { children: "Beneficiary / authorized representative signature: ____________________ (where required)" })
    ] }),
    warning && /* @__PURE__ */ jsx("div", { className: "or-tax-warning", children: "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX" }),
    /* @__PURE__ */ jsxs("table", { className: "or-items", children: [
      /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsxs("tr", { children: [
        /* @__PURE__ */ jsx("th", { children: "#" }),
        /* @__PURE__ */ jsx("th", { children: "DESCRIPTION / UNIT" }),
        /* @__PURE__ */ jsx("th", { children: "QTY" }),
        /* @__PURE__ */ jsx("th", { children: "UNIT PRICE" }),
        /* @__PURE__ */ jsx("th", { children: "AMOUNT" })
      ] }) }),
      /* @__PURE__ */ jsx("tbody", { children: d.items.length ? d.items.map((item, i) => /* @__PURE__ */ jsxs("tr", { children: [
        /* @__PURE__ */ jsx("td", { children: i + 1 }),
        /* @__PURE__ */ jsxs("td", { children: [
          item.description || "Not recorded",
          item.unit && /* @__PURE__ */ jsxs("small", { children: [
            "Unit: ",
            item.unit
          ] }),
          item.discount_reason && /* @__PURE__ */ jsxs("small", { children: [
            "Manual discount: ",
            fmt(item.discount || 0),
            " \xB7 ",
            item.discount_reason
          ] }),
          item.benefit_eligible && /* @__PURE__ */ jsx("small", { children: "Eligible statutory-benefit item" }),
          item.amount_derived && /* @__PURE__ */ jsx("small", { children: "Amount calculated from recorded quantity \xD7 unit price" })
        ] }),
        /* @__PURE__ */ jsx("td", { children: item.quantity == null ? "Not recorded" : Number(item.quantity) }),
        /* @__PURE__ */ jsx("td", { children: fmt(item.unit_price) }),
        /* @__PURE__ */ jsx("td", { children: fmt(item.amount) })
      ] }, i)) : /* @__PURE__ */ jsx("tr", { children: /* @__PURE__ */ jsx("td", { colSpan: 5, children: "Item details not recorded in the source document." }) }) })
    ] }),
    /* @__PURE__ */ jsxs("div", { className: "or-financials", children: [
      /* @__PURE__ */ jsxs("section", { className: "or-words", children: [
        /* @__PURE__ */ jsxs("h3", { children: [
          documentTotal,
          " in Words"
        ] }),
        /* @__PURE__ */ jsx("p", { children: d.currency && d.currency !== "PHP" ? "See the recorded currency amount." : amountWords(Number(d.amount)) })
      ] }),
      /* @__PURE__ */ jsxs("section", { className: "or-totals", children: [
        (payment ? [["Recorded Payment Amount", d.amount]] : [["Recorded Subtotal", d.subtotal], ["Less: Discount", d.discount], ...d.manual_discount ? [["Of which: Manual Discount", d.manual_discount]] : [], ...d.statutory_discount ? [["Of which: Statutory Discount", d.statutory_discount]] : [], ...d.vat_adjustment ? [["Less: VAT Adjustment", d.vat_adjustment]] : [], ...d.other_charges !== 0 ? [["Other Charges", d.other_charges]] : [], ["Less: Withholding Tax", d.withholding], ...d.net_payable != null && d.net_payable !== d.amount ? [["Net Payable Before Settlements", d.net_payable]] : [], ...d.amount_paid != null && d.amount_paid !== 0 ? [["Recorded Settlements", d.amount_paid]] : [], ...d.balance_due != null && d.balance_due !== d.net_payable ? [["Recorded Balance Due", d.balance_due]] : []]).map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("span", { children: label }),
          /* @__PURE__ */ jsx("span", { children: fmt(value) })
        ] }, String(label))),
        /* @__PURE__ */ jsxs("div", { className: "or-grand-total", children: [
          /* @__PURE__ */ jsx("strong", { children: documentTotal.toUpperCase() }),
          /* @__PURE__ */ jsx("strong", { children: fmt(d.amount) })
        ] })
      ] })
    ] }),
    !payment && /* @__PURE__ */ jsx("section", { className: "or-tax-breakdown", "aria-label": "Recorded tax breakdown", children: [...d.sspt_sales != null && d.vat_registered === false ? [["Non-VAT Sales", d.sspt_sales]] : [], ["VATable Sales", d.vatable], ["VAT Amount", d.vat], ["VAT-Exempt Sales", d.vat_exempt], ["Zero-Rated Sales", d.zero_rated]].map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
      label,
      /* @__PURE__ */ jsx("strong", { children: fmt(value) })
    ] }, String(label))) }),
    d.financial_validation_passed === false && /* @__PURE__ */ jsx("p", { className: "or-source-notice or-financial-warning", children: "Source amounts or line items need reconciliation. Values shown are preserved originals and have not been silently corrected." }),
    d.settlement_validation_passed === false && /* @__PURE__ */ jsx("p", { className: "or-source-notice or-financial-warning", children: "Recorded balance does not reconcile with the source total, withholding and settlements. Review original payment evidence; no paid status has been inferred." }),
    /* @__PURE__ */ jsxs("div", { className: "or-validation-row", children: [
      /* @__PURE__ */ jsxs("section", { className: "or-qr", children: [
        qr ? /* @__PURE__ */ jsxs(Fragment, { children: [
          /* @__PURE__ */ jsx("img", { src: qr, alt: `NUERS record lookup QR for ${d.number}` }),
          /* @__PURE__ */ jsxs("p", { children: [
            "NUERS record lookup",
            /* @__PURE__ */ jsx("br", {}),
            /* @__PURE__ */ jsx("strong", { children: "Does not establish BIR acceptance" })
          ] })
        ] }) : /* @__PURE__ */ jsxs(Fragment, { children: [
          /* @__PURE__ */ jsx(FileText, {}),
          /* @__PURE__ */ jsxs("p", { children: [
            "Verification link not available.",
            /* @__PURE__ */ jsx("br", {}),
            /* @__PURE__ */ jsx("strong", { children: "No record number or digest is presented as a verification QR." })
          ] })
        ] }),
        /* @__PURE__ */ jsx("small", { children: "Retain the original document and its evidence." })
      ] }),
      /* @__PURE__ */ jsxs("section", { className: "or-validation or-unverified", children: [
        /* @__PURE__ */ jsx("h3", { children: "RECORD EVIDENCE" }),
        /* @__PURE__ */ jsxs("dl", { children: [
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("dt", { children: "Source Status" }),
            /* @__PURE__ */ jsx("dd", { children: d.record_status.replaceAll("_", " ") })
          ] }),
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("dt", { children: "BIR Acceptance" }),
            /* @__PURE__ */ jsx("dd", { children: evidenceLabels[d.bir_status || "not_verified"] || "Not verified" })
          ] }),
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("dt", { children: "PTI Reference" }),
            /* @__PURE__ */ jsx("dd", { children: d.permit_number || "Not recorded" })
          ] }),
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("dt", { children: "ESP Reference" }),
            /* @__PURE__ */ jsx("dd", { children: d.accreditation_number || "Not recorded" })
          ] })
        ] })
      ] })
    ] }),
    /* @__PURE__ */ jsxs("p", { className: "or-source-note", children: [
      payment ? "Supplementary payment record. Does not create additional primary invoice sales." : "Invoice issuance and BIR transmission are separate controls.",
      " Recorded permit references alone do not establish current approval."
    ] }),
    p && /* @__PURE__ */ jsxs("section", { className: "or-custom-notes", children: [
      (d.footer_note || p.footer_text) && /* @__PURE__ */ jsx("p", { children: d.footer_note || p.footer_text }),
      (d.notes || p.additional_notes) && /* @__PURE__ */ jsx("p", { children: d.notes || p.additional_notes }),
      p.payment_methods?.length > 0 && /* @__PURE__ */ jsxs("p", { children: [
        "Accepted payment methods: ",
        p.payment_methods.map((m) => ({ cash: "Cash", bank_transfer: "Bank Transfer", card: "Card", e_wallet: "E-Wallet" })[m] || m).join(", ")
      ] }),
      p.payment_instructions && /* @__PURE__ */ jsx("p", { children: p.payment_instructions }),
      (d.terms_and_conditions || p.terms_and_conditions) && /* @__PURE__ */ jsx("p", { children: d.terms_and_conditions || p.terms_and_conditions })
    ] }),
    /* @__PURE__ */ jsx("div", { className: "or-skyline", "aria-hidden": "true", children: /* @__PURE__ */ jsx("img", { src: "/invoice-assets/receipt-reference.png", alt: "" }) }),
    /* @__PURE__ */ jsx("footer", { className: "or-footer", children: /* @__PURE__ */ jsxs("section", { children: [
      "DIGITAL TRANSACTIONS.",
      /* @__PURE__ */ jsx("br", {}),
      "A STRONGER PHILIPPINES.",
      /* @__PURE__ */ jsx("i", {})
    ] }) })
  ] }) });
}

// scripts/document-renderer.tsx
import { jsx as jsx2 } from "react/jsx-runtime";
async function renderDocumentHtml(document) {
  const styles = readFileSync(path.resolve("src/components/invoices/electronic-document.css"), "utf8");
  const qr = document.verification_url ? await QRCode2.toDataURL(document.verification_url, { margin: 0, width: 220, errorCorrectionLevel: "M" }) : "";
  let content = renderToStaticMarkup(/* @__PURE__ */ jsx2("div", { className: "merchant-electronic-preview", children: /* @__PURE__ */ jsx2(OfficialReceipt, { document, staticQr: qr }) }));
  content = content.replace(/<link\b[^>]*>/g, "");
  const assets = ["/auth-assets/nuers-digital-logo.png", "/invoice-assets/receipt-reference.png"];
  const brand = document.presentation?.show_logo ? document.presentation.logo_url : null;
  if (brand && /^\/(?:public\/)?uploads\/(?:business-logos|invoice-logos)\/[a-f0-9-]+\.png$/i.test(brand)) assets.push(brand);
  for (const asset of assets) {
    let data = "";
    try {
      data = `data:image/png;base64,${readFileSync(path.resolve(asset.startsWith("/public/") ? asset.slice(1) : "public" + asset)).toString("base64")}`;
    } catch {
      if (asset === brand) {
        content = content.replaceAll(`src="${asset}"`, 'alt="Business logo unavailable"');
        continue;
      }
      throw new Error("Required document asset unavailable");
    }
    content = content.replaceAll(`src="${asset}"`, `src="${data}"`);
  }
  const payment = ["payment_receipt", "official_receipt"].includes(document.document_type);
  const paper = (payment ? document.presentation?.receipt_paper : document.presentation?.invoice_paper) === "Letter" ? "Letter" : "A4";
  const pageWidth = paper === "Letter" ? 206 : 200;
  const zoom = pageWidth * 96 / 25.4 / 900;
  const title = document.number.replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'"><title>${title}</title><style>
    *,::before,::after{box-sizing:border-box}html,body{margin:0;padding:0}body{width:900px}img,svg{display:block;vertical-align:middle}img{max-width:100%;height:auto}h1,h2,h3,p{margin:0}table{border-color:inherit;text-indent:0}strong{font-weight:bolder}
    ${styles}
    @page{size:${paper};margin:5mm}
    @media print{html{width:${pageWidth}mm}body{width:900px;zoom:${zoom}}.merchant-electronic-preview{overflow:visible}.official-receipt{overflow:visible!important}.or-header,.or-parties,.or-buyer,.or-tax-warning,.or-source-notice,.or-financials,.or-tax-breakdown,.or-validation-row,.or-source-note,.or-footer,.or-items tr{break-inside:avoid}.or-items thead{display:table-header-group}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
  </style></head><body>${content}</body></html>`;
}
if (process.argv[2] === "--stdin") {
  try {
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(Buffer.from(chunk));
    const document = JSON.parse(Buffer.concat(chunks).toString("utf8"));
    process.stdout.write(await renderDocumentHtml(document));
  } catch {
    process.stderr.write("Could not render the authorized document preview.\n");
    process.exitCode = 1;
  }
}
export {
  renderDocumentHtml
};
