<?php
namespace Tests\Feature;
class IndexedTransactionReportTest extends TransactionReportTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $projection=app(\App\Services\LedgerProjection::class);
        while($projection->refresh()){}
        $projection->activate();
    }
}
