import { Archive, ArrowUpRight, BookOpen, Download, FileText } from "lucide-react";
import "./document-downloads.css";

export const documentDownloads = {
  version: "1.0.0",
  published: "29 October 2026",
  pdf: "/guides/NUERS-Principal-and-Supplementary-Documents-v1.0.0.pdf?v=brand-fc40dead1e2c",
  samples: "/guides/NUERS-Document-Sample-Copies-v1.0.0.zip?v=brand-fc40dead1e2c",
};

export function DocumentDownloads({ compact = false }: { compact?: boolean }) {
  const Heading = compact ? "h3" : "h2";
  return <section className={`nuers-downloads${compact ? " nuers-downloads-compact" : ""}`} aria-label="Principal and supplementary document downloads">
    <div className="nuers-downloads-heading">
      <span className="nuers-downloads-icon"><BookOpen size={23} aria-hidden="true" /></span>
      <div><span className="nuers-downloads-eyebrow">DOCUMENT LIBRARY</span><Heading>Principal &amp; Supplementary Documents</Heading></div>
      {!compact && <img src="/brand/nuers-official-logo.png" alt="NUERS" className="nuers-downloads-logo" />}
    </div>
    <p className="nuers-downloads-description">Your guide to NUERS invoices, payment receipts and supporting records, with a document register and 12 sample copies.</p>
    <div className="nuers-downloads-meta"><span>Version {documentDownloads.version}</span><span>Updated {documentDownloads.published}</span></div>
    <div className="nuers-downloads-files">
      <article className="nuers-downloads-file">
        <FileText size={24} aria-hidden="true" />
        <div><h4>Document Register &amp; Sample Copies</h4><p>PDF · 21 pages</p><span>Document types, tax breakdowns, linked examples and reference notes.</span></div>
        <a className="nuers-downloads-button" href={documentDownloads.pdf} download><Download size={16} aria-hidden="true" />Download PDF</a>
      </article>
      <article className="nuers-downloads-file">
        <Archive size={24} aria-hidden="true" />
        <div><h4>Individual Sample Copies</h4><p>ZIP · 12 PDFs</p><span>Separate samples, CSV register and file verification manifest.</span></div>
        <a className="nuers-downloads-button nuers-downloads-secondary" href={documentDownloads.samples} download><Download size={16} aria-hidden="true" />Download Samples (ZIP)</a>
      </article>
    </div>
    <div className="nuers-downloads-footer"><p>Sample documents use fictional data and are marked DRAFT. Compatibility examples are identified in the guide.</p><a href={documentDownloads.pdf} target="_blank" rel="noopener noreferrer">Preview PDF <ArrowUpRight size={15} aria-hidden="true" /><span className="sr-only"> (opens in a new tab)</span></a></div>
  </section>;
}
