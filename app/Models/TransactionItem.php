<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    protected $fillable = [
        'game_id',
        'type',
        'item_id',
        'amount',
        'from_player_id',
        'to_player_id',
    ];

    public $timestamps = false;

    public function item()
    {
        return match ($this->type) {
            'property' => $this->belongsTo(Property::class, 'item_id'),
            'card' => $this->belongsTo(Card::class, 'item_id'),
            default => $this->belongsTo(static::class, 'item_id')->withDefault(['title' => 'Cash']),
        };
    }

    public function fromPlayer()
    {
        return $this->belongsTo(Player::class, 'from_player_id')->withDefault(['name' => 'The Man']);
    }

    public function toPlayer()
    {
        return $this->belongsTo(Player::class, 'to_player_id')->withDefault(['name' => 'The Man']);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
