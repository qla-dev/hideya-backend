<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Verifies the identity tokens the app gets from Sign in with Apple and Google:
 * signature against the provider's published keys, issuer, audience (our app's
 * client IDs), expiry, and for Apple the hashed nonce the app sent.
 */
class IdentityVerifier
{
    private const APPLE_KEYS = 'https://appleid.apple.com/auth/keys';

    private const GOOGLE_KEYS = 'https://www.googleapis.com/oauth2/v3/certs';

    /** @return array{sub: string, email: ?string} */
    public function apple(string $identityToken, ?string $rawNonce): array
    {
        $claims = $this->decode($identityToken, self::APPLE_KEYS, 'apple');
        $this->require(($claims->iss ?? null) === 'https://appleid.apple.com', 'Not an Apple identity token.');
        $this->require(in_array($claims->aud ?? null, config('services.apple.client_ids'), true), 'This Apple token is for another app.');
        if ($rawNonce !== null) {
            $this->require(hash_equals(hash('sha256', $rawNonce), (string) ($claims->nonce ?? '')), 'The sign-in request did not match.');
        }

        return ['sub' => (string) $claims->sub, 'email' => $claims->email ?? null];
    }

    /** @return array{sub: string, email: ?string, name: ?string} */
    public function google(string $idToken): array
    {
        $claims = $this->decode($idToken, self::GOOGLE_KEYS, 'google');
        $this->require(in_array($claims->iss ?? null, ['accounts.google.com', 'https://accounts.google.com'], true), 'Not a Google ID token.');
        $this->require(in_array($claims->aud ?? null, config('services.google.client_ids'), true), 'This Google token is for another app.');

        return ['sub' => (string) $claims->sub, 'email' => ($claims->email_verified ?? false) ? ($claims->email ?? null) : null, 'name' => $claims->name ?? null];
    }

    private function decode(string $token, string $keysUrl, string $provider): object
    {
        try {
            $keys = Cache::remember("identity-keys:{$provider}", now()->addHours(6), fn () => Http::timeout(5)->get($keysUrl)->throw()->json());
            JWT::$leeway = 60;

            return JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable) {
            Cache::forget("identity-keys:{$provider}"); // keys rotate; refetch next time
            throw ValidationException::withMessages(['token' => 'Sign-in could not be verified. Try again.']);
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['token' => $message]);
        }
    }
}
