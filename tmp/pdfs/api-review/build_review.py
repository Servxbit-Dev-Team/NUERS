from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph, Table, TableStyle, Spacer, Preformatted, Flowable
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.colors import HexColor, white
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.utils import ImageReader
from pathlib import Path
import json, html, hashlib
ROOT=Path('/Users/marksioson/Desktop/NUERS'); OUT=ROOT/'output/pdf'
for n,f in [('Arial','Arial.ttf'),('Arial-Bold','Arial Bold.ttf'),('Mono','Courier New.ttf')]:pdfmetrics.registerFont(TTFont(n,'/System/Library/Fonts/Supplemental/'+f))
N=HexColor('#08274d'); B=HexColor('#0067ff'); T=HexColor('#17355b'); M=HexColor('#607996'); L=HexColor('#dbe6f4'); BG=HexColor('#f3f8ff'); G=HexColor('#008f67'); R=HexColor('#bc3350'); A=HexColor('#9b620b')
W,H=595.28,841.89; X=42; CW=W-84
styles={
 'body':ParagraphStyle('body',fontName='Arial',fontSize=9.6,leading=14.1,textColor=T,spaceAfter=8),
 'small':ParagraphStyle('small',fontName='Arial',fontSize=8.2,leading=11.8,textColor=M,spaceAfter=6),
 'h2':ParagraphStyle('h2',fontName='Arial-Bold',fontSize=13,leading=18,textColor=N,spaceAfter=9,spaceBefore=6),
 'cell':ParagraphStyle('cell',fontName='Arial',fontSize=8.3,leading=11.5,textColor=T),
 'head':ParagraphStyle('head',fontName='Arial-Bold',fontSize=8.3,leading=11.5,textColor=white),
 'code':ParagraphStyle('code',fontName='Mono',fontSize=8,leading=10.4,textColor=T),
 'call':ParagraphStyle('call',fontName='Arial-Bold',fontSize=10.2,leading=15,textColor=N),
}
def P(t,kind='body'):return Paragraph(t,styles[kind])
def table(headers,rows,widths=None):
 if widths: widths=[CW*w/sum(widths) for w in widths]
 else:widths=[CW/len(headers)]*len(headers)
 data=[[P(html.escape(str(v)),'head') for v in headers]]+[[P(str(v),'cell') for v in row] for row in rows]
 t=Table(data,colWidths=widths,hAlign='LEFT')
 t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),N),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),9),('RIGHTPADDING',(0,0),(-1,-1),9),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8),('ROWBACKGROUNDS',(0,1),(-1,-1),[white,BG]),('LINEBELOW',(0,1),(-1,-1),.4,L)]));return t
class Report:
 def __init__(self,file,title): self.path=OUT/file;self.c=canvas.Canvas(str(self.path),pagesize=(W,H));self.c.setTitle(title);self.c.setAuthor('NUERS');self.n=0;self.y=0;self.title=title;self.toc=[];self.metrics=[]
 def page(self,kicker,title,sub='Review edition | 18 October 2026'):
  if self.n:self.c.showPage()
  self.n+=1;c=self.c;c.setFillColor(N);c.rect(0,H-47,W,47,fill=1,stroke=0)
  c.setFont('Arial-Bold',13);c.setFillColor(white);c.drawString(X,H-29,'NUERS')
  c.setFont('Arial',8);c.drawRightString(W-X,H-28,'API INTEGRATION / REVIEW EDITION')
  c.setFillColor(B);c.rect(X,30,55,2,fill=1,stroke=0);c.setFillColor(HexColor('#ffca05'));c.rect(X+55,30,40,2,fill=1,stroke=0);c.setFillColor(HexColor('#ed253c'));c.rect(X+95,30,40,2,fill=1,stroke=0)
  c.setFont('Arial',7.5);c.setFillColor(M);c.drawString(X,18,'NUERS  |  Internal review  |  Application changes pending approval');c.drawRightString(W-X,18,str(self.n))
  c.setFillColor(B);c.setFont('Arial-Bold',8.7);c.drawString(X,H-76,kicker.upper())
  tp=Paragraph(title,ParagraphStyle('title',fontName='Arial-Bold',fontSize=23,leading=27,textColor=N));tw,th=tp.wrap(CW,100);tp.drawOn(c,X,H-93-th);self.y=H-105-th
  self.add(P(sub,'small'));self.y-=7;self.toc.append((self.n,title));
 def add(self,f):
  aw,ah=f.wrap(CW,self.y-50)
  if self.y-ah<51: raise Exception(f'Overflow page {self.n}: {self.y-ah} {str(f)[:120]}')
  f.drawOn(self.c,X,self.y-ah);self.y-=ah;self.metrics.append((self.n,round(self.y,1)));return self
 def text(self,t,kind='body'):return self.add(P(t,kind))
 def h(self,t):return self.text(t,'h2')
 def gap(self,h=8):self.y-=h
 def call(self,title,text,color=BG):
  t=Table([[P(title,'call')],[P(text,'body')]],colWidths=[CW]);t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,-1),color),('BOX',(0,0),(-1,-1),.6,L),('LEFTPADDING',(0,0),(-1,-1),13),('RIGHTPADDING',(0,0),(-1,-1),13),('TOPPADDING',(0,0),(-1,0),12),('BOTTOMPADDING',(0,-1),(-1,-1),6)]));return self.add(t)
 def code(self,t):return self.add(Preformatted(t,styles['code']))
 def flow(self,labels):
  h=88;c=self.c;y=self.y-h;gap=10;bw=(CW-gap*(len(labels)-1))/len(labels)
  for i,(a,b) in enumerate(labels):
   x=X+i*(bw+gap);c.setFillColor(BG);c.setStrokeColor(L);c.roundRect(x,y,bw,h,9,fill=1,stroke=1)
   p=P(a,'call');_,ph=p.wrap(bw-16,50);p.drawOn(c,x+8,y+h-12-ph)
   p=P(b,'small');_,ph=p.wrap(bw-16,55);p.drawOn(c,x+8,y+10)
   if i<len(labels)-1:c.setFillColor(B);c.setFont('Arial-Bold',10);c.drawString(x+bw+2,y+h/2,'>')
  self.y-=h+12
 def save(self):self.c.save();return {'file':str(self.path),'pages':self.n,'minimum_body_y':min(v for _,v in self.metrics)}
