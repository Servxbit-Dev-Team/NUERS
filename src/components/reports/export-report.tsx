import { useState } from "react";
import { createRoot } from "react-dom/client";
import { Download, FileText, LoaderCircle } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiFetch, publicAssetUrl, readJsonResponse } from "@/lib/api-url";
import {
  CsvReportParser,
  PDF_BYTE_LIMIT,
  PDF_RECORD_LIMIT,
  csvText,
  objectReport,
  type ReportTable,
} from "@/lib/report-data";
import type { ReportInfo } from "@/lib/report-pdf";
import "./export-report.css";

type Options = ReportInfo & {
  path?: string;
  filename?: string;
  data?: unknown;
  table?: ReportTable;
  initialFormat?: "pdf" | "csv";
};
let active = false;
const base64 = (bytes: Uint8Array) => {
  let s = "";
  for (let i = 0; i < bytes.length; i += 32768)
    s += String.fromCharCode(...bytes.subarray(i, i + 32768));
  return btoa(s);
};
async function asset(path: string) {
  const r = await fetch(publicAssetUrl(path));
  if (!r.ok) throw new Error("NUERS report assets could not load. Refresh the page and retry.");
  return base64(new Uint8Array(await r.arrayBuffer()));
}
function save(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob),
    a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 30000);
}

async function loadTable(response: Response, signal: AbortController): Promise<ReportTable> {
  const type = response.headers.get("Content-Type") || "";
  if (!type.includes("csv"))
    throw new Error("This report did not return CSV. Please refresh and retry.");
  const parser = new CsvReportParser();
  const reader = response.body?.getReader();
  if (!reader) throw new Error("The export response is unavailable.");
  const decoder = new TextDecoder();
  let bytes = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      bytes += value.length;
      if (bytes > PDF_BYTE_LIMIT)
        throw new Error(
          "This PDF contains too much detail. Narrow the filters or download the complete CSV.",
        );
      parser.push(decoder.decode(value, { stream: true }));
    }
    parser.push(decoder.decode());
    return parser.finish();
  } catch (e) {
    await reader.cancel().catch(() => undefined);
    signal.abort();
    throw e;
  } finally {
    reader.releaseLock();
  }
}

