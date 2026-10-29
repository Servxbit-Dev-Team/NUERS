from pathlib import Path
import json, html, copy, math
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph, Table, TableStyle
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.colors import HexColor, white
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.utils import ImageReader
ROOT=Path('/Users/marksioson/Desktop/NUERS')
TMP=ROOT/'tmp/pdfs/merchant-connection-final'; OUT=ROOT/'output/pdf'
LOGO=ROOT/'public/auth-assets/nuers-digital-logo.png'
for n,f in [('Body','Arial.ttf'),('Bold','Arial Bold.ttf'),('Mono','Courier New.ttf')]:
    pdfmetrics.registerFont(TTFont(n,'/System/Library/Fonts/Menlo.ttc' if n=='Mono' else '/System/Library/Fonts/Supplemental/'+f))
N=HexColor('#08274d'); B=HexColor('#0067ff'); T=HexColor('#213b60'); M=HexColor('#607996'); L=HexColor('#dbe6f4'); BG=HexColor('#f3f8ff'); G=HexColor('#008f67')
W,H=595.28,841.89; X=42; CW=W-84
styles={k:ParagraphStyle(k,fontName=f,fontSize=z,leading=h,textColor=c) for k,f,z,h,c in [('body','Body',10,15,T),('small','Body',8.5,12.5,M),('h','Bold',13,18,N),('cell','Body',8.8,12.3,T),('th','Bold',8.7,12,white)]}
def P(s,k='body'): return Paragraph(s,styles[k])
def esc(s): return html.escape(str(s))
BASE='https://nuers.net/api/integrations/transactions'
class Guide:
    def __init__(self):
        self.path=OUT/'NUERS-API-Connection-Guide.pdf';self.c=canvas.Canvas(str(self.path),pagesize=(W,H));self.n=0;self.y=0;self.titles=[];self.metrics=[]
        self.c.setTitle('NUERS Merchant API Connection Guide');self.c.setAuthor('NUERS');self.c.setCreator('NUERS Merchant Documentation');self.c.setSubject('Merchant integration with ERP, POS and ecommerce | NUERS-ingestion-1 | 29 October 2026')
    def footer(self):
        c=self.c
        for dx,ww,col in [(0,58,B),(58,42,HexColor('#ffca05')),(100,42,HexColor('#ed253c'))]:c.setFillColor(col);c.rect(X+dx,32,ww,2.5,fill=1,stroke=0)
        c.setFillColor(M);c.setFont('Body',7.4);c.drawString(X,18,'NUERS  /  MERCHANT CONNECTION GUIDE  /  29 OCT 2026');c.drawRightString(W-X,18,f'{self.n:02d}')
    def page(self,k,title):
        if self.n:self.c.showPage()
        self.n+=1;c=self.c;c.setFillColor(BG);c.rect(0,H-63,W,63,fill=1,stroke=0)
        c.drawImage(str(LOGO),X,H-55,width=107,height=43,mask='auto',preserveAspectRatio=True)
        c.setFillColor(M);c.setFont('Bold',7.6);c.drawRightString(W-X,H-28,'MERCHANT API CONNECTION');c.setFont('Body',7.3);c.drawRightString(W-X,H-41,'Version 1.5  |  NUERS-ingestion-1')
        c.bookmarkPage('p'+str(self.n));c.addOutlineEntry(title,'p'+str(self.n),0,False);self.titles.append((self.n,title));self.footer()
        c.setFillColor(B);c.setFont('Bold',8.1);c.drawString(X,H-88,k.upper())
        p=Paragraph(esc(title),ParagraphStyle('title',fontName='Bold',fontSize=23,leading=28,textColor=N));_,ht=p.wrap(CW,90);p.drawOn(c,X,H-103-ht);self.y=H-119-ht
    def add(self,f,gap=10):
        _,h=f.wrap(CW,1200)
        if self.y-h<53:raise RuntimeError(f'Page {self.n} overflow {self.y-h:.1f}: {str(f)[:140]}')
        f.drawOn(self.c,X,self.y-h);self.y-=h+gap;self.metrics.append((self.n,round(self.y,1)))
    def text(self,s,k='body',gap=10):self.add(P(s,k),gap)
    def h(self,s):self.text(s,'h',7)
    def steps(self,rows):
        for i,s in enumerate(rows):self.text(f'<font color="#0067ff"><b>{i+1:02d}</b></font>   '+s,gap=11)
    def table(self,heads,rows,widths=None):
        widths=[CW*w/sum(widths) for w in (widths or [1]*len(heads))]
        data=[[P(esc(v),'th')for v in heads]]+[[P(str(v),'cell')for v in row]for row in rows]
        tb=Table(data,colWidths=widths,hAlign='LEFT');tb.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),N),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),8),('RIGHTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),7),('BOTTOMPADDING',(0,0),(-1,-1),7),('ROWBACKGROUNDS',(0,1),(-1,-1),[white,BG]),('LINEBELOW',(0,1),(-1,-1),.4,L)]));self.add(tb,13)
    def call(self,title,body):
        tb=Table([[P('<b>'+title+'</b><br/>'+body)]],colWidths=[CW]);tb.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,-1),BG),('BOX',(0,0),(-1,-1),.7,L),('LINEBEFORE',(0,0),(0,-1),3,B),('LEFTPADDING',(0,0),(-1,-1),12),('RIGHTPADDING',(0,0),(-1,-1),12),('TOPPADDING',(0,0),(-1,-1),10),('BOTTOMPADDING',(0,0),(-1,-1),10)]));self.add(tb,13)
    def code(self,s,size=8.25):
        lines=s.strip('\n').splitlines();height=len(lines)*(size+3.2)+24
        for line in lines:
            if pdfmetrics.stringWidth(line,'Mono',size)>CW-24:raise RuntimeError(f'Code too wide page {self.n}: {line}')
        if self.y-height<53:raise RuntimeError(f'Code overflow page {self.n}: {self.y-height:.1f}')
        c=self.c;c.setFillColor(HexColor('#0c2038'));c.roundRect(X,self.y-height,CW,height,6,fill=1,stroke=0);c.setFont('Mono',size);c.setFillColor(HexColor('#e0edff'));y=self.y-14-size
        for line in lines:c.drawString(X+12,y,line);y-=size+3.2
        self.y-=height+13;self.metrics.append((self.n,round(self.y,1)))
    def nodes(self,nodes,height):
        top=self.y;c=self.c
        for x,y,w,h,title,body in nodes:
            c.setFillColor(BG);c.setStrokeColor(L);c.roundRect(X+x,top-y-h,w,h,8,fill=1,stroke=1)
            p=P('<b>'+title+'</b><br/>'+body,'cell');_,ph=p.wrap(w-20,h-18)
            if ph>h-18:raise RuntimeError('Node too small')
            p.drawOn(c,X+x+10,top-y-10-ph)
        self.y-=height+14;self.metrics.append((self.n,round(self.y,1)));return top
    def arrow(self,x1,y1,x2,y2,top):
        c=self.c;c.setStrokeColor(B);c.setLineWidth(1.3);c.line(X+x1,top-y1,X+x2,top-y2);a=math.atan2(-(y2-y1),x2-x1)
        for d in [-.55,.55]:c.line(X+x2,top-y2,X+x2-7*math.cos(a+d),top-y2-7*math.sin(a+d))
    def save(self):
        self.c.save();(TMP/'build-metadata.json').write_text(json.dumps({'pages':self.n,'titles':self.titles,'minimum_body_y':min(y for _,y in self.metrics)},indent=2));print(json.dumps({'pdf':str(self.path),'pages':self.n}))

# Synthetic examples: executed against the current contract during document QA.
base={'schema_version':'NUERS-ingestion-1','record_mode':'source_capture','source_profile_id':'00000000-0000-4000-8000-000000000001','source_system':'SAMPLE-owned-system','source_event_id':'erpA:invoice:0001','external_reference':'erpA:INV-0001','event_type':'invoice.finalized','occurred_at':'2026-10-18T10:00:00+08:00','branch_code':'00000','receipt_number':'SAMPLE-INV-0001','document_type':'sales_invoice','payment_method':'cash','gross_amount':'100.00','tax_type':'non_vat','vat_inclusive':True,'items':[{'description':'SAMPLE product','quantity':1,'unit_price':'100.00','tax_type':'non_vat'}]}
cases=[]
def case(name,vat,patch=None,items=None,expect=None):
    e=copy.deepcopy(base)
    if patch:e.update(patch)
    if items is not None:e['items']=items
    cases.append({'name':name,'vat':vat,'payload':e,'expected':expect or {}});return e
