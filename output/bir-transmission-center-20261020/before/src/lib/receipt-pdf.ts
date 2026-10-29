export type ReceiptPdfLineItem = {
  description: string;
  quantity: number;
  unitPrice: number;
  total: number;
};

export type ReceiptPdfData = {
  receiptNumber: string;
  reference: string;
  sellerName: string;
  sellerTin?: string | null;
  sellerAddress?: string | null;
  buyerName: string;
  buyerTin?: string | null;
  buyerAddress?: string | null;
  paymentMethod: string;
  paymentDate: string;
  accountNumber?: string | null;
  documentLabel?: string | null;
  rdoBranch?: string | null;
  amount: number;
  vat?: number | null;
  taxLabel?: string | null;
  status?: string | null;
  items: ReceiptPdfLineItem[];
};

export const RECEIPT_TEMPLATE_REFERENCE = "RMC No. 77-2024 Annex A1-B6, sample format B6 Payment Receipt";

const PAGE_WIDTH = 595.28;
const PAGE_HEIGHT = 841.89;
const encoder = new TextEncoder();

type PdfTextOptions = {
  size?: number;
  font?: "F1" | "F2";
  color?: [number, number, number];
};

function yPdf(y: number) {
  return PAGE_HEIGHT - y;
}

function n(value: number) {
  return Number(value.toFixed(2)).toString();
}

function asciiText(value: unknown) {
  return String(value ?? "")
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^\x20-\x7E]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function pdfText(value: unknown) {
  return asciiText(value)
    .replace(/\\/g, "\\\\")
    .replace(/\(/g, "\\(")
    .replace(/\)/g, "\\)");
}

