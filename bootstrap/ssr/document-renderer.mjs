// scripts/document-renderer.tsx
import { renderToStaticMarkup } from "react-dom/server";
import { readFileSync } from "node:fs";
import path from "node:path";

// src/lib/statutory-sales.ts
var SALE_CATEGORIES = { regular: "Regular sale", senior_citizen: "Senior citizen", pwd: "PWD", national_athlete_coach: "National athlete / coach", solo_parent: "Solo parent" };
var saleLabel = (v) => SALE_CATEGORIES[v] || "Regular sale";

// src/components/invoices/official-receipt.tsx
import { jsx, jsxs } from "react/jsx-runtime";
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
function OfficialReceipt({ document: d }) {
  const draft = d.record_status === "draft";
  const payment = ["payment_receipt", "official_receipt"].includes(d.document_type);
  const supplementary = payment || ["delivery_receipt", "purchase_invoice", "credit_memo", "debit_memo", "credit_note", "debit_note"].includes(d.document_type);
  const warning = supplementary || ["non_vat_invoice", "vat_exempt_invoice"].includes(d.document_type) || d.document_type === "mixed_sales_invoice" && d.vat_registered === false;
  const fmt = (v) => v == null ? "Not recorded" : `${d.currency === "PHP" || !d.currency ? "\u20B1" : d.currency} ${money(v)}`;
  const registered = d.vat_registered === true || d.vat_registered === "1" ? "VAT Registered" : d.vat_registered === false || d.vat_registered === "0" ? "Non-VAT Registered" : "VAT registration not recorded";
  const p = d.presentation, template = p ? payment ? p.receipt_template : p.invoice_template : null;
  const businessLogo = p?.show_logo && p.logo_url && /^\/(?:public\/)?uploads\/(?:business-logos|invoice-logos)\/[a-f0-9-]+\.png$/i.test(p.logo_url) ? p.logo_url : null;
  const customStyle = p ? { fontFamily: { Inter: "Inter, Arial, sans-serif", Arial: "Arial, sans-serif", Georgia: "Georgia, serif" }[p.font_family] || "Arial, sans-serif", "--doc-primary": p.primary_color, "--doc-secondary": p.secondary_color } : void 0;
  const reg = d.document_registration;
  const tinDigits = d.tin.replace(/[^0-9]/g, "");
  const encodedBranch = tinDigits.length === 14 ? tinDigits.slice(9) : tinDigits.length === 12 ? `00${tinDigits.slice(9)}` : null;
  const printedBranch = d.branch || encodedBranch;
  const printedTin = d.registered_tin || ((tinDigits.length === 9 || tinDigits.length === 12 || tinDigits.length === 14) && printedBranch && (!encodedBranch || encodedBranch === printedBranch) ? `${tinDigits.slice(0, 3)}-${tinDigits.slice(3, 6)}-${tinDigits.slice(6, 9)}-${printedBranch}` : d.tin);
  const vatLabel = d.vat_registered === true || d.vat_registered === "1" ? "VAT Reg TIN" : d.vat_registered === false || d.vat_registered === "0" ? "Non-VAT Reg TIN" : "TIN";
  const reference = d.invoice_reference || d.reference;
  const documentTotal = payment ? "Recorded Payment Amount" : "Document Total";
  return /* @__PURE__ */ jsx("div", { className: "official-receipt-container", children: /* @__PURE__ */ jsxs("article", { className: `official-receipt ${d.sales_benefit ? "or-benefit" : ""} ${p ? `or-custom or-template-${template}` : ""}`, style: customStyle, "aria-label": `${documentLabel(d.document_type)} ${d.number}`, children: [
    /* @__PURE__ */ jsx("div", { className: "or-sun", "aria-hidden": "true" }),
    /* @__PURE__ */ jsxs("header", { className: "or-header", children: [
      businessLogo ? /* @__PURE__ */ jsx("img", { className: "or-logo", src: businessLogo, alt: `${d.business} logo` }) : !p ? /* @__PURE__ */ jsx("img", { className: "or-logo", src: "/brand/nuers-official-logo.png", alt: "NUERS National Unified Electronic Receipt System" }) : /* @__PURE__ */ jsx("div", { className: "or-business-brand", children: d.business }),
      /* @__PURE__ */ jsxs("div", { className: "or-ribbon", children: [
        /* @__PURE__ */ jsx("strong", { children: payment ? "ELECTRONIC RECEIPT" : ["credit_memo", "debit_memo", "credit_note", "debit_note"].includes(d.document_type) ? "ELECTRONIC ADJUSTMENT" : "ELECTRONIC INVOICE" }),
        /* @__PURE__ */ jsxs("span", { children: [
          documentLabel(d.document_type).toUpperCase(),
          d.copy_label && !draft ? ` \xB7 ${d.copy_label}` : "",
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
          vatLabel,
          ": ",
          printedTin || "Not recorded"
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
          printedBranch || "Not recorded"
        ] }),
        /* @__PURE__ */ jsx("p", { children: registered })
      ] }),
      /* @__PURE__ */ jsx("dl", { className: "or-meta", children: [[payment ? "Receipt No." : "Invoice / Document No.", d.number], [d.date_precision === "day" || /^\d{4}-\d{2}-\d{2}$/.test(d.issued_at) ? "Business Date" : "Source Date (PHT)", documentDate(d.issued_at)], [payment || d.original_invoice_id || d.invoice_reference ? "Original Invoice No." : "Reference", reference || "Not recorded"], ["Document Type", documentLabel(d.document_type)], ["Payment Method", d.payment_method?.replaceAll("_", " ") || "Not recorded"], ["Source Status", d.record_status.replaceAll("_", " ")]].map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
        /* @__PURE__ */ jsx("dt", { children: label }),
        /* @__PURE__ */ jsx("dd", { children: label?.includes("No.") ? /* @__PURE__ */ jsx("strong", { children: value }) : value })
      ] }, label)) })
    ] }),
    /* @__PURE__ */ jsxs("section", { className: "or-buyer", children: [
      /* @__PURE__ */ jsxs("div", { children: [
        /* @__PURE__ */ jsx("h3", { children: payment ? "RECEIVED FROM" : "SOLD TO" }),
        /* @__PURE__ */ jsx("dl", { children: [["Registered Name", d.buyer_name || "Not recorded"], ["Address", d.buyer_address || (d.buyer_customer_type && d.buyer_customer_type !== "business" ? "Not required (B2C)" : "Not recorded")], ["TIN", d.buyer_tin || (d.buyer_customer_type && d.buyer_customer_type !== "business" ? "Not required (B2C)" : "Not recorded")], ...d.buyer_business_style ? [["Business Style", d.buyer_business_style]] : []].map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
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
    reg?.system_type === "POS" && /* @__PURE__ */ jsxs("p", { className: "or-source-note", children: [
      "MIN: ",
      reg.machine_identification_number || "Not recorded",
      " \xB7 Machine / Software Serial: ",
      reg.machine_serial_number || reg.software_license_number || "Not recorded"
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
    !payment && (d.document_type === "vat_exempt_invoice" || d.document_type === "zero_rated_invoice" || reg?.non_vat_basis === "exempt") && /* @__PURE__ */ jsx("p", { className: "or-source-note", children: /* @__PURE__ */ jsx("strong", { children: d.document_type === "zero_rated_invoice" ? "Zero-Rated Sale" : reg?.non_vat_basis === "exempt" ? "EXEMPT" : "VAT-Exempt Sale" }) }),
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
          item.tax_type && /* @__PURE__ */ jsx("small", { children: { vatable: "VATable", zero_rated: "Zero-Rated Sale", vat_exempt: "VAT-Exempt Sale", non_vat: "Non-VAT Sale" }[item.tax_type] || item.tax_type }),
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
    !payment && /* @__PURE__ */ jsx("section", { className: "or-tax-breakdown", "aria-label": "Recorded tax breakdown", children: [...d.sspt_sales != null && d.vat_registered === false ? [[reg?.non_vat_basis === "exempt" ? "Exempt Non-VAT Sales" : "Sales Subject to Percentage Tax (SSPT)", d.sspt_sales]] : [], ["VATable Sales", d.vatable], ["VAT Amount", d.vat], ["VAT-Exempt Sales", d.vat_exempt], ["Zero-Rated Sales", d.zero_rated]].map(([label, value]) => /* @__PURE__ */ jsxs("div", { children: [
      label,
      /* @__PURE__ */ jsx("strong", { children: fmt(value) })
    ] }, String(label))) }),
    !payment && d.withholding_details && /* @__PURE__ */ jsxs("p", { className: "or-source-note", children: [
      "CWT \xB7 ATC: ",
      d.withholding_details.atc || "Not recorded",
      " \xB7 Rate: ",
      d.withholding_details.rate ?? "Not recorded",
      "% \xB7 Tax base: ",
      fmt(d.withholding_details.base == null ? null : Number(d.withholding_details.base)),
      " \xB7 Buyer / payor: ",
      d.withholding_details.classification || "Not recorded"
    ] }),
    d.number_validation_error && /* @__PURE__ */ jsxs("p", { className: "or-source-notice", children: [
      "Number requires review: ",
      d.number_validation_error
    ] }),
    d.financial_validation_passed === false && /* @__PURE__ */ jsx("p", { className: "or-source-notice or-financial-warning", children: "Source amounts or line items need reconciliation. Values shown are preserved originals and have not been silently corrected." }),
    d.settlement_validation_passed === false && /* @__PURE__ */ jsx("p", { className: "or-source-notice or-financial-warning", children: "Recorded balance does not reconcile with the source total, withholding and settlements. Review original payment evidence; no paid status has been inferred." }),
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
  let content = renderToStaticMarkup(/* @__PURE__ */ jsx2("div", { className: "merchant-electronic-preview", children: /* @__PURE__ */ jsx2(OfficialReceipt, { document }) }));
  content = content.replace(/<link\b[^>]*>/g, "");
  const assets = ["/brand/nuers-official-logo.png", "/invoice-assets/receipt-reference.png"];
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
    @media print{html{width:${pageWidth}mm}body{width:900px;zoom:${zoom}}.merchant-electronic-preview{overflow:visible}.official-receipt{overflow:visible!important}.or-header,.or-parties,.or-buyer,.or-tax-warning,.or-source-notice,.or-financials,.or-tax-breakdown,.or-source-note,.or-footer,.or-items tr{break-inside:avoid}.or-items thead{display:table-header-group}.official-receipt:not(.or-custom) .or-header{height:9em}.official-receipt:not(.or-custom) .or-logo{height:8em;margin-top:.7em}.official-receipt:not(.or-custom) .or-footer{min-height:6em}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
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
