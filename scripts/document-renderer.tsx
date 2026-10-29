import { renderToStaticMarkup } from 'react-dom/server';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { OfficialReceipt, type CenterDocument } from '../src/components/invoices/official-receipt';

/** Reads only a server-authorized DTO. No client HTML or asset path is accepted. */
export async function renderDocumentHtml(document: CenterDocument): Promise<string> {
  const styles = readFileSync(path.resolve('src/components/invoices/electronic-document.css'), 'utf8');
  let content = renderToStaticMarkup(<div className="merchant-electronic-preview"><OfficialReceipt document={document} /></div>);
  // React's optional image preloads are unnecessary because every image is embedded below.
  content = content.replace(/<link\b[^>]*>/g, '');
  // Asset embedding avoids remote requests, expiring URLs and local file access in Chromium.
  const assets = ['/brand/nuers-official-logo.png', '/invoice-assets/receipt-reference.png'];
  const brand=document.presentation?.show_logo?document.presentation.logo_url:null;
  if(brand&&/^\/(?:public\/)?uploads\/(?:business-logos|invoice-logos)\/[a-f0-9-]+\.png$/i.test(brand))assets.push(brand);
  for (const asset of assets) {
    let data='';try{data=`data:image/png;base64,${readFileSync(path.resolve(asset.startsWith('/public/')?asset.slice(1):'public'+asset)).toString('base64')}`}catch{if(asset===brand){content=content.replaceAll(`src="${asset}"`,'alt="Business logo unavailable"');continue;}throw new Error('Required document asset unavailable');}
    content = content.replaceAll(`src="${asset}"`, `src="${data}"`);
  }
  const payment=['payment_receipt','official_receipt'].includes(document.document_type);
  const paper=(payment?document.presentation?.receipt_paper:document.presentation?.invoice_paper)==='Letter'?'Letter':'A4';
  const pageWidth=paper==='Letter'?206:200;
  const zoom=pageWidth*96/25.4/900;
  const title = document.number.replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]!);
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'"><title>${title}</title><style>
    *,::before,::after{box-sizing:border-box}html,body{margin:0;padding:0}body{width:900px}img,svg{display:block;vertical-align:middle}img{max-width:100%;height:auto}h1,h2,h3,p{margin:0}table{border-color:inherit;text-indent:0}strong{font-weight:bolder}
    ${styles}
    @page{size:${paper};margin:5mm}
    @media print{html{width:${pageWidth}mm}body{width:900px;zoom:${zoom}}.merchant-electronic-preview{overflow:visible}.official-receipt{overflow:visible!important}.or-header,.or-parties,.or-buyer,.or-tax-warning,.or-source-notice,.or-financials,.or-tax-breakdown,.or-source-note,.or-footer,.or-items tr{break-inside:avoid}.or-items thead{display:table-header-group}.official-receipt:not(.or-custom) .or-header{height:9em}.official-receipt:not(.or-custom) .or-logo{height:8em;margin-top:.7em}.official-receipt:not(.or-custom) .or-footer{min-height:6em}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
  </style></head><body>${content}</body></html>`;
}

// Keep the application API process boundary narrow: JSON stdin -> escaped static HTML stdout.
if (process.argv[2] === '--stdin') {
  try {
    const chunks: Buffer[] = [];
    for await (const chunk of process.stdin) chunks.push(Buffer.from(chunk));
    const document = JSON.parse(Buffer.concat(chunks).toString('utf8')) as CenterDocument;
    process.stdout.write(await renderDocumentHtml(document));
  } catch {
    process.stderr.write('Could not render the authorized document preview.\n');
    process.exitCode = 1;
  }
}
