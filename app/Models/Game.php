<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Game extends Model
{
    protected $fillable = [
        'board_id',
        'status',
        'invite_code',
    ];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function turns()
    {
        return $this->hasMany(Turn::class);
    }

    /**
     * Accessor: collection of non-bankrupt players ordered deterministically.
     */
    protected function activePlayers(): Attribute
    {
        return Attribute::make(
            get: function (): Collection {
                $players = $this->relationLoaded('players') ? $this->players : $this->players()->get();
                return $players
                    ->where('is_bankrupt', false)
                    ->sortBy('id')
                    ->values();
            },
        );
    }

    /**
     * Accessor: compute the player whose turn it is, based on the last turn that included a dice roll.
     * Synthetic turns (e.g., trades or purchases that create a completed turn without a roll)
     * are ignored for determining turn order.
     */
    protected function currentPlayer(): Attribute
    {
        return Attribute::make(
            get: function (): ?Player {
                $activePlayers = $this->active_players; // accessor above
                if ($activePlayers->isEmpty()) {
                    return null;
                }

                // Prefer already-loaded turns with rolls to avoid queries
                $turns = $this->relationLoaded('turns') ? $this->turns : $this->turns()->get();
                $lastRolledTurn = $turns
                    ->filter(function (Turn $turn) {
                        // Use loaded relation if available, otherwise query existence
                        if ($turn->relationLoaded('rolls')) {
                            return $turn->rolls->isNotEmpty();
                        }
                        return $turn->rolls()->exists();
                    })
                    ->sortByDesc('id')
                    ->first();

                if (!$lastRolledTurn) {
                    // No one has rolled yet: first active player starts
                    return $activePlayers->first();
                }

                // If last roller is now inactive/bankrupt, fall back to first active player
                $lastIndex = $activePlayers->search(fn (Player $p) => $p->id === $lastRolledTurn->player_id);
                if ($lastIndex === false) {
                    return $activePlayers->first();
                }

                $nextIndex = ($lastIndex + 1) % $activePlayers->count();
                return $activePlayers->get($nextIndex);
            },
        );
    }
}
