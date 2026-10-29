<?php
require __DIR__.'/preview-bootstrap.php';
(require database_path('migrations/2026_10_28_000001_index_reconciliation_evidence.php'))->up();
$p=app(App\Services\LedgerProjection::class);while($p->refresh()){} $p->activate(); echo 'Synthetic preview ready';
