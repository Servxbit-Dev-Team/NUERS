import ssl,socket,json,pathlib,urllib.request
report={'host':'nuers.net','tls':{},'cookies':[]}
for name,version in [('TLS1.2',ssl.TLSVersion.TLSv1_2),('TLS1.3',ssl.TLSVersion.TLSv1_3)]:
 ctx=ssl.create_default_context();ctx.minimum_version=version;ctx.maximum_version=version
 with socket.create_connection(('nuers.net',443),timeout=15) as tcp:
  with ctx.wrap_socket(tcp,server_hostname='nuers.net') as sock:
   cert=sock.getpeercert();report['tls'][name]={'negotiated':sock.version(),'cipher':sock.cipher()[0],'certificate_verified':True,'not_after':cert.get('notAfter')}
r=urllib.request.urlopen(urllib.request.Request('https://nuers.net/login',headers={'User-Agent':'NUERS-Authorized-Security-Retest/2026-11-15'}),timeout=15)
for cookie in r.headers.get_all('Set-Cookie',[]):
 parts=cookie.split(';');attrs={p.strip().split('=',1)[0].lower():p.strip().split('=',1)[1] if '=' in p else True for p in parts[1:]};report['cookies'].append({'name':parts[0].split('=',1)[0],'secure':'secure' in attrs,'httponly':'httponly' in attrs,'samesite':attrs.get('samesite')})
report['hsts']=r.headers.get('Strict-Transport-Security');report['scope_note']='Modern TLS handshake/certificate checks, not exhaustive cipher enumeration or downgrade testing; XSRF cookie is intentionally readable by client code.'
p=pathlib.Path.home()/'nuers-frontend-20261115'/'tls-cookie-checks.json';p.write_text(json.dumps(report,indent=2));print(json.dumps(report))
