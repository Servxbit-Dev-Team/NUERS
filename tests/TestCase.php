<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // General fixtures test their domain policies. MFA enforcement has separate explicit negative tests.
        config(['security.enforce_privileged_mfa'=>false]);
    }
}
