<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $fillable = [
        'board_id',
        'type',
        'message',
        'effect',
    ];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function item()
    {
        return $this->morphOne(PlayerAsset::class, 'itemable');
    }
}
