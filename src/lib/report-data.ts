export type ReportTable = { headers: string[]; rows: string[][] };
export const PDF_RECORD_LIMIT = 500;
export const PDF_BYTE_LIMIT = 8 * 1024 * 1024;

/** RFC 4180 parser: quoted commas, embedded newlines, escaped quotes and split UTF-8 chunks. */
export class CsvReportParser {
  private cell = ''; private row: string[] = []; private quoted = false; private afterQuote = false; private cr = false;
  readonly records: string[][] = [];
  private limit: number;
  constructor(limit = PDF_RECORD_LIMIT + 1) { this.limit = limit; }
  private endCell() { this.row.push(this.cell); this.cell = ''; }
  private endRow() {
    this.endCell();
    if (this.row.some(v => v !== '')) this.records.push(this.row);
    this.row = [];
    if (this.records.length > this.limit) throw new Error(`PDF supports up to ${PDF_RECORD_LIMIT} records. Narrow the filters or choose CSV for the full export. No partial PDF was created.`);
  }
  push(chunk: string) {
    for (const c of chunk) {
      if (this.cr) { this.cr = false; if (c === '\n') continue; }
      if (this.quoted) {
        if (this.afterQuote) {
          if (c === '"') { this.cell += '"'; this.afterQuote = false; continue; }
          this.quoted = false; this.afterQuote = false;
          if (c !== ',' && c !== '\r' && c !== '\n') throw new Error('The CSV export contains an invalid quoted field.');
        } else { if (c === '"') this.afterQuote = true; else this.cell += c; continue; }
      }
      if (c === '"' && !this.cell) this.quoted = true;
      else if (c === ',') this.endCell();
      else if (c === '\n' || c === '\r') { this.endRow(); this.cr = c === '\r'; }
      else if (c !== '\uFEFF' || this.records.length || this.row.length || this.cell) this.cell += c;
    }
  }
  finish(): ReportTable {
    if (this.quoted && !this.afterQuote) throw new Error('The CSV export ended inside a quoted field.');
    if (this.cell || this.row.length) this.endRow();
    const [headers = [], ...rows] = this.records;
    if (!headers.length) throw new Error('The export returned no column headings.');
    if (rows.some(row => row.length !== headers.length)) throw new Error('The export has inconsistent columns. Use CSV to inspect the source report.');
    return { headers, rows };
  }
}

export function csvText(table: ReportTable): string {
  const cell = (v: string) => '"' + (/^[\s\x00-\x1f]*[=+@-]/u.test(v) ? "'" + v : v).replaceAll('"', '""') + '"';
  return '\uFEFF' + [table.headers, ...table.rows].map(row => row.map(cell).join(',')).join('\r\n');
}
export function reportLabel(value: string): string {
  return value.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase()).replace(/\b(Php|Vat|Tin|Rdo|Bir|Api|Id|Mfa|Cpu)\b/g, c => c.toUpperCase());
}

/** Structured evidence becomes explicit paths; no nested field is silently discarded. */
export function objectReport(value: unknown): ReportTable {
  const rows: string[][] = [];
  const walk = (v: unknown, path: string) => {
    if (v && typeof v === 'object' && Object.keys(v).length) Object.entries(v).forEach(([key, x]) => walk(x, path ? `${path}.${key}` : key));
    else rows.push([path, v === null || v === undefined ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)]);
  };
  walk(value, ''); return { headers: ['Evidence field / record path', 'Recorded value'], rows };
}
