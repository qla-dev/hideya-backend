<?php

namespace Tests\Feature;

use App\Auth\IdentityVerifier;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['exp.host/*' => Http::response(['data' => []])]);
        // Apple/Google signature checks need the providers' real tokens; stub the
        // verifier and test what the API does with a verified identity.
        $this->app->instance(IdentityVerifier::class, new class extends IdentityVerifier
        {
            public function apple(string $identityToken, ?string $rawNonce): array
            {
                return $identityToken === 'valid-apple' ? ['sub' => 'apple-user-1', 'email' => 'a@privaterelay.appleid.com'] : throw ValidationException::withMessages(['token' => 'invalid']);
            }

            public function google(string $idToken): array
            {
                return $idToken === 'valid-google' ? ['sub' => 'google-user-1', 'email' => 'leo@example.com', 'name' => 'Leo'] : throw ValidationException::withMessages(['token' => 'invalid']);
            }
        });
    }

    public function test_sign_in_with_apple_creates_then_reuses_the_account(): void
    {
        $first = $this->postJson('/api/auth/apple', ['identityToken' => 'valid-apple', 'name' => 'Maya'])->assertCreated();
        $this->assertSame('Maya', $first->json('user.name'));
        $this->assertSame('apple', $first->json('user.provider'));
        $this->assertSame('hideya_'.$first->json('user.id'), $first->json('user.revenueCatId'));
        $this->postJson('/api/auth/apple', ['identityToken' => 'valid-apple'])->assertOk()->assertJsonPath('user.id', $first->json('user.id'));
        $this->postJson('/api/auth/apple', ['identityToken' => 'forged'])->assertUnprocessable();
        $this->assertSame(1, User::count());
    }

    public function test_sign_in_with_google_and_manage_the_account(): void
    {
        $signIn = $this->postJson('/api/auth/google', ['idToken' => 'valid-google'])->assertCreated();
        $auth = ['Authorization' => 'Bearer '.$signIn->json('token')];

        $this->getJson('/api/me', $auth)->assertOk()->assertJsonPath('user.email', 'leo@example.com');
        $this->patchJson('/api/me', ['name' => 'Leo B'], $auth)->assertOk()->assertJsonPath('user.name', 'Leo B');
        $this->postJson('/api/push-tokens', ['token' => 'ExponentPushToken[abc]', 'platform' => 'ios'], $auth)->assertOk();
        $this->postJson('/api/push-tokens', ['token' => 'not-expo'], $auth)->assertUnprocessable();
        $this->assertDatabaseHas('push_tokens', ['token' => 'ExponentPushToken[abc]']);

        // Creating a room while signed in links the player to the account.
        $room = $this->postJson('/api/rooms', ['name' => 'Leo'], $auth)->assertCreated();
        $this->assertSame(User::first()->id, Player::first()->user_id);

        $this->deleteJson('/api/me', [], $auth)->assertOk();
        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('push_tokens', ['token' => 'ExponentPushToken[abc]']);
        $this->assertNull(Player::first()->user_id);
        $this->getJson('/api/rooms/'.$room->json('room.code'), ['X-Player-Token' => $room->json('token')])->assertOk();
    }

    public function test_revenuecat_webhook_needs_its_secret(): void
    {
        config(['services.revenuecat.webhook_secret' => 'Bearer rc-secret']);
        $event = ['event' => ['type' => 'NON_RENEWING_PURCHASE', 'app_user_id' => 'hideya_1']];

        $this->postJson('/api/revenuecat/webhook', $event)->assertUnauthorized();
        $this->postJson('/api/revenuecat/webhook', $event, ['Authorization' => 'Bearer rc-secret'])->assertOk();
    }

    public function test_hosting_is_free_without_an_account(): void
    {
        $this->postJson('/api/rooms', ['name' => 'Maya'])->assertCreated();
    }
}
