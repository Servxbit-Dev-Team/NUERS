<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Tests\TestCase;

class PublicDocumentationAssetsTest extends TestCase
{
    public function test_repository_root_docs_use_the_existing_public_asset_directory(): void
    {
        $this->app->forgetInstance(Vite::class);
        $html = $this->withServerVariables(['DOCUMENT_ROOT' => base_path()])->get('/api/docs')->assertOk()->getContent();
        $this->assertStringContainsString('/public/build/assets/main-', $html);
        $this->assertStringNotContainsString('₱500 and above', $html);
    }
    public function test_public_root_docs_keep_the_standard_asset_directory(): void
    {
        $this->app->forgetInstance(Vite::class);
        $html = $this->withServerVariables(['DOCUMENT_ROOT' => public_path()])->get('/api/docs')->assertOk()->getContent();
        $this->assertStringContainsString('/build/assets/main-', $html);
        $this->assertStringNotContainsString('/public/build/', $html);
    }
}