S={
 'S1':('RR 7-2024, Section 6(B), pp. 12-14','https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%207-%202024.pdf'),
 'S2':('RMC 77-2024, Q25 and Q28, pp. 9-10','https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2077-2024.pdf'),
 'S3':('RMC 77-2024, official samples A1-A2 / B1-B6','https://orus.bir.gov.ph/files/RMC_No_77_2024_Annex_A1_B6.pdf'),
 'S4':('RR 6-2026, Sections 2, 3 and 6','https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%206-2026.pdf'),
 'S5':('RMC 98-2026, IV.2, IV.11-15','https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf'),
 'S6':('RA 10963, amendments to Sections 106 and 108','https://lawphil.net/statutes/repacts/ra2017/ra_10963_2017.html'),
 'S7':('RMC 66-2025, qualified RBE zero-rating evidence','https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2066-2025.pdf'),
 'S8':('BIR advisory, 11 June 2025, EOE/RBE zero-rating','https://bir-cdn.bir.gov.ph/BIR/pdf/CIR%20Advisory.pdf'),
}
def sources(r,keys):
 for k in keys:
  title,url=S[k];r.text(f'<b>[{k}] {title}</b><br/><link href="{url}" color="#0067ff">Open official source</link>','small')
def evidence(r,paths):
 r.h('Implementation evidence')
 for p,note in paths:r.text('<font name="Mono" size="8">'+html.escape(p)+'</font><br/>'+note,'small')
# AUDIT
r=Report('NUERS-API-Audit-and-Proposed-Changes.pdf','NUERS API audit and proposed changes')
r.page('Decision brief','API integration audit','Evidence date: 18 October 2026 | Read-only application review')
r.call('Review required before implementation','The current implementation has useful safeguards, but cannot yet be signed off as fully working for every activated merchant. Seven findings and the proposed tax design are presented for your review.')
r.gap();r.flow([('63 regression tests','654 assertions passed'),('10 audit tests','34 assertions; six gap observations'),('7 JavaScript tests','Offline recovery checks passed')])
r.h('What was verified')
r.text('Isolated SQLite fixtures exercised active and pending merchants, owner/MFA credential controls, branch and tenant isolation, wallet shortfalls, duplicate retries, atomic batches, IP policy, and the tax calculator. Tests used synthetic records. No live account was activated, no sale was sent, and no application code was edited.')
r.h('What remains unverified')
r.text('Authenticated live intake for an activated merchant, MySQL concurrency under load, real ERP/POS adoption, browser storage recovery across devices, official BIR interoperability, and external webhook delivery. Issuance-authority checks were mocked only in controlled acceptance fixtures; a separate test confirmed the real gate blocks an active merchant without evidence.')
r.h('Review package')
r.add(table(['Pages','Contents'],[['2','Feature-by-feature outcome'],['3-4','Prioritized findings and evidence'],['5','Proposed VAT model and settings'],['6','Source capture, offline recovery and authorization'],['7','Implementation and acceptance plan'],['8','Official sources and audit boundaries']],[1,5]))
r.page('Audit result','Feature readiness after activation')
r.add(table(['Feature','Observed result','Assessment'],[
 ['Merchant activation','Pending submissions and new credential creation return 403. Active status unlocks the next checks.','Pass / conditional'],
 ['Key lifecycle','Owner and fresh MFA; hash storage; one-time reveal; expiry, revocation and IP restrictions. Audit-failure atomicity gap remains.','Partial - A4'],
 ['Single event / batch','Durable acceptance, same-source replay, conflict checks and batch rollback pass under fixtures.','Pass with gates'],
 ['Wallet','402 blocks new eligible financial writes. Exact retry is free. Fee threshold and batch funding tested.','Pass in isolation'],
 ['Tenant / branch access','Foreign merchant and branch reads denied. Same-branch sandbox key can read a live intake result.','Gap - A3'],
 ['VAT and document types','Mixed 12% / exempt / zero-rated arithmetic reconciles. Registration-to-document rules are incomplete.','Gap - A1'],
 ['Customer / CWT fields','Name and TIN accepted; buyer address and supplied withholding are discarded by validation.','Gap - A2'],
 ['Testing Tools','Synthetic contract/maths only; seller registration inferred from header. No full staged API environment.','Partial - A6'],
 ['Manual Retry','Encrypted source queue, matched acknowledgements and branch-specific replay pass JS checks.','Pass / field QA due'],
 ['Webhooks / BIR','Callbacks remain platform-paused; BIR acceptance is explicitly not verified. Polling available.','Unavailable by design'],
 ['Connection guide','Current page downloads .txt. This review includes a detailed PDF; wiring is proposed.','Gap - A7']],[1.25,3.4,1.1]))
