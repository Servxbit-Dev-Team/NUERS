<?php
require __DIR__.'/bootstrap.php';
$p=app(App\Services\LedgerProjection::class);
$spec=(new ReflectionMethod($p,'triggerSpecs'))->invoke($p);
$out="USE `NUERS`;\nDELIMITER $$\n";$count=0;
foreach($spec as[$name,$table,$event,$statements])if(str_starts_with($name,'ledger_sales_')){
$out.="CREATE DEFINER=`nuers`@`%` TRIGGER $name AFTER $event ON $table FOR EACH ROW BEGIN ".implode('; ',$statements)."; END\$\$\n";$count++;
}
$out.="DELIMITER ;\n";file_put_contents(dirname(__DIR__).'/release/nuers-sales-triggers.sql',$out);echo "$count queue-only triggers rendered\n";
