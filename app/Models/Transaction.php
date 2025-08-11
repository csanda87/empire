<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'game_id',
        'turn_id',
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

    // Directional players are represented on items, not the parent transaction

    public function turn()
    {
        return $this->belongsTo(Turn::class);
    }
}