r.text('A passing test proves the exercised behavior only. Reproduction tests intentionally assert existing faulty behavior to document a gap; they are not approval of that behavior.','small')
r.page('Findings / priority 1','Tax and data integrity gaps')
r.h('A1 - High | Registration, line tax and invoice family can disagree')
r.text('<b>Reproduced:</b> a VAT seller can calculate and resolve a non_vat_invoice; a Non-VAT seller with vat_exempt lines resolves to vat_exempt_invoice. non_vat and vat_exempt both accumulate into vat_exempt_sales. There is no separate SSPT subtotal.')
r.text('<b>Proposed:</b> use the reviewed seller registration as authoritative; validate every line and select the legal invoice family from that registration plus line treatments. Preserve separate Non-VAT SSPT and exempt categories where applicable. Never use a per-key toggle to rewrite registration.')
r.h('A2 - High | Buyer address and withholding can disappear')
r.text('<b>Reproduced:</b> customer_address, withholding_tax and tax_basis_reference supplied to version 1 are removed by validated(). The calculator is called with withholding zero; total_due equals gross_amount. A payload differing only in these discarded fields has the same canonical hash.')
r.text('<b>Proposed:</b> a reviewed versioned schema must preserve buyer details, CWT amount/basis, invoice total and cash amount due separately. Reject unsupported financial fields instead of silently treating the document as complete. Preserve the original source snapshot and its hash.')
r.call('Regulatory basis','VAT mixed invoices need separate VATable, VAT, exempt and zero-rated totals [S1-S2]. The official B5 sample is a <b>Non-VAT mixed invoice</b>, with SSPT and exempt sales [S3]. RR 6-2026 adds separately shown applicable CWT [S4].')
evidence(r,[('app/Services/IntegrationContract.php','rules(), calculateDocument(), resolveDocument()'),('app/Services/InvoiceCalculator.php','Tax validation and shared non_vat / vat_exempt aggregation'),('app/Http/Controllers/Api/IntegrationTransactionController.php','Validated source snapshot and receipt writes')])
r.page('Findings / priority 1-2','Security and workflow gaps')
r.h('A3 - High | Sandbox key can read production status')
r.text('<b>Reproduced:</b> a sandbox key for the same merchant and branch successfully reads a transaction first accepted with a live key. Write and wallet endpoints enforce environment, but show() does not. This is an environment boundary issue; cross-tenant access remained denied.')
r.text('<b>Proposed:</b> enforce environment on all data paths and persisted resource ownership. Until a truly isolated sandbox exists, sandbox credentials must not read live transactions. Add tests for same-tenant cross-environment reads.')
r.h('A4 - High | Credential changes and audit are not atomic')
r.text('<b>Reproduced:</b> forcing audit() to fail returns 500 after a key has already been inserted. A new active credential remains, while its one-time secret was not returned. Revoke similarly updates before its audit call (source review). Key creation also accepts an unregistered five-digit branch.')
r.text('<b>Proposed:</b> wrap each lifecycle change plus audit in one transaction; validate an eligible registered branch; serialize creation policy checks. Never return a secret until its audited creation commits.')
r.h('A5 - High | External capture is coupled to NUERS issuance')
r.text('<b>Observed:</b> every new API event reserves the next NUERS authorized series number and creates an issued receipt. Even an active merchant with a valid key cannot ingest without the issuance gate. Existing ERP numbers and historical imports may conflict with the enforced prefix plus eight-digit format.')
r.text('<b>Proposed:</b> distinguish an immutable copy of an already-issued external invoice from an invoice being issued by NUERS. Design the storage, evidence and numbering rules before exposing either mode. Keep existing gates until this review is approved; do not bypass authority controls.')
evidence(r,[('app/Http/Controllers/Api/IntegrationTransactionController.php','authenticate(), show(), accept()'),('app/Http/Controllers/Api/MerchantApiKeyController.php','store(), destroy() and branch validation'),('app/Services/InvoiceSequence.php','One current series; strict next-number reservation')])
r.page('Proposed experience','VAT settings that match the seller')
r.call('A6 - Medium | Preview and selection need registration context','Current document selectors list all eight values, and the public preview infers seller VAT registration from the header tax_type. Add an explicit synthetic seller profile in preview, while production always uses the reviewed business profile.')
r.gap();r.add(table(['Setting','Proposed behavior'],[
 ['Seller tax registration','Read-only VAT Registered / Non-VAT from Business Profile. A reviewed effective-date history preserves the classification used by each original.'],
 ['Default line treatment','For VAT sellers: VATable 12%, VAT-Exempt, or Zero-Rated. For Non-VAT sellers: Non-VAT SSPT / exempt as legally applicable. This is a mapping default, not a tax-registration switch.'],
 ['Per-product / line override','Keep tax category per line. Allow a product default, then evaluate customer, transaction, evidence and date. Require a maintained legal-basis reference for exempt/zero-rated use.'],
 ['Mixed invoice','VAT seller: split VATable base, VAT, exempt and zero-rated totals. Non-VAT seller: retain Non-VAT invoice identity and separate SSPT/exempt totals.'],
 ['Unsupported cases','Do not treat a generic discount_pct as a complete senior/PWD or other statutory workflow. Hold unsupported special-tax cases for a reviewed mapping.']],[1.2,3.8]))
r.h('Proposed validation order')
r.text('Authenticate merchant / branch / environment → load reviewed seller registration → validate source schema and line categories → verify evidence and totals → resolve document family → apply relevant capture or issuance rules → atomically persist and debit once.')
r.h('A7 - Medium | Guide is currently text-only')
r.text('Replace both guide download actions with a versioned PDF and keep an accessible HTML companion. Publish only after this draft is reviewed. Include schema version, examples, known limits, change history and source links. The PDF supplied with this review is not yet installed on the live page.')
r.page('Design decision','Capture, issue and recover safely')
r.add(table(['Mode proposed for review','Purpose','Required guardrails'],[
 ['External invoice capture','Store the original legal invoice already issued in the ERP/POS.','Preserve issuer/system, exact original number, date, tax snapshot and linkage. Do not reserve a second legal number or label a second original as issued. Verify source authority appropriate to that system.'],
 ['NUERS invoice issuance','NUERS generates the legal original.','Maintain reviewed authority, branch, system scope and series. Reserve and issue atomically. Enforce wallet admission once.'],
 ['Offline replacement / correction','Recover an authorized manual invoice, or correct an issued one.','Use a distinct linked document and workflow; retain the original. Define separate replacement and correction semantics, rather than modifying accepted JSON.']],[1.3,2,3]))
r.h('Why this requires review')
r.text('The current contract represents finalized events, yet implements a new issuance. A mode change affects legal numbering, immutable source identity, fees, receipts, sales reports and BIR readiness. It must be designed with migrations and backward compatibility, rather than patched with an activation bypass.')
r.h('Current BIR alignment checkpoint')
r.text('RMC 98-2026 separates electronic invoicing from sales reporting; PTI and PTT have distinct triggers. Its downtime provision calls for authorized manual invoices and linked electronic replacements on restoration. It also specifies five mandatory certification tests, or seven with API callback. NUERS models seven accreditation stages as a different process; do not equate those stages with certification tests. Review each gate against the applicable authority [S5].')
r.call('Recommended release boundary','Ship tax, environment and audit-atomicity fixes together with a reviewed capture/issuance contract. Keep callbacks and official BIR transmission visibly unavailable until their own verified implementations are ready. Do not present a funded wallet or active business as BIR authorization.')
r.page('Proposed delivery plan','Changes and acceptance criteria')
r.add(table(['#','Change for review','Acceptance evidence required'],[
 ['1','Freeze contract and source semantics','Approve capture vs issuance, versioned tax fields, numbering, original-document links and fee policy. Existing version-1 accepted events retain replay compatibility.'],
 ['2','Fix registration and tax buckets','VAT/Non-VAT matrix, all permitted mixed cases, tax-inclusive/exclusive totals, cents rounding, discounts, CWT and due amount. Reject invalid seller/document combinations.'],
 ['3','Repair data and security boundaries','Buyer address and supported financial fields survive storage/render/export. Sandbox/live reads isolated. Create/edit/revoke audit failures roll back completely.'],
 ['4','Add readiness and settings UI','Show registration, branch and authority status separately; document selection filtered; explicit synthetic preview profile; guide PDF downloadable from both actions.'],
 ['5','Validate recovery and migration','Lost-response replay, key rotation, all-or-none batches, late failures, insufficient funds, linked replacements and original-source corrections. No replay double charge.'],
 ['6','Staged and live release checks','Isolated MySQL concurrent duplicate/wallet tests; real approved connector and restart recovery; then read-only live verification and approved controlled transaction, if needed.']],[.45,2.1,3.5]))
