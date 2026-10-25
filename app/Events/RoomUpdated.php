<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** The room's public state (roster, rules, timing) changed. Everyone in the room receives it. */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $code, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("room.{$this->code}")];
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
