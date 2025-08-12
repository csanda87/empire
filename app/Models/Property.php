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

    /**
     * Determine if this property supports building units (houses/hotels).
     * We require a defined unit price and all rent tiers to be present.
     */
    public function supportsUnits(): bool
    {
        if ($this->isRailroad() || $this->isUtility()) {
            return false;
        }

        $hasUnitPrice = $this->unit_price !== null && (int) $this->unit_price > 0;
        $hasAllTiers = $this->rent_one_unit !== null
            && $this->rent_two_unit !== null
            && $this->rent_three_unit !== null
            && $this->rent_four_unit !== null
            && $this->rent_five_unit !== null;

        return $hasUnitPrice && $hasAllTiers;
    }

    /**
     * Check that there is a rent tier defined for the specified number of units (1-5).
     */
    public function hasRentForUnits(int $units): bool
    {
        return match ($units) {
            1 => $this->rent_one_unit !== null,
            2 => $this->rent_two_unit !== null,
            3 => $this->rent_three_unit !== null,
            4 => $this->rent_four_unit !== null,
            5 => $this->rent_five_unit !== null,
            default => false,
        };
    }
}