non=case('non-vat',False,expect={'gross_amount':100,'vat_amount':0,'sspt_sales':100})
vat=case('vat-inclusive',True,{'tax_type':'vatable','gross_amount':'112.00'},[{'description':'SAMPLE taxable product','quantity':1,'unit_price':'112.00','tax_type':'vatable'}],{'gross_amount':112,'vat_amount':12})
mixed=case('vat-mixed',True,{'tax_type':'vatable','gross_amount':'187.00','tax_basis_reference':'SAMPLE approved product tax mapping'},[{'description':'SAMPLE taxable product','quantity':1,'unit_price':'112.00','tax_type':'vatable'},{'description':'SAMPLE exempt product','quantity':1,'unit_price':'50.00','tax_type':'vat_exempt'},{'description':'SAMPLE zero-rated product','quantity':1,'unit_price':'25.00','tax_type':'zero_rated'}],{'gross_amount':187,'vat_amount':12,'vatable_sales':100,'vat_exempt_sales':50,'zero_rated_sales':25})
exclusive=case('vat-exclusive',True,{'tax_type':'vatable','gross_amount':'112.00','vat_inclusive':False},[{'description':'SAMPLE product','quantity':1,'unit_price':'100.00','tax_type':'vatable'}],{'gross_amount':112,'vat_amount':12})
cwt=case('withholding',True,{'tax_type':'vatable','gross_amount':'112.00','withholding_tax':'2.00','withholding_basis_reference':'SAMPLE reviewed withholding basis'},vat['items'],{'gross_amount':112,'net_payable':110})
for cat,gross,dis,vat_amt,relief in [('senior_citizen','800.00',200,0,120),('pwd','800.00',200,0,120),('national_athlete_coach','896.00',200,96,24),('solo_parent','900.00',100,0,120)]:
    benefit={'category':cat,'scope':'medicine' if cat=='solo_parent' else 'restaurant','beneficiary_name':'SAMPLE Customer','id_number':'SAMPLE-ID-001','evidence_reference':'SAMPLE verified eligibility record','eligibility_confirmed':True}
    if cat=='solo_parent':benefit.update(child_birth_date='2023-01-01',id_valid_until='2027-01-01',income_below_250000=True,booklet_reference='SAMPLE-BOOKLET-001')
    case(cat,True,{'tax_type':'vatable','gross_amount':gross,'sales_benefit':benefit},[{'description':'SAMPLE eligible purchase','quantity':1,'unit_price':'1120.00','tax_type':'vatable','benefit_eligible':True}],{'gross_amount':float(gross),'statutory_discount_amount':dis,'vat_amount':vat_amt,'vat_relief_amount':relief})
case('discount',True,{'tax_type':'vatable','gross_amount':'201.60'},[{'description':'SAMPLE product','quantity':2,'unit_price':'112.00','tax_type':'vatable','discount_pct':10}],{'gross_amount':201.6,'vat_amount':21.6})
case('manual-amount',True,{'tax_type':'vatable','gross_amount':'1008.00','manual_discount':{'type':'amount','value':'112.00','reason':'SAMPLE loyalty discount'}},[{'description':'SAMPLE product','quantity':1,'unit_price':'1120.00','tax_type':'vatable'}],{'gross_amount':1008,'manual_discount_amount':112,'vatable_sales':900,'vat_amount':108})
case('manual-percentage',True,{'tax_type':'vatable','gross_amount':'1008.00','manual_discount':{'type':'percentage','value':'10.00','reason':'SAMPLE loyalty discount'}},[{'description':'SAMPLE product','quantity':1,'unit_price':'1120.00','tax_type':'vatable'}],{'gross_amount':1008,'manual_discount_amount':112,'vat_amount':108})
case('payment',False,{'event_type':'payment.recorded','document_type':'payment_receipt'},expect={'gross_amount':100})
(TMP/'examples.json').write_text(json.dumps(cases,indent=2))
for row in cases:(TMP/(row['name']+'.json')).write_text(json.dumps(row['payload'],indent=2)+'\n')

r=Guide()
# 1 / Cover
r.n=1;c=r.c;c.bookmarkPage('p1');c.addOutlineEntry('Merchant API Connection Guide','p1',0,False);r.titles.append((1,'Merchant API Connection Guide'))
c.setFillColor(BG);c.rect(0,0,W,H,fill=1,stroke=0)
c.drawImage(str(LOGO),X,H-165,width=273,height=109,mask='auto',preserveAspectRatio=True)
c.setFillColor(B);c.setFont('Bold',9);c.drawString(X,H-210,'FOR MERCHANTS & THEIR INTEGRATION DEVELOPERS')
c.setFillColor(N);c.setFont('Bold',36);c.drawString(X,H-265,'Merchant API');c.drawString(X,H-310,'Connection Guide')
p=Paragraph('Connect your ERP, POS or ecommerce system to your NUERS account.',ParagraphStyle('cover',fontName='Body',fontSize=16,leading=23,textColor=T));p.wrap(CW-35,100);p.drawOn(c,X,H-383)
c.setFillColor(N);c.roundRect(X,150,CW,247,14,fill=1,stroke=0)
c.setFillColor(white);c.setFont('Bold',16);c.drawString(X+23,362,'One connection. Reliable transaction records.')
for i,(title,sub) in enumerate([('01  SET UP','Choose Live or Sandbox. Protect your API key.'),('02  CONNECT','Submit original transactions with clear tax mapping.'),('03  RECONCILE','Recover offline events and verify signed callbacks.')]):
    y=323-i*58;c.setFillColor(HexColor('#7ab6ff'));c.setFont('Bold',9);c.drawString(X+23,y,title);c.setFillColor(white);c.setFont('Body',10);c.drawString(X+23,y-19,sub)
c.setFillColor(M);c.setFont('Bold',9);c.drawString(X,116,'EDITION 1.5  |  29 OCTOBER 2026');c.setFont('Body',9);c.drawString(X,97,'Contract: NUERS-ingestion-1  |  HTTPS / JSON  |  PHP currency')
r.footer()
# 2
r.page('Start here','Your route to a working connection')
r.text('This guide is for a merchant and the developer connecting the merchant\'s own system. Follow the setup once, implement the payload contract, then test recovery before going Live.')
contents=[('3-6','Prepare your account, credentials and environment'),('7-10','Endpoints, fields, items and document types'),('11-15','Complete payloads, tax mapping and special sales'),('16-19','cURL, Node.js and PHP connection examples'),('20-23','Acceptance, status, wallet and batch processing'),('24-26','Offline outbox, duplicate protection and manual retry'),('27-31','Webhook configuration, verification and delivery'),('32-34','Errors, security and go-live checklist'),('35-38','Reconciliation, quick reference and manual discounts'),('39-42','Sales status, customer email, branding and checklist'),('43-46','Buyer CWT, corrections, manual recovery and fee invoices'),('47-48','Invoice numbering, buyer identity and printed registration')]
r.table(['Pages','Connection topic'],[(f'<link href="#p{a.split("-")[0]}" color="#0067ff">{a}</link>',b) for a,b in contents],[.7,4.3])
r.h('Use this guide in order')
r.text('<b>Merchant owner:</b> start with pages 3-6, 22, 26 and 34.<br/><b>Integration developer:</b> read the field contract and examples, then implement the outbox and response checks.<br/><b>Webhook developer:</b> pages 27-31 are an optional completion channel; status polling remains available.')
r.call('Examples are synthetic','Replace sample connection identifiers with your own dashboard values. Use a Sandbox key for sample sales. The dates and document numbers printed here are examples, not real transactions.')
r.text('PDF navigation: click a contents page number or use the chapter bookmarks. Literal field names and enum values are case-sensitive. Amounts in transaction JSON are PHP pesos; wallet fields ending in _centavos are integer centavos.','small')
# 3
r.page('01 / Set up','From verified account to API key')
r.steps(['Sign in as the registered business owner / Merchant Admin and complete the login verification. Open <b>Business Profile</b> and confirm that the business is <b>Active</b> and its taxpayer details are correct.','Open <b>Merchant &gt; API Integration</b>. Choose <b>Generate New API Key</b>, enter a clear name such as ERP Connector, and choose <b>Sandbox</b> for initial testing.','Copy the API key when it is shown. Store it in your server\'s secret manager. It is shown once; the portal does not reveal the original secret again.','Copy the assigned <b>source_profile_id</b>, <b>source_system</b> and <b>branch_code</b> from the connection / ERP configuration or <b>Source Profiles</b> tab.','Use the wallet endpoint on page 7 to check authentication and confirm the environment and assigned business context. Then submit a synthetic Sandbox event.','After completing the checklist on page 34, generate a separate Live key and use the Live connection configuration for actual transactions.'])
r.call('Automatic business connection','An active, verified merchant receives its owned-system connection automatically. There is no second source approval and no merchant Branches / Terminals form to complete before creating this connection.')
r.text('If the button is disabled: enter a key name, check that you are the registered owner, then use <b>Refresh Status</b> or reload once. An already-active account does not need another approval. If it remains blocked, send a redacted screenshot and time to Submit a Request. Never include the secret key.','small')
# 4
r.page('01 / Set up','Copy the connection configuration')
r.table(['Setting','Where to get it / how to use it'],[['API base URL',BASE],['API key','One-time secret generated in API Integration. Send in X-NUERS-API-Key.'],['Environment','The key\'s fixed Live or Sandbox environment. Keep separate configuration and queues.'],['source_profile_id','Assigned UUID shown in Source Profiles / ERP configuration. Required for source_capture.'],['source_system','Exact assigned identifier. Do not replace it with a label such as SAP or My ERP.'],['branch_code','Assigned five-digit string, including leading zeros. Use the same value as the key.'],['merchant_id','Returned by the authenticated wallet endpoint. Useful for verifying signed webhook events.']],[1.25,3.75])
r.h('Suggested server configuration')
r.code('''NUERS_API_BASE=https://nuers.net/api/integrations/transactions
NUERS_ENVIRONMENT=sandbox
NUERS_API_KEY=<load from your secret manager>
NUERS_SOURCE_PROFILE_ID=<copy assigned UUID>
NUERS_SOURCE_SYSTEM=<copy assigned identifier>
NUERS_BRANCH_CODE=<copy all five digits>''')
r.text('The values above are connector configuration names, not additional API fields. Keep them outside public web folders and source control. Multiple owned systems can share the assigned source context; prefix their event IDs and external references, for example <b>erpA:invoice:0001</b> and <b>posB:invoice:0001</b>.')
r.call('Use source_capture for an existing original','Always send record_mode: source_capture when your ERP or POS has already issued the document. Omitting the field defaults to the separate NUERS issuance workflow and can fail authority checks.')
# 5
r.page('01 / Set up','Keep Sandbox and Live separate')
r.table(['Behavior','Sandbox','Live'],[['API URL','Same HTTPS API base','Same HTTPS API base'],['Authentication','Sandbox key','Live key'],['Records','Isolated test records','Merchant\'s actual financial records'],['Wallet','No debit; simulated fee information','Eligible fee debited atomically'],['Identifiers','Test transaction / receipt prefixes','Live record identifiers'],['BIR acceptance','not_applicable','Inspect the response; intake alone is not BIR acceptance'],['Purpose','Synthetic integration and retry tests','Actual finalized business events']],[1.2,1.9,1.9])
r.h('Environment rules for your connector')
r.steps(['Use separate secret-store entries, outbox queues, response stores and webhook configuration for each environment. The environment belongs to the API key; a JSON flag cannot switch it.','Check the response environment before marking any event delivered. A Sandbox success must never clear a pending Live event.','Keep test buyers, document numbers and reports out of Live. Select the matching environment when viewing merchant records and API logs.'])
r.call('Sandbox is a connector test','Sandbox validates payloads against the merchant\'s current tax classification. It does not prove that a Live source profile covers the original date or historical seller identity. Check the assigned Live profile and an authorized real pilot before cutover.')
# 6
r.page('01 / Set up','Authentication and credential care')
r.code('''X-NUERS-API-Key: <your server-held API key>
Content-Type: application/json
Accept: application/json''')
r.text('Use the header above on every API call. The API key is itself the secret credential; a separate public key, private key pair or token exchange is not required for this connection.')
r.table(['Control','Merchant action'],[['Scopes','transactions:write submits events. transactions:read reads status and wallet. Manual retry needs both.'],['IP restrictions','Use Edit Key Policy to allow the connector\'s exact public IPv4 / IPv6 egress addresses. Include your failover egress before switching.'],['Expiry','Generated keys expire after 90 days. Track the displayed expiry and rotate before it.'],['Daily quota','Default Live key: 50,000 requests/day. Sandbox: 5,000/day. Check the key\'s current policy. Other shared limits also apply.'],['Owner access','Credential changes require the registered owner\'s verified login. Keep MFA enabled.'],['Lost key','Generate a replacement. The original secret cannot be recovered from the masked display.']],[1.1,3.9])
r.h('Safe rotation')
r.text('Create the replacement in the same environment and business context. Update the server secret, verify wallet/status access, then revoke the old key. Keep the original event IDs and JSON when retrying queued events. Revocation is permanent.')
r.call('Secrets stay on the server','Do not embed API keys in browser code, mobile apps, URLs, screenshots, support tickets, webhook bodies or logs. Keep HTTPS certificate verification enabled and redact authentication headers.')
# 7
r.page('02 / API reference','Four endpoints for your connector')
r.text('<b>Base URL</b><br/>'+BASE)
r.table(['Method / path','Purpose','Scope'],[['POST /','Store one finalized transaction copy.','transactions:write'],['POST /batch','Store 1-50 events atomically.','transactions:write'],['GET /{id}','Read the accepted intake record by its returned id.','transactions:read'],['GET /wallet','Read environment, connection context, balance and pricing.','transactions:read']],[1.1,2.5,1.4])
r.text('Paths above are relative to the base URL. POST / means POST to the base URL itself. For example, the wallet URL is:')
r.code(BASE+'/wallet')
r.h('First connectivity check')
r.code('''curl --silent --show-error --fail-with-body \\
  "https://nuers.net/api/integrations/transactions/wallet" \\
  --header "X-NUERS-API-Key: ${NUERS_API_KEY}" \\
  --header "Accept: application/json"''')
