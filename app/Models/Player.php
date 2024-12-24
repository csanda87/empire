<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillablle = [
        'game_id',
        'user_id',
        'name',
        'color',
        'cash',
        'position',
        'is_bankrupt',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function getColors()
    {
        return [
            'amber',
            'blue',
            'cyan',
            'emerald',
            'green',
            'indigo',
            'lime',
            'pink',
            'purple',
            'red',
            'rose',
            'sky',
            'teal',
            'yellow',
        ];
    }
}
