import {exportReport as openReportExport} from "@/components/reports/export-report";
import {SALE_CATEGORIES} from '@/lib/statutory-sales';
import { formatTin } from "@/lib/tin";
import { useEffect, useState, type FormEvent } from "react";
import { Download, LoaderCircle } from "lucide-react";

import { apiJson } from "@/lib/api-url";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { SearchableSelect } from "@/components/registration/searchable-select";

type Business = { id: string; business_name: string; tin: string | null };
type Props = { open: boolean; onClose: () => void; from: string; to: string; type: string; status: string; saleCategory?:string;search?:string };

export function ExportDocuments({ open, onClose, from, to, type, status, saleCategory="all", search="" }: Props) {
  const [sale,setSale]=useState(saleCategory);
  const [dateFrom, setDateFrom] = useState(from);
  const [dateTo, setDateTo] = useState(to);
  const [business, setBusiness] = useState("");
  const [category, setCategory] = useState(type);
  const [recordStatus, setRecordStatus] = useState(status);
  const [businesses, setBusinesses] = useState<Business[]>([]);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [retry, setRetry] = useState(0);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    if (!open) return;
    setSale(saleCategory); setDateFrom(from); setDateTo(to); setCategory(type); setRecordStatus(status); setBusiness(""); setError("");
  }, [open, from, to, type, status, saleCategory]);
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    setLoading(true); setLoadError(""); setBusinesses([]);
    apiJson<{ businesses: Business[] }>("/api/invoice-center/businesses", { signal: controller.signal })
      .then(data => setBusinesses(data.businesses))
      .catch(e => { if (e.name !== "AbortError") setLoadError(e.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [open, retry]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (exporting || loading || loadError) return;
    if (dateFrom && dateTo && dateFrom > dateTo) { setError("Date to must be on or after Date from."); return; }
    setExporting(true); setError("");
    try {
      const query = new URLSearchParams({ sale_category:sale, search, type: category, status: recordStatus, ...(dateFrom ? { from: dateFrom } : {}), ...(dateTo ? { to: dateTo } : {}), ...(business ? { merchant_id: business } : {}) });
      onClose();
      await openReportExport({path:'/api/invoice-center/export?'+query,title:'Electronic Invoices & Receipts',scope:'All matching authorized Live document records across every page',filters:Object.fromEntries(query)});
      onClose();
    } catch (e) { setError(e instanceof Error ? e.message : "Unable to export documents."); }
    finally { setExporting(false); }
  }

  return <Dialog open={open} onOpenChange={value => { if (!value && !exporting) onClose(); }}><DialogContent className="ic-create-dialog ic-filter-export-dialog"><DialogHeader><DialogTitle>Export electronic documents</DialogTitle><DialogDescription>Download all matching records across every page. Both dates are inclusive; leave a date blank for an open range.</DialogDescription></DialogHeader>
    <form className="ic-create-form" onSubmit={submit} aria-busy={exporting}>
      <fieldset disabled={exporting} className="ic-export-fields"><div className="ic-form-grid">
        <label>Date from<input type="date" aria-label="Export date from" value={dateFrom} max={dateTo || undefined} onChange={e => setDateFrom(e.target.value)} /></label>
        <label>Date to<input type="date" aria-label="Export date to" value={dateTo} min={dateFrom || undefined} onChange={e => setDateTo(e.target.value)} /></label>
      </div>
      <div className="ic-export-business"><SearchableSelect label="Business name / TIN" value={business} onChange={setBusiness} required={false} loading={loading} disabled={exporting || !!loadError} items={[{ value: "", label: "All registered businesses" }, ...businesses.map(b => ({ value: b.id, label: `${b.business_name} · TIN: ${formatTin(b.tin) || "Not recorded"}` }))]} placeholder="All registered businesses" searchPlaceholder="Search business name or TIN…" searchLabel="Search export businesses by name or TIN" catalogLabel={`${businesses.length} registered businesses`} />
        {loadError && <p role="alert" className="ic-form-error">{loadError} <button type="button" onClick={() => setRetry(v => v + 1)}>Retry business list</button></p>}
      </div>
      <label>Sale category<select value={sale} onChange={e=>setSale(e.target.value)}><option value="all">All sale categories</option>{Object.entries(SALE_CATEGORIES).map(([k,l])=><option key={k} value={k}>{l}</option>)}</select></label><div className="ic-form-grid"><label>Document type<select value={category} onChange={e => setCategory(e.target.value)}><option value="all">All document types</option><option value="invoices">Sales Invoices</option><option value="receipts">Official Receipts</option><option value="notes">Credit/Debit Notes</option><option value="cancelled">Cancelled/Voided</option></select></label><label>Status<select value={recordStatus} onChange={e => setRecordStatus(e.target.value)}><option value="all">All statuses</option><option value="valid">Valid records</option><option value="pending">Pending / Draft</option><option value="cancelled">Cancelled / Voided</option></select></label></div></fieldset>
      {error && <p role="alert" className="ic-form-error">{error}</p>}
      <div className="ic-modal-actions"><button type="button" disabled={exporting} onClick={onClose}>Cancel</button><button type="submit" className="ic-primary" disabled={exporting || loading || !!loadError}>{exporting ? <LoaderCircle className="ic-spin" /> : <Download />}{exporting ? "Exporting…" : "Choose PDF / CSV"}</button></div>
    </form>
  </DialogContent></Dialog>;
}
