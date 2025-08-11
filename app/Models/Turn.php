<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turn extends Model
{
    protected $fillable = [
        'game_id',
        'player_id',
        'status',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function rolls()
    {
        return $this->hasMany(Roll::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
