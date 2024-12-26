<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerAsset extends Model
{
    protected $fillable = [
        'player_id',
        'item_type',
        'item_id',
    ];

    public function itemable()
    {
        return $this->morphTo();
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