r.h('Database and deployment constraints')
r.text('Use additive migrations and explicit schema versioning. Historical issued records and accepted request hashes must remain unchanged. Retain old replay decoding for accepted originals; prevent new invalid combinations. Do not introduce automatic tax-status changes, production test sales, account activation or webhook delivery as side effects of deployment.')
r.h('Requested review')
r.text('Review A1-A7 and the two integration modes. The companion guide describes today\'s API separately from the proposed additions. Approval of this design would authorize implementation planning; the current audit has not applied these changes.')
r.page('Evidence and references','Audit scope and official sources')
r.text('Local execution: 63 existing PHP regression tests / 654 assertions; 10 scratch audit tests / 34 assertions; 7 JavaScript outbox tests. The scratch tests include six observations of gaps and four control/calculation checks. First-run fixture issues were corrected before the final passing run. No security gap was fixed by making its observation test pass.')
r.text('Application source compared before and after the audit. Test fixtures use in-memory SQLite and notification dispatch disabled. This is not a penetration test, a load certification, a tax opinion for a particular product, or a certification of the live merchant account.')
sources(r,['S1','S2','S3','S4','S5','S6','S7','S8'])
r.h('Source interpretation')
r.text('The proposed model is an engineering interpretation of the cited rules and current source, not a BIR-approved API schema. Ordinary 12% arithmetic is covered here; product-specific exemptions, zero-rating evidence, special incentives and effective dates require maintained transaction-specific rules. The June 2025 advisory and RMC 66-2025 are relevant qualification sources, not a basis for blanket zero-rating.')
r.text('Reviewed local documentation: docs/integrations/merchant-ingestion-1.md; docs/NUERS_TRANSACTION_API_INTEGRATION.md; docs/knowledge/permits-integrations-and-sessions.md. The guide and legacy wording must be revised together once the model is approved.','small')
report_meta=r.save()
# GUIDE
r=Report('NUERS-API-Connection-Guide-Review-Draft.pdf','NUERS ERP POS and ecommerce API connection guide - review draft')
r.page('Implementation handbook','Connect your system to NUERS','NUERS-ingestion-1 | Review draft v1.0 | 18 October 2026')
r.call('Read this before connecting','This guide documents the current API and clearly labels proposed changes. The audit found tax, data and environment gaps. Use synthetic data in isolated staging until the review findings are resolved; do not treat the examples as authorization to send a live sale.')
r.gap();r.flow([('Your ERP / POS','Finalizes the source and saves its durable outbox'),('NUERS intake','Authenticates, checks funds, validates and records'),('Reconciliation','Matches source identity and stores acceptance IDs')])
r.h('Who this is for')
r.text('Developers integrating ERP, POS and ecommerce backends; merchant administrators configuring account and branch access; operations teams reconciling pending and accepted transactions. No SDK installation automatically connects an external business system.')
r.h('How to use this guide')
r.add(table(['Pages','Topic'],[['2-3','Scope, readiness, credentials and endpoints'],['4-5','Request fields, units and source identity'],['6-8','VAT design, mixed worked examples and supported cases'],['9-11','Version-1 JSON, PHP and Node.js examples'],['12-14','Acknowledgement, wallet, errors and batch recovery'],['15-16','Offline source outbox and manual portal retry'],['17-18','Security, reconciliation, acceptance tests and references']],[.7,4]))
r.text('Legend: CURRENT = implemented contract observed locally. PROPOSED = requires approval and implementation. PENDING = requires real deployment or connector evidence. All examples use synthetic identifiers and contain no real keys.','small')
r.page('Current / readiness','Before the first transaction')
r.add(table(['Check','Owner / action','Current API effect'],[
 ['Business account','Merchant Admin completes Business Profile and administrator review.','Pending or inactive business cannot create keys or submit new events.'],
 ['Seller registration','Review VAT status and relevant tax facts against business registration.','The server reads merchant.vat_registered. Do not derive registration from an API payload.'],
 ['Branch identity','Confirm the exact five-digit BIR branch, preserving leading zeros.','Payload branch_code must equal the key\'s branch.'],
 ['Issuance evidence','Maintain the authority currently required by NUERS and branch/system configuration.','Active status alone does not satisfy assertCanIssue().'],
 ['Legal series','Set up exactly one current supported series per branch / document family.','Source number must be the next prefix + eight-digit number. Missing, expired or exhausted series returns 409.'],
 ['Wallet','Read available balance, fee revision, threshold and intake preference.','New eligible events need sufficient available balance at commit time.'],
 ['Connector readiness','Install and test a server-side source outbox and reconciliation process.','The NUERS page cannot retrieve unsent records from an offline external ERP.']],[1.1,2.4,2.3]))
r.h('Current integration scope')
r.text('The version-1 API persists finalized source events but also creates issued receipt records under the NUERS series. It is not a generic historical archive importer. Arbitrary existing ERP numbering, multiple concurrent legal series per family and backfill require design review; never renumber an issued source merely to pass validation.')
r.call('Proposed readiness panel','Show account activation, registration, branch, capture/issuance mode, authority, series, wallet and connector status separately. An active account should not produce an unexplained generic “not ready” state.')
r.page('Current / credentials','Keys, permissions and endpoints')
r.text('<b>1.</b> Sign in as the registered Merchant Admin. Open API Integration and complete fresh MFA for credential changes. <b>2.</b> Generate one key for the intended system, exact branch and environment. <b>3.</b> Copy its secret once into a server-side secret manager. <b>4.</b> Restrict backend egress IPs where stable. <b>5.</b> Rotate before its 90-day expiry and revoke the old key after controlled cutover.')
r.add(table(['Method / path','Purpose','Required scope'],[
 ['POST /api/integrations/transactions','Submit one finalized source event.','transactions:write'],
 ['POST /api/integrations/transactions/batch','Submit 1-50 complete events atomically.','transactions:write'],
 ['GET /api/integrations/transactions/{id}','Read the durable intake result identified by inbox id.','transactions:read'],
 ['GET /api/integrations/transactions/wallet','Read the merchant wallet, pricing and intake preference.','transactions:read']],[2.4,2,1.3]))
