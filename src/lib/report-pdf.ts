import { jsPDF } from "jspdf";
import { PDF_RECORD_LIMIT, reportLabel, type ReportTable } from "./report-data";

export type ReportInfo = {
  title: string;
  scope: string;
  filters?: Record<string, unknown>;
  generatedAt?: string;
  note?: string;
};
export type ReportAssets = { logo: string; regular: string; bold: string };
const navy = "#082750",
  blue = "#0056ff",
  muted = "#536b8a",
  pale = "#eef5ff";

/** Text-based, searchable PDF. Wide source rows continue vertically without dropping columns. */
export function renderReportPdf(table: ReportTable, info: ReportInfo, assets: ReportAssets): jsPDF {
  if (table.rows.length > PDF_RECORD_LIMIT)
    throw new Error(`Narrow the report to ${PDF_RECORD_LIMIT} records or choose CSV.`);
  const pdf = new jsPDF({ unit: "mm", format: "a4", compress: true });
  pdf.addFileToVFS("NUERS-Regular.ttf", assets.regular);
  pdf.addFont("NUERS-Regular.ttf", "NUERS", "normal");
  pdf.addFileToVFS("NUERS-Bold.ttf", assets.bold);
  pdf.addFont("NUERS-Bold.ttf", "NUERS", "bold");
  pdf.setProperties({
    title: info.title,
    author: "NUERS",
    subject: info.scope,
    creator: "NUERS 1.0.0",
  });
  const generated =
    info.generatedAt ||
    new Intl.DateTimeFormat("en-PH", {
      timeZone: "Asia/Manila",
      dateStyle: "medium",
      timeStyle: "long",
    }).format(new Date());
  const id = "RPT-" + crypto.randomUUID().slice(0, 8).toUpperCase();
  let y = 47;
  const text = (value: string, x: number, at: number, size = 9, bold = false, color = navy) => {
    pdf.setFont("NUERS", bold ? "bold" : "normal");
    pdf.setFontSize(size);
    pdf.setTextColor(color);
    pdf.text(value.replaceAll("₱", "PHP "), x, at);
  };
  const lines = (value: string, width: number, size = 9): string[] => {
    pdf.setFont("NUERS", "normal");
    pdf.setFontSize(size);
    return pdf.splitTextToSize((value || "Not recorded").replaceAll("₱", "PHP "), width);
  };
  function header() {
    pdf.setFillColor(navy);
    pdf.rect(0, 0, 210, 28, "F");
    pdf.setFillColor("#ffffff");
    pdf.roundedRect(11, 3, 50, 22, 2, 2, "F");
    pdf.addImage(assets.logo, "PNG", 13, 5, 45, 17, undefined, "FAST");
    text("REPORTS & RECORDS", 127, 11, 10, true, "#ffffff");
    text("NUERS · Version 1.0.0", 127, 18, 8, false, "#d9e7ff");
    for (const [i, color] of ["#00b8ff", "#ffc400", "#ec1536"].entries()) {
      pdf.setFillColor(color);
      pdf.rect(13 + i * 61.3, 29, 61.3, 1, "F");
    }
    text(id, 13, 36, 8, true, muted);
    text("CONFIDENTIAL · AUTHORIZED USE", 128, 36, 7, false, muted);
  }
  function next() {
    pdf.addPage();
    header();
    y = 46;
  }
  function paragraph(value: string, size = 9, color = muted) {
    for (const line of lines(value, 184, size)) {
      if (y > 276) next();
      text(line, 13, y, size, false, color);
      y += size * 0.46;
    }
    y += 3;
  }
  function heading(value: string) {
    if (y > 260) next();
    text(value, 13, y, 12, true);
    y += 7;
  }
  function pair(label: string, value: string) {
    const labels = lines(label, 55, 8),
      values = lines(value, 120, 9);
    const n = Math.max(labels.length, values.length);
    for (let i = 0; i < n; i++) {
      if (y > 275) {
        next();
        text("Record details · continued", 13, y, 10, true);
        y += 6;
      }
      text(labels[i] || "", 15, y, 8, true, muted);
      text(values[i] || "", 75, y, 9);
      y += 4.4;
    }
    pdf.setDrawColor("#dfE8f4");
    pdf.line(13, y - 2, 197, y - 2);
    y += 2;
  }
  function displayValue(header: string, value: string) {
    if (!value) return "Not recorded";
    if (/\bPHP\b|₱/i.test(header)) {
      const numeric = value.replace(/^'(?=-\d)/, "").replace(/,/g, "");
      if (/^-?\d+(\.\d+)?$/.test(numeric))
        return (
          "PHP " +
          Number(numeric).toLocaleString("en-PH", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
          })
        );
    }
    return value;
  }
  header();
  for (const line of lines(info.title, 184, 21)) {
    text(line, 13, y, 21, true);
    y += 9;
  }
  paragraph(info.scope, 10);
  pdf.setFillColor(pale);
  pdf.roundedRect(13, y, 184, 21, 2, 2, "F");
  text("EXPORTED ROWS", 18, y + 6, 8, true, muted);
  text(table.rows.length.toLocaleString("en-PH"), 18, y + 15, 18, true, blue);
  text("SOURCE FIELDS", 82, y + 6, 8, true, muted);
  text(String(table.headers.length), 82, y + 15, 18, true, blue);
  text("CURRENCY / TIME", 140, y + 6, 8, true, muted);
  text("PHP / PHT", 140, y + 15, 11, true);
  y += 30;
  heading("Report scope & generation");
  pair("Generated (Asia/Manila)", generated);
  pair("Software", "National Unified Electronic Receipt System · Version 1.0.0");
  pair("Coverage", info.scope);
  heading("Applied filters");
  const filters = Object.entries(info.filters || {}).filter(
    ([k, v]) =>
      !["page", "per_page", "format", "download"].includes(k) &&
      v !== "" &&
      v !== null &&
      v !== undefined &&
      v !== "all",
  );
  if (!filters.length)
    paragraph("No additional filters. The export follows the authorized scope stated above.");
  else
    filters.forEach(([k, v]) => pair(reportLabel(k), Array.isArray(v) ? v.join(", ") : String(v)));
  // Only explicitly monetary columns are summed; running balances, rates and IDs never become totals.
  const sums = table.headers
    .map((h, i) => ({ h, i }))
    .filter(({ h }) => /\bPHP\b|\(₱\)/i.test(h) && !/balance|rate|price|average/i.test(h))
    .map(({ h, i }) => {
      let cents = 0,
        available = 0;
      for (const row of table.rows) {
        const v = row[i]?.replace(/^'(?=-\d)/, "").replace(/,/g, "");
        if (v !== "" && /^-?\d+(\.\d+)?$/.test(v)) {
          cents += Math.round(Number(v) * 100);
          available++;
        }
      }
      return { h, cents, available };
    })
    .filter((x) => x.available);
  if (sums.length) {
    heading("Recorded monetary totals");
    sums.forEach((s) =>
      pair(
        reportLabel(s.h),
        `₱${(s.cents / 100).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} · ${s.available} recorded values`,
      ),
    );
  }
  heading("Reading this report");
  paragraph(
    info.note ||
      "This is an internal NUERS report of the exported records. Recorded totals are not a tax return or proof of BIR acceptance. Blank fields are marked “Not recorded”. Monetary totals include only the displayed source columns and must not be added across different financial measures.",
  );
  if (!table.rows.length) {
    heading("No matching records");
    paragraph("The selected scope and filters returned no records. No records were omitted.");
  }
  table.rows.forEach((row, index) => {
    if (index === 0 || y > 237) next();
    pdf.setFillColor(pale);
    pdf.rect(13, y - 4, 184, 9, "F");
    text(`RECORD ${index + 1} OF ${table.rows.length}`, 16, y + 2, 10, true, blue);
    y += 12;
    table.headers.forEach((h, i) => pair(reportLabel(h), displayValue(h, row[i])));
    y += 6;
  });
  const pages = pdf.getNumberOfPages();
  for (let i = 1; i <= pages; i++) {
    pdf.setPage(i);
    pdf.setDrawColor("#dce7f4");
    pdf.line(13, 282, 197, 282);
    text("NUERS · " + id, 13, 288, 7, false, muted);
    text(`Page ${i} of ${pages}`, 174, 288, 7, false, muted);
  }
  return pdf;
}