r.text('Load NUERS_API_KEY privately before running the command. Confirm HTTP 200, the expected environment and the assigned branch_code. A successful wallet check confirms authentication and read scope; it does not create a transaction or guarantee a later write will pass.')
r.call('Use the intake id for status','GET /{id} uses the UUID named id from the acceptance response. It does not use receipt_id, source_event_id or receipt_number. The key must belong to the same merchant, branch and environment.')
# 8
r.page('02 / API reference','Required event fields')
r.table(['Field','Required value / rule'],[['schema_version','Exactly NUERS-ingestion-1.'],['record_mode','Send source_capture explicitly for copies from your system.'],['source_profile_id','Assigned source UUID; required with source_capture.'],['source_system','Exact assigned identifier. Maximum 120 characters; letters, digits, underscore, dot and hyphen.'],['source_event_id','Stable original event identifier, maximum 120 characters. Never regenerate it on a retry.'],['external_reference','Stable source business reference, maximum 120 characters. Namespace it by system.'],['event_type','invoice.finalized for a sales invoice; payment.recorded for a supplementary payment receipt.'],['occurred_at','Original ISO 8601 timestamp with Z or an offset, e.g. +08:00. At most five minutes in the future.'],['branch_code','Exact assigned five-digit string; preserve leading zeros.'],['receipt_number','Original seller document number, 1-50 characters. Preserve prefix and leading zeros.'],['document_type','Use sales_invoice to classify from calculated lines, or an explicit type from page 10.'],['payment_method','cash, card, bank_transfer, e_wallet, credit or other.'],['gross_amount','Final PHP total after discounts and VAT, before withholding. Prefer a two-decimal string.'],['tax_type','Default line treatment: vatable, vat_exempt, zero_rated or non_vat. Do not send mixed here.'],['items','An array of 1-100 line items. See page 9.']],[1.35,3.65])
r.text('Unknown fields are rejected. Use the names shown here rather than sending an entire ERP object. Send vat_inclusive explicitly even though its default is true.','small')
# 9
r.page('02 / API reference','Line items and optional details')
r.table(['Line field','Rule'],[['description','Required text, up to 1,000 characters.'],['quantity','Required; greater than zero, at most 1,000,000; up to four decimal places.'],['unit_price','Required PHP amount; zero or greater, at most 100,000,000; up to two decimals.'],['tax_type','Defaults to header tax_type. Set it explicitly on each line in a mixed invoice.'],['vat_rate','Optional: 12 for vatable; 0 for other treatments.'],['discount_pct / manual_discount','Legacy percentage or amount/percentage object. See pages 37-38. Do not stack discounts.'],['line_total','Optional asserted final line total. If supplied, it must equal the server calculation.'],['tax_basis_reference','Optional evidence reference, up to 2,000 characters. Include the basis for exempt or zero-rated treatment.'],['benefit_eligible','Boolean. Set true only on lines qualifying for the declared sales_benefit.']],[1.35,3.65])
r.h('Optional event details')
r.text('<b>customer_name</b> (255), <b>customer_address</b> (2,000), and <b>customer_tin</b> may accompany the original. A TIN must be 9, 12 or 14 digits, or a 3-3-3 formatted TIN with an optional 3- or 5-digit suffix. Keep it as text.')
r.text('<b>tax_basis_reference</b> at event level is inherited by its lines. <b>withholding_tax</b> requires <b>withholding_basis_reference</b> (3-2,000 characters). <b>sales_benefit</b> is described on pages 14-15.')
r.call('Use clean numeric values','Send "112.00", not "PHP 112.00" or "1,000.00". Booleans should be JSON true / false. Omit optional fields you do not use; once accepted, preserve the original JSON types and field presence on retries.')
# 10
r.page('02 / API reference','Document types and tax treatments')
r.table(['document_type','event_type / meaning'],[['sales_invoice','invoice.finalized. Recommended: server classifies from the calculated lines.'],['vat_invoice','invoice.finalized; all lines vatable, VAT seller.'],['non_vat_invoice','invoice.finalized; ordinary non-VAT or wholly exempt sale by a non-VAT seller.'],['vat_exempt_invoice','invoice.finalized; all lines exempt, VAT seller.'],['zero_rated_invoice','invoice.finalized; all lines zero-rated, VAT seller.'],['mixed_sales_invoice','invoice.finalized; more than one permitted line treatment.'],['payment_receipt','payment.recorded; supplementary payment evidence.'],['official_receipt','Compatibility alias for payment_receipt. Use payment.recorded; it is not the primary sales invoice.']],[1.6,3.4])
r.h('Seller registration controls line treatment')
r.table(['Seller','Allowed input line tax_type'],[['VAT registered','vatable, vat_exempt, zero_rated'],['Non-VAT registered','non_vat, vat_exempt']],[1.3,3.7])
r.text('A VAT merchant can mix taxable, exempt and zero-rated products when the classification is supported by its evidence. Use vat_exempt for an exempt product; <b>non_vat is the seller\'s non-VAT treatment</b>. Do not change the merchant\'s registration just to submit one item.')
r.text('Payment receipts must use their own stable event identity. Keep the relationship to the original invoice in your source records. Arbitrary cancellation, credit-note and debit-note types are not accepted by this contract.','small')
# 11
r.page('03 / Payload examples','Complete non-VAT invoice copy')
r.text('Valid shape for a non-VAT merchant. Replace the sample connection values, original IDs and timestamp. Use this structure as event.json for the sender examples.')
r.code(json.dumps(non,indent=2),8.2)
r.call('Expected calculation','PHP 100.00 gross, PHP 0.00 VAT and PHP 100.00 ordinary non-VAT sales (sspt_sales). The API does not calculate a seller\'s percentage-tax liability from this field.')
# 12
r.page('03 / Payload examples','VAT and mixed-product invoices')
r.text('Start with the full event on page 11. For a VAT merchant, replace the following fields. Keep all connection and original-document fields from that complete event.')
patch={k:mixed[k] for k in ['document_type','gross_amount','tax_type','vat_inclusive','tax_basis_reference','items']}
r.code(json.dumps(patch,indent=2),8.2)
r.table(['Calculated bucket','PHP'],[['VATable base','100.00'],['VAT','12.00'],['VAT-exempt sales','50.00'],['Zero-rated sales','25.00'],['gross_amount','187.00']],[3,1])
r.text('This is a patch illustration, not a complete standalone request. Replace the sample tax-basis reference with the source evidence supporting your product classification. The result resolves to mixed_sales_invoice.','small')
# 13
r.page('03 / Payload examples','Prices, discounts and withholding')
r.table(['Input','Expected final amounts'],[['Inclusive price: 1 x PHP 112.00, vatable','Base 100.00 + VAT 12.00 = gross 112.00.'],['Exclusive price: 1 x PHP 100.00, vatable; vat_inclusive false','Base 100.00 + VAT 12.00 = gross 112.00.'],['Inclusive: 2 x PHP 112.00; discount_pct 10','Ordinary discount 22.40; base 180.00 + VAT 21.60 = gross 201.60.'],['Gross 112.00; withholding_tax 2.00','gross_amount remains 112.00. net_payable is 110.00. Include withholding_basis_reference.']],[2.4,2.6])
r.h('Reconciliation rules')
r.steps(['Choose one price basis for the whole document with vat_inclusive. Express unit_price in that basis.','Calculate each line using quantity, price and applicable discount; round currency amounts to centavos. Calculate each line\'s VAT, then sum the lines.','Send gross_amount as the final total including any VAT and after discounts, before withholding. The server recalculates and rejects a mismatch.','Compare the returned calculation to your original source document. Do not silently alter an already-issued original to force a match.'])
r.call('Do not pre-discount twice','For a statutory benefit, supply the original pre-benefit unit_price and mark eligible lines. NUERS applies the category calculation. A pre-discounted price plus benefit_eligible would apply the benefit again.')
r.text('Use integer centavos or a decimal library in your own accounting code. Binary floating-point addition should not determine monetary equality. The accepted document total is capped at PHP 10 billion.','small')
# 14
r.page('03 / Special sales','Senior, PWD, athlete and solo parent')
r.text('Send sales_benefit only when the merchant has checked the applicable eligibility evidence. The API records the category and calculates the declared benefit; a buyer label alone does not establish entitlement.')
r.table(['category','Accepted scope values'],[['senior_citizen or pwd','restaurant, hotel, recreation, admission, medicine, medical_dental, domestic_transport, funeral'],['national_athlete_coach','restaurant, hotel, recreation, admission, medicine, sports_equipment, domestic_transport'],['solo_parent','baby_milk, baby_food, micronutrient_supplements, diapers, medicine, vaccines, medical_supplements']],[1.5,3.5])
r.h('Required sales_benefit fields')
r.table(['Field','Rule'],[['category / scope','Use one supported category and a matching scope from the table.'],['beneficiary_name','2-200 characters.'],['id_number','2-100 characters.'],['evidence_reference','5-500 characters; a private source record reference.'],['eligibility_confirmed','true only after the merchant checks the evidence.']],[1.5,3.5])
r.text('Use document_type: <b>sales_invoice</b> and mark only eligible lines with <b>benefit_eligible: true</b>. One benefit category applies per document. Separate non-eligible lines and avoid combining different beneficiary categories in one event.')
r.call('Additional solo-parent fields','Provide child_birth_date, id_valid_until, income_below_250000: true and booklet_reference. Dates use YYYY-MM-DD. The API checks that the child is at most six years old on the original transaction date and that the identification is unexpired. For other categories, id_valid_until is optional but checked when supplied.')
r.text('These are API validation rules. Your merchant tax/compliance reviewer must establish the covered purchase and retain its underlying evidence. Do not send image attachments or full identity documents in the transaction JSON.','small')
# 15
r.page('03 / Special sales','Benefit example and expected totals')
r.text('Apply this patch to the complete event on page 11 for an eligible VAT-registered seller\'s PWD purchase. It replaces the tax, amount and items fields; other required event fields remain.')
pwd=next(x['payload']for x in cases if x['name']=='pwd')
r.code(json.dumps({k:pwd[k] for k in ['document_type','gross_amount','tax_type','vat_inclusive','sales_benefit','items']},indent=2),8.1)
r.text('For the same eligible PHP 1,120.00 VAT-inclusive pre-benefit line, the current calculator produces:','small')
r.table(['Category','Gross','Discount','VAT','VAT relief'],[['Senior / PWD','800.00','200.00','0.00','120.00'],['Athlete / coach','896.00','200.00','96.00','24.00'],['Solo parent','900.00','100.00','0.00','120.00']],[1.6,.85,.85,.85,.85])
r.text('Amounts above are PHP and apply only to this qualifying example. Ordinary discount_pct cannot be stacked on an eligible statutory-benefit line. Returned sale_category, statutory_discount_amount and vat_relief_amount support reconciliation and filtered reports.','small')
# 16
r.page('04 / Send requests','cURL and Postman quick start')
r.h('Submit one saved JSON file')
r.code('''curl --silent --show-error \\
  --connect-timeout 10 --max-time 45 \\
  --request POST \\
  "https://nuers.net/api/integrations/transactions" \\
  --header "X-NUERS-API-Key: ${NUERS_API_KEY}" \\
  --header "Content-Type: application/json" \\
  --header "Accept: application/json" \\
  --data-binary @event.json \\
  --output response.json \\
  --write-out 'HTTP %{http_code}\\n' ''')
