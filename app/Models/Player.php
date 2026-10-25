<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'is_host' => 'boolean',
            'ready' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'located_at' => 'datetime',
            'tagged_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function isOut(): bool
    {
        return $this->tagged_at !== null || $this->left_at !== null;
    }
}
