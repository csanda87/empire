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
            $turn = $game->turns()->create(['player_id' => $player->id, 'status' => 'in_progress']);

            $dieOne = rand(1, 6);
            $dieTwo = rand(1, 6);
            $total = $dieOne + $dieTwo;
            $isDouble = $dieOne === $dieTwo;

            $turn->rolls()->create([
                'dice' => [$dieOne, $dieTwo],
                'is_double' => $isDouble,
                'total' => $total,
            ]);

            $previousPosition = $player->position ?? 0;
            $newPosition = $previousPosition + $total;
            $passedGo = $newPosition >= self::BOARD_SIZE;
            $newPosition = $newPosition % self::BOARD_SIZE;

            // Award GO bonus when passing or landing on GO (space 0) from any space other than 0
            $landingOnGo = ($newPosition === 0 && $previousPosition !== 0);
            $shouldAwardGoBonus = $passedGo || $landingOnGo;

            $transactions = [];
            if ($shouldAwardGoBonus) {
                $transactions[] = $this->collectFromBank($game, $turn, $player, self::GO_BONUS);
                $player->cash += self::GO_BONUS;
            }

            $player->position = $newPosition;
            $player->save();

            $space = $this->spaceAt($game, $newPosition);
            $resolution = $this->resolveSpace($game, $turn, $player, $space);
            $transactions = array_merge($transactions, $resolution['transactions']);

            $turn->update(['status' => 'completed']);

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
}