r.text('event.json must contain a complete event. Save its exact content in your outbox first. A network timeout leaves the result unknown; keep the event pending and retry unchanged. Inspect response.json and the HTTP status together.')
r.h('Postman setup')
r.steps(['Create separate private Sandbox and Live environments. Set base_url to the API base and api_key as a secret variable. Do not export or share its current value.','Create a POST request to {{base_url}}. Add X-NUERS-API-Key: {{api_key}}, Content-Type: application/json and Accept: application/json.','Choose Body &gt; raw &gt; JSON. Paste the complete event with your assigned identifiers, then send in Sandbox.','Verify HTTP 201 and status: durably_recorded. Save id. Repeat the same request and expect HTTP 200 with duplicate: true and the same IDs.'])
r.call('Development tool, not the delivery queue','Postman and cURL help inspect a request. Your production connector still needs durable storage, controlled retries and response reconciliation. Avoid verbose command output that exposes authentication headers.')
# 17
r.page('04 / Sender code','Node.js: one controlled send')
r.text('Node.js 20+ example. The caller supplies the exact JSON already saved in its durable outbox. This function performs one attempt and returns an acknowledgement only after checking identity and environment.')
node='''export async function sendNuers(savedJson) {
  const event = JSON.parse(savedJson);
  const key = process.env.NUERS_API_KEY;
  const env = process.env.NUERS_ENVIRONMENT;
  if (!key || !['live', 'sandbox'].includes(env)) {
    throw new Error('Missing private connector configuration');
  }
  const url =
    'https://nuers.net/api/integrations/transactions';
  const res = await fetch(url, {
    method: 'POST', redirect: 'error',
    signal: AbortSignal.timeout(45000),
    headers: {
      'X-NUERS-API-Key': key,
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: savedJson
  });
  const body = await res.json().catch(() => null);
  const accepted = res.status === 201 ||
    (res.status === 200 && body?.duplicate === true);
  const valid = accepted && body?.id && body?.receipt_id &&
    body?.transaction_id && body.environment === env &&
    body.status === 'durably_recorded' &&
    body.nuers_status === 'success' &&
    body.source_event_id === event.source_event_id &&
    body.external_reference === event.external_reference;
  if (!valid) {
    const err = new Error('NUERS response needs review/retry');
    err.status = res.status;
    err.retryAfter = res.headers.get('retry-after');
    // Redact personal data before logging field-level errors.
    throw err;
  }
  return body; // Caller commits IDs + delivered state locally.
}'''
r.code(node,8.1);(TMP/'sender.mjs').write_text(node+'\n')
r.text('A thrown network/parse error must leave the outbox pending. Handle HTTP statuses using page 32. Store the returned IDs and delivered state atomically. Do not print savedJson or the API key.','small')
# 18
r.page('04 / Sender code','PHP: request and transport handling')
r.text('PHP 8.2+ with cURL. This is the first half of a single function; continue directly with page 19. It sends the JSON string already stored in your outbox.')
php1='''<?php
function sendNuers(string $savedJson): array
{
    $event = json_decode($savedJson, true, 64,
        JSON_THROW_ON_ERROR);
    $key = getenv('NUERS_API_KEY');
    $env = getenv('NUERS_ENVIRONMENT');
    if (!$key || !in_array($env, ['live', 'sandbox'], true)) {
        throw new RuntimeException('Missing connector config');
    }
    $retryAfter = null;
    $ch = curl_init(
        'https://nuers.net/api/integrations/transactions'
    );
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $savedJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'X-NUERS-API-Key: '.$key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_HEADERFUNCTION =>
            function ($ch, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($line, 12));
                }
                return strlen($line);
            },
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Transport outcome unknown');
    }
'''
r.code(php1,8.1)
# 19
r.page('04 / Sender code','PHP: validate the acknowledgement')
php2='''    $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    $accepted = $status === 201 ||
        ($status === 200 && ($body['duplicate'] ?? false) === true);
    $valid = $accepted && !empty($body['id']) &&
        !empty($body['transaction_id']) &&
        !empty($body['receipt_id']) &&
        ($body['environment'] ?? null) === $env &&
        ($body['status'] ?? null) === 'durably_recorded' &&
        ($body['nuers_status'] ?? null) === 'success' &&
        ($body['source_event_id'] ?? null) ===
            $event['source_event_id'] &&
        ($body['external_reference'] ?? null) ===
            $event['external_reference'];
    if (!$valid) {
        return ['acknowledged' => false,
            'http_status' => $status,
            'retry_after' => $retryAfter];
    }
    return ['acknowledged' => true, 'response' => $body];
}
'''
r.code(php2,8.3);(TMP/'sender.php').write_text(php1+php2)
r.h('How to connect the function to your worker')
r.steps(['Load one pending event from durable storage and take a worker lock so two local workers do not process it concurrently.','Call sendNuers with the stored original JSON. A thrown exception or acknowledged: false leaves it pending or marks it for operator review according to page 32.','If acknowledged is true, commit response.id, transaction_id, receipt_id, receipt_number and the delivered state in one local database transaction.','Release the worker lock. If the local commit fails, leave the event pending. A later unchanged retry should return the already-accepted IDs.'])
r.call('Complete the operational layer','These examples show authenticated sending and acknowledgement checks. Your application supplies persistent queues, database transactions, scheduling, alerting and access control. Do not use an in-memory loop as the only record of unsent sales.')
# 20
r.page('05 / Responses','Understand a successful acceptance')
r.text('A newly accepted single event returns <b>HTTP 201</b>. A previously accepted identical event returns <b>HTTP 200</b> with duplicate: true. The following is an abbreviated Live response; calculation and wallet contain more detail.')
r.code('''{
  "duplicate": false,
  "environment": "live",
  "id": "11111111-1111-4111-8111-111111111111",
  "transaction_id": "22222222-2222-4222-8222-222222222222",
  "receipt_id": "33333333-3333-4333-8333-333333333333",
  "receipt_number": "SAMPLE-INV-0001",
  "external_reference": "erpA:INV-0001",
  "record_mode": "source_capture",
  "source_event_id": "erpA:invoice:0001",
  "occurred_at": "2026-10-18T10:00:00+08:00",
  "accepted_at": "2026-10-18T10:00:03+08:00",
  "status": "durably_recorded",
  "nuers_status": "success"
}''')
r.table(['Check','What your connector must do'],[['Status + identity','Require the expected environment, durably_recorded, non-empty IDs and matching event / external reference.'],['Local commit','Save acknowledgement and original-to-NUERS ID mapping atomically.'],['Duplicate response','Treat an identical accepted duplicate as delivered. It is not another sale or another eligible charge.'],['Unexpected response','Do not mark delivered on an HTML login page, redirect, malformed JSON or mismatched identity.']],[1.25,3.75])
r.call('Storage acknowledgement','durably_recorded confirms that the transaction has been saved in NUERS. It does not mean the BIR has accepted it. Display NUERS Success. Government-status fields are not part of this merchant response.')
# 21
r.page('05 / Responses','Look up and reconcile a transaction')
r.code('''curl --silent --show-error --fail-with-body \\
  "${NUERS_API_BASE}/${NUERS_INTAKE_ID}" \\
  --header "X-NUERS-API-Key: ${NUERS_API_KEY}" \\
  --header "Accept: application/json"''')
