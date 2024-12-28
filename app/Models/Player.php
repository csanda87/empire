<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = [
        'game_id',
        'user_id',
        'name',
        'color',
        'cash',
        'position',
        'is_bankrupt',
    ];

    public function assets()
    {
        return $this->hasMany(PlayerAsset::class);
    }

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