/** Uses each page's existing authorized export, never arbitrary tables or browser-visible rows. */
export function exportReport(options: Options): Promise<boolean> {
  if (active) return Promise.resolve(false);
  active = true;
  const host = document.createElement("div");
  document.body.appendChild(host);
  const root = createRoot(host);
  return new Promise((resolve) => {
    const finish = (done: boolean) => {
      active = false;
      setTimeout(() => {
        root.unmount();
        host.remove();
        resolve(done);
      }, 0);
    };
    const filters =
      options.filters ||
      (options.path ? Object.fromEntries(new URLSearchParams(options.path.split("?")[1])) : {});
    root.render(<ReportExportDialog options={{ ...options, filters }} finish={finish} />);
  });
}
function ReportExportDialog({
  options,
  finish,
}: {
  options: Options;
  finish: (done: boolean) => void;
}) {
  const [format, setFormat] = useState<"pdf" | "csv">(options.initialFormat || "pdf"),
    [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  async function run() {
    if (busy) return;
    setBusy(true);
    setError("");
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 120000);
    try {
      let table =
        options.table || (options.data !== undefined ? objectReport(options.data) : undefined);
      let response: Response | undefined;
      if (options.path) {
        const url = new URL(options.path, window.location.origin);
        // Only existing same-origin report endpoints receive the current session credentials.
        if (url.origin !== window.location.origin || !url.pathname.startsWith("/api/"))
          throw new Error("Invalid report source.");
        url.searchParams.set("format", "csv");
        url.searchParams.delete("page");
        url.searchParams.delete("per_page");
        if (format === "pdf") url.searchParams.set("report_limit", String(PDF_RECORD_LIMIT));
        response = await apiFetch(url.pathname + url.search, { signal: controller.signal });
        if (!response.ok) await readJsonResponse(response, "The report could not be exported.");
        if (response.status === 202)
          throw new Error("This report is preparing in Export History. Download it when ready.");
        if (format === "csv") {
          if (!(response.headers.get("Content-Type") || "").includes("csv"))
            throw new Error("The report did not return CSV. Refresh and retry.");
          save(await response.blob(), filename("csv"));
          finish(true);
          return;
        }
        table = await loadTable(response, controller);
      }
      if (!table) throw new Error("Report data is unavailable.");
      if (format === "csv")
        save(new Blob([csvText(table)], { type: "text/csv;charset=utf-8" }), filename("csv"));
      else {
        if (table.rows.length > PDF_RECORD_LIMIT)
          throw new Error(
            `PDF supports up to ${PDF_RECORD_LIMIT} records. Narrow the filters or choose CSV. No partial report was created.`,
          );
        const [{ renderReportPdf }, logo, regular, bold] = await Promise.all([
          import("@/lib/report-pdf"),
          asset("/brand/nuers-official-logo.png"),
          asset("/pdf-assets/standard_fonts/LiberationSans-Regular.ttf"),
          asset("/pdf-assets/standard_fonts/LiberationSans-Bold.ttf"),
        ]);
        const pdf = renderReportPdf(table, options, {
          logo: "data:image/png;base64," + logo,
          regular,
          bold,
        });
        save(pdf.output("blob"), filename("pdf"));
      }
      finish(true);
    } catch (e) {
      setError(
        e instanceof Error
          ? e.name === "AbortError"
            ? "The export timed out. Narrow the filters or retry."
            : e.message
          : "Export failed.",
      );
    } finally {
      clearTimeout(timeout);
      setBusy(false);
    }
  }
  function filename(ext: string) {
    return (
      (options.filename || "NUERS-" + options.title)
        .replace(/\.(csv|json|pdf|xlsx)$/i, "")
        .replace(/[^a-zA-Z0-9._-]/g, "-") +
      "." +
      ext
    );
  }
  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) finish(false);
      }}
    >
      <DialogContent className="nuers-export-dialog">
        <DialogHeader>
          <DialogTitle>Export {options.title}</DialogTitle>
          <DialogDescription>
            Choose the report format. Your current authorized scope and applied filters are
            preserved.
          </DialogDescription>
        </DialogHeader>
        <div className="nuers-export-brand">
          <img src={publicAssetUrl("/brand/nuers-official-logo.png")} alt="NUERS official logo" />
          <div>
            <strong>NUERS Reports & Records</strong>
            <span>Version 1.0.0 · Philippine Standard Time</span>
          </div>
        </div>
        <div className="nuers-export-scope">
          <strong>Export scope</strong>
          <p>{options.scope}</p>
          <small>
            PDF includes record count, applied filters, recorded monetary totals where available,
            all source fields and page numbers.
          </small>
        </div>
        <fieldset disabled={busy}>
          <legend>File format</legend>
          <label className={format === "pdf" ? "selected" : ""}>
            <input
              type="radio"
              name="nuers-report-format"
              checked={format === "pdf"}
              onChange={() => setFormat("pdf")}
            />
            <FileText />
            <span>
              <b>PDF report</b>
              <small>
                NUERS branding · printable, searchable detail · up to {PDF_RECORD_LIMIT} records
              </small>
            </span>
          </label>
          <label className={format === "csv" ? "selected" : ""}>
            <input
              type="radio"
              name="nuers-report-format"
              checked={format === "csv"}
              onChange={() => setFormat("csv")}
            />
            <Download />
            <span>
              <b>CSV data</b>
              <small>Spreadsheet-ready · all matching rows within the report's stated scope</small>
            </span>
          </label>
        </fieldset>
        <p className="nuers-export-note">
          Large PDFs are rejected with a clear message; they are never silently truncated. Original
          invoice PDFs, import templates, recovery keys and migration archives retain their required
          formats.
        </p>
        {error && (
          <p role="alert" className="nuers-export-error">
            {error}
          </p>
        )}
        {busy && <p role="status">Preparing your private export… Keep this dialog open.</p>}
        <footer>
          <button type="button" disabled={busy} onClick={() => finish(false)}>
            Cancel
          </button>
          <button type="button" className="primary" disabled={busy} onClick={() => void run()}>
            {busy ? <LoaderCircle className="animate-spin" /> : <Download />}
            {busy ? "Preparing…" : `Download ${format.toUpperCase()}`}
          </button>
        </footer>
      </DialogContent>
    </Dialog>
  );
}
