import { useEffect, useState } from 'react';
import { Download, FileText, LoaderCircle, RefreshCw } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { OfficialReceipt, type CenterDocument } from '@/components/invoices/official-receipt';
import { apiFetch, apiJson, readJsonResponse } from '@/lib/api-url';
import './merchant-receipt-dialog.css';

export type MerchantReceiptReference = { source: 'invoice' | 'receipt'; id: string; number: string };

export function receiptReference(row: { document_source?: unknown; document_id?: unknown; receipt_number?: unknown }): MerchantReceiptReference | null {
  if (!['invoice', 'receipt'].includes(String(row.document_source)) || typeof row.document_id !== 'string' || !row.document_id) return null;
  return { source: row.document_source as MerchantReceiptReference['source'], id: row.document_id, number: String(row.receipt_number || 'Merchant document') };
}

/** Read the stored merchant document on demand; the table never preloads receipt payloads. */
export function MerchantReceiptDialog({ target, onClose }: { target: MerchantReceiptReference | null; onClose: () => void }) {
  const [document, setDocument] = useState<CenterDocument | null>(null);
  const [error, setError] = useState(''), [pdfError, setPdfError] = useState('');
  const [retry, setRetry] = useState(0), [downloading, setDownloading] = useState(false);
  const path = target ? `/api/invoice-center/${target.source}/${encodeURIComponent(target.id)}` : '';
  useEffect(() => {
    setDocument(null); setError(''); setPdfError('');
    if (!path) return;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 30000);
    let active = true;
    apiJson<{ document: CenterDocument }>(path, { signal: controller.signal, cache: 'no-store' })
      .then(result => { if (active) setDocument(result.document); })
      .catch(e => { if (active) setError(controller.signal.aborted ? 'The receipt request timed out. Please retry.' : e instanceof Error ? e.message : 'Unable to load this receipt.'); })
      .finally(() => window.clearTimeout(timeout));
    return () => { active = false; controller.abort(); window.clearTimeout(timeout); };
  }, [path, retry]);

  async function download() {
    if (!document || downloading) return;
    setDownloading(true); setPdfError('');
    try {
      const response = await apiFetch(`${path}/pdf`);
      if (!response.ok) await readJsonResponse(response, 'Unable to download the merchant receipt.');
      const blob = await response.blob();
      if (!blob.type.includes('application/pdf')) throw new Error('The server did not return a PDF. Please retry.');
      const url = URL.createObjectURL(blob), link = window.document.createElement('a');
      link.href = url; link.download = `${document.number.replace(/[^a-zA-Z0-9_-]/g, '-')}.pdf`;
      window.document.body.appendChild(link); link.click(); link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 30000);
    } catch (e) { setPdfError(e instanceof Error ? e.message : 'PDF download failed. Please retry.'); }
    finally { setDownloading(false); }
  }

  return <Dialog open={!!target} onOpenChange={open => { if (!open) onClose(); }}>
    <DialogContent className="admin-receipt-dialog">
      <DialogHeader className="admin-receipt-heading">
        <DialogTitle><FileText size={21} />Merchant receipt / invoice</DialogTitle>
        <DialogDescription>{document ? `${document.business} · ${document.number}` : target?.number || 'Stored merchant document'}</DialogDescription>
      </DialogHeader>
      <div className="admin-receipt-body" aria-busy={!document && !error}>
        {error ? <div className="admin-receipt-state" role="alert"><p>{error}</p><button onClick={() => setRetry(n => n + 1)}><RefreshCw size={16} />Retry</button></div>
          : document ? <OfficialReceipt document={document} />
            : <div className="admin-receipt-state" role="status"><LoaderCircle className="animate-spin" size={24} /><p>Loading merchant receipt…</p></div>}
      </div>
      <footer className="admin-receipt-footer">
        {pdfError && <p role="alert" className="admin-receipt-error">{pdfError}</p>}
        <span>Merchant branding · recorded line items and totals</span>
        <button type="button" onClick={onClose}>Close preview</button>
        <button type="button" className="admin-receipt-primary" disabled={!document || downloading} onClick={() => void download()}>
          {downloading ? <LoaderCircle className="animate-spin" size={17} /> : <Download size={17} />}{downloading ? 'Preparing PDF…' : 'Download receipt PDF'}
        </button>
      </footer>
    </DialogContent>
  </Dialog>;
}
