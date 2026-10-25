<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RevenueCat webhook. The app logs in to RevenueCat as `hideya_{userId}`, so a
 * purchase or restore that grants the hosting entitlement unlocks hosting on
 * that account. Hosting is a one-time purchase: nothing here revokes it except
 * a refund (CANCELLATION with a refund reason) or a transfer to another account.
 */
class RevenueCatController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('services.revenuecat.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('Authorization')), 401);

        $event = (array) $request->input('event', []);
        $entitlement = config('services.revenuecat.hosting_entitlement');
        $grants = in_array($entitlement, (array) ($event['entitlement_ids'] ?? []), true);
        $type = $event['type'] ?? '';

        if ($type === 'TRANSFER') {
            foreach ((array) ($event['transferred_from'] ?? []) as $id) {
                self::user($id)?->update(['hosting_unlocked_at' => null]);
            }
            foreach ((array) ($event['transferred_to'] ?? []) as $id) {
                self::user($id)?->update(['hosting_unlocked_at' => now()]);
            }
        } elseif ($grants && in_array($type, ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'RENEWAL', 'UNCANCELLATION'], true)) {
            self::user($event['app_user_id'] ?? null)?->update(['hosting_unlocked_at' => now()]);
        } elseif ($grants && $type === 'CANCELLATION' && ($event['cancel_reason'] ?? null) === 'CUSTOMER_SUPPORT') {
            self::user($event['app_user_id'] ?? null)?->update(['hosting_unlocked_at' => null]);
        }

        return response()->json(['ok' => true]);
    }

    private static function user(?string $appUserId): ?User
    {
        return $appUserId && preg_match('/^hideya_(\d+)$/', $appUserId, $m) ? User::find((int) $m[1]) : null;
    }
}
