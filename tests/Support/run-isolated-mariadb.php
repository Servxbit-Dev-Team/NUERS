<?php
// Disposable local MariaDB only. Usage: php tests/Support/run-isolated-mariadb.php [security|sessions|wallet|registration]
// Requires the dedicated socket/datadir below; never accepts a remote host or customer database.
$root = dirname(__DIR__, 2);
$evidence = $root.'/.local/vapt-remediation-2026-11-14';
$mode = $argv[1] ?? 'security';
$tests = match ($mode) {
 'security' => ['tests/Feature/SecurityBoundaryTest.php','tests/Feature/PrivilegedSessionTest.php','tests/Feature/EndpointSecurityTest.php'],
 'sessions' => ['tests/Feature/PrivilegedSessionTest.php'],
 'registration' => ['tests/RegistrationConcurrencyTest.php'],
 'wallet' => ['tests/WalletConcurrencyTest.php'],
 default => throw new InvalidArgumentException('Unknown isolated test group'),
};
if (!is_dir($evidence)) mkdir($evidence, 0700, true);
$cnf=['socket'=>$root.'/.local/vapt-mariadb-2026-11-14/mysql.sock','user'=>posix_getpwuid(posix_geteuid())['name'],'password'=>''];
$pdo=new PDO('mysql:unix_socket='.$cnf['socket'],$cnf['user'],$cnf['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!str_contains($pdo->query('SELECT VERSION()')->fetchColumn(),'10.11.19-MariaDB') || realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($root.'/.local/vapt-mariadb-2026-11-14/data'))throw new RuntimeException('Isolated MariaDB guard failed');
$id=bin2hex(random_bytes(4));$db='nuers_vapt_test_'.$id;$user='nuers_test_'.$id;$pass=bin2hex(random_bytes(32));$account=$pdo->quote($user)."@'localhost'";
$pdo->exec('CREATE DATABASE `'.$db.'`');
try{
 $pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($pass));
 $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$db.'`.* TO '.$account);
 $env=array_merge(getenv(),['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3311','DB_SOCKET'=>$cnf['socket'],'DB_DATABASE'=>$db,'NUERS_TEST_MYSQL_DATABASE'=>$db,'DB_USERNAME'=>$user,'DB_PASSWORD'=>$pass,'DB_URL'=>'','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'database','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','NUERS_TEST_MIGRATOR_USERNAME'=>$cnf['user'],'NUERS_TEST_MIGRATOR_PASSWORD'=>$cnf['password']??'','NUERS_TEST_MIGRATOR_SOCKET'=>$cnf['socket']]);
 $process=proc_open([PHP_BINARY,'vendor/bin/phpunit',...$tests,'--log-junit',$evidence.'/mariadb-'.$mode.'.xml'],[0=>['pipe','r'],1=>['file',$evidence.'/mariadb-'.$mode.'.log','w'],2=>['file',$evidence.'/mariadb-'.$mode.'.log','a']],$pipes,$root,$env);
 fclose($pipes[0]);$exit=proc_close($process);
 echo 'Isolated MariaDB synthetic test exit: '.$exit.PHP_EOL;
}finally{
 $pdo->exec('DROP DATABASE `'.$db.'`');$pdo->exec('DROP USER IF EXISTS '.$account);
}
exit($exit);
