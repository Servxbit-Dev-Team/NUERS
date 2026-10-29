<?php
namespace Tests\Feature;

/** The indexed sales report must retain original financial and tenant evidence rules. */
class IndexedSalesReadinessAlignmentTest extends EisReadinessAlignmentTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default'=>'array']);
        $projection=app(\App\Services\LedgerProjection::class);
        while($projection->refresh()){}
        $projection->activate();
    }
}
