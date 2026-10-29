import urllib.request,json,hashlib
from pathlib import Path
base="https://nuers.net"
results={}
for path in ["/login", "/super-admin/compliance-operations", "/api/developer/openapi?release=20261029", "/public/guides/NUERS-API-Connection-Guide.pdf?release=20261029", "/api/compliance-operations", "/guides/NUERS-API-Connection-Guide.pdf?v=compliance-20261029", "/guides/NUERS-API-Developer-Documentation.pdf?v=compliance-20261029"]:
 req=urllib.request.Request(base+path,headers={"Accept":"application/json" if path.startswith("/api/") else "*/*","User-Agent":"NUERS-Deployment-Verification"})
 try:
  with urllib.request.urlopen(req,timeout=40) as r:
   body=r.read(); results[path]={"status":r.status,"content_type":r.headers.get("Content-Type"),"bytes":len(body)}
   if path=="/super-admin/compliance-operations":
    assert b"/assets/index-sGq2Tec1.js" in body; results[path]["current_static_entry"]=True
   if "openapi" in path:
    x=json.loads(body);a=x["components"]["schemas"]["IngestionEvent"];b=x["paths"]["/api/integrations/transactions"]["post"]["requestBody"]["content"]["application/json"]["schema"];results[path]["single_batch_contract_equal"]=a==b;assert a==b
    assert "withholding_details" in a["properties"] and "manual_recovery_id" in a["properties"]
   if ".pdf" in path:
    expected=hashlib.sha256(Path("public/guides/NUERS-API-Connection-Guide.pdf").read_bytes()).hexdigest();results[path]["sha256"]=hashlib.sha256(body).hexdigest();assert results[path]["sha256"]==expected
 except urllib.error.HTTPError as e:
  results[path]={"status":e.code}
  if path!="/api/compliance-operations" or e.code!=401:raise
assert results["/api/compliance-operations"]["status"]==401
Path("output/compliance-alignment-20261029/release/http-verification.json").write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))