r.text('NUERS_INTAKE_ID is the response field <b>id</b>. A successful lookup returns the stored acknowledgement and current customer email delivery state. The returned receipt_id is a different identifier. Store both under clear column names.')
r.table(['Scenario','Action'],[['POST succeeded, response saved','Use GET /{id} for later checks. Avoid repeatedly resubmitting a delivered event.'],['POST timed out; no id received','Repeat the exact saved event with the same key context. Duplicate protection returns the existing record if it committed.'],['GET returns 404','Check id, environment, merchant and assigned branch. Do not assume the original sale is absent and generate a new identity.'],['Customer email pending','Keep NUERS Success once recorded. Poll customer_receipt separately; email is asynchronous.'],['Dashboard and ERP differ','Compare environment, original number, event identity and returned IDs, then use request logs and a support ticket.']],[1.7,3.3])
r.h('Source-profile date coverage')
r.text('The automatic connection covers new originals from its effective creation time while the registered seller details remain compatible. Use the actual original occurred_at. Earlier documents or a historic change in seller registration need a suitable historical-source process through support.')
r.call('Accepted originals stay immutable','Do not modify an accepted event and resend it under the same identity. Do not change the receipt number or event ID to bypass a conflict. Preserve the original and request the supported correction process.')
# 22
r.page('05 / Wallet','Check pricing and available balance')
r.text('GET /wallet returns the current connection context and published pricing. Read the policy instead of hard-coding PHP 3 forever. Values named *_centavos are integer centavos: 300 means PHP 3.00.')
r.code('''{
  "environment": "live",
  "currency": "PHP",
  "available_centavos": 100000,
  "pricing": {
    "fee_centavos": 300,
    "minimum_transaction_centavos": 0,
    "revision": 2
  }
}''')
r.text('Abbreviated illustrative response. Use the actual pricing values returned by your wallet endpoint. Super Admin can set a positive minimum: a PHP 500 minimum exempts sales below PHP 500. A PHP 0 minimum charges every primary sale. Changes apply to new acceptances; earlier charges and unchanged retries keep their original result.')
r.table(['Default: PHP 3 per sale, minimum PHP 0','Fee'],[['Primary sale gross PHP 0.01','PHP 3.00'],['Primary sale gross PHP 499.99','PHP 3.00'],['Primary sale gross PHP 500.00','PHP 3.00'],['Identical retry of an accepted sale','No second charge'],['Supplementary payment receipt','No second primary-sale fee'],['Sandbox submission','No debit; simulated pricing only']],[3.7,1.3])
r.call('Insufficient balance: HTTP 402','An eligible Live write is rejected if the wallet cannot cover its charge. Fund the wallet, then resend the same event unchanged. The rejected attempt does not commit a new transaction or fee.')
r.text('A balance lookup is advisory. Another concurrent transaction may spend the balance before your POST. NUERS checks and charges during acceptance. The fee uses the final gross before withholding; withholding does not reduce the amount used for a configured minimum.','small')
# 23
r.page('05 / Batch','Send up to 50 events atomically')
r.text('POST to <b>'+BASE+'/batch</b> with a JSON object containing events. All events must match the key\'s assigned business and branch.')
r.code('''{
  "events": [
    { "...": "complete event A from page 11" },
    { "...": "complete event B with its own stable IDs" }
  ]
}''')
r.text('The placeholders above show the wrapper only; they are not valid payload fields. Replace each object with a complete event. An accepted batch returns HTTP 201 and this response shape:')
r.code('''{
  "atomic": true,
  "accepted": 2,
  "results": [
    { "...": "acceptance response for event A" },
    { "...": "acceptance response for event B" }
  ]
}''')
r.steps(['Queue complete immutable events first. Group 1-50 with the same environment and assigned branch.','Validate each result against its original source_event_id and external_reference; save each returned id. Do not rely only on the top-level accepted count.','If any new event fails validation or the wallet cannot cover the batch, the new writes and debits roll back. Previously accepted events still exist.','For an uncertain timeout, resend the same batch or its unchanged events. Existing accepted events return duplicates; do not generate replacements.'])
r.call('Count and rate limits','accepted counts result entries, including duplicates; it is not a count of new wallet charges. Batch requests also have a 10-per-minute route limit, in addition to key and shared limits. Honor HTTP 429 and Retry-After.')
# 24
r.page('06 / Reliability','Deliver sales after an internet outage')
top=r.nodes([(0,0,235,80,'1  Finalize locally','Commit the original document and a pending outbox row together.'),(276,0,235,80,'2  Send from the outbox','Use stored JSON and a server-held key when a connection is available.'),(0,133,235,80,'4  Reconcile locally','Save NUERS IDs and mark delivered in one local transaction.'),(276,133,235,80,'3  Verify acknowledgement','Check status, identity and environment before clearing the queue.')],235)
r.arrow(235,40,275,40,top);r.arrow(393,80,393,132,top);r.arrow(276,173,236,173,top)
r.h('Minimum durable outbox fields')
r.table(['Field group','Store'],[['Identity','environment, source_system, source_event_id, external_reference, receipt_number'],['Original event','Exact finalized JSON, original occurred_at and a local content hash'],['Delivery state','pending / sending / delivered / needs_review, attempt count, next attempt time'],['Acknowledgement','NUERS id, transaction_id, receipt_id, accepted_at, response environment'],['Operations','Redacted last error, timestamps and worker-lock / lease information']],[1.2,3.8])
r.call('A timeout is an unknown result','The server may have committed before the connection was lost. Leave the event pending and retry its original content. Never create a new event ID just because the first request timed out.')
r.text('NUERS cannot pull transactions from an offline ERP by itself. Your connector or a merchant-operated manual retry sends the retained originals after connectivity returns.','small')
# 25
r.page('06 / Reliability','Duplicate protection and retry policy')
r.text('NUERS checks stable source identity within the merchant, branch and environment. Keep source_system, source_event_id and the family-specific external_reference unchanged. The original document number is also protected.')
r.table(['Result','What to do'],[['201 accepted','Commit acknowledgement; stop retries for this event.'],['200 + duplicate: true','Same original already accepted. Save the existing IDs; stop.'],['Network failure / timeout / 5xx','Keep pending. Retry the identical event using backoff and jitter.'],['429','Wait according to Retry-After when provided, then retry unchanged.'],['402','Pause eligible Live sending, fund the wallet and retry unchanged.'],['401 / 403','Pause and fix the credential, scope, IP, expiry or account issue.'],['409','Stop automatic retries. Review identity/content or profile mismatch.'],['422','Inspect field errors. Correct unaccepted mapping data only after checking whether an earlier attempt was accepted.']],[1.55,3.45])
r.h('Suggested connector backoff')
r.text('For transient failures, start around 2 seconds and increase exponentially with random jitter, capped at 5 minutes. Honor a longer server Retry-After. Alert an operator after a sustained failure window; keep the pending row instead of dropping it.')
r.call('Preserve the accepted event','Do not replace occurred_at with the retry time, reorder items, round prices differently, change JSON strings to numbers, add nulls or regenerate IDs. Object-key order is normalized by NUERS, but payload values and types remain significant.')
r.text('Retries require a currently authorized key. Key rotation does not reset the identity of a sale. Never bypass a conflict by inventing a new receipt_number or external_reference.','small')
# 26
r.page('06 / Reliability','Merchant-operated manual retry')
r.text('Use this when the ERP has exported pending events but its automated sender is unavailable. It reuses the same transaction contract and duplicate checks.')
r.steps(['Export the finalized pending events from your system as JSON. Use an array of events or an object with an events array. Keep all original identifiers, amounts, timestamps and source context.','Open <b>Merchant &gt; API Integration &gt; Manual Retry</b>. Select the matching Live or Sandbox environment.','Import a file of at most <b>50 events and 2 MB</b>. This JSON limit is separate from Business Profile document-upload limits.','Protect the browser queue with a passphrase of at least 16 characters. Use a private workstation and your merchant-owned key with read and write scopes.','Review and send the queue. Branch and environment must match the key. Keep entries pending until their response confirms durable acceptance.','Reconcile successful responses back to the ERP and mark those original outbox rows delivered. Store the returned NUERS IDs.'])
r.call('Browser storage is a recovery aid','Keep the authoritative pending queue in your source system. Browser data can be cleared, and the API key is kept only in memory. Do not use the browser as the only copy of unsent transactions.')
r.h('After reconnecting the automated sender')
r.text('It may encounter an event already sent manually. Resend its original payload if its local acknowledgement is missing; duplicate protection returns the existing record. Reconcile that record and remove the pending state locally.')
r.text('If an event is rejected, preserve the error, original JSON and identity. Use the same review steps as the automated connector. Do not re-export the sale under a new identity just to make the queue empty.','small')
# 27
r.page('07 / Webhooks','Connect your HTTPS receiver')
r.text('Webhooks notify your system after an event is accepted. They complement status polling; they do not replace the durable acknowledgement returned by POST.')
r.steps(['Prepare an HTTPS callback route on a public domain that your organization controls. Use port 443. Keep Live and Sandbox receiver configuration separate.','Open <b>API Integration &gt; Webhooks</b>. Add a name, callback URL, environment and event subscriptions. Confirm ownership of the destination.','Save the one-time webhook signing secret in the receiver\'s secret manager. This is separate from your NUERS API key. Record its key version.','Deploy the signature verification and durable inbox described on pages 29-30. Configure the expected merchant_id and environment.','Choose <b>Verify Receiver</b>. Your route must verify the signed challenge and echo its nonce. Verification leaves delivery paused.','Choose <b>Enable</b>, then send a test event and inspect the delivery result. The test event is synthetic and does not create a sale.'])
r.call('Destination restrictions','Use a public HTTPS hostname on port 443, without credentials, query parameters or fragments. Private/local addresses, IP literals and redirects are rejected. Expose the actual receiver route directly.')
r.text('A callback URL cannot be generated for your ERP by NUERS because the endpoint must exist in your own system. If the receiver is not ready, leave callbacks paused and use GET /{id} for reconciliation.','small')
# 28
r.page('07 / Webhooks','Choose events and process the envelope')
r.table(['Event type','Meaning'],[['transaction.accepted','A transaction was durably accepted. Recommended general subscription for a source connector.'],['source.received','An original source document was captured.'],['invoice.issued','A new original was issued through the separate NUERS issuance flow.'],['payment.recorded','A payment event was recorded through its applicable flow.'],['webhook.test','Synthetic receiver test; do not book a sale.'],['webhook.endpoint_challenge','Receiver verification challenge; echo the signed nonce.']],[1.65,3.35])
r.code('''{
  "id": "44444444-4444-4444-8444-444444444444",
  "type": "transaction.accepted",
  "environment": "sandbox",
  "merchant_id": "<your merchant UUID>",
  "subject_id": "<accepted intake UUID>",
  "occurred_at": "2026-10-18T10:00:03+08:00",
  "data": { "id": "<accepted intake UUID>",
            "status": "durably_recorded" }
}''')
r.text('Abbreviated envelope. data includes acceptance metadata; your handler should tolerate additional fields. It does not carry the API key or a full line-item/customer payload. Fetch details using the accepted intake id and your configured API base when needed.')
r.call('Avoid counting a sale twice','transaction.accepted and source.received can describe the same source capture. Subscribe to one for your business action, or deduplicate that action by the accepted subject / intake id as well as the delivery event id.')
# 29
r.page('07 / Webhooks','Verify every signed request')
r.table(['Header','Value'],[['X-NUERS-Event','Webhook event UUID.'],['X-NUERS-Timestamp','Unix timestamp in seconds.'],['X-NUERS-Signature','v1= followed by a 64-character SHA-256 HMAC hex value.'],['X-NUERS-Environment','live or sandbox.'],['X-NUERS-Key-Version','Signing-secret version configured for this receiver.'],['Content-Type','application/json.']],[1.8,3.2])
r.h('Signature input')
r.code('''message = timestamp + "." + event_id + "." + raw_body
expected = HMAC_SHA256_HEX(signing_secret, message)
header   = "v1=" + expected''')
r.steps(['Read the exact raw request bytes once. Reject duplicate security headers, malformed signatures and timestamps more than 300 seconds from your synchronized server clock.','Compute the HMAC before parsing JSON. Compare it in constant time. Do not reserialize JSON, trim the body or normalize whitespace before signing.','After signature verification, parse JSON and require matching event id, expected environment and merchant_id. Check key version against the receiver configuration.','For a challenge, respond HTTP 200 with {"nuers_challenge": "received nonce"}. For business events, commit a durable inbox row before returning 2xx.'])
r.text('Challenge nonces are 48 characters and expire after 10 minutes. Start a new verification if expired. Do not reuse challenge handlers as a general unauthenticated echo endpoint.','small')
# 30
r.page('07 / Receiver code','PHP signature-verification core')
r.text('Call this before your business logic. The HTTP adapter must reject duplicate security headers and supply lower-case header names, the raw body and private receiver settings.')
verify='''<?php
function verifyNuers(string $raw, array $h, array $cfg): array
{
    $id = $h['x-nuers-event'] ?? '';
    $ts = $h['x-nuers-timestamp'] ?? '';
    $sig = $h['x-nuers-signature'] ?? '';
    if (strlen($raw) > 65536 ||
        !preg_match('/^[a-f0-9-]{36}$/D', $id) ||
        !ctype_digit($ts) || strlen($ts) > 12 ||
        abs(time() - (int)$ts) > 300 ||
        !preg_match('/^v1=[a-f0-9]{64}$/D', $sig) ||
        ($h['x-nuers-environment'] ?? '') !== $cfg['environment'] ||
        ($h['x-nuers-key-version'] ?? '') !== $cfg['key_version']) {
        throw new RuntimeException('Invalid webhook headers');
    }
    $mac = hash_hmac('sha256', $ts.'.'.$id.'.'.$raw, $cfg['secret']);
    if (!hash_equals($mac, substr($sig, 3))) {
        throw new RuntimeException('Invalid webhook signature');
    }
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (($event['id'] ?? null) !== $id ||
        ($event['environment'] ?? null) !== $cfg['environment'] ||
        ($event['merchant_id'] ?? null) !== $cfg['merchant_id']) {
        throw new RuntimeException('Unexpected webhook identity');
    }
    return $event;
}'''
r.code(verify,8.05);(TMP/'verify-webhook.php').write_text(verify+'\n')
r.text('cfg contains secret, key_version as a string, environment and merchant_id. Map verification failures to HTTP 401; do not process the body. Validate the event type, handle signed challenges, then apply the inbox rules on page 31.')
r.call('Complete the HTTP route','Read php://input once, verifyNuers first, then handle the signed challenge or commit the business event to your inbox. Return JSON with Content-Type: application/json. Do not return 2xx before persistence succeeds.')
# 31
r.page('07 / Reliability','Webhook inbox, retries and rotation')
r.h('Acknowledge only after durable storage')
r.steps(['Begin a local database transaction and insert the verified raw event under a unique (environment, event_id) key. Store its body hash and pending processing state.','If the key already exists and the hash matches, return the existing successful acknowledgement. If the content differs, reject it and alert an operator.','Commit before returning HTTP 200 or another 2xx. If persistence is unavailable, return 503 so delivery can retry.','Process the inbox asynchronously. Upsert the existing source-to-NUERS mapping; do not create a second ERP sale. Events can arrive out of order.'])
r.table(['Delivery behavior','Receiver / merchant action'],[['Timeout','NUERS waits up to 8 seconds. Keep acknowledgement fast and process asynchronously.'],['Transient failures','Automatic retry uses backoff and jitter, up to 12 attempts within 24 hours.'],['Non-retry responses','3xx and 400, 401, 403, 404, 405, 410, 413 or 422 stop automatic retries. Fix the cause before manual retry.'],['Manual retry','Up to three retry cycles. Event id and body stay stable; deduplicate them.'],['Pause / revoke / change','Pending deliveries incompatible with the new policy are cancelled. An already-in-flight request can still arrive.'],['Secret rotation','Rotation pauses delivery. Deploy the new secret/version, verify again, then enable.']],[1.3,3.7])
r.call('Recover from missed callbacks','Compare the source outbox, accepted intake IDs and merchant records. Poll GET /{id} when delivery is delayed or a callback is unavailable. Callback delivery status does not change whether the original POST was accepted.')
# 32
r.page('08 / Troubleshooting','HTTP status and recovery reference')
r.table(['Status','Likely cause / safe next step'],[['200','Read succeeded, or identical POST duplicate. Check the full response.'],['201','New event / accepted batch. Validate each acknowledgement before clearing the queue.'],['401','Missing or invalid key, or conflicting credentials. Load the correct secret; never paste it into a ticket.'],['402','Insufficient wallet balance. Fund wallet and resend unchanged.'],['403','Expired/revoked key, scope, egress IP, inactive account or assigned-branch mismatch. Fix the stated policy issue.'],['404','Unknown record or wrong merchant, branch or environment. Confirm the returned intake id.'],['409','Source identity/content conflict, duplicate original number or source-profile mismatch. Stop and investigate.'],['413','Request exceeds an edge/application limit. Reduce batch size; preserve each event unchanged.'],['415','Send Content-Type: application/json.'],['422','Invalid fields, totals, tax treatment or benefit evidence. Read message and errors. Correct the unaccepted mapping carefully.'],['429','Quota or rate limit. Honor Retry-After; slow the connector.'],['5xx / network timeout','Outcome may be unknown. Retain the event and retry unchanged with backoff.']],[.7,4.3])
r.h('Typical field-validation response')
r.code('''{
  "message": "The given data was invalid.",
  "errors": { "items.0.quantity": ["Validation detail"] }
}''')
r.text('Illustrative error structure. Some failures return only message; an edge failure can be non-JSON. Keep HTTP status and a redacted diagnostic, not raw credentials or beneficiary details.','small')
# 33
r.page('08 / Security','Protect the connection in production')
r.table(['Area','Required connector practice'],[['Credentials','Server-side secret manager, least scopes, restricted access, expiry reminders and controlled rotation.'],['Network','HTTPS verification enabled; stable public egress allowlist; no redirect-following with credentials.'],['Tenant / environment','One configured merchant context per connector; separate Live/Sandbox secrets, queues and webhook inboxes.'],['Payload integrity','Persist immutable source JSON; use stable original IDs; restrict changes after finalization.'],['Privacy','Protect customer and beneficiary details in databases, backups and exports. Use evidence references instead of attachments.'],['Logs','Record correlation IDs, timestamps, status and redacted field errors. Never log API keys, signing secrets or full identity evidence.'],['Webhook ingress','HMAC on raw bytes, timestamp window, duplicate-header rejection, durable replay protection and synchronized clocks.'],['Operations','Monitor queue age, wallet blocks, expiry, repeated validation errors and webhook failures; assign an owner for alerts.']],[1.25,3.75])
r.h('Recommended operational alerts')
r.text('Alert when pending Live events exceed your normal delivery window, an account/key returns 401 or 403, wallet rejections persist, or a batch repeatedly fails. Track oldest pending age and reconciliation mismatches, not only request counts.')
r.call('If a credential is exposed','Revoke it in API Integration, create a replacement and update your connector securely. Review request logs and affected time ranges. Use stable event identities when resuming pending traffic.')
# 34
r.page('09 / Launch checklist','Prove the connection before going Live')
r.table(['Test','Expected evidence'],[['Authentication','Wallet lookup succeeds for the intended merchant and environment; wrong key is rejected.'],['Single capture','Synthetic Sandbox sale returns 201 and expected tax amounts.'],['Identical retry','Same event returns 200 duplicate with the same accepted IDs.'],['Conflicting retry','Changed content under the same identity is rejected with 409.'],['Tax / benefits','Your supported product treatments and eligible categories reconcile to the original amounts.'],['Network loss','A simulated lost response leaves the queue pending; resend resolves without a second sale.'],['Batch','Valid batch acknowledges each event; one invalid event does not commit other new events.'],['Environment boundary','Sandbox records and acknowledgements cannot clear Live rows or debit the wallet.'],['Webhook, if used','Challenge passes; test event commits once; invalid signatures fail; duplicate delivery causes no second business action.'],['Operations','Expiry alerts, wallet funding, backups and a named support owner are ready.']],[1.3,3.7])
r.h('Controlled Live cutover')
r.steps(['Use the assigned Live source configuration and a Live key. Confirm account status, current pricing, sufficient balance and the original-date coverage.','Send one authorized actual finalized business event. Do not turn the printed sample sale into a Live record.','Reconcile the original, returned IDs, calculated amounts and wallet entry. Then enable normal traffic and monitor the first operating period.'])
# 35
r.page('09 / Operations','Reconcile records and request support')
r.h('Merchant dashboard checks')
r.text('Use <b>API Integration &gt; Usage &amp; Logs</b> for request outcomes and <b>Webhooks</b> for delivery status. Select the same environment as your connector. Compare accepted originals in <b>Invoices &amp; Receipts</b>, and reconcile eligible charges in <b>Wallet</b>.')
r.text('Use <b>Sales Reports</b> to filter the relevant date range and sales category, including senior citizen, PWD, national athlete/coach and solo parent. Keep the same filters and environment when exporting so totals match your ERP reconciliation.')
r.table(['Daily comparison','What should match'],[['Original identity','Source system, event ID, external reference, number and original date.'],['Document amounts','Gross, VAT buckets, withholding and net payable.'],['Special sales','sale_category, statutory_discount_amount and vat_relief_amount.'],['Delivery','Every finalized outbox row is either acknowledged or visibly pending / under review.'],['Wallet','Eligible newly accepted Live sales have the expected policy charge; duplicates have no second charge.']],[1.3,3.7])
r.h('Send a useful support request')
r.text('Open <b>Merchant &gt; Submit a Request</b>. Include your business name, environment, endpoint/method, timestamp with timezone, HTTP status, redacted error message, source_event_id, external_reference and returned intake id if available. Describe whether the issue is a first attempt or a retry.')
r.call('Keep the report safe','Never include the API key, webhook signing secret, MFA code or full beneficiary identification. Share a redacted payload only when requested through the authorized support process. Preserve the original privately for reconciliation.')
# 36
r.page('Quick reference','Connection essentials at a glance')
r.table(['Item','Value / action'],[['Base URL',BASE],['Authentication','X-NUERS-API-Key; HTTPS; server-side use only'],['Schema','NUERS-ingestion-1'],['Mode for ERP originals','source_capture, explicitly supplied'],['Setup','Active account; name + environment; automatic assigned business/source context'],['Primary document','sales_invoice + invoice.finalized'],['Supplementary payment','payment_receipt + payment.recorded'],['Payload limit','1-100 items/event; batch 1-50 events'],['Acknowledgement','201 new, or 200 with duplicate: true; status durably_recorded; verify environment and IDs'],['Retry identity','Same original JSON, source IDs, reference, number and occurred_at'],['Wallet rejection','402: fund and resend unchanged'],['Callback security','HMAC-SHA256 of timestamp.event_id.raw_body; verify before parsing'],['Support','Merchant dashboard &gt; Submit a Request']],[1.45,3.55])
r.h('Manual discounts and version')
r.text('Edition 1.4, issued 29 October 2026 for contract NUERS-ingestion-1. Manual amount/percentage discounts are detailed on pages 37-38. This guide covers connecting a merchant-owned system and saving its already-issued originals. Separate original issuance, historical-source review and BIR reporting controls are outside this connection workflow.')
r.text('Use the current values shown in the merchant dashboard for credentials, source identifiers, expiry, limits and pricing. Keep this guide with your connector implementation and retest its acceptance suite when adopting a later contract version.','small')

