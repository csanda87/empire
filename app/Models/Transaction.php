<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'game_id',
        'turn_id',
        'from_player_id',
        'to_player_id',
        'status',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function items()
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function fromPlayer()
    {
        return $this->belongsTo(Player::class, 'from_player_id');
    }

    public function toPlayer()
    {
        return $this->belongsTo(Player::class, 'to_player_id');
    }

    public function turn()
    {
        return $this->belongsTo(Turn::class);
    }
}
