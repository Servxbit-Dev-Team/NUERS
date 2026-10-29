<?php
require __DIR__.'/bootstrap.php';
$projection = app(App\Services\LedgerProjection::class);
$method = new ReflectionMethod($projection, 'triggerSpecs');
$ddl = "-- NUERS reporting queue only. Existing application user remains the definer.\nUSE `NUERS`;\nDELIMITER $$\n";
foreach ($method->invoke($projection) as [$name, $table, $event, $statements]) {
    $ddl .= "CREATE DEFINER=`nuers`@`%` TRIGGER $name AFTER $event ON $table FOR EACH ROW BEGIN ".implode('; ', $statements)."; END$$\n";
}
$ddl .= "DELIMITER ;\n";
file_put_contents(dirname(__DIR__).'/release/nuers-reporting-triggers.sql', $ddl);
echo json_encode(['triggers'=>count($method->invoke($projection)), 'bytes'=>strlen($ddl)]).PHP_EOL;
