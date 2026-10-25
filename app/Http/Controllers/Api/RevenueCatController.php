<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RevenueCat webhook (the app logs in to RevenueCat as `hideya_{userId}`).
 * Hosting and playing are free; purchases will be power-up packs, credited
 * here once those products exist. Until then the hook only authenticates and
 * acknowledges events.
 */
class RevenueCatController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('services.revenuecat.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('Authorization')), 401);

        return response()->json(['ok' => true]);
    }
}
