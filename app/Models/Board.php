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
                [
                    'title' => 'Go',
                    'type' => 'ActionSpace',
                    'effect' => 'Collect::200',
                ],
                $properties->where('color', 'brown')->values()[0],
                [
                    'title' => 'Vault',
                    'type' => 'ActionSpace',
                    'effect' => 'Draw::Vault',
                ],
                $properties->where('color', 'brown')->values()[1],
                [
                    'title' => 'Tribute',
                    'type' => 'ActionSpace',
                    'effect' => 'Pay::200',
                ],
                $properties->where('color', 'black')->values()[0],
                $properties->where('color', 'cyan')->values()[0],
                [
                    'title' => 'Fate',
                    'type' => 'ActionSpace',
                    'effect' => 'Draw::Fate',
                ],
                $properties->where('color', 'cyan')->values()[1],
                $properties->where('color', 'cyan')->values()[2],
            ],
            [
                [
                    'title' => 'The Joint',
                    'type' => 'ActionSpace',
                    'effect' => 'Do::Lockup',
                ],
                $properties->where('color', 'pink')->values()[0],
                $properties->where('color', 'white')->values()[0],
                $properties->where('color', 'pink')->values()[1],
                $properties->where('color', 'pink')->values()[2],
                $properties->where('color', 'black')->values()[1],
                $properties->where('color', 'orange')->values()[0],
                [
                    'title' => 'Vault',
                    'type' => 'ActionSpace',
                    'effect' => 'Draw::Vault',
                ],
                $properties->where('color', 'orange')->values()[1],
                $properties->where('color', 'orange')->values()[2],
            ],
            [
                [
                    'title' => 'Safehouse',
                    'type' => 'ActionSpace',
                    'effect' => 'Do::nothing',
                ],
                $properties->where('color', 'red')->values()[0],
                [
                    'title' => 'Fate',
                    'type' => 'ActionSpace',
                    'effect' => 'Draw::Fate',
                ],
                $properties->where('color', 'red')->values()[1],
                $properties->where('color', 'red')->values()[2],
                $properties->where('color', 'black')->values()[2],
                $properties->where('color', 'yellow')->values()[0],
                $properties->where('color', 'yellow')->values()[1],
                $properties->where('color', 'white')->values()[1],
                $properties->where('color', 'yellow')->values()[2],
            ],
            [
                [
                    'title' => 'Go to the Joint',
                    'type' => 'ActionSpace',
                    'effect' => 'Move::toJoint',
                ],
                $properties->where('color', 'green')->values()[0],
                $properties->where('color', 'green')->values()[1],
                [
                    'title' => 'Vault',
                    'type' => 'ActionSpace',
                    'effect' => 'Draw::Vault',
                ],
                $properties->where('color', 'green')->values()[2],
                $properties->where('color', 'black')->values()[3],
                [
                    'title' => 'Fate',
                    'type' => 'ActionSpace',
                    'effect' => 'Draw::Fate',
                ],
                $properties->where('color', 'blue')->values()[0],
                [
                    'title' => 'Finer Things',
                    'type' => 'ActionSpace',
                    'effect' => 'Pay::100',
                ],
                $properties->where('color', 'blue')->values()[1],
            ],
        ];
    }
}
