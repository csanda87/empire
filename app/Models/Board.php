<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Game;

class Board extends Model
{
    protected $fillable = [
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

    public function getSpaces(Game $game)
    {
        // Always fetch properties with ownership scoped to this game's players
        $playerIds = $game->players->pluck('id')->values();
        $properties = $this->properties()
            ->with(['item' => function ($q) use ($playerIds) {
                if ($playerIds->isNotEmpty()) {
                    $q->whereIn('player_id', $playerIds);
                } else {
                    // Ensure no cross-game leakage when no players yet
                    $q->whereRaw('1 = 0');
                }
            }])
            ->get();

        return [
            [
                [
                    'title' => 'Go',
                    'type' => 'ActionSpace',
                    'effect' => 'Do::nothing',
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

    /**
     * Find the absolute board index of a property by its title.
     */
    public function positionOfPropertyTitle(Game $game, string $title): ?int
    {
        $needle = strtolower(trim($title));
        $normalize = function (string $s): string {
            $s = strtolower(trim($s));
            $s = str_replace(['.', '  '], ['', ' '], $s);
            return $s;
        };

        $spaces = collect($this->getSpaces($game))->flatten(1)->values();
        foreach ($spaces as $idx => $space) {
            if ($space instanceof Property) {
                $spaceTitle = (string) ($space->title ?? '');
                if ($normalize($spaceTitle) === $normalize($needle)) {
                    return (int) $idx;
                }
            }
        }
        return null;
    }
}
