from pathlib import Path
from pypdf import PdfReader,PdfWriter
from pypdf.generic import ContentStream,FloatObject
from PIL import Image
import io,json,hashlib,zipfile,shutil
from reportlab.pdfgen import canvas
out=Path('output/official-logo-20261029');known=set()
for p in [out/'before/public/auth-assets/nuers-digital-logo.png',out/'before/public/auth-assets/nuers-digital-white.png',out/'old-sample-logo.png']:
 known.add(hashlib.sha256(Image.open(p).convert('RGB').tobytes()).hexdigest())
new=Image.open('public/brand/nuers-official-logo.png');newratio=new.width/new.height
brandbuf=io.BytesIO();c=canvas.Canvas(brandbuf);c.drawImage('public/brand/nuers-official-logo.png',0,0,width=100,height=100/newratio,mask='auto');c.save();brand_reader=PdfReader(io.BytesIO(brandbuf.getvalue()));brand_obj=next(iter(brand_reader.pages[0]['/Resources']['/XObject'].values())).get_object()
results=[]
def update(data,label):
 reader=PdfReader(io.BytesIO(data));before=[p.extract_text() for p in reader.pages];w=PdfWriter(clone_from=reader);matches={};images={}
 for p in w.pages:
  for im in p.images:
   ref=im.indirect_reference
   if not ref or ref.idnum in images:continue
   images[ref.idnum]=im
   if hashlib.sha256(im.image.convert('RGB').tobytes()).hexdigest() in known:matches[ref.idnum]=im.image.width/im.image.height
 if not matches:return data,{'file':label,'logos':0,'pages':len(w.pages),'unchanged':True}
 # Keep original bounding boxes; fit the new artwork without stretching it.
 visited=set()
 def rewrite(owner,resources,page=False):
  stream=owner.get_contents() if page else owner
  if stream is None:return
  cs=ContentStream(stream,w);ops=[];xobjs=resources.get('/XObject',{})
  for operands,op in cs.operations:
   ref=xobjs.get(operands[0]) if op==b'Do' else None
   if ref is not None and ref.idnum in matches:
    ratio=matches[ref.idnum];sx=min(1,newratio/ratio);sy=min(1,ratio/newratio)
    ops.extend([([],b'q'),([FloatObject(sx),FloatObject(0),FloatObject(0),FloatObject(sy),FloatObject((1-sx)/2),FloatObject((1-sy)/2)],b'cm'),(operands,op),([],b'Q')])
   else:ops.append((operands,op))
  cs.operations=ops
  if page:owner.replace_contents(cs)
  else:owner.set_data(cs.get_data())
  for ref in xobjs.values():
   obj=ref.get_object()
   if obj.get('/Subtype')=='/Form' and ref.idnum not in visited:
    visited.add(ref.idnum);rewrite(obj,obj.get('/Resources',resources))
 for p in w.pages:rewrite(p,p['/Resources'],True)
 # Clone the complete PNG image stream and alpha mask, avoiding stale decoder metadata.
 new_obj=brand_obj.clone(w);replacement=new_obj.indirect_reference;replaced_resources=set()
 def swap(resources):
  xobjs=resources.get('/XObject',{})
  if id(xobjs) in replaced_resources:return
  replaced_resources.add(id(xobjs))
  for name,ref in list(xobjs.items()):
   if ref.idnum in matches:xobjs[name]=replacement
   elif ref.get_object().get('/Subtype')=='/Form':swap(ref.get_object().get('/Resources',resources))
 for p in w.pages:swap(p['/Resources'])
 w.compress_identical_objects(remove_identicals=True,remove_orphans=True)
 buf=io.BytesIO();w.write(buf);changed=buf.getvalue();after=PdfReader(io.BytesIO(changed))
 assert [p.extract_text() for p in after.pages]==before,label
 return changed,{'file':label,'logos':len(matches),'pages':len(w.pages),'text_unchanged':True}
for name in ['NUERS-API-Connection-Guide.pdf','NUERS-Principal-and-Supplementary-Documents-v1.0.0.pdf']:
 p=Path('public/guides')/name;data,result=update((out/'before'/p).read_bytes(),name);p.write_bytes(data);results.append(result)
 for dest in [Path('output/pdf')/name,Path('dist/guides')/name]:dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
for base in ['public/guides','dist/guides','output/pdf']:shutil.copy2(Path(base)/'NUERS-API-Connection-Guide.pdf',Path(base)/'NUERS-API-Developer-Documentation.pdf')
zpath=Path('public/guides/NUERS-Document-Sample-Copies-v1.0.0.zip');contents={}
with zipfile.ZipFile(out/'before'/zpath) as z:
 for name in z.namelist():
  data=z.read(name)
  if name.endswith('.pdf'):
   data,result=update(data,name);results.append(result)
   dest=out/'samples'/Path(name).name;dest.parent.mkdir(exist_ok=True);dest.write_bytes(data)
  contents[name]=data
contents['SHA256SUMS.txt']=('\n'.join(hashlib.sha256(d).hexdigest()+'  '+name for name,d in contents.items() if name!='SHA256SUMS.txt')+'\n').encode()
with zipfile.ZipFile(zpath,'w',zipfile.ZIP_DEFLATED) as z:
 for name,data in contents.items():z.writestr(name,data)
shutil.copy2(zpath,Path('dist/guides')/zpath.name)
(out/'pdf-verification.json').write_text(json.dumps(results,indent=2));print(json.dumps(results))
