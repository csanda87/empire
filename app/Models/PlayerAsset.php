<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerAsset extends Model
{
    protected $fillable = [
        'player_id',
        'type',
        'item_id',
    ];

    public function item()
    {
        return match ($this->type) {
            'property' => $this->belongsTo(Property::class, 'item_id'),
            'card' => $this->belongsTo(Card::class, 'item_id'),
            default => $this->belongsTo(static::class, 'item_id'),
        };
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
