<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiAccess;
use App\Services\DashboardRevision;
use Illuminate\Http\Request;

class DashboardRevisionController
{
    public function __invoke(Request $request)
    {
        $actor = app(ApiAccess::class)->require($request);
        return response()->json(['revision' => app(DashboardRevision::class)->token((string) $actor->id)])
            ->header('Cache-Control', 'private, no-store');
    }
}
