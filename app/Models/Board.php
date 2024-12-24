<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Board extends Model
{
    protected $fillablle = [
        'name',
        'description',
        'created_by',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function properties()
    {
        return $this->hasMany(Property::class);
    }

    public function getSpaces()
    {
        $properties = $this->properties()->get();

        return [
            [
                'title' => 'Go',
                'type' => 'ActionSpace',
            ],
            $properties->where('color', 'brown')->values()[0],
            [
                'title' => 'Chest',
                'type' => 'ActionSpace',
                'effect' => 'Draw::Chest',
            ],
            $properties->where('color', 'brown')->values()[1],
            [
                'title' => 'Tax',
                'type' => 'ActionSpace',
                'effect' => 'Pay::200',
            ],
            $properties->where('color', 'black')->values()[0],
            $properties->where('color', 'cyan')->values()[0],
            [
                'title' => 'Chest',
                'type' => 'ActionSpace',
                'effect' => 'Draw::Chest',
            ],
            $properties->where('color', 'cyan')->values()[1],
            $properties->where('color', 'cyan')->values()[2],
        ];
    }
}