# 37 / Manual discount extension
r.page('10 / Manual discounts','Amount or percentage, invoice or line')
r.text('Send original unit prices before discount. Add the following object at the top level to discount the whole invoice, or inside one items[] entry to discount that line only.')
r.code('''
"manual_discount": {
  "type": "amount",
  "value": "112.00",
  "reason": "Loyalty discount"
}
''')
r.table(['Field','Accepted input'],[['type','amount for a fixed PHP amount, or percentage for 0-100%.'],['value','Non-negative; at most two decimals. Use a two-decimal string. Amount cannot exceed the original line/document price.'],['reason','Required non-blank text, maximum 500 characters. Record the actual discount reason.']],[1,4])
r.h('One VAT-inclusive item: PHP1,120.00')
r.table(['Discount method','Expected result'],[['amount: 112.00','Manual discount 112.00; VATable base 900.00; VAT 108.00; gross_amount 1008.00.'],['percentage: 10.00','Same result. Existing items[].discount_pct: 10 also remains supported.']],[1.4,3.6])
r.text('With vat_inclusive=true, discount amounts include VAT. With false, they reduce the pre-VAT price before VAT is added. A fixed line discount applies to the whole quantity extension, not separately to every unit. VAT follows the recorded tax type of each discounted line.','small')
r.call('Keep totals consistent','gross_amount is the final discounted total including VAT and before withholding. Do not pre-discount unit_price and also send manual_discount. Invalid values or inconsistent totals return HTTP 422 before financial records are committed.')
# 38
r.page('10 / Manual discounts','Allocation, reconciliation and reporting')
r.steps(['Choose either one document discount or line discounts. A document discount cannot be combined with any line discount or sales_benefit.','Document discounts allocate proportionally by each original line extension. NUERS uses integer centavos and distributes leftover centavos to the largest fractional shares; original line order breaks ties. Use per-line fixed amounts if your ERP uses a different allocation.','A line manual_discount cannot be combined with non-zero discount_pct or a statutory-eligible line. A basket may have statutory-eligible items and separate non-eligible lines with manual discounts.','Compare calculation.manual_discount_amount, discount_amount (manual plus statutory), statutory_discount_amount, VAT buckets and gross_amount to the original invoice. Gross totals already contain the reduction; do not subtract it again.'])
r.table(['Where','What is recorded'],[['API response / source record','Manual and statutory totals separately; line discount_reason plus discount_pct or fixed_discount_amount in PHP. fixed_discount_amount is a response field, not a request input.'],['Merchant and admin records','Manual Discount is shown separately from Statutory Discount and VAT Adjustment. Filtered CSV, sales XLSX/PDF and document copies retain the breakdown.'],['Sandbox','Same validation and discount calculation; test records stay isolated and do not debit the Live wallet.'],['Wallet / retries','Fee eligibility uses final discounted gross. Identical retries have no second charge; changing discount or reason under an accepted identity returns 409.']],[1.1,3.9])
r.call('Preserve the original','These fields record an ordinary discount reflected at sale time. Later conditional rebates require the appropriate correction workflow. Existing accepted documents are not silently changed to add missing discount details.')
r.text('Tax reference: BIR RR 16-2005, section 4.106-9, addresses invoiced at-sale discounts for goods. Product eligibility and the applicable tax basis remain the responsibility of the merchant. <link href="https://bir-cdn.bir.gov.ph/BIR/pdf/26116rr16-2005.pdf" color="#0067ff">BIR published regulation</link>.','small')


