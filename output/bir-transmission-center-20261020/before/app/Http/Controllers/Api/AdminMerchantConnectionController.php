<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminMerchantConnections;
use App\Services\ApiAccess;
use Illuminate\Http\Request;

class AdminMerchantConnectionController extends Controller
{
    public function index(Request $request)
    {
        app(ApiAccess::class)->require($request, ['admin', 'super_admin']);
        $filters = $request->validate([
            'search' => 'nullable|string|max:160', 'environment' => 'nullable|in:all,live,sandbox',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        return response()->json(app(AdminMerchantConnections::class)->overview($filters))
            ->header('Cache-Control', 'private, no-store');
    }
}
