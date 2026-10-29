<?php
namespace Tests\Feature;

/** Repeat the existing access/filter contract with the indexed read model active. */
class IndexedMerchantTeamTest extends MerchantTeamTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $projection = app(\App\Services\LedgerProjection::class);
        while ($projection->refresh()) {}
        $projection->activate();
    }
}
