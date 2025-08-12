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

    public function item()
    {
        return $this->morphOne(PlayerAsset::class, 'itemable');
    }

    public function colorGroupCountOnBoard(): int
    {
        // Count how many properties of this color exist on this board
        return (int) static::query()
            ->where('board_id', $this->board_id)
            ->whereRaw('LOWER(color) = ?', [strtolower((string) $this->color)])
            ->count();
    }

    public function ownerOwnsFullColorSet(): bool
    {
        $ownerId = optional($this->item)->player_id;
        if (!$ownerId) {
            return false;
        }
        $totalInColor = $this->colorGroupCountOnBoard();
        if ($totalInColor <= 1) {
            return false;
        }
        $ownedInColor = (int) static::query()
            ->where('board_id', $this->board_id)
            ->whereRaw('LOWER(color) = ?', [strtolower((string) $this->color)])
            ->whereHas('item', fn($q) => $q->where('player_id', $ownerId))
            ->count();
        return $ownedInColor === $totalInColor;
    }

    /**
     * Determine if this property represents a railroad.
     * By default we treat color "black" or explicit type "railroad" as railroad spaces.
     */
    public function isRailroad(): bool
    {
        $type = strtolower((string) ($this->type ?? ''));
        $color = strtolower((string) ($this->color ?? ''));
        return $type === 'railroad' || $color === 'black';
    }

    /**
     * Determine if this property represents a utility.
     * By default we treat color "white" or explicit type "utility" as utilities.
     */
    public function isUtility(): bool
    {
        $type = strtolower((string) ($this->type ?? ''));
        $color = strtolower((string) ($this->color ?? ''));
        return $type === 'utility' || $color === 'white';
    }
}
