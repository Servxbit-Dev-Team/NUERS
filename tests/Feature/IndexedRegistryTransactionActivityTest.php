<?php

namespace Tests\Feature;

class IndexedRegistryTransactionActivityTest extends RegistryTransactionActivityTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $projection = app(\App\Services\LedgerProjection::class);
        while ($projection->refresh()) {
        }
        $projection->activate();
    }
}
