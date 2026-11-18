"""Run synthetic process races against the explicitly isolated local MariaDB socket.
The server must already be running with --skip-networking. Creates a random schema
and least-privilege test user, then removes both even when PHPUnit fails.
No application .env or production credentials are read. Usage: python3
scripts/run-financial-concurrency.py evidence-label [optional PHPUnit filters].
"""
import os,pathlib,subprocess,secrets,json,sys
root=pathlib.Path(__file__).resolve().parents[1];os.chdir(root);out=root/'.local/vapt-races-2026-11-15';sock=str(root/'.local/vapt-mariadb-2026-11-14/mysql.sock');bin='/opt/homebrew/opt/mariadb@10.11/bin/mariadb'
out.mkdir(parents=True,exist_ok=True)
assert pathlib.Path(sock).is_socket(), 'Start the isolated network-disabled MariaDB server first'
label=sys.argv[1] if len(sys.argv)>1 else 'race';args=sys.argv[2:]
assert __import__('re').fullmatch(r'[a-zA-Z0-9_-]+',label), 'Invalid evidence label'
id=secrets.token_hex(6);db='nuers_vapt_test_'+id;user='vt_'+id;password=secrets.token_hex(24)
def sql(s):
 r=subprocess.run([bin,'--no-defaults','--socket='+sock,'-u','marksioson','--batch','--skip-column-names'],input=s,text=True,capture_output=True);assert r.returncode==0,r.stderr;return r.stdout
sql(f"CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '{user}'@'localhost' IDENTIFIED BY '{password}'; GRANT SELECT,INSERT,UPDATE,DELETE ON `{db}`.* TO '{user}'@'localhost';")
env=os.environ.copy();env.update({'APP_ENV':'testing','APP_KEY':'base64:'+__import__('base64').b64encode(secrets.token_bytes(32)).decode(),'APP_CONFIG_CACHE':str(out/'nonexistent-config.php'),'DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_PORT':'3311','DB_SOCKET':sock,'DB_DATABASE':db,'DB_USERNAME':user,'DB_PASSWORD':password,'DB_URL':'','NUERS_TEST_MYSQL_DATABASE':db,'NUERS_TEST_MIGRATOR_USERNAME':'marksioson','NUERS_TEST_MIGRATOR_PASSWORD':'','NUERS_TEST_MIGRATOR_SOCKET':sock,'CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'database','MAIL_MAILER':'array'})
try:
 with(out/(label+'.log')).open('w')as f:r=subprocess.run(['php','vendor/bin/phpunit','tests/Concurrency/FinancialConcurrencyTest.php','--log-junit',str(out/(label+'.xml')),*args],env=env,stdout=f,stderr=subprocess.STDOUT)
 print(json.dumps({'run':label,'exit':r.returncode,'log':str(out/(label+'.log'))}));code=r.returncode
finally:
 sql(f"DROP DATABASE `{db}`; DROP USER '{user}'@'localhost';")
 (out/(label+'-cleanup.json')).write_text(json.dumps({'synthetic_database_removed':True,'synthetic_user_removed':True}))
sys.exit(code)
