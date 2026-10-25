<?php

namespace App\Http\Controllers\Api;

use App\Game\PowerUps;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RevenueCat webhook (the app logs in to RevenueCat as `hideya_{userId}`).
 * Hosting and playing are free; what is sold is power-up packs, credited to
 * the account once per RevenueCat event.
 */
class RevenueCatController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('services.revenuecat.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('Authorization')), 401);

        $event = (array) $request->input('event', []);
        $id = (string) ($event['id'] ?? '');
        $isPurchase = in_array($event['type'] ?? '', ['NON_RENEWING_PURCHASE', 'INITIAL_PURCHASE'], true);
        $user = self::user($event['app_user_id'] ?? null);
        if ($id !== '' && $isPurchase && $user && isset(PowerUps::PACKS[$event['product_id'] ?? ''])) {
            DB::transaction(function () use ($id, $user, $event) {
                if (DB::table('revenuecat_events')->where('id', $id)->lockForUpdate()->exists()) {
                    return;
                }
                DB::table('revenuecat_events')->insert(['id' => $id, 'created_at' => now()]);
                PowerUps::creditPack($user, $event['product_id']);
            });
        }

        return response()->json(['ok' => true]);
    }

    private static function user(?string $appUserId): ?User
    {
        return $appUserId && preg_match('/^hideya_(\d+)$/', $appUserId, $m) ? User::find((int) $m[1]) : null;
    }
}
