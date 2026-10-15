"""Map only uniquely identified localities explicitly named by the RDO directory.
This does not expand a headquarters into province-wide jurisdiction.
Split-city and freeport scopes remain unresolved until specific source rules exist.
"""
import json,re,unicodedata
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=root/'resources/data/registration-rdos.json'
reference=json.loads(p.read_text());geo=json.loads((root/'resources/data/philippine-addresses.json').read_text())
def norm(s):
 s=''.join(c for c in unicodedata.normalize('NFKD',s.lower()) if not unicodedata.combining(c))
 s=re.sub(r'\b(city of|municipality of|city|municipality)\b','',s)
 return re.sub(r'[^a-z0-9]','',s)
def province(s):return norm(re.sub(r'\b(north|south|east|west|central)\b','',s,flags=re.I)).replace('mtprovince','mountainprovince')
aliases={'100':'Ozamiz'}
skip={'018','021A','019','021C','028','029','030','031','032','033','034','038','039','040','047','048','049','050','081','082','113A','113B'}
added=[]
for rdo,office in reference['offices'].items():
 if rdo in skip:continue
 parts=office['rdo_name'].split(',');place=aliases.get(rdo,parts[0]);qualifier=province(parts[1]) if len(parts)>1 else None
 matches=[]
 for city in geo['cities'].values():
  if city.get('is_district') or norm(city['name'])!=norm(place):continue
  parent=geo['provinces'].get(city['parent'],{})
  if re.search(r'\bcity\b',place,re.I) and 'city' not in city['name'].lower():continue
  if qualifier and province(parent.get('name',''))!=qualifier and not(parent.get('is_group') and re.search(r'\bcity\b',place,re.I)):
   if not(rdo=='107' and city['code']=='1908703000'):continue
  matches.append(city)
 if len(matches)!=1:continue
 city=matches[0]
 if city['code'] in reference['city_rules']:continue
 reference['city_rules'][city['code']]=rdo
 reference.setdefault('city_rule_sources',{})[city['code']]={'rdo_code':rdo,'scope':'Named city / municipality only; not a province-wide inference','sources':[reference['source']],'reviewed_at':'2026-10-15','basis':'Unique PSGC locality explicitly named in the official RDO directory'}
 added.append((rdo,city['name']))
p.write_text(json.dumps(reference,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'added':len(added),'rules':len(reference['city_rules']),'localities':added},ensure_ascii=False))