function formatPHP(value: number) {
  return `PHP ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value?: string | null) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return asciiText(value);

  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(date);
}

function wrapText(value: unknown, maxChars: number, maxLines = 2) {
  const words = asciiText(value).split(" ").filter(Boolean);
  const lines: string[] = [];
  let line = "";

  for (const word of words) {
    const candidate = line ? `${line} ${word}` : word;
    if (candidate.length <= maxChars) {
      line = candidate;
      continue;
    }

    if (line) lines.push(line);
    line = word.length > maxChars ? `${word.slice(0, Math.max(0, maxChars - 1))}-` : word;
    if (lines.length >= maxLines) break;
  }

  if (line && lines.length < maxLines) lines.push(line);
  if (!lines.length) lines.push("");

  return lines.slice(0, maxLines);
}

function estimatedWidth(value: string, size: number) {
  return asciiText(value).length * size * 0.52;
}

function buildContentStream(data: ReceiptPdfData) {
  const commands: string[] = [];
  const push = (command: string) => commands.push(command);

  function strokeColor(r = 0, g = 0, b = 0) {
    push(`${n(r)} ${n(g)} ${n(b)} RG`);
  }

  function fillColor(r = 0, g = 0, b = 0) {
    push(`${n(r)} ${n(g)} ${n(b)} rg`);
  }

  function line(x1: number, y1: number, x2: number, y2: number, width = 0.75, color: [number, number, number] = [0, 0, 0]) {
    strokeColor(...color);
    push(`${n(width)} w ${n(x1)} ${n(yPdf(y1))} m ${n(x2)} ${n(yPdf(y2))} l S`);
  }

  function rect(x: number, y: number, width: number, height: number, options: { fill?: [number, number, number]; stroke?: [number, number, number]; lineWidth?: number } = {}) {
    if (options.fill) {
      fillColor(...options.fill);
      push(`${n(x)} ${n(yPdf(y + height))} ${n(width)} ${n(height)} re f`);
    }

    if (options.stroke !== undefined) {
      strokeColor(...options.stroke);
      push(`${n(options.lineWidth ?? 0.75)} w ${n(x)} ${n(yPdf(y + height))} ${n(width)} ${n(height)} re S`);
    }
  }

  function text(x: number, y: number, value: unknown, options: PdfTextOptions = {}) {
    const size = options.size ?? 10;
    const font = options.font ?? "F1";
    const color = options.color ?? [0, 0, 0];
    fillColor(...color);
    push(`BT /${font} ${n(size)} Tf ${n(x)} ${n(yPdf(y))} Td (${pdfText(value)}) Tj ET`);
  }

  function centeredText(y: number, value: unknown, options: PdfTextOptions = {}) {
    const size = options.size ?? 10;
    text((PAGE_WIDTH - estimatedWidth(asciiText(value), size)) / 2, y, value, options);
  }

  function rightText(xRight: number, y: number, value: unknown, options: PdfTextOptions = {}) {
    const size = options.size ?? 10;
    text(xRight - estimatedWidth(asciiText(value), size), y, value, options);
  }

  function multilineText(x: number, y: number, value: unknown, maxChars: number, options: PdfTextOptions & { lineHeight?: number; maxLines?: number } = {}) {
    wrapText(value, maxChars, options.maxLines ?? 2).forEach((lineText, index) => {
      text(x, y + index * (options.lineHeight ?? 12), lineText, options);
    });
  }

  function checkbox(x: number, y: number, checked: boolean) {
    rect(x, y, 9, 9, { stroke: [0, 0, 0], lineWidth: 0.9 });
    if (checked) {
      line(x + 2, y + 5, x + 4, y + 8, 1, [0, 0, 0]);
      line(x + 4, y + 8, x + 8, y + 2, 1, [0, 0, 0]);
    }
  }

  rect(0, 0, PAGE_WIDTH, PAGE_HEIGHT, { fill: [1, 1, 1] });

  const formX = 58;
  const formY = 112;
  const formW = 480;
  const formH = 515;
  const receiptNo = data.receiptNumber || data.reference;
  const paymentMethod = asciiText(data.paymentMethod).toLowerCase();
  const itemDescription = data.items.length
    ? data.items.map((item) => item.description).join("; ")
    : data.documentLabel || "Payment collection";
  const footerDate = formatDate(data.paymentDate);

  text(520, 46, "B6", { font: "F2", size: 16 });
  centeredText(98, "PAYMENT RECEIPT", { font: "F2", size: 16 });

  rect(formX, formY, formW, formH, { stroke: [0, 0, 0], lineWidth: 0.85 });
  rect(formX, formY, formW, 12, { fill: [0.02, 0.16, 0.39] });

  text(formX + 36, formY + 48, "NUERS", { font: "F2", size: 18 });
  multilineText(formX + 112, formY + 45, data.sellerName, 38, { font: "F2", size: 13, maxLines: 2 });
  text(formX + 90, formY + 77, `REG TIN ${data.sellerTin || "TIN pending"}`, { size: 11 });
  multilineText(formX + 90, formY + 94, data.sellerAddress || data.rdoBranch || "Registered business address", 54, { size: 8.5, maxLines: 2, lineHeight: 10 });

  text(formX + 52, formY + 152, "PAYMENT RECEIPT", { font: "F2", size: 18 });
  rightText(formX + formW - 18, formY + 152, `No. ${receiptNo}`, { font: "F2", size: 15, color: [0.86, 0, 0] });

  checkbox(formX + 52, formY + 174, paymentMethod.includes("cash"));
  text(formX + 67, formY + 182, "CASH", { font: "F2", size: 10 });
  checkbox(formX + 52, formY + 188, paymentMethod.includes("card") || paymentMethod.includes("credit"));
  text(formX + 67, formY + 196, "CREDIT CARD", { font: "F2", size: 10 });

  const payBoxX = formX + 295;
  const payBoxY = formY + 173;
  rect(payBoxX, payBoxY, 170, 28, { stroke: [0, 0, 0], lineWidth: 0.75 });
  line(payBoxX, payBoxY + 14, payBoxX + 170, payBoxY + 14);
  line(payBoxX + 72, payBoxY, payBoxX + 72, payBoxY + 28);
  text(payBoxX + 7, payBoxY + 10, "Payment Date:", { font: "F2", size: 9 });
  text(payBoxX + 79, payBoxY + 10, footerDate, { size: 8 });
  text(payBoxX + 7, payBoxY + 24, "Account No.:", { font: "F2", size: 9 });
  text(payBoxX + 79, payBoxY + 24, data.accountNumber || data.reference, { size: 8 });

  const receivedY = formY + 202;
  rect(formX + 30, receivedY, formW - 60, 78, { stroke: [0, 0, 0], lineWidth: 0.75 });
  line(formX + 30, receivedY + 17, formX + formW - 30, receivedY + 17);
  text(formX + 38, receivedY + 13, "RECEIVED FROM:", { font: "F2", size: 13 });
  text(formX + 52, receivedY + 36, "Registered Name", { font: "F2", size: 11 });
  text(formX + 160, receivedY + 36, ":", { font: "F2", size: 11 });
  multilineText(formX + 172, receivedY + 36, data.buyerName || "Client account", 46, { size: 10, maxLines: 1 });
  text(formX + 52, receivedY + 55, "TIN", { font: "F2", size: 11 });
  text(formX + 160, receivedY + 55, ":", { font: "F2", size: 11 });
  text(formX + 172, receivedY + 55, data.buyerTin || "TIN pending", { size: 10 });
  text(formX + 52, receivedY + 73, "Business Address", { font: "F2", size: 11 });
  text(formX + 160, receivedY + 73, ":", { font: "F2", size: 11 });
  multilineText(formX + 172, receivedY + 73, data.buyerAddress || "Client address on file", 44, { size: 8.5, maxLines: 1 });

  const tableY = formY + 300;
  rect(formX + 30, tableY, formW - 60, 92, { stroke: [0, 0, 0], lineWidth: 0.75 });
  rect(formX + 30, tableY, formW - 60, 28, { fill: [0.84, 0.84, 0.84] });
  line(formX + 30, tableY + 28, formX + formW - 30, tableY + 28);
  line(formX + formW - 112, tableY, formX + formW - 112, tableY + 92);
  centeredText(tableY + 18, "Description of Transaction/Nature of Service", { font: "F2", size: 12 });
  text(formX + formW - 76, tableY + 18, "Amount", { font: "F2", size: 12 });
  multilineText(formX + 48, tableY + 52, `Payment for ${itemDescription}`, 48, { size: 10.5, maxLines: 2, lineHeight: 13 });
  rightText(formX + formW - 42, tableY + 52, formatPHP(data.amount), { font: "F2", size: 10.5 });
  line(formX + 30, tableY + 62, formX + formW - 30, tableY + 62);

  text(formX + 42, formY + 430, "\"THIS DOCUMENT IS", { font: "F2", size: 15, color: [0.9, 0, 0] });
  text(formX + 38, formY + 449, "NOT VALID FOR CLAIM", { font: "F2", size: 15, color: [0.9, 0, 0] });
  text(formX + 55, formY + 468, "OF INPUT TAX.\"", { font: "F2", size: 15, color: [0.9, 0, 0] });

  const totalX = formX + 255;
  const totalY = formY + 421;
  rect(totalX, totalY, 225, 64, { stroke: [0, 0, 0], lineWidth: 0.75 });
  line(totalX, totalY + 32, totalX + 225, totalY + 32);
  line(totalX + 140, totalY, totalX + 140, totalY + 32);
  text(totalX + 16, totalY + 21, "TOTAL PAID AMOUNT", { font: "F2", size: 10.5 });
  rightText(totalX + 215, totalY + 21, formatPHP(data.amount), { font: "F2", size: 9.5 });
  text(totalX + 16, totalY + 45, "Invoice Reference No.:", { size: 9 });
  text(totalX + 118, totalY + 45, data.reference, { font: "F2", size: 8 });

  line(formX, formY + formH - 30, formX + formW, formY + formH - 30);
  text(formX + 8, formY + formH - 17, "PERMIT TO USE LOOSE LEAF NO.: NUERS-DIGITAL-2026", { size: 7.5 });
  text(formX + 8, formY + formH - 7, `DATE ISSUED: ${footerDate || "On file"}`, { size: 7.5 });
  text(formX + 258, formY + formH - 17, "BIR AUTHORITY TO PRINT NO.: DIGITAL E-RECEIPT", { size: 7.5 });
  text(formX + 258, formY + formH - 7, `APPROVED SERIES: ${receiptNo} | STATUS: ${data.status || "issued"}`, { size: 7.5 });

  const noteY = formY + formH + 44;
  rect(formX, noteY, formW, 55, { stroke: [0, 0, 0], lineWidth: 0.75 });
  line(formX + 135, noteY, formX + 135, noteY + 55);
  text(formX + 8, noteY + 17, "Sample Format:", { font: "F2", size: 11 });
  text(formX + 160, noteY + 17, "SUPPLEMENTARY INVOICE", { font: "F2", size: 13 });
  text(formX + 8, noteY + 34, "Who will use:", { font: "F2", size: 11 });
  text(formX + 160, noteY + 34, "Both seller of goods, properties or services rendered", { size: 10 });
  text(formX + 8, noteY + 51, "Purpose:", { font: "F2", size: 11 });
  text(formX + 160, noteY + 51, "To record collection of payment or cash receipt", { size: 10 });

  rect(formX, noteY + 75, formW, 57, { stroke: [0, 0, 0], lineWidth: 0.75 });
  multilineText(
    formX + 18,
    noteY + 92,
    `Reminder: This NUERS PDF receipt follows ${RECEIPT_TEMPLATE_REFERENCE}. Accredited printers and digital issuers must preserve the required BIR document information, numbering, and receipt purpose.`,
    82,
    { size: 8.5, maxLines: 3, lineHeight: 11 },
  );

  return commands.join("\n");
}

function buildPdf(objects: string[]) {
  let pdf = "%PDF-1.4\n";
  const offsets: number[] = [];

  objects.forEach((object, index) => {
    offsets.push(encoder.encode(pdf).length);
    pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
  });

  const xrefOffset = encoder.encode(pdf).length;
  pdf += `xref\n0 ${objects.length + 1}\n`;
  pdf += "0000000000 65535 f \n";
  offsets.forEach((offset) => {
    pdf += `${String(offset).padStart(10, "0")} 00000 n \n`;
  });
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;

  return encoder.encode(pdf);
}

export function buildB6ReceiptPdf(data: ReceiptPdfData) {
  const content = buildContentStream(data);
  const contentLength = encoder.encode(content).length;

  return buildPdf([
    "<< /Type /Catalog /Pages 2 0 R >>",
    "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PAGE_WIDTH} ${PAGE_HEIGHT}] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>`,
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>",
    `<< /Length ${contentLength} >>\nstream\n${content}\nendstream`,
  ]);
}

export function downloadB6ReceiptPdf(data: ReceiptPdfData, filenameBase: string) {
  const blob = new Blob([buildB6ReceiptPdf(data)], { type: "application/pdf" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");

  anchor.href = url;
  anchor.download = `${filenameBase}.pdf`;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 100);
}
