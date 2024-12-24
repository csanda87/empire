<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'board_id',
        'status',
        'invite_code',
    ];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function players()
    {
        return $this->hasMany(Player::class);
    }
}