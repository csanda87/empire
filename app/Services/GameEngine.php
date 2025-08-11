<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Property;
use App\Models\Transaction;
use App\Models\Turn;
use Illuminate\Support\Facades\DB;

class GameEngine
{
    public const BOARD_SIZE = 40;
    public const GO_BONUS = 200;

    public function rollAndAdvance(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            // Reuse an existing in-progress turn for doubles, otherwise create a new one
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->where('status', '!=', 'completed')
                ->orderByDesc('id')
                ->first();

            if (!$turn) {
                $turn = $game->turns()->create(['player_id' => $player->id, 'status' => 'in_progress']);
            }

            $dieOne = rand(1, 6);
            $dieTwo = rand(1, 6);
            $total = $dieOne + $dieTwo;
            $isDouble = $dieOne === $dieTwo;

            $turn->rolls()->create([
                'dice' => [$dieOne, $dieTwo],
                'is_double' => $isDouble,
                'total' => $total,
            ]);

            // Count doubles already rolled this turn
            $existingDoublesCount = (int) $turn->rolls()->where('is_double', true)->count();

            $previousPosition = $player->position ?? 0;
            $transactions = [];
            $actions = [];

            // Handle third consecutive double: go directly to The Joint (position 10), no movement and end turn
            if ($isDouble && $existingDoublesCount >= 2) {
                $player->position = 10;
                $player->save();

                $space = $this->spaceAt($game, 10);
                $turn->update(['status' => 'completed']);

                return [
                    'dice' => [$dieOne, $dieTwo],
                    'total' => $total,
                    'is_double' => $isDouble,
                    'passed_go' => false,
                    'position' => 10,
                    'space' => $this->spaceSummary($space),
                    'actions' => array_merge($actions, [['type' => 'sent_to_joint']]),
                    'transactions' => $transactions,
                ];
            }

            $newPosition = $previousPosition + $total;
            $passedGo = $newPosition >= self::BOARD_SIZE;
            $newPosition = $newPosition % self::BOARD_SIZE;

            // Award GO bonus when passing or landing on GO (space 0) from any space other than 0
            $landingOnGo = ($newPosition === 0 && $previousPosition !== 0);
            $shouldAwardGoBonus = $passedGo || $landingOnGo;

            if ($shouldAwardGoBonus) {
                $transactions[] = $this->collectFromBank($game, $turn, $player, self::GO_BONUS);
                $player->cash += self::GO_BONUS;
            }

            $player->position = $newPosition;
            $player->save();

            $space = $this->spaceAt($game, $newPosition);
            $resolution = $this->resolveSpace($game, $turn, $player, $space);
            $transactions = array_merge($transactions, $resolution['transactions']);

            // Determine if there is a blocking decision (e.g., offer to purchase)
            $hasBlockingDecision = collect($resolution['actions'])->contains(function ($a) {
                return ($a['type'] ?? null) === 'offer_purchase';
            });

            // Set status rules:
            // - Third double already handled above and completed
            // - If blocking decision, await decision
            // - Else if doubles, keep in_progress to allow another roll
            // - Else, complete the turn immediately
            if ($hasBlockingDecision) {
                $turn->update(['status' => 'awaiting_decision']);
            } else {
                $turn->update(['status' => $isDouble ? 'in_progress' : 'completed']);
            }

            return [
                'dice' => [$dieOne, $dieTwo],
                'total' => $total,
                'is_double' => $isDouble,
                'passed_go' => $passedGo,
                'position' => $newPosition,
                'space' => $this->spaceSummary($space),
                'actions' => $resolution['actions'],
                'transactions' => $transactions,
            ];
        });
    }

    /**
     * After the player makes a decision for a blocking action (e.g., buys or skips a property),
     * update the current open turn status appropriately based on whether the last roll was a double.
     */
    public function resolvePendingDecision(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->where('status', 'awaiting_decision')
                ->orderByDesc('id')
                ->first();

            if (!$turn) {
                return ['updated' => false];
            }

            $lastRoll = $turn->rolls()->orderByDesc('id')->first();
            $wasDouble = (bool) optional($lastRoll)->is_double;
            $turn->update(['status' => $wasDouble ? 'in_progress' : 'completed']);

            return [
                'updated' => true,
                'turn_id' => $turn->id,
                'status' => $turn->status,
            ];
        });
    }

    /**
     * End the current player's turn after rolling. This marks the latest rolled
     * turn that is not yet completed as completed, advancing play to the next
     * player per Game::current_player rules.
     */
    public function endTurn(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            // Find the latest turn for this player that has a roll and is not completed
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->where('status', '!=', 'completed')
                ->orderByDesc('id')
                ->first();

            if (!$turn) {
                return ['ended' => false];
            }

            // Ensure the turn actually had a roll associated; otherwise ignore
            if (!$turn->rolls()->exists()) {
                return ['ended' => false];
            }

            $turn->update(['status' => 'completed']);

            return [
                'ended' => true,
                'turn_id' => $turn->id,
            ];
        });
    }

    protected function spaceAt(Game $game, int $position): array|Property
    {
        $flatSpaces = collect($game->board->getSpaces())->flatten(1)->values();
        return $flatSpaces[$position];
    }

    protected function resolveSpace(Game $game, Turn $turn, Player $player, array|Property $space): array
    {
        $transactions = [];
        $actions = [];

        if (is_array($space) && ($space['type'] ?? null) === 'ActionSpace') {
            $effect = $space['effect'] ?? '';
            [$verb, $arg] = array_pad(explode('::', $effect, 2), 2, null);

            switch ($verb) {
                case 'Collect':
                    $amount = (int) $arg;
                    $transactions[] = $this->collectFromBank($game, $turn, $player, $amount);
                    $player->increment('cash', $amount);
                    $actions[] = ['type' => 'collect', 'amount' => $amount];
                    break;

                case 'Pay':
                    $amount = (int) $arg;
                    $transactions[] = $this->payToBank($game, $turn, $player, $amount);
                    $player->decrement('cash', $amount);
                    $actions[] = ['type' => 'pay', 'amount' => $amount];
                    break;

                case 'Move':
                    if ($arg === 'toJoint') {
                        $player->position = 10;
                        $player->save();
                        $actions[] = ['type' => 'move', 'to' => 10];
                    }
                    break;

                case 'Do':
                    $actions[] = ['type' => 'noop', 'detail' => $arg];
                    break;

                case 'Draw':
                    $actions[] = ['type' => 'draw', 'deck' => $arg];
                    break;
            }

            return compact('transactions', 'actions');
        }

        if ($space instanceof Property) {
            $owner = optional($space->item)->player; // Player or null

            if ($owner && $owner->id !== $player->id) {
                $rent = (int) ($space->rent ?? 0);
                $transactions[] = $this->transferBetweenPlayers($game, $turn, $player, $owner, $rent);
                $player->decrement('cash', $rent);
                $owner->increment('cash', $rent);
                $actions[] = ['type' => 'rent_paid', 'to' => $owner->id, 'amount' => $rent];
            } elseif (!$owner) {
                $actions[] = [
                    'type' => 'offer_purchase',
                    'property_id' => $space->id,
                    'price' => $space->price,
                ];
            } else {
                $actions[] = ['type' => 'landed_own_property', 'property_id' => $space->id];
            }
        }

        return compact('transactions', 'actions');
    }

    protected function collectFromBank(Game $game, Turn $turn, Player $to, int $amount): Transaction
    {
        $tx = $game->transactions()->create([
            'turn_id' => $turn->id,
            'status' => 'completed',
        ]);

        $tx->items()->create([
            'game_id' => $game->id,
            'type' => 'cash',
            'item_id' => 0,
            'amount' => $amount,
            'from_player_id' => null,
            'to_player_id' => $to->id,
        ]);

        return $tx;
    }

    protected function payToBank(Game $game, Turn $turn, Player $from, int $amount): Transaction
    {
        $tx = $game->transactions()->create([
            'turn_id' => $turn->id,
            'status' => 'completed',
        ]);

        $tx->items()->create([
            'game_id' => $game->id,
            'type' => 'cash',
            'item_id' => 0,
            'amount' => $amount,
            'from_player_id' => $from->id,
            'to_player_id' => null,
        ]);

        return $tx;
    }

    protected function transferBetweenPlayers(Game $game, Turn $turn, Player $from, Player $to, int $amount): Transaction
    {
        $tx = $game->transactions()->create([
            'turn_id' => $turn->id,
            'status' => 'completed',
        ]);

        $tx->items()->create([
            'game_id' => $game->id,
            'type' => 'cash',
            'item_id' => 0,
            'amount' => $amount,
            'from_player_id' => $from->id,
            'to_player_id' => $to->id,
        ]);

        return $tx;
    }

    protected function spaceSummary(array|Property $space): array
    {
        if ($space instanceof Property) {
            return [
                'type' => 'Property',
                'id' => $space->id,
                'title' => $space->title,
                'color' => $space->color,
                'price' => $space->price,
            ];
        }

        return $space;
    }

    public function purchaseProperty(Game $game, Player $player, Property $property): array
    {
        return DB::transaction(function () use ($game, $player, $property) {
            // Validate ownership and affordability
            if ($property->item) {
                throw new \RuntimeException('Property already owned');
            }
            if ($player->cash < (int) $property->price) {
                throw new \RuntimeException('Insufficient funds');
            }

            // Create a synthetic turn if none active? For now, attach to latest or create a new quick turn
            $turn = $game->turns()->create([
                'player_id' => $player->id,
                'status' => 'completed',
            ]);

            // Pay bank
            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'completed',
            ]);

            $tx->items()->create([
                'game_id' => $game->id,
                'type' => 'cash',
                'item_id' => 0,
                'amount' => (int) $property->price,
                'from_player_id' => $player->id,
                'to_player_id' => null,
            ]);

            // Transfer property from bank to player
            $tx->items()->create([
                'game_id' => $game->id,
                'type' => 'property',
                'item_id' => $property->id,
                'amount' => 0,
                'from_player_id' => null,
                'to_player_id' => $player->id,
            ]);

            // Persist ownership
            $property->item()->create([
                'player_id' => $player->id,
            ]);

            // Update balances
            $player->decrement('cash', (int) $property->price);

            return [
                'transaction_id' => $tx->id,
                'property_id' => $property->id,
                'player_id' => $player->id,
            ];
        });
    }

    public function executeTrade(
        Game $game,
        Player $from,
        Player $to,
        int $cashAmount = 0,
        ?Property $property = null
    ): array {
        return DB::transaction(function () use ($game, $from, $to, $cashAmount, $property) {
            if ($from->id === $to->id) {
                throw new \RuntimeException('Cannot trade with yourself');
            }
            if ($cashAmount < 0) {
                throw new \RuntimeException('Cash amount must be non-negative');
            }
            if ($cashAmount > 0 && $from->cash < $cashAmount) {
                throw new \RuntimeException('Insufficient funds');
            }

            if ($property) {
                // Ensure the property belongs to the same board/game and is owned by the from player
                if (!$game->board->properties->contains('id', $property->id)) {
                    throw new \RuntimeException('Property not part of this game');
                }
                if (!$property->item || $property->item->player_id !== $from->id) {
                    throw new \RuntimeException('You do not own this property');
                }
            }

            // Attach the trade to a concise, completed turn for traceability
            $turn = $game->turns()->create([
                'player_id' => $from->id,
                'status' => 'completed',
            ]);

            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'completed',
            ]);

            if ($cashAmount > 0) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => 'cash',
                    'item_id' => 0,
                    'amount' => $cashAmount,
                    'from_player_id' => $from->id,
                    'to_player_id' => $to->id,
                ]);

                $from->decrement('cash', $cashAmount);
                $to->increment('cash', $cashAmount);
            }

            if ($property) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => 'property',
                    'item_id' => $property->id,
                    'amount' => 0,
                    'from_player_id' => $from->id,
                    'to_player_id' => $to->id,
                ]);

                // Transfer ownership of the property
                $property->item->update(['player_id' => $to->id]);
            }

            return [
                'transaction_id' => $tx->id,
                'from_player_id' => $from->id,
                'to_player_id' => $to->id,
                'cash' => $cashAmount,
                'property_id' => $property?->id,
            ];
        });
    }

    /**
     * Create a pending trade request between two players. This records the intended
     * cash/property movements but does not change balances or ownership until approved.
     */
    public function createTradeRequest(
        Game $game,
        Player $from,
        Player $to,
        int $cashAmount = 0,
        ?Property $property = null
    ): array {
        return DB::transaction(function () use ($game, $from, $to, $cashAmount, $property) {
            if ($from->id === $to->id) {
                throw new \RuntimeException('Cannot trade with yourself');
            }
            if ($cashAmount < 0) {
                throw new \RuntimeException('Cash amount must be non-negative');
            }

            if ($property) {
                if (!$game->board->properties->contains('id', $property->id)) {
                    throw new \RuntimeException('Property not part of this game');
                }
                if (!$property->item || $property->item->player_id !== $from->id) {
                    throw new \RuntimeException('You do not own this property');
                }
            }

            // Synthetic turn for traceability; ignored by current player logic
            $turn = $game->turns()->create([
                'player_id' => $from->id,
                'status' => 'completed',
            ]);

            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'pending',
            ]);

            if ($cashAmount > 0) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => 'cash',
                    'item_id' => 0,
                    'amount' => $cashAmount,
                    'from_player_id' => $from->id,
                    'to_player_id' => $to->id,
                ]);
            }

            if ($property) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => 'property',
                    'item_id' => $property->id,
                    'amount' => 0,
                    'from_player_id' => $from->id,
                    'to_player_id' => $to->id,
                ]);
            }

            return [
                'transaction_id' => $tx->id,
                'from_player_id' => $from->id,
                'to_player_id' => $to->id,
                'cash' => $cashAmount,
                'property_id' => $property?->id,
                'status' => 'pending',
            ];
        });
    }

    /**
     * Approve a pending trade. Only the recipient can approve, and only on their turn.
     */
    public function approveTrade(Game $game, int $transactionId, Player $approver): array
    {
        return DB::transaction(function () use ($game, $transactionId, $approver) {
            $tx = $game->transactions()->with('items')->where('id', $transactionId)->firstOrFail();
            if ($tx->status !== 'pending') {
                throw new \RuntimeException('Trade is not pending');
            }

            // Must be approver's turn
            if (!$game->current_player || $game->current_player->id !== $approver->id) {
                throw new \RuntimeException('Only the current player can approve a trade');
            }

            // Validate approver is the recipient on at least one item
            $isRecipient = collect($tx->items)->contains(fn ($i) => (int) ($i->to_player_id ?? 0) === $approver->id);
            if (!$isRecipient) {
                throw new \RuntimeException('You are not the recipient of this trade');
            }

            // Execute each item
            foreach ($tx->items as $item) {
                if ($item->type === 'cash') {
                    if ($item->amount <= 0) {
                        continue;
                    }
                    $from = $game->players->firstWhere('id', (int) $item->from_player_id);
                    $to = $game->players->firstWhere('id', (int) $item->to_player_id);
                    if (!$from || !$to) {
                        throw new \RuntimeException('Invalid players on trade');
                    }
                    if ((int) $from->cash < (int) $item->amount) {
                        throw new \RuntimeException('Insufficient funds to approve trade');
                    }
                    $from->decrement('cash', (int) $item->amount);
                    $to->increment('cash', (int) $item->amount);
                } elseif ($item->type === 'property') {
                    $property = Property::query()->findOrFail((int) $item->item_id);
                    // Ensure ownership has not changed
                    if (!$property->item || (int) $property->item->player_id !== (int) $item->from_player_id) {
                        throw new \RuntimeException('Property no longer owned by the expected player');
                    }
                    $property->item->update(['player_id' => (int) $item->to_player_id]);
                }
            }

            $tx->update(['status' => 'completed']);

            return [
                'transaction_id' => $tx->id,
                'status' => 'completed',
            ];
        });
    }

    /**
     * Reject or cancel a pending trade.
     */
    public function rejectTrade(Game $game, int $transactionId, Player $actor): array
    {
        return DB::transaction(function () use ($game, $transactionId, $actor) {
            $tx = $game->transactions()->with('items')->where('id', $transactionId)->firstOrFail();
            if ($tx->status !== 'pending') {
                throw new \RuntimeException('Trade is not pending');
            }

            // Only a participant may reject
            $isParticipant = collect($tx->items)->contains(function ($i) use ($actor) {
                return (int) ($i->from_player_id ?? 0) === $actor->id || (int) ($i->to_player_id ?? 0) === $actor->id;
            });
            if (!$isParticipant) {
                throw new \RuntimeException('You are not part of this trade');
            }

            $tx->update(['status' => 'rejected']);

            return [
                'transaction_id' => $tx->id,
                'status' => 'rejected',
            ];
        });
    }
}