r.gap();r.code('X-NUERS-API-Key: <server-held-key>\nContent-Type: application/json\nAccept: application/json')
r.text('Base example: <link href="https://nuers.net" color="#0067ff">https://nuers.net</link>. Use the transaction_endpoint published by your merchant API metadata, particularly when a deployment has a path prefix. No separate auth/token exchange is required for these merchant integration endpoints.')
r.call('Current sandbox limitation','Synthetic Testing Tools performs validation without writing records. A sandbox key is not a complete isolated staged ledger. The audit reproduced a sandbox-to-live status-read gap; close that gap before distributing sandbox credentials broadly.')
r.h('Least privilege')
r.text('Grant write only to a sender and read only to a status monitor where appropriate. The portal Manual Retry tool requires both read and write for its merchant/branch wallet preflight. Never put a key in storefront JavaScript, mobile app code, a URL, a PDF, a payload, a screenshot or a support message.')
r.page('Current / schema','Required envelope and identity fields')
r.add(table(['Field','Version-1 requirement / meaning'],[
 ['schema_version','Exactly NUERS-ingestion-1.'],
 ['source_system','Stable connector identifier, maximum 120 characters; letters, numbers, underscore, dot or hyphen. Never change it for a retry.'],
 ['source_event_id','Immutable event identifier, maximum 120 characters. Generate once in the source.'],
 ['external_reference','Original source document reference, maximum 120 characters. Do not replace it with a retry counter.'],
 ['event_type','invoice.finalized for a primary invoice; payment.recorded for supplementary payment evidence.'],
 ['occurred_at','Original ISO 8601 timestamp with Z or an explicit offset, e.g. 2026-10-18T10:00:00+08:00. At most five minutes in the future.'],
 ['branch_code','Exactly five digits; must match the credential branch, including leading zeros.'],
 ['receipt_number','Original legal document number. Under current issuance logic it must match the next configured NUERS series value.'],
 ['document_type','One of the documented eight enum values; must match event family and computed classifications. See page 8 for limits.'],
 ['payment_method','cash, card, bank_transfer, e_wallet, credit or other.'],
 ['tax_type','vatable, vat_exempt, zero_rated or non_vat. Supplies a default for lines, not seller registration.'],
 ['gross_amount','Recommended explicit final invoice total in PHP pesos, including VAT. Use a decimal string with no more than two decimal places.'],
 ['items','One to 100 complete line objects. No aggregate-only sales import.']],[1.3,4.5]))
r.text('Prefer gross_amount and omit legacy amount to avoid ambiguous VAT-exclusive input. Supplying both is not an extra reconciliation check today: gross_amount takes precedence. Tenant and wallet ownership are derived from the authenticated credential, never a merchant_id in JSON.','small')
r.page('Current / money','Lines, totals and preserved originals')
r.add(table(['Field','Current handling'],[
 ['description','Required nonempty description; maximum 1,000 characters.'],
 ['quantity','Positive, maximum 1,000,000; calculation supports up to four decimal places. Prefer quantity over the qty alias.'],
 ['unit_price','PHP pesos, at most two money decimals, maximum 100,000,000 per unit. Prefer unit_price over price.'],
 ['tax_type','Optional line classification; defaults to header. Send it explicitly on every line for mixed documents.'],
 ['vat_rate','Ordinary VATable lines require 12; zero/exempt/non_vat compute zero VAT. Do not encode an exemption only by setting a VAT rate to zero.'],
 ['discount_pct','Optional 0-100 percentage. This generic discount is not a complete statutory discount eligibility model.'],
 ['line_total','Optional supplied total; server reconciles it. The current calculator rounds per line using centavo arithmetic.'],
 ['vat_inclusive','One document-level boolean, default true; applies to every item price. Normalize mixed source pricing conventions before import.'],
 ['customer_name / customer_tin','Optional name and supported TIN format. Buyer address and CWT are not preserved by this API today; see proposal on page 8.']],[1.3,4.5]))
r.h('Units must stay explicit')
r.text('Transaction API amounts are <b>PHP pesos</b>: "560.00" means PHP560. Wallet available_centavos and fee_centavos are <b>integer centavos</b>: 300 means PHP3. The separate portal invoice API uses a different monetary contract. Do not reuse its raw values without conversion.')
r.h('Identity and immutability')
r.text('NUERS independently constrains the source event and the merchant + branch + source system + document family + external reference. An exact normalized validated replay returns the original result. Changed accepted content returns 409. Preserve field types, source time and complete original values; do not add or remove optional fields during a retry. Version 1 currently hashes validated fields, so unsupported fields are not protected as part of that identity.')
r.page('Proposed / tax model','Two levels of tax classification')
r.call('Seller registration is authoritative','An API key identifies a system and branch. It must not offer a VAT / Non-VAT switch that can override the seller\'s reviewed registration. Provide a read-only registration summary and a configurable default line treatment instead.')
r.gap();r.add(table(['Seller profile','Permitted proposed line treatment','Document outcome'],[
 ['VAT registered','VATable 12%; VAT-Exempt; Zero-Rated, subject to the applicable transaction facts and evidence.','VAT invoice family; mixed totals when treatments differ.'],
 ['Non-VAT','Non-VAT taxable / SSPT, or legally exempt, as applicable to that seller and transaction.','Non-VAT invoice; retain SSPT and exempt separation when required. No VAT charged.']],[1,2.8,2]))
r.h('Product mapping is a default, not a legal decision')
r.text('Proposed mapping: source SKU → default category → transaction-specific eligibility → reviewed effective-date rule → immutable line snapshot. Customer circumstances, export or incentive qualification, statutory exemptions and dates can alter treatment. Do not classify solely from the product name, zero amount, the buyer\'s VAT registration or a zero tax rate.')
r.h('Keep these cases distinct')
r.text('<b>VAT-Exempt:</b> an exempt transaction does not become a zero-rated transaction. <b>Zero-Rated:</b> a qualifying taxable transaction at 0%, with its own legal basis. <b>Non-VAT:</b> describes the seller\'s registration and relevant non-VAT treatment. It is not the label for every untaxed line sold by a VAT registrant.')
r.text('Source basis: [S1-S3], with ordinary VAT rate context [S6]. This matrix is the proposed software rule, subject to the relevant facts. Qualified RBE/EOE zero-rating evidence must follow current applicable rules [S7-S8]; do not impose a blanket prior-BIR-approval rule or assume every business buyer qualifies.','small')
r.page('Tax worked examples','Mixed products on one VAT invoice')
r.text('Illustrative amounts in PHP. Assume a reviewed VAT-registered seller and that the exempt and zero-rated lines have valid treatment. These values demonstrate arithmetic; they do not classify actual products.')
r.add(table(['Line / treatment','Price basis','Sales base','VAT','Line total'],[
 ['Ordinary taxable item','112.00 inclusive','100.00','12.00','112.00'],
 ['Qualifying exempt item','100.00','100.00','0.00','100.00'],
 ['Qualifying zero-rated item','200.00','200.00','0.00','200.00'],
 ['Invoice totals','','400.00','12.00','412.00']],[2.3,1.5,1.1,.8,1.1]))
