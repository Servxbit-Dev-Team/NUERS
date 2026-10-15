"""Rebuild registration industries from the archived official BSP workbook. Requires openpyxl."""
import openpyxl,json,re
w=openpyxl.load_workbook('docs/knowledge/sources/BSP_PSIC_Annex_D2A_Cir1197_2024.xlsx',data_only=True)
items={}
for r in w['PSIC code'].values:
 if isinstance(r[1],str) and (m:=re.match(r'^(\d{5})\s*-\s*(.+)',r[1].strip(),re.S)):
  code,label=m.groups();items['psic:'+code]={'code':code,'name':' '.join(label.split()),'version':'BSP PSIC reference (2024)'}
assert len(items)==1272,len(items)
json.dump({'source':'https://www.bsp.gov.ph/Lists/Download%20Section/Attachments/49/Annex%20D.2.A%20(Cir1197_2024).xlsx','version':'BSP PSIC reference (2024)','industries':items},open('resources/data/registration-industries.json','w'),ensure_ascii=False,indent=2)