contract=json.loads((ROOT/'resources/data/merchant-api-receipts.json').read_text())
r.page('11 / Connector sales dashboard','Show a truthful NUERS status')
r.text(contract['dashboard_requirement'])
r.table(['Dashboard state','When to display it'],[['Pending','The local sale is saved but has not yet received a valid NUERS acknowledgement.'],['Success','Matching acknowledgement with nuers_status=success and status=durably_recorded. Store all returned NUERS IDs.'],['Error + reason','A known rejection. Show error.reason and relevant field messages. Keep the source sale for correction or funding.'],['Pending confirmation','Timeout, malformed response or error.outcome=unknown. Retry the identical event; never create a replacement identity.']],[1.1,3.9])
r.text(contract['errors'])
r.call('NUERS status in the sales row',contract['bir_fields'])
r.text('Download the small connector UI adapter from https://nuers.net/guides/NUERS-Transaction-Status.mjs. Use it in your backend with the saved source event and configured environment. Render messages as plain text. Your ERP/POS developer must connect these fields to its own sales dashboard.','small')
r.page('11 / API response contract','Success and error examples')
r.text('Selected fields; all original identifiers, calculated amounts and wallet details remain available. The event is recorded before the success response is returned.','small')
r.code('''
{
  "nuers_status": "success", "status": "durably_recorded",
  "environment": "live", "duplicate": false,
  "id": "<inbox-id>", "transaction_id": "<transaction-id>",
  "receipt_id": "<receipt-id>",
  "source_event_id": "erpA:invoice:0001",
  "external_reference": "erpA:INV-0001", "error": null,
  "customer_receipt": {
    "delivery_id": "<delivery-id>", "status": "queued",
    "reason": "Customer receipt is queued for email processing."
  }
}
''',7.7)
r.h('Rejected request: HTTP 422')
r.code('''
{
  "nuers_status": "error", "code": "VALIDATION_FAILED",
  "message": "The customer email is invalid.",
  "error": {
    "code": "VALIDATION_FAILED",
    "reason": "The customer email is invalid.",
    "retryable": false, "outcome": "rejected"
  },
  "errors": {"customer_email": ["Use one valid email address."]}
}
''',7.7)
r.text('Actual wording follows the validator. Branch on HTTP status and machine code, never on English message text. retryable=true means a bounded unchanged retry is appropriate; 402 requires funding first. For atomic batches, use results[] only after the entire batch succeeds.','small')
r.page('12 / Customer e-receipts','Map checkout email and saved branding')
r.code('''
"customer_name": "SAMPLE Customer",
"customer_email": "customer@example.test"
''')
r.text(contract['customer_email'])
r.text(contract['receipt_delivery'])
r.h('Use the merchant receipt design')
r.text(contract['branding'])
r.steps(['Merchant opens Invoice Settings, saves the dedicated E-Receipt Logo, colors, layout, footer and receipt preferences.','Connector commits the customer email with the finalized source payload and sends it with the same immutable event identity.','NUERS commits the sale and encrypted email job together, then returns Success plus customer_receipt.status=queued.','The email worker renders the same merchant document as a PDF attachment and sends it through configured platform SMTP.'])
r.call('One copy per accepted event','Keep customer_email unchanged on an identical retry. Changing it under an already accepted source identity returns 409. Use the merchant document Send Copy action for a separately requested copy; do not create another sale.')
r.page('12 / Customer email operations','Track delivery independently')
r.table(['customer_receipt.status','Meaning'],[['not_requested','No checkout email supplied.'],['suppressed_sandbox','No customer email is sent in Sandbox.'],['queued / preparing / sending','Asynchronous work is waiting or in progress.'],['smtp_accepted','SMTP accepted the message; inbox delivery is not confirmed.'],['failed / expired / cancelled','Mail preparation, queue lifetime or account/document eligibility prevented sending.'],['uncertain','SMTP or worker outcome is unknown. Investigate before a manual resend.']],[1.55,3.45])
r.text(contract['delivery_status'])
r.h('Connector acceptance checklist')
r.steps(['Show NUERS Status, environment, reference, last attempt and the error reason on every sales row.','Use synthetic addresses and separate Sandbox keys during mapping tests. Sandbox will not send test mail.','Test missing/invalid email, retry deduplication, batch rollback and branded PDF output in isolated fixtures.','Confirm the production email worker and SMTP configuration. Monitor delivery history; do not claim delivered when only smtp_accepted is known.'])
r.text('Use the public API reference, this PDF and the merchant connection guide from the same release: '+contract['version']+'. Existing records are not emailed retroactively.','small')

