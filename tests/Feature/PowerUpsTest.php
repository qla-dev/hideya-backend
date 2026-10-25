<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PowerUpsTest extends TestCase
{
    use RefreshDatabase;

    private const CENTER = ['latitude' => 43.8563, 'longitude' => 18.4131];

    /**
     * A started round: a hunter and a runner, each signed in unless asked not to
     * be. Returns [code, hunterToken, runnerToken].
     */
    private function round(bool $signedIn = true, array $rules = []): array
    {
        $auth = function (string $name) use ($signedIn) {
            if (! $signedIn) {
                return [];
            }
            $user = User::create(['name' => $name, 'apple_sub' => "sub-{$name}"]);

            return ['Authorization' => 'Bearer '.$user->createToken('app')->plainTextToken];
        };
        $host = $this->postJson('/api/rooms', ['name' => 'Maya', 'center' => self::CENTER, 'rules' => $rules], $auth('Maya'))->assertCreated();
        $code = $host->json('room.code');
        $guest = $this->postJson("/api/rooms/{$code}/join", ['name' => 'Leo'], $auth('Leo'))->assertCreated();
        $hunter = $host->json('token');
        $runner = $guest->json('token');
        $this->as($hunter)->patchJson("/api/rooms/{$code}/me", ['pick' => 'hunter'])->assertOk();
        $this->as($runner)->patchJson("/api/rooms/{$code}/me", ['pick' => 'runner'])->assertOk();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start")->assertOk();

        return [$code, $hunter, $runner];
    }

    private function as(string $token): static
    {
        return $this->withHeader('X-Player-Token', $token);
    }

    private function use(string $token, string $code, string $kind, array $extra = [])
    {
        return $this->as($token)->postJson("/api/rooms/{$code}/power-ups", ['kind' => $kind] + $extra);
    }

    private function at(string $token, string $code, float $lat, float $lng)
    {
        return $this->as($token)->postJson("/api/rooms/{$code}/location", ['latitude' => $lat, 'longitude' => $lng])->assertOk();
    }

    public function test_not_during_the_head_start_and_only_for_the_own_team(): void
    {
        [$code, $hunter] = $this->round();
        $this->use($hunter, $code, 'flare')->assertStatus(409);
        $this->travel(3)->minutes();
        $this->use($hunter, $code, 'ghost')->assertForbidden();
    }

    public function test_guests_have_no_daily_power_up(): void
    {
        [$code, $hunter] = $this->round(signedIn: false);
        $this->travel(3)->minutes();
        $this->use($hunter, $code, 'flare')->assertStatus(402)->assertJsonPath('message', 'Sign in to get a free power-up every day, or grab one on the map.');
    }

    public function test_daily_power_up_then_credits_once_per_kind_per_round(): void
    {
        [$code, $hunter] = $this->round();
        $this->travel(3)->minutes();
        $this->use($hunter, $code, 'flare')->assertOk()->assertJsonPath('you.powerUps.daily', false)->assertJsonPath('you.powerUps.used', ['flare']);
        // Same kind again this round: no.
        $this->use($hunter, $code, 'flare')->assertStatus(409);
        // The daily one is spent and none of this kind is owned.
        $this->use($hunter, $code, 'shrink')->assertStatus(402);
        User::where('name', 'Maya')->first()->forceFill(['power_ups' => ['shrink' => 2]])->save();
        $this->use($hunter, $code, 'shrink')->assertOk()->assertJsonPath('you.powerUps.inventory.shrink', 1);
    }

    public function test_ghost_leaves_the_runner_out_of_pings(): void
    {
        [$code, $hunter, $runner] = $this->round();
        $this->at($runner, $code, 43.8570, 18.4140);
        $this->travel(3)->minutes();
        $this->use($runner, $code, 'ghost')->assertOk()->assertJsonPath('you.powerUps.ghostUntil', 480);
        $this->travel(2)->minutes(); // the 300 s ping
        $hunterView = $this->as($hunter)->getJson("/api/rooms/{$code}")->assertOk();
        // Hunters see the latest ping only, and the runner is not in it.
        $runnerId = $this->as($runner)->getJson("/api/rooms/{$code}")->json('you.id');
        $this->assertNull(collect($hunterView->json('positions'))->firstWhere('id', $runnerId));
        $this->assertContains('chat.event.runnersHidden', collect($hunterView->json('chat'))->pluck('key'));
        $this->assertContains('chat.event.powerUp.ghostOther', collect($hunterView->json('chat'))->pluck('key'));
    }

    public function test_decoy_shows_at_the_next_ping_instead_of_the_runner(): void
    {
        [$code, $hunter, $runner] = $this->round();
        $this->at($runner, $code, 43.8563, 18.4131);
        $this->travel(3)->minutes();
        // Too close to the runner, outside the 420 m zone, then a valid spot about 190 m north.
        $this->use($runner, $code, 'decoy', ['point' => ['latitude' => 43.8565, 'longitude' => 18.4131]])->assertUnprocessable();
        $this->use($runner, $code, 'decoy', ['point' => ['latitude' => 43.8600, 'longitude' => 18.4131]])->assertUnprocessable();
        $this->use($runner, $code, 'decoy', ['point' => ['latitude' => 43.8580, 'longitude' => 18.4131]])->assertOk()->assertJsonPath('you.powerUps.decoyPending', true);
        $this->travel(2)->minutes();
        $ping = collect($this->as($hunter)->getJson("/api/rooms/{$code}")->json('positions'))->firstWhere('second', 300);
        $this->assertEqualsWithDelta(43.8580, $ping['lat'], 1e-6);
        // The hunters were not told about it.
        $this->assertNotContains('chat.event.powerUp.decoyTeam', collect($this->as($hunter)->getJson("/api/rooms/{$code}")->json('chat'))->pluck('key'));
    }

    public function test_shrink_now_brings_the_next_shrink_to_thirty_seconds(): void
    {
        [$code, $hunter, $runner] = $this->round();
        $this->travel(4)->minutes(); // 240 s; the first shrink is due at 480 s
        $advance = $this->use($hunter, $code, 'shrink')->assertOk()->json('room.zoneAdvance');
        $this->assertEqualsWithDelta(210, $advance, 1);
        $keys = collect($this->as($runner)->getJson("/api/rooms/{$code}")->json('chat'))->pluck('key');
        $this->assertContains('chat.event.powerUp.shrinkOther', $keys);
        $this->assertNotContains('chat.event.shrinking', $keys);
        $this->travel(31)->seconds();
        $this->assertContains('chat.event.shrinking', collect($this->as($runner)->getJson("/api/rooms/{$code}")->json('chat'))->pluck('key'));
    }

    public function test_flare_pings_now(): void
    {
        [$code, $hunter, $runner] = $this->round();
        $this->at($runner, $code, 43.8570, 18.4140);
        $this->travel(4)->minutes();
        $this->use($hunter, $code, 'flare')->assertOk();
        $seconds = collect($this->as($hunter)->getJson("/api/rooms/{$code}")->json('positions'))->pluck('second');
        $this->assertEqualsWithDelta(240, $seconds->max(), 1);
    }

    public function test_a_map_pickup_spawns_and_gives_an_extra_go(): void
    {
        [$code, $hunter, $runner] = $this->round(signedIn: false);
        $this->travel(5)->minutes(); // head start + 2 min
        $pickup = $this->as($runner)->getJson("/api/rooms/{$code}")->assertOk()->json('room.pickup');
        $this->assertNotNull($pickup);
        $this->at($runner, $code, $pickup['latitude'], $pickup['longitude'])->assertJsonPath('you.powerUps.charges', 1)->assertJsonPath('room.pickup', null);
        // A guest with a charge can use a power-up, and the charge does not count as their own one.
        $this->use($runner, $code, 'ghost')->assertOk()->assertJsonPath('you.powerUps.charges', 0)->assertJsonPath('you.powerUps.used', []);
    }

    public function test_the_host_can_turn_power_ups_off(): void
    {
        [$code, $hunter] = $this->round(rules: ['powerUps' => false]);
        $this->travel(3)->minutes();
        $this->use($hunter, $code, 'flare')->assertStatus(409);
        $this->travel(2)->minutes();
        $this->assertNull($this->as($hunter)->getJson("/api/rooms/{$code}")->json('room.pickup'));
    }

    public function test_revenuecat_credits_a_pack_once(): void
    {
        config(['services.revenuecat.webhook_secret' => 'Bearer rc-secret']);
        $user = User::create(['name' => 'Maya', 'apple_sub' => 'x']);
        $event = ['event' => ['id' => 'evt-1', 'type' => 'NON_RENEWING_PURCHASE', 'app_user_id' => "hideya_{$user->id}", 'product_id' => 'hideya_powerups_15']];
        $headers = ['Authorization' => 'Bearer rc-secret'];
        $this->postJson('/api/revenuecat/webhook', $event, $headers)->assertOk();
        $this->postJson('/api/revenuecat/webhook', $event, $headers)->assertOk();
        $owned = \App\Game\PowerUps::inventory($user->fresh());
        $this->assertSame(15, array_sum($owned));
        foreach ($owned as $count) {
            $this->assertContains($count, [3, 4], 'an even mix of the four kinds');
        }
        $token = $user->createToken('app')->plainTextToken;
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertJsonPath('user.powerUps.inventory', $owned)->assertJsonPath('user.powerUps.dailyAvailable', true);
    }

    public function test_the_daily_pack_opens_once_a_day_and_refills_at_local_midnight(): void
    {
        $user = User::create(['name' => 'Maya', 'apple_sub' => 'x']);
        $auth = ['Authorization' => 'Bearer '.$user->createToken('app')->plainTextToken];
        $this->getJson('/api/me', $auth)->assertJsonPath('user.powerUps.dailyAvailable', true);
        $opened = $this->postJson('/api/me/daily-power-up', [], $auth)->assertOk();
        $this->assertContains($opened->json('amount'), [1, 2, 3]);
        $this->assertContains($opened->json('kind'), ['ghost', 'decoy', 'shrink', 'flare']);
        $this->assertSame($opened->json('amount'), $opened->json('inventory.'.$opened->json('kind')));
        $this->assertFalse($opened->json('user.powerUps.dailyAvailable'));
        // Opening it again (an interrupted reveal) shows the same drop and adds nothing.
        $this->postJson('/api/me/daily-power-up', [], $auth)->assertOk()->assertJsonPath('repeat', true)
            ->assertJsonPath('kind', $opened->json('kind'))->assertJsonPath('amount', $opened->json('amount'))->assertJsonPath('inventory', $opened->json('inventory'));
        // Midnight in Sarajevo, not UTC.
        $this->travelTo(now('Europe/Sarajevo')->addDay()->startOfDay()->addMinute());
        $this->getJson('/api/me', $auth)->assertJsonPath('user.powerUps.dailyAvailable', true);
    }

    public function test_guests_cannot_open_a_daily_pack(): void
    {
        $this->postJson('/api/me/daily-power-up')->assertUnauthorized();
    }
}
