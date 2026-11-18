#!/usr/bin/env python3
"""Rehearse least-privilege grants against the named disposable database only."""
from pathlib import Path
import subprocess,json
r=Path('/srv/nuers-vapt');config=dict(l.split('=',1) for l in (r/'private/runtime.env').read_text().splitlines());assert config['APP_ENV']=='staging' and config['DB_DATABASE']=='nuers_vapt_multi' and config['DB_HOST']=='10.85.0.70'
admin=(r/'private/db.env').read_text().strip().split('=',1)[1]
def sql(user,password,query):
 return subprocess.run(['docker','exec','-e','MYSQL_PWD='+password,'nuers-multi-db','mariadb','-h127.0.0.1','-u'+user,'--batch','--skip-column-names','-e',query],text=True,capture_output=True)
def root(q):
 p=sql('root',admin,q);assert p.returncode==0,p.stderr;return p
checks=[]
def check(name,ok):
 checks.append({'check':name,'pass':bool(ok)});assert ok,name
root("GRANT SELECT,INSERT,UPDATE,DELETE ON nuers_vapt_forbidden.* TO 'nuers_stage'@'%'")
try:
 check('Synthetic excess grant initially permits access',sql('nuers_stage',config['DB_PASSWORD'],'USE nuers_vapt_forbidden').returncode==0)
finally:
 root("REVOKE SELECT,INSERT,UPDATE,DELETE ON nuers_vapt_forbidden.* FROM 'nuers_stage'@'%'")
p=sql('nuers_stage',config['DB_PASSWORD'],'USE nuers_vapt_forbidden');check('Cross-database access denied after exact four-privilege revoke',p.returncode!=0 and '1044' in p.stderr)
p=sql('nuers_stage',config['DB_PASSWORD'],'CREATE TABLE nuers_vapt_multi.vapt_unauthorized_ddl (id INT)');check('Runtime DDL denied',p.returncode!=0 and '1142' in p.stderr)
root('CREATE TABLE nuers_vapt_multi.vapt_permission_probe (id INT PRIMARY KEY,value INT)')
try:
 p=sql('nuers_stage',config['DB_PASSWORD'],'START TRANSACTION;INSERT INTO nuers_vapt_multi.vapt_permission_probe VALUES(1,1);UPDATE nuers_vapt_multi.vapt_permission_probe SET value=2 WHERE id=1;SELECT value FROM nuers_vapt_multi.vapt_permission_probe;DELETE FROM nuers_vapt_multi.vapt_permission_probe WHERE id=1;ROLLBACK;');check('Allowed application DML preserved',p.returncode==0 and p.stdout.strip()=='2')
finally:root('DROP TABLE nuers_vapt_multi.vapt_permission_probe')
(r/'evidence/runtime-grants.json').write_text(json.dumps(checks,indent=2));print(json.dumps(checks))
