// src/lib/report-data.ts
var PDF_RECORD_LIMIT = 500;
var PDF_BYTE_LIMIT = 8 * 1024 * 1024;
var CsvReportParser = class {
  cell = "";
  row = [];
  quoted = false;
  afterQuote = false;
  cr = false;
  records = [];
  limit;
  constructor(limit = PDF_RECORD_LIMIT + 1) {
    this.limit = limit;
  }
  endCell() {
    this.row.push(this.cell);
    this.cell = "";
  }
  endRow() {
    this.endCell();
    if (this.row.some((v) => v !== "")) this.records.push(this.row);
    this.row = [];
    if (this.records.length > this.limit) throw new Error(`PDF supports up to ${PDF_RECORD_LIMIT} records. Narrow the filters or choose CSV for the full export. No partial PDF was created.`);
  }
  push(chunk) {
    for (const c of chunk) {
      if (this.cr) {
        this.cr = false;
        if (c === "\n") continue;
      }
      if (this.quoted) {
        if (this.afterQuote) {
          if (c === '"') {
            this.cell += '"';
            this.afterQuote = false;
            continue;
          }
          this.quoted = false;
          this.afterQuote = false;
          if (c !== "," && c !== "\r" && c !== "\n") throw new Error("The CSV export contains an invalid quoted field.");
        } else {
          if (c === '"') this.afterQuote = true;
          else this.cell += c;
          continue;
        }
      }
      if (c === '"' && !this.cell) this.quoted = true;
      else if (c === ",") this.endCell();
      else if (c === "\n" || c === "\r") {
        this.endRow();
        this.cr = c === "\r";
      } else if (c !== "\uFEFF" || this.records.length || this.row.length || this.cell) this.cell += c;
    }
  }
  finish() {
    if (this.quoted && !this.afterQuote) throw new Error("The CSV export ended inside a quoted field.");
    if (this.cell || this.row.length) this.endRow();
    const [headers = [], ...rows] = this.records;
    if (!headers.length) throw new Error("The export returned no column headings.");
    if (rows.some((row) => row.length !== headers.length)) throw new Error("The export has inconsistent columns. Use CSV to inspect the source report.");
    return { headers, rows };
  }
};
function csvText(table) {
  const cell = (v) => '"' + (/^[\s\x00-\x1f]*[=+@-]/u.test(v) ? "'" + v : v).replaceAll('"', '""') + '"';
  return "\uFEFF" + [table.headers, ...table.rows].map((row) => row.map(cell).join(",")).join("\r\n");
}
function reportLabel(value) {
  return value.replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase()).replace(/\b(Php|Vat|Tin|Rdo|Bir|Api|Id|Mfa|Cpu)\b/g, (c) => c.toUpperCase());
}
function objectReport(value) {
  const rows = [];
  const walk = (v, path) => {
    if (v && typeof v === "object" && Object.keys(v).length) Object.entries(v).forEach(([key, x]) => walk(x, path ? `${path}.${key}` : key));
    else rows.push([path, v === null || v === void 0 ? "" : typeof v === "object" ? JSON.stringify(v) : String(v)]);
  };
  walk(value, "");
  return { headers: ["Evidence field / record path", "Recorded value"], rows };
}
export {
  CsvReportParser,
  PDF_BYTE_LIMIT,
  PDF_RECORD_LIMIT,
  csvText,
  objectReport,
  reportLabel
};