r.page('13 / Withholding & corrections','Buyer-confirmed CWT')
r.text(contract['withholding_details'])
r.code(json.dumps({'withholding_tax':'10.00','withholding_basis_reference':'BUYER-CONFIRMATION-001','withholding_details':{'withholding_agent':True,'classification':'Buyer-confirmed withholding agent','atc':'WC160','rate':'1.00','base':'1000.00','amount':'10.00','evidence_reference':'BUYER-CONFIRMATION-001'}},indent=2))
r.call('Example only','ATC and rate are illustrative. The buyer/payor must determine the applicable classification and rate. A PHP 1,120 invoice with PHP 10 CWT still records PHP 1,120 gross and PHP 120 VAT; net payable is PHP 1,110.')
r.page('13 / Withholding & corrections','Preserve the original')
r.text(contract['withholding_corrections'])
r.text(contract['corrections'])
r.steps(['Open Compliance Operations and choose CWT adjustment memos.','Select the original invoice; provide buyer-confirmed classification, ATC, rate, base, amount and evidence.','Review the draft. Official issuance requires verified authority and an active credit-memo series.','Download the issued memo and retain the original invoice. Later corrections use the latest issued CWT adjustment.'])
r.page('14 / Outage recovery','Authorized manual invoices')
r.text(contract['manual_recovery'])
r.code(json.dumps({'manual_recovery_id':'00000000-0000-4000-8000-000000000002','manual_invoice_number':'SAMPLE-MANUAL-001'},indent=2))
r.steps(['Record the outage and retain its evidence.','Register each authorized manual invoice with its original number, branch, buyer, date, gross amount and authority reference.','Record verified service restoration. Track the replacement deadline shown in the register.','Send the replacement from your owned system with the recovery fields and matching original identity; preserve its acknowledgement.','Review unreconciled manual invoices. A retry must use the identical original event; a second replacement is rejected.'])
r.page('15 / Platform controls','Readiness and fee invoices')
r.text(contract['source_intake_policy'])
r.text('NUERS service-fee statements use posted wallet debits and cannot bill a debit twice. An administrator prepares the billing period. The merchant can review and download the statement under Compliance Operations. Official service invoices require a separate registered NUERS billing entity, verified issuance authority and an active invoice series. Preparing or issuing a fee document does not debit the merchant wallet again.')
r.call('External evidence remains separate','BIR credentials and transmission, actual accreditation, independent security certifications and production disaster-recovery evidence cannot be replaced by local software tests. Your records continue to be available under the current approved operational scope.')

r.page('16 / Invoice identity','Preserve the seller invoice number')
r.text(contract['invoice_numbering'])
r.table(['Record mode','Numbering behavior'],[['source_capture','Retains the original finalized ERP/POS number. The connector must enforce the source system’s registered series; NUERS does not allocate a second invoice number for the copy.'],['nuers_issuance','Reserves the next number in the configured business / branch / document-family series. Checks its active period, range, recorded authority and collisions. A failed issuance rolls back the reservation.'],['Existing originals','Never renumber, overwrite or reuse a number, including a voided number. A legal correction has its own document identity and references the original.'],['Preview / draft','A unique DRAFT reference identifies unsaved work. Only successful issuance allocates a registered serial; a draft is not a tax invoice.']],[1.2,3.8])
r.h('Buyer and related-document mapping')
r.text(contract['buyer_identity'])
r.text(contract['invoice_reference'])
r.call('Keep identity stable on retry','Use the same complete payload, source_event_id and external_reference. After acceptance, changes to buyer, number, registration or financial data conflict with the stored event. Do not invent a new identity to bypass a conflict.')
r.text('Reference: BIR EIS JSON Format v2.1 (CompInvoiceId); RR 7-2024, Section 6; RMC 98-2026. Official API guide: https://eis-cert.bir.gov.ph/#/apiGuide. BIR-issued credentials and authority remain separate prerequisites.','small')
r.page('16 / Printed evidence','Invoice and receipt fields')
r.text(contract['document_registration'])
r.code(json.dumps({'customer_type':'business','invoice_reference':'SAMPLE-ORIGINAL-000001','document_registration':{'system_type':'CAS','pti_number':'SAMPLE-PTI-REFERENCE','ac_number':'SAMPLE-AC-REFERENCE','authority_issued_at':'2026-01-01','series_start':'SAMPLE-000001','series_end':'SAMPLE-999999'}},indent=2),7.7)
r.text('Synthetic references only. Replace them with authentic recorded evidence; never send example values to claim authority. For POS, also map the MIN and machine serial or software license, plus final PTU. For a non-VAT seller, non_vat_basis distinguishes percentage_tax from an evidenced exempt basis.','small')
r.table(['Shared preview / email / PDF','Printed content'],[['Seller and buyer','Registered seller name/address, VAT or Non-VAT Reg TIN and five-digit branch; applicable buyer name/address/TIN.'],['Items and amounts','Description, optional unit, quantity, unit cost, discounts, VAT classifications and separate totals. Buyer-confirmed CWT includes ATC, rate and base; it reduces net payable, not sales/VAT.'],['Evidence and copy','Recorded authority, date and series; original-invoice reference; REPRINT. Supplementary documents carry the input-tax warning. Unknown evidence stays not recorded.']],[1.55,3.45])
r.text('Design stays consistent with Merchant Invoice Settings. RR 7-2024 specifies invoicing information; RMC 112-2026 addresses CWT presentation. These fields and local validation do not establish BIR approval or acceptance.','small')

r.save()
