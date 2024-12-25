<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Roll extends Model
{
    protected function casts()
    {
        return [
            'dice' => 'array',
        ];
    }

    protected $fillable = [
        'turn_id',
        'dice',
        'is_double',
        'total',
    ];

    public function turn()
    {
        return $this->belongsTo(Turn::class);
    }
}
