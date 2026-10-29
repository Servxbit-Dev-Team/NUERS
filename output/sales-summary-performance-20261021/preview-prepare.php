<?php
require __DIR__.'/preview-bootstrap.php';
(require database_path('migrations/2026_10_21_000001_index_operations_sales_evidence.php'))->up();
$p=app(App\Services\LedgerProjection::class);while($p->refresh()){} $p->activate(); echo 'Synthetic preview ready';