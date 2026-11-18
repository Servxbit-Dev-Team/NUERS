"""Regression corpus for supplemental query-string WAF normalization; not a SQL/XSS parser."""
import json,pathlib,urllib.parse
p=pathlib.Path(__file__).parent
needles={'sql':['union select','drop table',"' or 1=1"],'xss':['<script','javascript:']}
expressions={}
for k,words in needles.items():
 parts=[]
 for field in ['http.request.uri.query','lower(url_decode(http.request.uri.query))','lower(url_decode(url_decode(http.request.uri.query)))']:
  for w in words:parts.append('('+field+' contains '+json.dumps(w)+')')
 # Form-encoded spaces, without regex dependence on a paid plan.
 for w in words:
  if ' ' in w:parts.append('(lower(url_decode(url_decode(http.request.uri.query))) contains '+json.dumps(w.replace(' ','+'))+')')
 expressions[k]=' or '.join(parts)
attacks={'sql':['q=UNION%20SELECT','q=union+select','q=%2527%2520OR%25201%253D1','q=DrOp%20TaBlE'], 'xss':['q=%3CSCRIPT%3E','q=%253CsCrIpT%253E','q=JavaScript%3Aalert(1)']}
benign=['q=Juan+Dela+Cruz','q=invoice&status=issued','q=Seafood%20Trading','q=2026-11-15','q=union%20bank','q=javascript%20training']
count=0
for k,words in needles.items():
 def hit(q):
  variants=[q,urllib.parse.unquote(q).lower(),urllib.parse.unquote(urllib.parse.unquote(q)).lower()]
  return any(w in v for w in words for v in variants) or any(w.replace(' ','+') in variants[-1] for w in words if ' ' in w)
 for q in attacks[k]:assert hit(q),(k,q);count+=1
 for q in benign:assert not hit(q),(k,q);count+=1
(p/'waf-expressions.json').write_text(json.dumps(expressions,indent=2)+'\n');print(f'{count} corpus cases passed; verify Cloudflare syntax and public behavior separately.')
