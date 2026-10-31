<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Events\TeamUpdated;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RoomFlowTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $token): static
    {
        return $this->withHeader('X-Player-Token', $token);
    }

    /** Host and one guest in a lobby, with picks set. Returns [code, hostToken, guestToken]. */
    private function lobby(string $hostPick = 'hunter', string $guestPick = 'runner'): array
    {
        $host = $this->postJson('/api/rooms', ['name' => 'Maya', 'center' => ['latitude' => 43.8563, 'longitude' => 18.4131]])->assertCreated();
        $code = $host->json('room.code');
        $guest = $this->postJson("/api/rooms/{$code}/join", ['name' => 'Leo'])->assertCreated();
        $this->as($host->json('token'))->patchJson("/api/rooms/{$code}/me", ['pick' => $hostPick])->assertOk();
        $this->as($guest->json('token'))->patchJson("/api/rooms/{$code}/me", ['pick' => $guestPick])->assertOk();

        return [$code, $host->json('token'), $guest->json('token')];
    }

    public function test_host_creates_room_and_guest_joins(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        $response = $this->postJson('/api/rooms', ['name' => 'Maya'])->assertCreated();
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $response->json('room.code'));
        $this->assertSame(30, $response->json('room.rules.roundLength'));
        $this->assertTrue($response->json('you.host'));

        $join = $this->postJson('/api/rooms/'.strtolower($response->json('room.code')).'/join', ['name' => 'Leo'])->assertCreated();
        $this->assertCount(2, $join->json('room.players'));
        $this->assertArrayNotHasKey('token_hash', $join->json('room.players.0'));
        Event::assertDispatched(RoomUpdated::class, fn ($e) => $e->payload['reason'] === 'joined');
    }

    public function test_only_the_host_changes_rules_and_starts(): void
    {
        [$code, $host, $guest] = $this->lobby();
        $this->as($guest)->patchJson("/api/rooms/{$code}", ['rules' => ['roundLength' => 45]])->assertForbidden();
        $this->as($host)->patchJson("/api/rooms/{$code}", ['rules' => ['roundLength' => 45, 'ping' => 99]])->assertUnprocessable();
        $this->as($host)->patchJson("/api/rooms/{$code}", ['rules' => ['roundLength' => 45]])->assertOk()->assertJsonPath('room.rules.roundLength', 45);
        $this->as($guest)->postJson("/api/rooms/{$code}/start")->assertForbidden();
        $this->as($host)->postJson("/api/rooms/{$code}/start")->assertOk()->assertJsonPath('room.status', 'playing')->assertJsonPath('you.team', 'hunter');
        $this->postJson("/api/rooms/{$code}/join", ['name' => 'Late'])->assertStatus(409);
    }

    public function test_requests_need_the_players_own_token(): void
    {
        [$code] = $this->lobby();
        $this->flushHeaders();
        $this->getJson("/api/rooms/{$code}")->assertUnauthorized();
        $this->as('not-a-token')->getJson("/api/rooms/{$code}")->assertForbidden();
        $other = $this->postJson('/api/rooms', ['name' => 'Other'])->json('token');
        $this->as($other)->getJson("/api/rooms/{$code}")->assertForbidden();
    }

    public function test_runners_never_see_hunters_and_hunters_see_runners_only_at_pings(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$code, $hunter, $runner] = $this->lobby();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start")->assertOk();
        $this->as($hunter)->postJson("/api/rooms/{$code}/location", ['latitude' => 43.856, 'longitude' => 18.413])->assertOk();
        $this->as($runner)->postJson("/api/rooms/{$code}/location", ['latitude' => 43.857, 'longitude' => 18.414])->assertOk();

        // During the head start the hunter sees only themself; the runner never sees the hunter.
        $hunterIds = collect($this->as($hunter)->getJson("/api/rooms/{$code}")->json('positions'))->pluck('id');
        $runnerView = $this->as($runner)->getJson("/api/rooms/{$code}");
        $this->assertCount(1, $hunterIds);
        $this->assertSame([$runnerView->json('you.id')], collect($runnerView->json('positions'))->pluck('id')->all());
        Event::assertDispatched(TeamUpdated::class, fn ($e) => $e->team === 'runner' && $e->payload['type'] === 'location');
        Event::assertNotDispatched(TeamUpdated::class, fn ($e) => $e->team === 'hunter' && ($e->payload['position']['id'] ?? null) === $runnerView->json('you.id'));

        // The head start (3 min) ends with the first ping: hunters get the runner's snapshot.
        $this->travel(3)->minutes();
        $after = $this->as($hunter)->getJson("/api/rooms/{$code}")->assertOk();
        $ghost = collect($after->json('positions'))->firstWhere('id', $runnerView->json('you.id'));
        $this->assertNotNull($ghost);
        $this->assertFalse($ghost['live']);
        $this->assertSame(180, $ghost['second']);
        Event::assertDispatched(TeamUpdated::class, fn ($e) => $e->team === 'hunter' && $e->payload['type'] === 'ping');
        $keys = collect($this->as($runner)->getJson("/api/rooms/{$code}")->json('chat'))->pluck('key');
        $this->assertContains('chat.event.headOverRunners', $keys);
        $this->assertContains('chat.event.pingRunners', $keys);
        $this->assertNotContains('chat.event.pingHunters', $keys);
    }

    public function test_chat_stays_within_the_team(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$code, $hunter, $runner] = $this->lobby();
        $this->as($runner)->postJson("/api/rooms/{$code}/chat", ['text' => 'too early'])->assertStatus(409);
        $this->as($hunter)->postJson("/api/rooms/{$code}/start");
        $this->as($runner)->postJson("/api/rooms/{$code}/chat", ['text' => 'Under the slide.'])->assertOk();
        $this->assertContains('Under the slide.', collect($this->as($runner)->getJson("/api/rooms/{$code}")->json('chat'))->pluck('text'));
        $this->assertNotContains('Under the slide.', collect($this->as($hunter)->getJson("/api/rooms/{$code}")->json('chat'))->pluck('text'));
        Event::assertDispatched(TeamUpdated::class, fn ($e) => $e->team === 'runner' && ($e->payload['message']['text'] ?? null) === 'Under the slide.');
    }

    public function test_a_teammate_can_report_a_chat_message(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$code, $maya, $leo] = $this->lobby('runner', 'runner');
        $ana = $this->postJson("/api/rooms/{$code}/join", ['name' => 'Ana'])->assertCreated()->json('token');
        $this->as($ana)->patchJson("/api/rooms/{$code}/me", ['pick' => 'hunter'])->assertOk();
        $this->as($maya)->postJson("/api/rooms/{$code}/start")->assertOk();
        $this->as($leo)->postJson("/api/rooms/{$code}/chat", ['text' => 'Something rude.'])->assertOk();
        $id = collect($this->as($maya)->getJson("/api/rooms/{$code}")->json('chat'))->firstWhere('text', 'Something rude.')['id'];

        $this->as($leo)->postJson("/api/rooms/{$code}/chat/{$id}/report")->assertUnprocessable();
        $this->as($ana)->postJson("/api/rooms/{$code}/chat/{$id}/report")->assertNotFound();
        $this->as($maya)->postJson("/api/rooms/{$code}/chat/{$id}/report")->assertOk();
        $this->as($maya)->postJson("/api/rooms/{$code}/chat/{$id}/report")->assertOk();

        $this->assertDatabaseCount('chat_reports', 1);
        $this->assertDatabaseHas('chat_reports', ['room_code' => $code, 'chat_message_id' => $id, 'reporter_name' => 'Maya', 'reported_name' => 'Leo', 'message_text' => 'Something rude.']);
    }

    public function test_tagging_the_last_runner_ends_the_round(): void
    {
        Event::fake([RoomUpdated::class, TeamUpdated::class]);
        [$code, $hunter, $runner] = $this->lobby();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start");
        $this->as($hunter)->postJson("/api/rooms/{$code}/tagged")->assertStatus(409);
        $this->as($runner)->postJson("/api/rooms/{$code}/tagged")->assertOk()->assertJsonPath('room.status', 'ended')->assertJsonPath('room.outcome', 'huntersWin');
        Event::assertDispatched(RoomUpdated::class, fn ($e) => $e->payload['reason'] === 'ended');
    }

    public function test_time_running_out_is_a_runner_win(): void
    {
        [$code, $hunter] = $this->lobby();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start");
        $this->travel(34)->minutes();
        $this->as($hunter)->getJson("/api/rooms/{$code}")->assertJsonPath('room.status', 'ended')->assertJsonPath('room.outcome', 'runnersWin');
    }

    public function test_channel_auth_allows_only_own_room_and_team(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
            'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => '1', 'options' => ['cluster' => 'eu'],
        ]]);
        [$code, $hunter, $runner] = $this->lobby();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start");
        $auth = fn ($token, $channel) => $this->as($token)->postJson('/api/realtime/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

        $auth($runner, "private-room.{$code}")->assertOk()->assertJsonStructure(['auth']);
        $auth($runner, "private-room.{$code}.runner")->assertOk();
        $auth($runner, "private-room.{$code}.hunter")->assertForbidden();
        $auth($hunter, "private-room.{$code}.runner")->assertForbidden();
        $auth($hunter, 'private-room.ZZZZZZ')->assertForbidden();
    }

    public function test_random_picks_fill_hunters_to_a_third_and_both_teams_exist(): void
    {
        $teams = RoomController::assignTeams([1 => 'random', 2 => 'random', 3 => 'random', 4 => 'random', 5 => 'random', 6 => 'random']);
        $this->assertSame(2, count(array_keys($teams, 'hunter')));
        $this->assertSame(['hunter', 'runner'], array_values(array_unique(RoomController::assignTeams([1 => 'runner', 2 => 'runner']))));
        $this->assertContains('runner', RoomController::assignTeams([1 => 'hunter', 2 => 'hunter']));
    }

    public function test_runners_surrender_instead_of_leaving_and_hunters_leaving_hands_runners_the_win(): void
    {
        [$code, $hunter, $runner] = $this->lobby();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start")->assertOk();
        $this->as($runner)->postJson("/api/rooms/{$code}/leave")->assertStatus(409);

        // The last hunter leaving ends it for the runners.
        $this->as($hunter)->postJson("/api/rooms/{$code}/leave")->assertOk();
        $this->assertSame('runnersWin', $this->as($runner)->getJson("/api/rooms/{$code}")->json('room.outcome'));
    }

    public function test_surrender_puts_the_runner_out(): void
    {
        [$code, $hunter, $runner] = $this->lobby();
        $this->as($hunter)->postJson("/api/rooms/{$code}/start")->assertOk();
        $this->as($hunter)->postJson("/api/rooms/{$code}/surrender")->assertStatus(409);
        $this->as($runner)->postJson("/api/rooms/{$code}/surrender")->assertOk();
        $view = $this->as($hunter)->getJson("/api/rooms/{$code}");
        $this->assertSame('huntersWin', $view->json('room.outcome'));
        $this->assertContains('chat.event.surrendered', collect($view->json('chat'))->pluck('key'));
    }
}
