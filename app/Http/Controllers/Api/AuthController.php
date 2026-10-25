<?php

namespace App\Http\Controllers\Api;

use App\Auth\IdentityVerifier;
use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Accounts from Sign in with Apple and Google. The app sends the provider's
 * identity token; the API verifies it and answers with a Sanctum token for
 * `Authorization: Bearer`. Accounts are optional for play: they carry the name,
 * push notifications across devices.
 */
class AuthController extends Controller
{
    public function apple(Request $request, IdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'identityToken' => ['required', 'string'],
            'nonce' => ['nullable', 'string', 'max:128'],
            // Apple sends the name only on the first sign-in, to the app, not in the token.
            'name' => ['nullable', 'string', 'max:24'],
        ]);
        $identity = $verifier->apple($data['identityToken'], $data['nonce'] ?? null);

        return $this->signIn('apple_sub', $identity['sub'], $identity['email'], $data['name'] ?? null);
    }

    public function google(Request $request, IdentityVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['idToken' => ['required', 'string']]);
        $identity = $verifier->google($data['idToken']);

        return $this->signIn('google_sub', $identity['sub'], $identity['email'], $identity['name'] ? mb_substr($identity['name'], 0, 24) : null);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::present($request->user())]);
    }

    /** Open today's free power-up pack (registered players only). */
    public function claimDaily(Request $request): JsonResponse
    {
        $result = DB::transaction(fn () => \App\Game\PowerUps::claimDaily($request->user()));
        abort_unless($result, 409, 'You’ve opened today’s power-up. The next one comes at midnight.');

        return response()->json($result + ['user' => self::present($request->user()->fresh())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:24']]);
        $request->user()->update(['name' => trim($data['name'])]);

        return response()->json(['user' => self::present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** Account deletion, required by the App Store for apps that offer sign-in. */
    public function destroy(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request) {
            $user = $request->user();
            $user->tokens()->delete();
            $user->delete(); // push tokens cascade; room players keep their names but lose the link
        });

        return response()->json(['ok' => true]);
    }

    public function registerPush(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:191', 'regex:/^(Exponent|Expo)PushToken\[.+\]$/'], 'platform' => ['nullable', 'in:ios,android']]);
        PushToken::updateOrCreate(['token' => $data['token']], ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null]);

        return response()->json(['ok' => true]);
    }

    public function forgetPush(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:191']]);
        $request->user()->pushTokens()->where('token', $data['token'])->delete();

        return response()->json(['ok' => true]);
    }

    private function signIn(string $column, string $sub, ?string $email, ?string $name): JsonResponse
    {
        $user = User::firstWhere($column, $sub);
        $created = ! $user;
        if (! $user) {
            $user = User::create([$column => $sub, 'email' => $email, 'name' => $name ? trim($name) : '']);
        } elseif (! $user->email && $email) {
            $user->update(['email' => $email]);
        }

        return response()->json(['token' => $user->createToken('app')->plainTextToken, 'user' => self::present($user), 'created' => $created], $created ? 201 : 200);
    }

    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'provider' => $user->apple_sub ? 'apple' : ($user->google_sub ? 'google' : null),
            // RevenueCat app user ID, so purchases attach to this account on every device.
            'revenueCatId' => "hideya_{$user->id}",
            'powerUps' => ['credits' => (int) $user->power_up_credits, 'dailyAvailable' => \App\Game\PowerUps::dailyAvailable($user), 'nextDailyAt' => \App\Game\PowerUps::nextReset()],
        ];
    }
}
