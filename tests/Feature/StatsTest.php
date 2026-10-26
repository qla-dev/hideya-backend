<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Events\TeamUpdated;
use App\Game\Referee;
use App\Game\Rules;
use App\Models\Player;
use App\Models\Room;
use App\Models\RoundResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    private function account(string $name = 'Maya'): array
    {
        $user = User::create(['name' => $name, 'apple_sub' => $name]);
        // Real HTTP requests get fresh guards; this test process switches accounts.
        $this->app['auth']->forgetGuards();

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('app')->plainTextToken]];
    }

    private function demo(string $id = 'round-1'): array
    {
        return ['id' => $id, 'date' => now()->toIso8601String(), 'team' => 'runner', 'outcome' => 'survived', 'seconds' => 600, 'metres' => 500];
    }

    public function test_stats_require_an_account_and_demo_retries_are_idempotent_and_private(): void
    {
        $this->getJson('/api/me/stats')->assertUnauthorized();
        $this->postJson('/api/me/demo-results', $this->demo())->assertUnauthorized();
        [$user, $auth] = $this->account();
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.total', 0)->assertJsonPath('recent', []);
        $this->postJson('/api/me/demo-results', $this->demo(), $auth)->assertOk();
        $this->postJson('/api/me/demo-results', [...$this->demo(), 'metres' => 9999], $auth)->assertOk();
        $this->assertSame(1, RoundResult::count());
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.won', 1)->assertJsonPath('summary.farthest', 500)->assertJsonPath('recent.0.source', 'demo');

        [, $otherAuth] = $this->account('Leo');
        $this->getJson('/api/me/stats', $otherAuth)->assertOk()->assertJsonPath('summary.total', 0);
        $this->postJson('/api/me/demo-results', [...$this->demo(), 'user_id' => $user->id], $otherAuth)->assertOk();
        $this->assertSame(2, RoundResult::count());
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/me', [], $auth)->assertOk();
        $this->assertDatabaseMissing('round_results', ['user_id' => $user->id]);
        $this->assertSame(1, RoundResult::count());
    }

    public function test_lifetime_totals_include_more_than_fifty_rounds_and_do_not_trust_client_wins(): void
    {
        [$user, $auth] = $this->account();
        foreach (range(1, 55) as $i) {
            RoundResult::create(['user_id' => $user->id, 'source_id' => 'demo:'.$i, 'source' => 'demo', 'team' => 'runner', 'outcome' => 'survived', 'seconds' => $i, 'metres' => $i, 'won' => true, 'played_at' => now()->subMinutes($i)]);
        }
        $this->postJson('/api/me/demo-results', [...$this->demo('left'), 'team' => 'hunter', 'outcome' => 'left', 'won' => true], $auth)->assertOk();
        $this->getJson('/api/me/stats', $auth)->assertOk()
            ->assertJsonPath('summary.total', 56)->assertJsonPath('summary.played', 55)
            ->assertJsonPath('summary.won', 55)->assertJsonPath('summary.neverCaught', 55)
            ->assertJsonPath('summary.runnerWon', 55)->assertJsonPath('summary.hunterWon', 0)
            ->assertJsonPath('summary.longest', 55)->assertJsonCount(12, 'recent');
        $this->postJson('/api/me/demo-results', [...$this->demo(), 'seconds' => -1], $auth)->assertUnprocessable();
        $this->postJson('/api/me/demo-results', [...$this->demo(), 'team' => 'invalid'], $auth)->assertUnprocessable();
    }

    public function test_online_results_save_without_the_results_screen_and_recover_old_rooms(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$user, $auth] = $this->account();
        $room = Room::create(['code' => 'STATS1', 'rules' => Rules::merge([], ['headStart' => 0]), 'status' => 'playing', 'started_at' => now()->subMinutes(5)]);
        $player = $room->players()->create(['name' => 'Maya', 'user_id' => $user->id, 'team' => 'runner', 'token_hash' => hash('sha256', 'runner'), 'metres' => 123]);
        $room->players()->create(['name' => 'Guest hunter', 'team' => 'hunter', 'token_hash' => hash('sha256', 'guest')]);
        DB::transaction(fn () => Referee::end(Room::lockForUpdate()->findOrFail($room->id), 'runnersWin'));
        $this->assertDatabaseHas('round_results', ['user_id' => $user->id, 'player_id' => $player->id, 'outcome' => 'survived', 'won' => true, 'seconds' => 300, 'metres' => 123]);
        $this->assertSame(1, RoundResult::count());
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.played', 1)->assertJsonPath('recent.0.source', 'online');

        // Simulate an account-linked room that finished before the stats release.
        $old = Room::create(['code' => 'STATS2', 'rules' => Rules::merge([], ['headStart' => 0]), 'status' => 'ended', 'outcome' => 'huntersWin', 'started_at' => now()->subMinutes(10), 'ended_at' => now()->subMinutes(1)]);
        $old->players()->create(['name' => 'Maya', 'user_id' => $user->id, 'team' => 'hunter', 'token_hash' => hash('sha256', 'old')]);
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.won', 2);
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.played', 2);
        $room->delete();
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.played', 2);
    }

    public function test_mid_round_sign_in_records_distance_and_does_not_transfer_a_player(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$user, $auth] = $this->account();
        $host = $this->postJson('/api/rooms', ['name' => 'Maya', 'rules' => ['headStart' => 0]])->assertCreated();
        $code = $host->json('room.code');
        $hostAuth = [...$auth, 'X-Player-Token' => $host->json('token')];
        $guest = $this->postJson("/api/rooms/{$code}/join", ['name' => 'Leo'])->assertCreated();
        $this->patchJson("/api/rooms/{$code}/me", ['pick' => 'hunter'], ['X-Player-Token' => $host->json('token')])->assertOk();
        $this->patchJson("/api/rooms/{$code}/me", ['pick' => 'runner'], ['X-Player-Token' => $guest->json('token')])->assertOk();
        // Signing in after joining claims this previously guest player.
        $this->postJson("/api/rooms/{$code}/start", [], $hostAuth)->assertOk();
        $this->assertSame($user->id, Player::find($host->json('you.id'))->user_id);
        $point = ['latitude' => 43.8563, 'longitude' => 18.4131];
        $this->postJson("/api/rooms/{$code}/location", $point, $hostAuth)->assertOk();
        foreach ([43.85633, 43.85636, 43.85639, 43.85642, 43.85645, 43.85648] as $lat) {
            $this->postJson("/api/rooms/{$code}/location", [...$point, 'latitude' => $lat], $hostAuth)->assertOk();
        }
        $this->assertGreaterThan(19, Player::find($host->json('you.id'))->metres, 'Slow walking must accumulate across sub-six-metre samples.');
        $this->postJson("/api/rooms/{$code}/location", [...$point, 'latitude' => 43.8565], $hostAuth)->assertOk();
        [, $other] = $this->account('Other');
        $this->getJson("/api/rooms/{$code}", [...$other, 'X-Player-Token' => $host->json('token')])->assertOk();
        $this->assertSame($user->id, Player::find($host->json('you.id'))->user_id);
        $this->travel(1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/rooms/{$code}/tagged", [], ['X-Player-Token' => $guest->json('token')])->assertOk();
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.hunterWon', 1)->assertJsonPath('summary.farthest', 20);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me/stats', $other)->assertOk()->assertJsonPath('summary.total', 0);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/rooms/{$code}/location", [...$point, 'latitude' => 43.8568], $hostAuth)->assertOk();
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.farthest', 20);
    }

    public function test_tagged_left_and_cancelled_results_are_not_wins_and_timers_stop(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$user, $auth] = $this->account();
        $room = Room::create(['code' => 'STATS3', 'rules' => Rules::merge([], ['headStart' => 1]), 'status' => 'playing', 'started_at' => now()->subMinutes(10)]);
        $room->players()->create(['name' => 'Tagged', 'user_id' => $user->id, 'team' => 'runner', 'token_hash' => hash('sha256', 'tagged'), 'tagged_at' => now()->subMinutes(7)]);
        $room->players()->create(['name' => 'Left', 'user_id' => $user->id, 'team' => 'hunter', 'token_hash' => hash('sha256', 'left'), 'left_at' => now()->subMinutes(6)]);
        DB::transaction(fn () => Referee::end(Room::lockForUpdate()->findOrFail($room->id), 'runnersWin'));
        $this->assertDatabaseHas('round_results', ['outcome' => 'tagged', 'seconds' => 120, 'won' => false]);
        $this->assertDatabaseHas('round_results', ['outcome' => 'left', 'seconds' => 180, 'won' => false]);
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.total', 2)->assertJsonPath('summary.played', 1)->assertJsonPath('summary.won', 0)->assertJsonPath('summary.neverCaught', 0);

        $cancelled = Room::create(['code' => 'STATS4', 'rules' => Rules::merge([], []), 'status' => 'playing', 'started_at' => now()->subMinute()]);
        $cancelled->players()->create(['name' => 'Cancelled', 'user_id' => $user->id, 'team' => 'runner', 'token_hash' => hash('sha256', 'cancelled')]);
        DB::transaction(fn () => Referee::end(Room::lockForUpdate()->findOrFail($cancelled->id), 'cancelled'));
        $this->assertDatabaseHas('round_results', ['source_id' => 'online:3', 'outcome' => 'left', 'seconds' => 0, 'won' => false]);
        $this->getJson('/api/me/stats', $auth)->assertOk()->assertJsonPath('summary.total', 3)->assertJsonPath('summary.played', 1);
    }
}
