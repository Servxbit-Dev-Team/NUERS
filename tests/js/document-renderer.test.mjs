import {test} from 'node:test';
import assert from 'node:assert/strict';
import {renderDocumentHtml} from '../../bootstrap/ssr/document-renderer.mjs';
import {previewDocument} from '../../src/lib/document-presentation.ts';

const source=previewDocument({documentType:'vat_exempt_invoice',documentNumber:'SOURCE-1',issueDate:'2026-10-17',seller:{name:'Original seller',tin:'123456789',vatRegistered:true},buyer:{name:'Original customer'},items:[{description:'Recorded service',quantity:1,unitPrice:100000,amount:100000}],totals:{gross:100000,vatExemptSales:100000,vatAmount:0,discount:0,withholdingTax:0,otherCharges:0,totalDue:100000},status:'issued'});

test('server PDF uses the preview ribbon, watermark, words, skyline and source metadata',async()=>{
 const html=await renderDocumentHtml({...source,record_status:'issued'});
 for(const visible of ['ELECTRONIC INVOICE','Original seller','Original customer','SOURCE-1','ONE THOUSAND PESOS ONLY','1,000.00','or-sun','or-thanks','or-skyline','or-footer'])assert.ok(html.includes(visible),visible);
 assert.equal(html.includes('ORIGINAL SOURCE REPRESENTATION'),false);
 assert.equal(html.includes('DRAFT — NOT AN ISSUED DOCUMENT'),false);
 assert.match(html,/class="merchant-electronic-preview"/);
 assert.match(html,/img-src data:/);
 assert.equal((html.match(/<img /g)||[]).length,2);
 assert.equal((html.match(/src="data:image\/png;base64,/g)||[]).length,2);
 assert.equal(html.includes('<link'),false);
 assert.equal(/powered\s*by|ok-technology-logo/i.test(html),false);
});

test('source text is escaped and cannot add HTML, scripts, external images or resource preloads',async()=>{
 const html=await renderDocumentHtml({...source,record_status:'draft',business:'<script>location="https://example.test"</script>',buyer_name:'<img src="https://example.test/a">',number:'<COPY & "SOURCE">'});
 assert.equal(html.includes('<script>'),false);assert.equal(html.includes('src="https://'),false);assert.equal(html.includes('<link'),false);
 assert.ok(html.includes('&lt;script&gt;'));assert.ok(html.includes('&lt;img'));assert.ok(html.includes('&lt;COPY &amp; &quot;SOURCE&quot;&gt;'));
 assert.ok(html.includes('DRAFT — NOT AN ISSUED DOCUMENT'));
});

test('an available verification QR is embedded without fetching the destination',async()=>{
 const html=await renderDocumentHtml({...source,verification_url:'https://example.test/controlled-lookup'});
 assert.equal((html.match(/src="data:image\/png;base64,/g)||[]).length,3);
 assert.ok(html.includes('NUERS record lookup QR'));assert.equal(html.includes('href="https://example.test'),false);
});

test('dedicated receipt logo is embedded locally and never fetched from a remote path',async()=>{
 const {mkdirSync,writeFileSync,unlinkSync}=await import('node:fs');
 const path='public/uploads/invoice-logos/aaaaaaaa-eeee-cccc-dddd-bbbbbbbbbbbb.png';
 const pixel='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII=';
 mkdirSync('public/uploads/invoice-logos',{recursive:true});writeFileSync(path,Buffer.from(pixel,'base64'));
 const presentation={show_logo:true,logo_url:'/public/uploads/invoice-logos/aaaaaaaa-eeee-cccc-dddd-bbbbbbbbbbbb.png',font_family:'Inter',invoice_template:'standard',receipt_template:'minimal',primary_color:'#0056d6',secondary_color:'#0f2d5b'};
 try{
  const html=await renderDocumentHtml({...source,presentation});
  assert.ok(html.includes('src="data:image/png;base64,'+pixel+'"'));
  assert.ok(!html.includes('src="/public/uploads/'));
  const unsafe=await renderDocumentHtml({...source,presentation:{...presentation,logo_url:'https://example.test/logo.png'}});
  assert.ok(!unsafe.includes('src="https://'));
  const hidden=await renderDocumentHtml({...source,presentation:{...presentation,show_logo:false}});
  assert.ok(!hidden.includes(pixel));
 }finally{unlinkSync(path);}
});