r.h('Reconciliation')
r.code('VATable sales        = 100.00\nVAT amount           =  12.00\nVAT-exempt sales     = 100.00\nZero-rated sales     = 200.00\nGross invoice total  = 412.00')
r.text('Inclusive ordinary VAT: base = price / 1.12; VAT is the difference after the current line rounding. Exclusive ordinary VAT: VAT = base × 12%; line total = base + VAT. The current calculator was checked against this 412.00 mixed example.')
r.h('Default and override')
r.text('A merchant may default products to VATable, then set a qualifying line to VAT-Exempt or Zero-Rated with a recorded basis. Do not change the whole API to Non-VAT for that exempt item. Proposed UI should show the resulting document type and grouped totals before the mapping is enabled.')
r.call('Non-VAT mixed example','If a Non-VAT seller has PHP300 SSPT sales and PHP200 legally exempt sales, preserve a PHP500 Non-VAT invoice with those two distinct subtotals and zero VAT. Current version 1 collapses both into vat_exempt_sales; do not use it as a complete SSPT reporting model.')
r.page('Contract boundaries','Document types and missing fields')
r.add(table(['Current API value','Meaning / important limit'],[
 ['vat_invoice','Primary VAT invoice with VATable lines.'],
 ['non_vat_invoice','Primary Non-VAT invoice. Current server does not consistently enforce seller-family compatibility.'],
 ['vat_exempt_invoice','VAT invoice for VAT-exempt sales; not a substitute for a Non-VAT seller\'s invoice.'],
 ['zero_rated_invoice','VAT invoice for qualifying zero-rated sales.'],
 ['mixed_sales_invoice','Current resolver selects this when more than one line tax_type occurs. Registration-specific mixed handling needs repair.'],
 ['sales_invoice','Auto-classifies from lines. It does not solve the registration defects.'],
 ['payment_receipt','Supplementary payment evidence, event payment.recorded; not an additional primary sale.'],
 ['official_receipt','Payment alias of payment_receipt. Do not use it to represent a second primary sale.']],[1.65,4.2]))
r.h('Proposed versioned additions - not accepted fields today')
r.text('Preserve buyer registered address; a line tax-basis/effective-date reference; distinct Non-VAT SSPT and exempt subtotals; CWT amount and basis; gross invoice total and amount due; original source-system/authority snapshot; and explicit source-capture, issuance, replacement or correction linkage. Final names and schema version require review.')
r.text('Example design: invoice total PHP1,120 and applicable CWT PHP20 produce amount due PHP1,100 while preserving PHP1,000 VATable sales and PHP120 VAT. This illustrates separate balances only, not a withholding-rate determination. A CWT deduction must not silently reduce the original VAT base.')
r.call('Do not silently send unsupported financial fields','Version 1 drops customer_address, withholding_tax and tax_basis_reference supplied by a connector. Until supported, hold documents requiring those fields for reviewed integration handling. RR 6-2026 requires applicable CWT shown separately [S4].')
r.page('Current / JSON example','Version-1 mixed invoice payload')
r.text('Synthetic example only. Change source identities, branch and legal series once when creating a real event; then preserve the saved payload for every retry. Configure the series and authorization first. Do not send this sample as a live sale. New proposal fields are deliberately excluded.')
payload={'schema_version':'NUERS-ingestion-1','source_system':'sample-erp','source_event_id':'SAMPLE-invoice-0001','external_reference':'SAMPLE-INV-0001','event_type':'invoice.finalized','occurred_at':'2026-10-18T10:00:00+08:00','branch_code':'00000','receipt_number':'INV-00000001','document_type':'mixed_sales_invoice','payment_method':'cash','gross_amount':'412.00','tax_type':'vatable','vat_inclusive':True,'customer_name':'SAMPLE Customer','items':[{'description':'SAMPLE taxable item','quantity':1,'unit_price':'112.00','tax_type':'vatable'},{'description':'SAMPLE exempt item','quantity':1,'unit_price':'100.00','tax_type':'vat_exempt'},{'description':'SAMPLE zero-rated item','quantity':1,'unit_price':'200.00','tax_type':'zero_rated'}]}
r.code(json.dumps(payload,indent=2))
r.text('Top-level tax_type defaults unclassified lines; it is not the seller registration. In mixed documents, send explicit tax_type on every item. Do not set the header to "mixed": that value is not accepted by current validation.','small')
r.page('Current / PHP transport','Send the original JSON from PHP')
r.text('Read the immutable payload from your durable source outbox. Load the endpoint and key from server configuration. This transport returns an HTTP outcome; a separate acknowledgement transaction must decide whether the outbox entry was accepted.')
r.code('''<?php
$eventJson = $outboxRow['original_json'];
$url = rtrim(getenv('NUERS_ENDPOINT'), '/');
// NUERS_ENDPOINT is the published transaction endpoint.
$key = getenv('NUERS_API_KEY');
if (!$key || !$url) throw new RuntimeException('Config missing');
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER => [
    'X-NUERS-API-Key: '.$key,
    'Content-Type: application/json',
    'Accept: application/json',
  ],
  CURLOPT_POSTFIELDS => $eventJson,
]);
$raw = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$networkError = ($raw === false);
curl_close($ch);
if ($networkError) {
  // UNKNOWN outcome: keep pending, retry identical JSON later.
  return;
}
try {
  $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
  // Never acknowledge HTML, a login page, or malformed JSON.
  return;
}
// Apply the identity-matched acknowledgement on page 12.
// 402: funding required; 409/422: operator review.
// 429/5xx: bounded backoff, without changing the source.
''')
r.text('Do not log $key, full headers or buyer data. Sanitize errors. A timeout does not prove that the transaction failed at NUERS; the server may have committed before the connection dropped.','small')
r.page('Current / Node.js transport','Send the original JSON from Node.js')
r.text('Server-side example. Supply the exact configured HTTPS endpoint; do not accept a destination URL from an untrusted customer request. The original JSON comes from the source database, not a new object assembled at retry time.')
r.code('''async function sendOriginal(originalJson) {
  const endpoint = process.env.NUERS_ENDPOINT;
  const key = process.env.NUERS_API_KEY;
  if (!endpoint || !key) throw new Error('Config missing');
  let response;
  try {
    response = await fetch(endpoint, {
      method: 'POST',
      redirect: 'error',
      headers: {
        'X-NUERS-API-Key': key,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: originalJson,
      signal: AbortSignal.timeout(30000)
    });
  } catch (error) {
    return {kind: 'unknown'};
  }
  let body;
  try { body = await response.json(); }
  catch { return {kind: 'unknown'}; }
  return {
    kind: 'http', status: response.status, body,
    retryAfter: response.headers.get('retry-after')
  };
}
// Store outcome using source identity checks on page 12.
// Do not acknowledge every 2xx response automatically.
''')
r.h('Wallet and status requests')
r.code('''// Reuse the same server-only key with transactions:read.
// GET <published endpoint>/wallet
// GET <published endpoint>/<inbox id>
// Keep HTTPS, no redirect, timeout and error handling.
// Use id from a validated response, not receipt_id.
''')
r.text('These examples are transport recipes, not a full connector worker. Your adapter must add transactional local storage, retry scheduling, alerting, access controls, redaction and reconciliation. It must also implement the merchant\'s realtime or batch preference.','small')
r.page('Current / acceptance','Acknowledge exactly one durable event')
r.flow([('Send original','Keep local record pending'),('Validate result','Match immutable source IDs'),('Commit ACK','Save NUERS IDs atomically')])
r.h('Expected success shape - illustrative')
r.code('''{
  "id": "<inbox-uuid>",
  "receipt_id": "<receipt-uuid>",
  "transaction_id": "<transaction-uuid>",
  "source_event_id": "SAMPLE-invoice-0001",
  "external_reference": "SAMPLE-INV-0001",
  "status": "durably_recorded",
  "duplicate": false,
  "bir_acceptance": "not_verified",
  "status_url": "<published status URL>",
  "wallet": { "fee_centavos": 0 }
}''')
r.text('A first acceptance returns 201. An exact replay returns 200 with duplicate=true and the original resource IDs and accepted time. Current batch responses use 201 even when all results are already accepted; inspect every result instead of inferring new sales from the HTTP status.')
r.h('Required acknowledgement checks')
r.text('Require HTTP 200/201, parsed JSON, status=durably_recorded, matching source_event_id and external_reference, and nonempty inbox id and receipt_id. Save the IDs and original response in the same local transaction that marks the outbox accepted. If identity or schema checks fail, leave it pending for reconciliation.')
r.h('Duplicate identity is independent of key rotation')
r.text('Rotate a credential for the same merchant/branch without changing the source event. A different key does not require a new invoice. A known accepted ID can be polled with an authorized same-branch read key. If the response was lost before the ID was known, resend the same original event to retrieve the prior result.')
r.call('Three statuses to keep separate','Client finalized → NUERS durably recorded → BIR outcome. NUERS acceptance and a PDF or QR code do not establish BIR acceptance. Current integration responses explicitly report bir_acceptance=not_verified.')
r.page('Current / wallet','Fee admission and error recovery')
r.text('The published starting policy is PHP3 per eligible primary sale of PHP500 and above. Fees may be changed prospectively by Super Admin. Read the current fee, threshold and revision rather than hard-coding this starting rate. This is a NUERS service fee, not the sale\'s VAT or a payment-gateway fee.')
r.add(table(['Outcome','What the connector should do'],[
 ['201 / 200 duplicate','Validate identities, save original response and acknowledge locally. Accepted retries do not incur another fee.'],
 ['402 / insufficient funds','Pause eligible intake. Keep the original on the client, notify the merchant, obtain confirmed funding and retry unchanged. No new financial write or series advance commits. HTTP logs and quota use may still be recorded.'],
 ['401 / 403','Stop repeated automatic authentication attempts; inspect key, expiry, scopes, IP, branch and account status. Preserve sources. Resume after correction.'],
 ['409','Review identity conflict, changed content, environment, series, authority or exhausted numbering. Do not change a legal number to force acceptance.'],
 ['422','Fix the connector mapping before first acceptance; keep the original source record and a traceable rejected-attempt history. An accepted original cannot be overwritten.'],
 ['429','Honor Retry-After where supplied; bounded exponential backoff and jitter. Shared network quotas may apply before a key\'s own limit.'],
 ['Timeout / 5xx / bad success body','Outcome unknown. Reconcile by exact replay; never create a new source ID or assume failure.']],[1.4,4.5]))
