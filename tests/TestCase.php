<?php

namespace Tests;

use App\Support\SchemaMap;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests build and alter schemas freely; never carry memoised schema lookups between them.
        SchemaMap::flush();
        // General fixtures test their domain policies. MFA enforcement has separate explicit negative tests.
        config(['security.enforce_privileged_mfa'=>false]);
    }
}
