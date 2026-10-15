"""Compact a downloaded fish-and-bear/psgc Q1 2026 core-data directory for NUERS.
Usage: python3 scripts/build-registration-geography.py /path/to/core-data
The source directory must contain regions/provinces/cities/barangays.json and LICENSE.
"""
import json, pathlib, shutil, sys
source=pathlib.Path(sys.argv[1]); target=pathlib.Path(__file__).resolve().parents[1]/'resources/data'
geo={}
source_cities={x['psgc_code']:x for x in json.loads((source/'cities.json').read_text())}
for level,parent in [('regions',None),('provinces','region_code'),('cities','province_code'),('barangays','city_code')]:
    geo[level]={}
    for record in json.loads((source/f'{level}.json').read_text()):
        item={'code':record['psgc_code'],'name':record['name'].strip()}
        if parent:item['parent']=record[parent]
        if level=='provinces':item['is_group']=bool(record.get('is_pseudo'))
        if level=='cities':
            item['region_code']=record['region_code']
            item['is_district']=record.get('geographic_level')=='SubMun'
        # Manila's administrative districts are below the city. Present all city
        # barangays together with a district label, while preserving the PSGC code.
        if level=='barangays' and source_cities[record['city_code']].get('geographic_level')=='SubMun':
            item['district']=source_cities[record['city_code']]['name'].strip()
            item['parent']='1380600000'
            item['name']=item['district']+' — '+item['name']
        geo[level][item['code']]=item
for level,previous in [('provinces','regions'),('cities','provinces'),('barangays','cities')]:
    assert all(x['parent'] in geo[previous] for x in geo[level].values()), level
geo['source']={'release':'PSGC Q1 2026','retrieved':'2026-10-15','url':'https://github.com/fish-and-bear/psgc','license':'MIT','commit':'e360c9da26ae9455f357ab7d048389a1a8f05c2a'}
target.mkdir(parents=True,exist_ok=True)
(target/'philippine-addresses.json').write_text(json.dumps(geo,ensure_ascii=False,separators=(',',':')))
shutil.copy(source/'LICENSE',target/'PSGC-LICENSE.txt')
print({k:len(v) for k,v in geo.items() if k!='source'})
