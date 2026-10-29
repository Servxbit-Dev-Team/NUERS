<?php
namespace Tests\Feature;
class IndexedEisTransmissionCenterTest extends EisTransmissionCenterTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $p=app(\App\Services\LedgerProjection::class);
        $p->enqueueAll();while($p->refresh()){}$p->activate();
    }
}
