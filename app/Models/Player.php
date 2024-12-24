<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillablle = [
        'game_id',
        'user_id',
        'name',
        'cash',
        'position',
        'is_bankrupt',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
