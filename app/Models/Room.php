<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Room extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'fired' => 'array',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RadioMessage::class);
    }

    public function pings(): HasMany
    {
        return $this->hasMany(RoomPing::class);
    }

    /** Seconds since the round started, capped at its end. */
    public function elapsed(?Carbon $now = null): float
    {
        if (! $this->started_at) {
            return 0;
        }
        $until = $this->ended_at ?? $now ?? now();

        return max(0, $this->started_at->diffInMilliseconds($until) / 1000);
    }

    public function headStartSeconds(): int
    {
        return (int) $this->rules['headStart'] * 60;
    }

    public function roundSeconds(): int
    {
        return ((int) $this->rules['headStart'] + (int) $this->rules['roundLength']) * 60;
    }

    public function bump(): void
    {
        $this->increment('revision');
    }
}
