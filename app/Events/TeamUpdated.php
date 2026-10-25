<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something only one team may see: a teammate's position, a Radio message,
 * or (hunters only) the runners' positions at a ping.
 */
class TeamUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $code, public string $team, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("room.{$this->code}.{$this->team}")];
    }

    public function broadcastAs(): string
    {
        return 'team.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
