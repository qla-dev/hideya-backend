<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoundResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seconds' => 'integer', 'metres' => 'integer', 'won' => 'boolean', 'played_at' => 'datetime'];
    }
}
