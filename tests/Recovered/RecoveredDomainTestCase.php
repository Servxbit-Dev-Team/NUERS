<?php
namespace Tests\Recovered;

/** Historical domain regression fixtures; current authentication gates have dedicated tests. */
abstract class RecoveredDomainTestCase extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['security.enforce_privileged_mfa' => false, 'notifications.dispatch_enabled' => false]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
        if (PHP_OS_FAMILY === 'Darwin' && ! config('document_pdf.chrome_binary') && is_executable($chrome)) {
            config(['document_pdf.chrome_binary' => $chrome]);
        }
    }
}
