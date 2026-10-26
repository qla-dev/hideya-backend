<?php

namespace App\Services;

use App\Game\PowerUps;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Grants what an account bought in the app, from RevenueCat's own record of
 * it (REST API v1, secret key), the same flow as qla.fit's. Each pack
 * transaction is granted once; Hideya Plus follows the `plus` entitlement's
 * expiry.
 */
class RevenueCatPurchases
{
    /**
     * Records the account's unrecorded transactions of `$productId`, or
     * refreshes Plus.
     *
     * @return array{powerUps: int, plus: bool}
     */
    public function sync(User $user, string $productId): array
    {
        $subscriber = $this->subscriber($user);
        if ($productId === config('purchases.plus_product')) {
            return ['powerUps' => 0, 'plus' => $this->applyPlus($user, $subscriber)];
        }
        $amount = (int) (config('purchases.packs')[$productId] ?? 0);
        // By key, not by dotted path: product ids are full of dots.
        $purchases = $subscriber['non_subscriptions'] ?? [];
        $transactions = collect(is_array($purchases) ? ($purchases[$productId] ?? []) : [])
            ->filter(fn ($transaction) => is_string($transaction['id'] ?? null) && $transaction['id'] !== '')
            ->sortBy('purchase_date')->values()->all();

        $granted = DB::transaction(function () use ($user, $productId, $amount, $transactions) {
            $user = User::lockForUpdate()->find($user->id);
            $granted = 0;
            foreach ($transactions as $transaction) {
                $new = DB::table('revenuecat_purchases')->insertOrIgnore([
                    'user_id' => $user->id, 'transaction_id' => $transaction['id'], 'product_id' => $productId, 'power_ups' => $amount,
                    'purchased_at' => isset($transaction['purchase_date']) ? Carbon::parse($transaction['purchase_date']) : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($new && PowerUps::creditPack($user, $productId)) {
                    $granted += $amount;
                }
            }

            return $granted;
        });

        return ['powerUps' => $granted, 'plus' => PowerUps::plus($user->fresh())];
    }

    /** Plus ran out on our side: ask RevenueCat whether it renewed. */
    public function refreshPlus(User $user): void
    {
        if ($user->plus_until !== null && $user->plus_until->isPast() && config('purchases.secret_api_key')) {
            rescue(fn () => $this->applyPlus($user, $this->subscriber($user)), report: false);
        }
    }

    private function applyPlus(User $user, array $subscriber): bool
    {
        $expires = $subscriber['entitlements'][config('purchases.plus_entitlement')]['expires_date'] ?? null;
        $until = is_string($expires) ? Carbon::parse($expires) : null;
        $user->forceFill(['plus_until' => $until?->isFuture() ? $until : null])->save();

        return PowerUps::plus($user);
    }

    private function subscriber(User $user): array
    {
        $key = config('purchases.secret_api_key');
        abort_unless($key, 503, 'Purchases are not configured yet.');
        $response = Http::withToken($key)->acceptJson()->timeout(10)
            ->get('https://api.revenuecat.com/v1/subscribers/'.rawurlencode(config('purchases.app_user_prefix').$user->id));
        abort_unless($response->successful(), 502, 'The purchase could not be verified.');

        return (array) ($response->json('subscriber') ?? []);
    }
}
