<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Property;
use App\Models\Card;
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

            // Special handling: player is in The Joint
            if ($player->in_joint) {
                // Doubles => leave joint and move; otherwise increment attempts or on 3rd attempt, pay to leave and move
                if ($isDouble) {
                    // Leave joint
                    $player->in_joint = false;
                    $player->joint_attempts = 0;
                    $player->save();
                    $actions[] = ['type' => 'left_joint', 'via' => 'doubles'];

                    // Move by rolled amount
                    $this->movePlayerRelative($game, $turn, $player, $total);
                    $space = $this->spaceAt($game, (int) $player->position);
                    $resolution = $this->resolveSpace($game, $turn, $player, $space);
                    $transactions = array_merge($transactions, $resolution['transactions']);

                    // Always end turn after leaving joint, regardless of doubles
                    $turn->update(['status' => 'completed']);
                    return [
                        'dice' => [$dieOne, $dieTwo],
                        'total' => $total,
                        'is_double' => false,
                        'passed_go' => false,
                        'position' => (int) $player->position,
                        'space' => $this->spaceSummary($space),
                        'actions' => array_merge($actions, $resolution['actions']),
                        'transactions' => $transactions,
                    ];
                }

                // Not doubles
                if ((int) $player->joint_attempts < 2) {
                    $player->increment('joint_attempts');
                    $actions[] = ['type' => 'joint_failed_attempt', 'attempts' => (int) $player->joint_attempts];
                    $turn->update(['status' => 'completed']);
                    $space = $this->spaceAt($game, (int) $player->position);
                    return [
                        'dice' => [$dieOne, $dieTwo],
                        'total' => $total,
                        'is_double' => false,
                        'passed_go' => false,
                        'position' => (int) $player->position,
                        'space' => $this->spaceSummary($space),
                        'actions' => $actions,
                        'transactions' => $transactions,
                    ];
                }

                // Third failed attempt: pay up to $50, leave, move
                $amountToPay = min(50, (int) $player->cash);
                if ($amountToPay > 0) {
                    $transactions[] = $this->payToBank($game, $turn, $player, $amountToPay);
                    $player->decrement('cash', $amountToPay);
                }
                $player->in_joint = false;
                $player->joint_attempts = 0;
                $player->save();
                $actions[] = ['type' => 'left_joint', 'via' => 'paid', 'amount' => $amountToPay];

                $this->movePlayerRelative($game, $turn, $player, $total);
                $space = $this->spaceAt($game, (int) $player->position);
                $resolution = $this->resolveSpace($game, $turn, $player, $space);
                $transactions = array_merge($transactions, $resolution['transactions']);

                $turn->update(['status' => 'completed']);
                return [
                    'dice' => [$dieOne, $dieTwo],
                    'total' => $total,
                    'is_double' => false,
                    'passed_go' => false,
                    'position' => (int) $player->position,
                    'space' => $this->spaceSummary($space),
                    'actions' => array_merge($actions, $resolution['actions']),
                    'transactions' => $transactions,
                ];
            }

            // Handle third consecutive double: go directly to The Joint (position 10), no movement and end turn
            if ($isDouble && $existingDoublesCount >= 2) {
                $player->position = 10;
                $player->in_joint = true;
                $player->joint_attempts = 0;
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
            $sentToJoint = collect($resolution['actions'])->contains(function ($a) {
                return ($a['type'] ?? null) === 'sent_to_joint';
            });

            // Set status rules:
            // - Third double already handled above and completed
            // - If blocking decision, await decision
            // - Else if doubles, keep in_progress to allow another roll
            // - Else, complete the turn immediately
            if ($hasBlockingDecision) {
                $turn->update(['status' => 'awaiting_decision']);
            } elseif ($sentToJoint) {
                $turn->update(['status' => 'completed']);
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

    /**
     * Manually pay $50 to leave The Joint before the third attempt.
     * Does not move the player; they should roll normally afterward.
     */
    public function payToLeaveJoint(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            if (!$player->in_joint) {
                throw new \RuntimeException('Player is not in The Joint');
            }
            if ((int) $player->cash < 50) {
                throw new \RuntimeException('Insufficient funds to leave The Joint');
            }

            // Synthetic completed turn for traceability
            $turn = $game->turns()->create([
                'player_id' => $player->id,
                'status' => 'completed',
            ]);

            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'completed',
            ]);

            $tx->items()->create([
                'game_id' => $game->id,
                'type' => 'cash',
                'item_id' => 0,
                'amount' => 50,
                'from_player_id' => $player->id,
                'to_player_id' => null,
            ]);

            $player->decrement('cash', 50);
            $player->in_joint = false;
            $player->joint_attempts = 0;
            $player->save();

            return [
                'transaction_id' => $tx->id,
                'left_joint' => true,
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
                        $player->in_joint = true;
                        $player->joint_attempts = 0;
                        $player->save();
                        $actions[] = ['type' => 'sent_to_joint'];
                    }
                    break;

                case 'Do':
                    $actions[] = ['type' => 'noop', 'detail' => $arg];
                    break;

                case 'Draw':
                    [$cardTx, $cardActions] = $this->drawAndResolveCard($game, $turn, $player, (string) $arg);
                    $transactions = array_merge($transactions, $cardTx);
                    $actions = array_merge($actions, $cardActions);
                    break;
            }

            return compact('transactions', 'actions');
        }

        if ($space instanceof Property) {
            $owner = optional($space->item)->player; // Player or null

            if ($owner && $owner->id !== $player->id) {
                $rent = $this->calculateRent($game, $turn, $owner, $space);
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

    /**
     * Draw a card from a given deck type (e.g., "Vault" or "Fate") and apply its effects.
     * Returns [transactions[], actions[]].
     */
    protected function drawAndResolveCard(Game $game, Turn $turn, Player $player, string $deckType): array
    {
        $transactions = [];
        $actions = [];

        /** @var Card|null $card */
        $card = Card::query()
            ->where('board_id', $game->board->id)
            ->whereRaw('LOWER(type) = ?', [strtolower($deckType)])
            ->inRandomOrder()
            ->first();

        if (!$card) {
            $actions[] = ['type' => 'card_drawn_none', 'deck' => $deckType];
            return [$transactions, $actions];
        }

        $actions[] = [
            'type' => 'card_drawn',
            'deck' => $deckType,
            'card_id' => $card->id,
            'message' => $card->message,
        ];

        $normalized = $this->normalizeCardEffects($card->effect);
        // If effect missing or is a noop, try to infer from message
        if (count($normalized) === 1 && ($normalized[0]['verb'] ?? '') === 'Do') {
            $normalized = $this->inferEffectsFromMessage($card);
        }

        foreach ($normalized as $effect) {
            $verb = $effect['verb'];
            $arg = $effect['arg'];

            switch ($verb) {
                case 'Collect': {
                    $amount = (int) $arg;
                    $transactions[] = $this->collectFromBank($game, $turn, $player, $amount);
                    $player->increment('cash', $amount);
                    $actions[] = ['type' => 'collect', 'amount' => $amount, 'source' => 'card'];
                    break;
                }
                case 'Pay': {
                    $amount = (int) $arg;
                    $transactions[] = $this->payToBank($game, $turn, $player, $amount);
                    $player->decrement('cash', $amount);
                    $actions[] = ['type' => 'pay', 'amount' => $amount, 'source' => 'card'];
                    break;
                }
                case 'MoveTo': {
                    $to = (int) $arg;
                    $passedGo = $this->movePlayerTo($game, $turn, $player, $to, true);
                    $actions[] = ['type' => 'move', 'to' => $to, 'source' => 'card', 'passed_go' => $passedGo];
                    [$tx2, $act2] = $this->resolvePropertyLanding($game, $turn, $player);
                    $transactions = array_merge($transactions, $tx2);
                    $actions = array_merge($actions, $act2);
                    break;
                }
                case 'MoveRelative': {
                    $delta = (int) $arg;
                    $passedGo = $this->movePlayerRelative($game, $turn, $player, $delta);
                    $actions[] = ['type' => 'move', 'by' => $delta, 'source' => 'card', 'passed_go' => $passedGo];
                    [$tx2, $act2] = $this->resolvePropertyLanding($game, $turn, $player);
                    $transactions = array_merge($transactions, $tx2);
                    $actions = array_merge($actions, $act2);
                    break;
                }
                case 'GoToJoint': {
                    // Directly to The Joint (jail) without GO bonus
                    $player->position = 10;
                    $player->in_joint = true;
                    $player->joint_attempts = 0;
                    $player->save();
                    $actions[] = ['type' => 'sent_to_joint', 'source' => 'card'];
                    break;
                }
                case 'Keep': {
                    // Keepable card (e.g., Get Out of Joint). Attach the card to the player as an asset.
                    if (!$card->item) {
                        $card->item()->create(['player_id' => $player->id]);
                    } else {
                        $card->item->update(['player_id' => $player->id]);
                    }
                    $actions[] = ['type' => 'card_kept', 'card_id' => $card->id];
                    break;
                }
                case 'PayEachPlayer': {
                    $amount = (int) $arg;
                    foreach ($game->players as $other) {
                        if ($other->id === $player->id) { continue; }
                        $transactions[] = $this->transferBetweenPlayers($game, $turn, $player, $other, $amount);
                        $player->decrement('cash', $amount);
                        $other->increment('cash', $amount);
                    }
                    $actions[] = ['type' => 'pay_each_player', 'amount' => $amount, 'source' => 'card'];
                    break;
                }
                case 'CollectFromEachPlayer': {
                    $amount = (int) $arg;
                    foreach ($game->players as $other) {
                        if ($other->id === $player->id) { continue; }
                        $transactions[] = $this->transferBetweenPlayers($game, $turn, $other, $player, $amount);
                        $other->decrement('cash', $amount);
                        $player->increment('cash', $amount);
                    }
                    $actions[] = ['type' => 'collect_from_each_player', 'amount' => $amount, 'source' => 'card'];
                    break;
                }
                default:
                    $actions[] = ['type' => 'noop', 'detail' => $verb];
            }
        }

        return [$transactions, $actions];
    }

    /**
     * Normalize card effects into a list of ['verb' => string, 'arg' => mixed].
     * Accepts string like "Collect::200"; associative arrays like ['Collect' => 200];
     * objects like ['verb' => 'Collect', 'arg' => 200]; or arrays of such entries.
     */
    protected function normalizeCardEffects($rawEffect): array
    {
        if ($rawEffect === null || $rawEffect === '') {
            return [['verb' => 'Do', 'arg' => 'nothing']];
        }

        // If effect is already a list, normalize each item
        if (is_array($rawEffect) && array_is_list($rawEffect)) {
            $out = [];
            foreach ($rawEffect as $e) {
                $n = $this->normalizeCardEffects($e);
                foreach ($n as $item) { $out[] = $item; }
            }
            return $out;
        }

        // Single associative array or scalar/string
        if (is_array($rawEffect)) {
            // ['verb' => 'Collect', 'arg' => 200]
            if (isset($rawEffect['verb'])) {
                return [[
                    'verb' => (string) $rawEffect['verb'],
                    'arg' => $rawEffect['arg'] ?? null,
                ]];
            }
            // ['Collect' => 200]
            if (count($rawEffect) === 1) {
                $verb = (string) array_key_first($rawEffect);
                $arg = $rawEffect[$verb];
                return [[
                    'verb' => $verb,
                    'arg' => $arg,
                ]];
            }
        }

        // Strings like "Collect::200"
        if (is_string($rawEffect)) {
            [$verb, $arg] = array_pad(explode('::', $rawEffect, 2), 2, null);
            return [[
                'verb' => (string) $verb,
                'arg' => $arg,
            ]];
        }

        return [['verb' => 'Do', 'arg' => 'nothing']];
    }

    /**
     * Best-effort inference of card effect(s) from message text when effect is not stored.
     */
    protected function inferEffectsFromMessage(Card $card): array
    {
        $msg = strtolower((string) $card->message);
        $effects = [];

        // Simple amount extraction
        $amount = null;
        if (preg_match('/\$(\s*)?(\d{1,5})/', $msg, $m)) {
            $amount = (int) ($m[2] ?? 0);
        }

        if (str_contains($msg, 'get out of jail')) {
            $effects[] = ['verb' => 'Keep'];
            return $effects;
        }
        if (str_contains($msg, 'go back 3')) {
            $effects[] = ['verb' => 'MoveRelative', 'arg' => -3];
            return $effects;
        }
        if (str_contains($msg, 'go to jail')) {
            $effects[] = ['verb' => 'GoToJoint'];
            return $effects;
        }
        if (str_contains($msg, 'advance to go')) {
            $effects[] = ['verb' => 'MoveTo', 'arg' => 0];
            if ($amount && str_contains($msg, 'collect')) {
                $effects[] = ['verb' => 'Collect', 'arg' => $amount];
            } else {
                $effects[] = ['verb' => 'Collect', 'arg' => self::GO_BONUS];
            }
            return $effects;
        }

        if ($amount !== null) {
            if (str_contains($msg, 'collect') || str_contains($msg, 'receive') || str_contains($msg, 'bank pays')) {
                $effects[] = ['verb' => 'Collect', 'arg' => $amount];
                return $effects;
            }
            if (str_contains($msg, 'pay each player')) {
                $effects[] = ['verb' => 'PayEachPlayer', 'arg' => $amount];
                return $effects;
            }
            if (str_contains($msg, 'collect') && str_contains($msg, 'every player')) {
                $effects[] = ['verb' => 'CollectFromEachPlayer', 'arg' => $amount];
                return $effects;
            }
            if (str_contains($msg, 'pay')) {
                $effects[] = ['verb' => 'Pay', 'arg' => $amount];
                return $effects;
            }
        }

        // Unhandled complex cases (nearest railroad/utility, repairs per house) => noop
        return [['verb' => 'Do', 'arg' => 'nothing']];
    }

    /**
     * Move player to an absolute board position, optionally awarding GO bonus if passed.
     * Returns whether GO was passed and bonus awarded.
     */
    protected function movePlayerTo(Game $game, Turn $turn, Player $player, int $newPosition, bool $awardGoIfPassed = true): bool
    {
        $previous = (int) ($player->position ?? 0);
        $passedGo = $awardGoIfPassed && $newPosition < $previous;
        if ($passedGo) {
            $this->collectFromBank($game, $turn, $player, self::GO_BONUS);
            $player->increment('cash', self::GO_BONUS);
        }
        $player->position = $newPosition % self::BOARD_SIZE;
        $player->save();
        return $passedGo;
    }

    /**
     * Move player by a relative delta; awards GO if crossing index 0.
     */
    protected function movePlayerRelative(Game $game, Turn $turn, Player $player, int $delta): bool
    {
        $previous = (int) ($player->position ?? 0);
        $raw = $previous + $delta;
        $wrapped = (($raw % self::BOARD_SIZE) + self::BOARD_SIZE) % self::BOARD_SIZE; // handle negatives
        $passedGo = $delta > 0 && $raw >= self::BOARD_SIZE;
        if ($passedGo) {
            $this->collectFromBank($game, $turn, $player, self::GO_BONUS);
            $player->increment('cash', self::GO_BONUS);
        }
        $player->position = $wrapped;
        $player->save();
        return $passedGo;
    }

    /**
     * After a card-induced move, resolve only property landings (rent or purchase offer).
     * Skips action spaces to prevent chained draws.
     */
    protected function resolvePropertyLanding(Game $game, Turn $turn, Player $player): array
    {
        $space = $this->spaceAt($game, (int) $player->position);
        $transactions = [];
        $actions = [];
        if ($space instanceof Property) {
            $owner = optional($space->item)->player; // Player or null
            if ($owner && $owner->id !== $player->id) {
                $rent = $this->calculateRent($game, $turn, $owner, $space);
                $transactions[] = $this->transferBetweenPlayers($game, $turn, $player, $owner, $rent);
                $player->decrement('cash', $rent);
                $owner->increment('cash', $rent);
                $actions[] = ['type' => 'rent_paid', 'to' => $owner->id, 'amount' => $rent, 'source' => 'card'];
            } elseif (!$owner) {
                $actions[] = [
                    'type' => 'offer_purchase',
                    'property_id' => $space->id,
                    'price' => $space->price,
                    'source' => 'card',
                ];
            } else {
                $actions[] = ['type' => 'landed_own_property', 'property_id' => $space->id, 'source' => 'card'];
            }
        }
        return [$transactions, $actions];
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

    /**
     * Calculate rent for a property, including railroad logic.
     */
     protected function calculateRent(Game $game, Turn $turn, Player $owner, Property $property): int
    {
        // Utilities: rent is 4x dice total if 1 owned, 10x if both owned
        if (method_exists($property, 'isUtility') && $property->isUtility()) {
            $lastRoll = $turn->rolls()->orderByDesc('id')->first();
            $diceTotal = (int) optional($lastRoll)->total;
            if ($diceTotal <= 0) {
                // Fallback to base rent if no roll found (e.g., card move)
                return (int) ($property->rent ?? 0);
            }

            $ownedUtilitiesCount = $game->board->properties
                ->filter(fn (Property $p) => method_exists($p, 'isUtility') && $p->isUtility())
                ->filter(fn (Property $p) => $p->item && (int) $p->item->player_id === (int) $owner->id)
                ->count();

            $multiplier = $ownedUtilitiesCount >= 2 ? 10 : 4;
            return $diceTotal * $multiplier;
        }

        // Railroads: rent doubles per additional railroad the owner holds on this board.
        if (method_exists($property, 'isRailroad') && $property->isRailroad()) {
            $ownedRailroadsCount = $game->board->properties
                ->filter(fn (Property $p) => method_exists($p, 'isRailroad') && $p->isRailroad())
                ->filter(fn (Property $p) => $p->item && (int) $p->item->player_id === (int) $owner->id)
                ->count();

            $base = (int) ($property->rent ?? 25);
            $multiplier = max(1, $ownedRailroadsCount);
            return (int) ($base * (2 ** ($multiplier - 1)));
        }

        // Normal properties: use units and monopoly rules
        $units = (int) optional($property->item)->units;
        if ($units >= 5) {
            return (int) ($property->rent_five_unit ?? 0);
        }
        if ($units === 4) {
            return (int) ($property->rent_four_unit ?? 0);
        }
        if ($units === 3) {
            return (int) ($property->rent_three_unit ?? 0);
        }
        if ($units === 2) {
            return (int) ($property->rent_two_unit ?? 0);
        }
        if ($units === 1) {
            return (int) ($property->rent_one_unit ?? 0);
        }

        // No units: if owner owns full color set, apply color-set rent
        if (method_exists($property, 'ownerOwnsFullColorSet') && $property->ownerOwnsFullColorSet()) {
            return (int) ($property->rent_color_set ?? ($property->rent ?? 0));
        }

        // Default base rent
        return (int) ($property->rent ?? 0);
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
                'units' => 0,
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

    /**
     * Buy one unit (house/hotel) on a property. Requires full color set ownership and max 5 units.
     */
    public function buyUnit(Game $game, Player $player, Property $property): array
    {
        return DB::transaction(function () use ($game, $player, $property) {
            // Only current player may buy units
            if (!$game->current_player || (int) $game->current_player->id !== (int) $player->id) {
                throw new \RuntimeException('You can only buy units on your turn');
            }
            // Ownership checks
            if (!$property->item || (int) $property->item->player_id !== (int) $player->id) {
                throw new \RuntimeException('You do not own this property');
            }
            if (method_exists($property, 'isRailroad') && $property->isRailroad()) {
                throw new \RuntimeException('Cannot buy units on railroads');
            }
            if (method_exists($property, 'isUtility') && $property->isUtility()) {
                throw new \RuntimeException('Cannot buy units on utilities');
            }
            if (!method_exists($property, 'ownerOwnsFullColorSet') || !$property->ownerOwnsFullColorSet()) {
                throw new \RuntimeException('Must own all properties of this color to buy units');
            }

            $currentUnits = (int) ($property->item->units ?? 0);
            if ($currentUnits >= 5) {
                throw new \RuntimeException('Maximum units reached on this property');
            }

            // Enforce even building: must build on a property with the fewest units in the color set
            $ownedColorSet = Property::query()
                ->where('board_id', $property->board_id)
                ->whereRaw('LOWER(color) = ?', [strtolower((string) $property->color)])
                ->whereHas('item', fn($q) => $q->where('player_id', $player->id))
                ->with('item')
                ->get()
                // Exclude non-buildables (railroads/utilities)
                ->filter(fn(Property $p) => !(method_exists($p, 'isRailroad') && $p->isRailroad()) && !(method_exists($p, 'isUtility') && $p->isUtility()));
            if ($ownedColorSet->isEmpty()) {
                throw new \RuntimeException('No buildable properties in this color set');
            }
            $minUnits = (int) $ownedColorSet->map(fn(Property $p) => (int) optional($p->item)->units)->min();
            if ($currentUnits > $minUnits) {
                throw new \RuntimeException('You must build evenly across the color set');
            }

            $price = (int) ($property->unit_price ?? 0);
            if ((int) $player->cash < $price) {
                throw new \RuntimeException('Insufficient funds');
            }

            // Synthetic completed turn for audit
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
                'amount' => $price,
                'from_player_id' => $player->id,
                'to_player_id' => null,
            ]);

            $player->decrement('cash', $price);
            $property->item->update(['units' => $currentUnits + 1]);

            return [
                'transaction_id' => $tx->id,
                'property_id' => $property->id,
                'units' => $currentUnits + 1,
            ];
        });
    }

    /**
     * Sell one unit from a property back to the bank at half price.
     */
    public function sellUnit(Game $game, Player $player, Property $property): array
    {
        return DB::transaction(function () use ($game, $player, $property) {
            // Only current player may sell units
            if (!$game->current_player || (int) $game->current_player->id !== (int) $player->id) {
                throw new \RuntimeException('You can only sell units on your turn');
            }
            if (!$property->item || (int) $property->item->player_id !== (int) $player->id) {
                throw new \RuntimeException('You do not own this property');
            }
            if ((int) ($property->item->units ?? 0) <= 0) {
                throw new \RuntimeException('No units to sell');
            }

            // Enforce even selling: must sell from a property with the most units in the color set
            $ownedColorSet = Property::query()
                ->where('board_id', $property->board_id)
                ->whereRaw('LOWER(color) = ?', [strtolower((string) $property->color)])
                ->whereHas('item', fn($q) => $q->where('player_id', $player->id))
                ->with('item')
                ->get()
                ->filter(fn(Property $p) => !(method_exists($p, 'isRailroad') && $p->isRailroad()) && !(method_exists($p, 'isUtility') && $p->isUtility()));
            if ($ownedColorSet->isEmpty()) {
                throw new \RuntimeException('No buildable properties in this color set');
            }
            $maxUnits = (int) $ownedColorSet->map(fn(Property $p) => (int) optional($p->item)->units)->max();
            if ((int) $property->item->units < $maxUnits) {
                throw new \RuntimeException('You must sell evenly across the color set');
            }

            $refund = (int) floor(((int) ($property->unit_price ?? 0)) / 2);

            // Synthetic completed turn for audit
            $turn = $game->turns()->create([
                'player_id' => $player->id,
                'status' => 'completed',
            ]);

            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'completed',
            ]);
            $tx->items()->create([
                'game_id' => $game->id,
                'type' => 'cash',
                'item_id' => 0,
                'amount' => $refund,
                'from_player_id' => null,
                'to_player_id' => $player->id,
            ]);

            $player->increment('cash', $refund);
            $property->item->decrement('units');

            return [
                'transaction_id' => $tx->id,
                'property_id' => $property->id,
                'units' => (int) $property->item->units,
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