r.h('Limits observed in the current contract')
r.text('100 lines/event; 50 events/batch; 600 requests/minute/key plus configured daily budget; batch route adds 10 requests/minute; network protection can limit first. These are configuration ceilings, not measured production throughput. Use bounded polling and an authorized staging load test.')
r.text('Sub-PHP500 primary sales, supplementary payment receipts and already accepted replays do not create an extra primary-sale fee under the current policy. Wallet checks are repeated at commit time; a preflight balance is not a reservation.','small')
r.page('Current / batch','Realtime, batch and all-or-none writes')
r.add(table(['Mode','Connector action'],[
 ['Realtime','Commit the finalized source and local outbox first; attempt submission promptly. If funds or internet are unavailable, retain the outbox event.'],
 ['Batch','Retain finalized source events and transmit on the configured schedule or operator action. Group by branch and source series. The portal preference alone does not install a worker.'],
 ['Manual','Export the unchanged original events from the source and use a reviewed server sender or the NUERS Manual Retry tab. Do not retype accepted invoices.']],[1,4.5]))
r.h('Batch request')
r.code('''POST <published transaction endpoint>/batch
X-NUERS-API-Key: <server-held-key>

{
  "events": [
    <complete original event 1>,
    <complete original event 2>
  ]
}''')
r.text('Use 1-50 complete version-1 events for one exact credential branch. Sort by the current legal series order. A failure in any new item rolls back every new financial write, sequence reservation, preparation and debit in that request. A previously accepted exact duplicate remains accepted and free.')
r.h('Accept the batch only when every result matches')
r.text('Require atomic=true, the expected result count, and identity-matched durable results for every original event. Store acknowledgements transactionally in the source. A partial or malformed result leaves the local group pending until reconciled by unchanged replay.')
r.call('Series discipline','Stop on the first unaccepted document in a series. Do not send later source numbers around a wallet, validation or authority failure. For historical or differently formatted legal numbers, use the proposed external-capture design; current v1 requires its exact next series number.')
r.page('Connector design','Survive internet loss without duplicates')
r.text('Recommended source adapter design. This is a connector responsibility; the NUERS server cannot save an event it has never received. A browser queue is an additional recovery tool, not the primary source of truth.')
r.code('''BEGIN local database transaction
  finalize source document using authorized source workflow
  INSERT outbox (
    stable_source_event_id, original_document_reference,
    original_occurred_at, original_json, payload_hash,
    branch_code, state = 'pending', next_attempt_at
  )
COMMIT

worker locks next due source event in series order
send the saved original_json
if validated durable acceptance:
  BEGIN local database transaction
    save NUERS IDs and response; set state = 'accepted'
  COMMIT
else if 402: state = 'funding_required'; alert merchant
else if 409/422: state = 'review'; alert operator
else: retain pending; schedule bounded retry
''')
r.h('Four common failure scenarios')
r.add(table(['Scenario','Safe recovery'],[
 ['Internet fails before request','Source outbox remains pending. Send the same saved event after recovery.'],
 ['NUERS commits, response is lost','Exact replay returns the original IDs and no extra fee.'],
 ['Source crashes before saving ACK','Read its pending row and replay unchanged. Do not generate a replacement identity.'],
 ['Wallet becomes insufficient','Keep the source locally; after confirmed funding, resume the earliest blocked event and reconcile.']],[1.6,4]))
