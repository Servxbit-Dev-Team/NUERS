import { OfficialReceipt } from './official-receipt';
import { previewDocument, type PreviewInput } from '@/lib/document-presentation';
export type { PreviewParty as BirDocumentParty, PreviewItem as BirDocumentItem, PreviewTotals as BirDocumentTotals } from '@/lib/document-presentation';
/** Retained import name for callers; renders the shared customer-readable E-Receipt layout. */
export function BirDocumentPreview(props:PreviewInput) {
 return <div className={`merchant-electronic-preview ${props.className||''}`}><OfficialReceipt document={previewDocument(props)}/></div>;
}
