"""Update the maintained API reference without rebuilding unrelated endpoint contracts."""
import copy
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = '2026-10-29.invoice-documents'
number_note = 'Original seller invoice/document number: 1–50 characters; no control characters or surrounding whitespace. Preserve prefixes and leading zeros. This is EIS CompInvoiceId, not the separate 24-character EisUniqueId. Source capture preserves the source number; NUERS issuance uses the next registered series number. Never renumber accepted originals.'
registration_note = 'Optional recorded source registration evidence. system_type: CAS, POS or ELECTRONIC. Supply authentic applicable PTI, PTU/AC, authority issue date, series range and POS MIN/machine/software identifiers. This snapshot is printed on copies; supplying it does not establish BIR approval. Missing historical evidence is shown as not recorded. Native NUERS issuance requires an evidence-backed series.'
buyer_note = 'business, retail or walk_in. business requires the buyer registered name, registered address and supported nonzero TIN. B2C address/TIN are optional. Declare the actual buyer type.'
reference_note = 'Original invoice number for a related payment or additional invoice, maximum 50 characters. Existing originals remain immutable. This reference is printed on the receipt; it does not cancel or replace the original.'
fields = [
    dict(name='customer_type',type='string',required=False,description=buyer_note),
    dict(name='invoice_reference',type='string',required=False,description=reference_note),
    dict(name='document_registration',type='object',required=False,description=registration_note),
]

def save(name, data):
    (ROOT/name).write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n')

catalog=json.loads((ROOT/'resources/data/developer-catalog.json').read_text())
catalog['version']=VERSION
for endpoint in catalog['endpoints']:
    if endpoint['path']=='/api/integrations/transactions' and endpoint['method']=='POST':
        endpoint['fields']=[f for f in endpoint['fields'] if f['name'] not in {x['name'] for x in fields}]+fields
        for f in endpoint['fields']:
            if f['name']=='receipt_number': f['description']=number_note
        endpoint['notes']=[n for n in endpoint.get('notes',[]) if not n.startswith('Printed document fields:')]
        endpoint['notes'].append('Printed document fields: items[].unit (optional, max 40 characters), line tax classification, original invoice reference, buyer-confirmed CWT details and source registration metadata use the shared merchant preview/email/PDF renderer. Copies are labelled REPRINT; supplementary receipts are not primary invoices.')
save('resources/data/developer-catalog.json',catalog)

spec=json.loads((ROOT/'resources/data/developer-openapi.json').read_text())
spec['info']['version']=VERSION
schema=spec['paths']['/api/integrations/transactions']['post']['requestBody']['content']['application/json']['schema']
p=schema['properties']
p['receipt_number'].update(description=number_note,minLength=1,maxLength=50,pattern=r'^[^\s\x00-\x1F\x7F](?:[^\x00-\x1F\x7F]*[^\s\x00-\x1F\x7F])?$')
p['customer_type']={'type':['string','null'],'enum':['business','retail','walk_in',None],'description':buyer_note}
p['invoice_reference']={'type':['string','null'],'maxLength':50,'pattern':r'^[^\x00-\x1F\x7F]*$','description':reference_note}
props={k:{'type':['string','null'],'maxLength':120,'pattern':r'^[^\x00-\x1F\x7F]*$'} for k in ['pti_number','ptu_number','ac_number','machine_identification_number','machine_serial_number','software_license_number']}
props['system_type']={'type':'string','enum':['CAS','POS','ELECTRONIC']}
props['authority_issued_at']={'type':['string','null'],'format':'date','description':'Authentic issue date, YYYY-MM-DD; not in the future.'}
for k in ['series_start','series_end']:props[k]={'type':['string','null'],'maxLength':50,'pattern':r'^[^\x00-\x1F\x7F]*$','description':'Full recorded boundary including prefix and leading zeros.'}
props['non_vat_basis']={'type':['string','null'],'enum':['percentage_tax','exempt',None],'description':'Applicable seller tax basis; use reviewed evidence, not an inferred exemption.'}
p['document_registration']={'type':['object','null'],'additionalProperties':False,'required':['system_type'],'properties':props,'description':registration_note}
p['items']['items']['properties']['unit']={'type':['string','null'],'maxLength':40,'description':'Recorded unit of measure, printed with the item.'}
batch=spec['paths']['/api/integrations/transactions/batch']['post']['requestBody']['content']['application/json']['schema']
batch['properties']['events']['items']={'$ref':'#/components/schemas/IngestionEvent'}
spec.setdefault('components',{}).setdefault('schemas',{})['IngestionEvent']=copy.deepcopy(schema)
save('resources/data/developer-openapi.json',spec)

contract=json.loads((ROOT/'resources/data/merchant-api-receipts.json').read_text())
contract.update(version=VERSION,invoice_numbering=number_note,document_registration=registration_note,buyer_identity=buyer_note,invoice_reference=reference_note)
save('resources/data/merchant-api-receipts.json',contract)