r.text('Offline invoice issuance has its own legal workflow. RMC 98-2026 IV.11 addresses authorized manual invoices and linked electronic replacements after recovery [S5]. A pending outbox or low wallet balance is not an extension of an invoicing deadline. The current API lacks an explicit replacement-reference field; that workflow is proposed, not implemented.','small')
r.page('Current / operator runbook','Use Manual Retry from the portal')
r.text('<b>1. Export.</b> From the external system export the original source events as an array or {"events":[...]}. Limit the file to 50 events and 2 MB. Exclude credentials and unrelated customer data.')
r.text('<b>2. Sign in.</b> Open Merchant → API Integration → Manual Retry for the correct merchant. Keep the source system\'s independent backup.')
r.text('<b>3. Unlock.</b> Use a private recovery passphrase of at least 16 characters. It derives an encryption key; NUERS cannot recover this browser queue if the passphrase is lost. Store it securely outside the queue.')
r.text('<b>4. Import and review.</b> Check branch, source system, original reference, timestamp and values. The queue encrypts payloads and responses using AES-GCM and tenant-bound associated data. Do not clear browser storage while pending records exist.')
r.text('<b>5. Authenticate.</b> Supply a valid matching live branch key with read and write scopes in memory. Wallet preflight verifies merchant and branch before source submission. Do not put the key into the imported JSON.')
r.text('<b>6. Transmit.</b> Resume after funds are sufficient. The sender selects that branch\'s queued events. A different branch remains pending; use its own matching credential. The first unaccepted source stops ordered replay.')
r.text('<b>7. Reconcile.</b> Match accepted source IDs in Invoices & Receipts and persist NUERS IDs back in the source system. Preserve review/funding rows until resolved. Protect both source backups and browser recovery material.')
r.h('Storage limitations')
r.text('The portal displays the latest 1,000 queue metadata rows and retains older encrypted records. Browser storage can be deleted, evicted or exhausted; it is not the merchant\'s five-year server archive. Local crypto tests do not prove browser restart, quota or device-recovery behavior in every deployment.')
r.h('Credential or passphrase issue')
r.text('For an expired/revoked key, replace it with a permitted same-merchant, same-branch credential and retry unchanged. For the wrong recovery passphrase, preserve encrypted data and use the correct passphrase. If browser storage is lost, recover the original JSON from the authoritative source outbox; do not reconstruct new source IDs.')
r.call('No automatic external-system access','The NUERS portal cannot reach an ERP that is offline or export its local data. The customer\'s connector or operator must provide the saved source event when the connection is restored.')
r.page('Operational controls','Security, reconciliation and support')
r.h('Protect data and access')
r.text('Use TLS with certificate verification; do not follow unexpected redirects with credentials. Keep API keys in a backend secret manager and restrict exact egress IPs where possible. An empty allowlist permits any IP possessing the valid key. Maintain trusted-edge proxy configuration rather than trusting arbitrary forwarded headers.')
r.text('Separate environments, merchant tenants and branches. Apply least privilege, redact headers and buyer data from logs, limit queue access, encrypt source backups, track credential expiry and revoke compromised keys. A rotated key must not change transaction identity. Current findings A3 and A4 require remediation before full security sign-off.')
r.h('Reconcile daily and after outages')
r.add(table(['Compare','Resolve'],[
 ['Source finalized vs NUERS durable','Match branch, source event, source reference and original amount. Pending sources remain visible to operators.'],
 ['Sales totals vs payment evidence','Count the primary invoice once. A supplementary payment collection must not add a second sale.'],
 ['Wallet debits vs accepted eligible sales','Match fee revision, original resource, debit and balance. Do not charge exact replay again.'],
 ['Tax buckets and copies','Compare source line categories, grouped bases, VAT and due amounts. Hold documents needing unsupported fields.'],
 ['Delivery status vs BIR status','Recorded in NUERS and sent to customer are separate from authenticated BIR response evidence.']],[1.5,4]))
r.h('Logs and webhooks')
r.text('Usage & Logs records scoped intake-attempt metadata with status and latency. It is not a complete performance/APM system or proof that every network request reached the application. Merchant callbacks remain platform-controlled and paused on the current page. Use polling with bounded backoff; a saved callback URL does not prove delivery.')
r.text('Support evidence should contain a sanitized source reference, branch, timestamp, HTTP status, request correlation or inbox ID, and redacted error. Never send a live API key, password, recovery code or full customer payload in a ticket.','small')
r.page('Acceptance / sources','Staging sign-off and reference material')
r.add(table(['Test before production','Expected result'],[
 ['Pending / inactive merchant','New writes denied; no financial side effects.'],
 ['Wrong tenant, branch or environment','No source data leakage or cross-boundary write. Environment-read defect must be fixed.'],
 ['First event / same event / changed event','One record and one eligible fee; exact replay original IDs; changed accepted payload conflict.'],
 ['VAT / Non-VAT / mixed / CWT','Registration-safe family, preserved fields and all grouped totals reconcile. Resolve A1-A2 before sign-off.'],
 ['Insufficient funds / late batch error','No new financial writes, fees or series progress commit.'],
 ['Lost response / restart / key rotation','Identical source replay restores acknowledgement; no duplicate or extra fee.'],
 ['Audit storage failure on key change','Key change and audit roll back together; resolve A4 first.']],[2.1,3.5]))
r.gap();sources(r,['S1','S2','S3','S4','S5','S6','S7','S8'])
r.text('Research checked 18 October 2026. Product eligibility and legal evidence must be maintained as rules change. The detailed audit accompanies this draft. Proposed fields, capture modes, PDF download wiring and environment fixes have not been implemented.','small')
guide_meta=r.save()
(Path('tmp/pdfs/api-review/metadata.json')).write_text(json.dumps([report_meta,guide_meta],indent=2));print(json.dumps([report_meta,guide_meta],indent=2))
