<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RevenueCatPurchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Called by the app after a store purchase (and to restore one): the server
 * verifies it with RevenueCat and grants it once. Hosting and playing are
 * free; only power-up packs and Hideya Plus are sold.
 */
class PurchaseController extends Controller
{
    public function store(Request $request, RevenueCatPurchases $purchases): JsonResponse
    {
        $data = $request->validate([
            'productId' => ['required', 'string', Rule::in([...array_keys(config('purchases.packs')), config('purchases.plus_product')])],
        ]);
        $granted = $purchases->sync($request->user(), $data['productId']);

        return response()->json(['granted' => $granted, 'user' => AuthController::present($request->user()->fresh())]);
    }
}
