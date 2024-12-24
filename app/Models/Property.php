<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $fillable = [
        'board_id',
        'title',
        'type',
        'color',
        'price',
        'mortgage_price',
        'unmortgage_price',
        'rent',
        'rent_color_set',
        'rent_one_unit',
        'rent_two_unit',
        'rent_three_unit',
        'rent_four_unit',
        'rent_five_unit',
        'unit_price',
    ];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }
}
