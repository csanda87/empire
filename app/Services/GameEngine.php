<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Property;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\Turn;
use Illuminate\Support\Facades\DB;
use App\Models\PlayerAsset;

class GameEngine
{
    public const BOARD_SIZE = 40;
    public const GO_BONUS = 200;

    public function rollAndAdvance(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            if (($game->status ?? '') === 'completed') {
                throw new \RuntimeException('Game is completed');
            }
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

            // Determine if there is a blocking decision (e.g., offer to purchase or payment required)
            $actionsList = $resolution['actions'];
            $hasBlockingDecision = collect($actionsList)->contains(function ($a) {
                $t = ($a['type'] ?? null);
                return $t === 'offer_purchase' || $t === 'payment_required';
            });

            // Belt-and-suspenders: if player currently stands on an unowned property and
            // no explicit offer was added (e.g., card text mapping edge cases), add an offer
            // and treat it as blocking.
            $currentSpace = $this->spaceAt($game, (int) $player->position);
            if (!$hasBlockingDecision && $currentSpace instanceof Property && !$this->assetForGame($game, $currentSpace)) {
                $alreadyOffered = collect($actionsList)->contains(function ($a) use ($currentSpace) {
                    return ($a['type'] ?? null) === 'offer_purchase'
                        && (int) ($a['property_id'] ?? 0) === (int) $currentSpace->id;
                });
                if (!$alreadyOffered) {
                    $actionsList[] = [
                        'type' => 'offer_purchase',
                        'property_id' => $currentSpace->id,
                        'price' => $currentSpace->price,
                        'source' => 'card_or_roll',
                    ];
                }
                $hasBlockingDecision = true;
            }
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
                'actions' => $actionsList,
                'transactions' => $transactions,
            ];
        });
    }

    /**
     * Return the PlayerAsset for this property restricted to the given game's players.
     */
    protected function assetForGame(Game $game, Property $property): ?PlayerAsset
    {
        $playerIds = $game->players->pluck('id')->values();
        if ($playerIds->isEmpty()) {
            return null;
        }
        if ($property->relationLoaded('item')) {
            $loaded = $property->getRelation('item');
            if ($loaded && $playerIds->contains((int) $loaded->player_id)) {
                return $loaded;
            }
        }
        return $property->item()->whereIn('player_id', $playerIds)->first();
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
            if (($game->status ?? '') === 'completed') {
                throw new \RuntimeException('Game is completed');
            }
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
        $flatSpaces = collect($game->board->getSpaces($game))->flatten(1)->values();
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
            $owner = optional($this->assetForGame($game, $space))->player; // Player or null

            if ($owner && $owner->id !== $player->id) {
                $rent = $this->calculateRent($game, $turn, $owner, $space);
                $charge = $this->attemptCharge($game, $turn, $player, $owner, $rent, 'rent');
                $transactions = array_merge($transactions, $charge['transactions']);
                $actions = array_merge($actions, $charge['actions']);
                if ($charge['paid']) {
                    $actions[] = ['type' => 'rent_paid', 'to' => $owner->id, 'amount' => $rent];
                }
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
            $normalized = $this->inferEffectsFromMessage($game, $card);
        }

        foreach ($normalized as $effect) {
            $verb = (string) ($effect['verb'] ?? 'Do');
            $arg = $effect['arg'] ?? null;

            switch ($verb) {
                case 'Collect': {
                    $amount = (int) $arg;
                    if ($player->in_joint) {
                        // Cannot collect while in The Joint
                        $actions[] = ['type' => 'noop', 'detail' => 'collect_blocked_in_joint', 'amount' => $amount, 'source' => 'card'];
                        break;
                    }
                    $transactions[] = $this->collectFromBank($game, $turn, $player, $amount);
                    $player->increment('cash', $amount);
                    $actions[] = ['type' => 'collect', 'amount' => $amount, 'source' => 'card'];
                    break;
                }
                case 'Pay': {
                    $amount = (int) $arg;
                    $charge = $this->attemptCharge($game, $turn, $player, null, $amount, 'bank');
                    $transactions = array_merge($transactions, $charge['transactions']);
                    $actions = array_merge($actions, $charge['actions']);
                    if ($charge['paid']) {
                        $actions[] = ['type' => 'pay', 'amount' => $amount, 'source' => 'card'];
                    }
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
                        $charge = $this->attemptCharge($game, $turn, $player, $other, $amount, 'card_pay_each');
                        $transactions = array_merge($transactions, $charge['transactions']);
                        $actions = array_merge($actions, $charge['actions']);
                        if (!$charge['paid'] && ($player->is_bankrupt ?? false)) {
                            break;
                        }
                    }
                    if (($player->is_bankrupt ?? false) === false) {
                        $actions[] = ['type' => 'pay_each_player', 'amount' => $amount, 'source' => 'card'];
                    }
                    break;
                }
                case 'CollectFromEachPlayer': {
                    $amount = (int) $arg;
                    if ($player->in_joint) {
                        // Cannot collect while in The Joint
                        $actions[] = ['type' => 'noop', 'detail' => 'collect_each_blocked_in_joint', 'amount' => $amount, 'source' => 'card'];
                        break;
                    }
                    foreach ($game->players as $other) {
                        if ($other->id === $player->id) { continue; }
                        $charge = $this->attemptCharge($game, $turn, $other, $player, $amount, 'card_collect_from_each');
                        $transactions = array_merge($transactions, $charge['transactions']);
                        $actions = array_merge($actions, $charge['actions']);
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
    protected function inferEffectsFromMessage(Game $game, Card $card): array
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

        // Advance to a named property (e.g., "Advance to St. Charles Place")
        if (preg_match('/advance\s+to\s+([^\.]+)(\.|$)/i', (string) $card->message, $m)) {
            $rawTitle = trim($m[1] ?? '');
            $pos = $game->board->positionOfPropertyTitle($game, $rawTitle);
            if ($pos !== null) {
                $effects[] = ['verb' => 'MoveTo', 'arg' => $pos];
                return $effects;
            }
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

    // Removed local finder; now delegated to Board::positionOfPropertyTitle

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
            $owner = optional($this->assetForGame($game, $space))->player; // Player or null
            if ($owner && $owner->id !== $player->id) {
                $rent = $this->calculateRent($game, $turn, $owner, $space);
                $charge = $this->attemptCharge($game, $turn, $player, $owner, $rent, 'rent');
                $transactions = array_merge($transactions, $charge['transactions']);
                $actions = array_merge($actions, $charge['actions']);
                if ($charge['paid']) {
                    $actions[] = ['type' => 'rent_paid', 'to' => $owner->id, 'amount' => $rent, 'source' => 'card'];
                }
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
     * Attempt to charge a player an amount. If they have enough cash, pay immediately.
     * If not enough cash but assets cover the amount, mark the turn as awaiting decision
     * and return an action instructing the UI to let the player liquidate manually.
     * If assets plus cash are insufficient, auto-liquidate: sell all units, mortgage
     * all properties, transfer properties to the creditor if any, and pay as much as possible.
     */
    protected function attemptCharge(
        Game $game,
        Turn $turn,
        Player $from,
        ?Player $to,
        int $amount,
        string $reason
    ): array {
        $transactions = [];
        $actions = [];
        $amount = max(0, (int) $amount);
        if ($amount === 0) {
            return ['paid' => true, 'transactions' => [], 'actions' => []];
        }

        // If enough cash, settle immediately
        if ((int) $from->cash >= $amount) {
            if ($to) {
                $transactions[] = $this->transferBetweenPlayers($game, $turn, $from, $to, $amount);
                $from->decrement('cash', $amount);
                $to->increment('cash', $amount);
            } else {
                $transactions[] = $this->payToBank($game, $turn, $from, $amount);
                $from->decrement('cash', $amount);
            }
            return ['paid' => true, 'transactions' => $transactions, 'actions' => $actions];
        }

        // Compute total liquidation value
        $liquidationValue = $this->calculateLiquidationValue($game, $from);
        $totalPossible = (int) $from->cash + $liquidationValue;

        if ($totalPossible >= $amount) {
            // Require manual liquidation: block turn and prompt UI. Ensure we attach a hint about who is owed.
            $turn->update(['status' => 'awaiting_decision']);
            $actions[] = [
                'type' => 'payment_required',
                'amount' => $amount,
                'to_player_id' => $to?->id,
                'reason' => $reason,
            ];
            // Persist pending payment on the turn for refresh safety
            $turn->update([
                'pending_payment_amount' => $amount,
                'pending_payment_to_player_id' => $to?->id,
                'pending_payment_reason' => $reason,
            ]);
            return ['paid' => false, 'transactions' => $transactions, 'actions' => $actions];
        }

        // Not enough even after liquidation: present bankruptcy option (do not auto-bankrupt)
        $turn->update(['status' => 'awaiting_decision']);
        $turn->update([
            'pending_payment_amount' => $amount,
            'pending_payment_to_player_id' => $to?->id,
            'pending_payment_reason' => $reason,
        ]);
        $actions[] = [
            'type' => 'bankruptcy_available',
            'amount' => $amount,
            'to_player_id' => $to?->id,
            'reason' => $reason,
        ];

        return ['paid' => false, 'transactions' => $transactions, 'actions' => $actions];
    }

    protected function calculateLiquidationValue(Game $game, Player $player): int
    {
        $value = 0;
        $properties = $game->board->properties
            ->filter(fn(Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $player->id);
        foreach ($properties as $prop) {
            $units = (int) ($this->assetForGame($game, $prop)?->units ?? 0);
            $unitPrice = (int) ($prop->unit_price ?? 0);
            if ($units > 0 && $unitPrice > 0) {
                $value += (int) floor($units * ($unitPrice / 2));
            }
            $isMortgaged = (bool) (($this->assetForGame($game, $prop)?->is_mortgaged) ?? false);
            if (!$isMortgaged) {
                $value += (int) ($prop->mortgage_price ?? 0);
            }
        }
        return $value;
    }

    /**
     * Auto-sell all units, mortgage properties, and transfer properties to creditor if provided.
     * Returns [transactions[], actions[]].
     */
    protected function autoLiquidateAndTransfer(Game $game, Turn $turn, Player $from, ?Player $to): array
    {
        $transactions = [];
        $actions = [];
        $properties = $game->board->properties
            ->filter(fn(Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $from->id);
        foreach ($properties as $prop) {
            $asset = $this->assetForGame($game, $prop);
            $units = (int) ($asset->units ?? 0);
            $unitPrice = (int) ($prop->unit_price ?? 0);
            if ($units > 0 && $unitPrice > 0) {
                $refund = (int) floor($units * ($unitPrice / 2));
                if ($refund > 0) {
                    $tx = $game->transactions()->create(['turn_id' => $turn->id, 'status' => 'completed']);
                    $tx->items()->create([
                        'game_id' => $game->id,
                        'type' => 'cash',
                        'item_id' => 0,
                        'amount' => $refund,
                        'from_player_id' => null,
                        'to_player_id' => $from->id,
                    ]);
                    $from->increment('cash', $refund);
                    $asset->update(['units' => 0]);
                    $transactions[] = $tx;
                    $actions[] = ['type' => 'units_sold_auto', 'property_id' => $prop->id, 'amount' => $refund];
                }
            }

            $isMortgaged = (bool) ($asset->is_mortgaged ?? false);
            if (!$isMortgaged) {
                $mortgageCash = (int) ($prop->mortgage_price ?? 0);
                if ($mortgageCash > 0) {
                    $tx = $game->transactions()->create(['turn_id' => $turn->id, 'status' => 'completed']);
                    $tx->items()->create([
                        'game_id' => $game->id,
                        'type' => 'cash',
                        'item_id' => 0,
                        'amount' => $mortgageCash,
                        'from_player_id' => null,
                        'to_player_id' => $from->id,
                    ]);
                    $from->increment('cash', $mortgageCash);
                    $asset->update(['is_mortgaged' => true]);
                    $transactions[] = $tx;
                    $actions[] = ['type' => 'mortgaged_auto', 'property_id' => $prop->id, 'amount' => $mortgageCash];
                } else {
                    $asset->update(['is_mortgaged' => true]);
                    $actions[] = ['type' => 'mortgaged_auto', 'property_id' => $prop->id, 'amount' => 0];
                }
            }

            // Transfer to creditor if applicable
            if ($to) {
                $asset->update(['player_id' => $to->id]);
                $actions[] = ['type' => 'property_transferred_auto', 'property_id' => $prop->id, 'to' => $to->id];
            }
        }

        return [$transactions, $actions];
    }

    /**
     * Settle a pending payment while a turn is awaiting decision.
     * If a creditor is specified, pay them; otherwise pay bank. Requires enough cash now.
     * Afterwards, resume the awaiting turn according to doubles rules or complete it.
     */
    public function settlePendingPayment(Game $game, Player $player, int $amount, ?int $toPlayerId = null): array
    {
        return DB::transaction(function () use ($game, $player, $amount, $toPlayerId) {
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->where('status', 'awaiting_decision')
                ->orderByDesc('id')
                ->first();
            if (!$turn) {
                throw new \RuntimeException('No pending payment to settle');
            }

            $amount = max(0, (int) $amount);
            if ((int) $player->cash < $amount) {
                throw new \RuntimeException('Insufficient cash to pay now');
            }

            $to = $toPlayerId ? $game->players->firstWhere('id', (int) $toPlayerId) : null;
            if ($to) {
                $this->transferBetweenPlayers($game, $turn, $player, $to, $amount);
                $player->decrement('cash', $amount);
                $to->increment('cash', $amount);
            } else {
                $this->payToBank($game, $turn, $player, $amount);
                $player->decrement('cash', $amount);
            }

            // Resume turn: if last roll was a double, allow another roll; else complete.
            // Clear pending fields first
            $turn->update([
                'pending_payment_amount' => null,
                'pending_payment_to_player_id' => null,
                'pending_payment_reason' => null,
            ]);
            $lastRoll = $turn->rolls()->orderByDesc('id')->first();
            $wasDouble = (bool) optional($lastRoll)->is_double;
            $turn->update(['status' => $wasDouble ? 'in_progress' : 'completed']);

            return [
                'settled' => true,
                'turn_id' => $turn->id,
                'status' => $turn->status,
            ];
        });
    }

    /**
     * Declare bankruptcy: auto-sell all units, mortgage properties, transfer mortgaged properties to creditor,
     * pay whatever cash exists, mark player bankrupt, and complete the turn.
     */
    public function declareBankruptcy(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->where('status', 'awaiting_decision')
                ->orderByDesc('id')
                ->first();
            if (!$turn) {
                throw new \RuntimeException('No bankruptcy to declare');
            }

            $to = null;
            if ($turn->pending_payment_to_player_id) {
                $to = $game->players->firstWhere('id', (int) $turn->pending_payment_to_player_id);
            }

            // Liquidate and transfer
            [$txAuto] = $this->autoLiquidateAndTransfer($game, $turn, $player, $to);

            // Zero any remaining cash and mark bankrupt
            $player->update(['is_bankrupt' => 1, 'cash' => 0]);

            // Clear pending fields and complete turn
            $turn->update([
                'pending_payment_amount' => null,
                'pending_payment_to_player_id' => null,
                'pending_payment_reason' => null,
                'status' => 'completed',
            ]);

            // If only one player remains, mark game as completed
            $winner = $game->refresh()->winner;
            if ($winner) {
                $game->update(['status' => 'completed']);
            }

            return [
                'bankrupt' => true,
                'turn_id' => $turn->id,
                'winner_player_id' => $winner?->id,
            ];
        });
    }

    /**
     * Voluntarily leave a game: liquidate assets and remove the player from turn order.
     * If there is a pending creditor on the player's most recent (awaiting) turn, transfer
     * properties to that creditor after mortgaging; otherwise assets return to the bank.
     * Remaining cash is zeroed and the player is marked bankrupt to exclude from play.
     */
    public function leaveGame(Game $game, Player $player): array
    {
        return DB::transaction(function () use ($game, $player) {
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->orderByDesc('id')
                ->first();

            if (!$turn) {
                // Create a synthetic completed turn to attach liquidation transactions
                $turn = $game->turns()->create([
                    'player_id' => $player->id,
                    'status' => 'completed',
                ]);
            }

            // Determine creditor if leaving during a pending payment
            $to = null;
            if ($turn->pending_payment_to_player_id) {
                $to = $game->players->firstWhere('id', (int) $turn->pending_payment_to_player_id);
            }

            // Liquidate assets, transferring properties to creditor if applicable
            $this->autoLiquidateAndTransfer($game, $turn, $player, $to);

            // If there is no creditor, return properties to bank (remove ownership)
            if (!$to) {
                $properties = $game->board->properties
                    ->filter(fn(Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $player->id);
                foreach ($properties as $prop) {
                    $asset = $this->assetForGame($game, $prop);
                    if ($asset) { $asset->delete(); }
                }
            }

            // Mark player as out and clear pending fields
            $player->update([
                'is_bankrupt' => 1,
                'cash' => 0,
                'in_joint' => false,
                'joint_attempts' => 0,
            ]);

            $turn->update([
                'pending_payment_amount' => null,
                'pending_payment_to_player_id' => null,
                'pending_payment_reason' => null,
                'status' => 'completed',
            ]);

            // If only one player remains, mark game as completed
            $winner = $game->refresh()->winner;
            if ($winner) {
                $game->update(['status' => 'completed']);
            }

            return [
                'left' => true,
                'turn_id' => $turn->id,
                'winner_player_id' => $winner?->id,
            ];
        });
    }
    /**
     * Calculate rent for a property, including railroad logic.
     */
     protected function calculateRent(Game $game, Turn $turn, Player $owner, Property $property): int
    {
        // Owner in The Joint cannot collect rent
        if ((bool) ($owner->in_joint ?? false)) {
            return 0;
        }
        // Mortgaged properties do not collect rent
        if ($this->assetForGame($game, $property) && (bool) ($this->assetForGame($game, $property)->is_mortgaged ?? false)) {
            return 0;
        }
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
                ->filter(fn (Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $owner->id)
                ->count();

            $multiplier = $ownedUtilitiesCount >= 2 ? 10 : 4;
            return $diceTotal * $multiplier;
        }

        // Railroads: rent doubles per additional railroad the owner holds on this board.
        if (method_exists($property, 'isRailroad') && $property->isRailroad()) {
            $ownedRailroadsCount = $game->board->properties
                ->filter(fn (Property $p) => method_exists($p, 'isRailroad') && $p->isRailroad())
                ->filter(fn (Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $owner->id)
                ->count();

            $base = (int) ($property->rent ?? 25);
            $multiplier = max(1, $ownedRailroadsCount);
            return (int) ($base * (2 ** ($multiplier - 1)));
        }

        // Normal properties: use units and monopoly rules
        $units = (int) ($this->assetForGame($game, $property)?->units ?? 0);
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
            if (($game->status ?? '') === 'completed') {
                throw new \RuntimeException('Game is completed');
            }
            // Validate ownership (scoped to this game's players) and affordability
            $playerIds = $game->players->pluck('id')->values();
            $alreadyOwnedInThisGame = $property->relationLoaded('item')
                ? (bool) $property->item
                : $property->item()->whereIn('player_id', $playerIds)->exists();
            if ($alreadyOwnedInThisGame) {
                throw new \RuntimeException('Property already owned');
            }
            if ($player->cash < (int) $property->price) {
                throw new \RuntimeException('Insufficient funds');
            }

            // Attach to an existing awaiting_decision turn if present; else create a concise completed turn
            /** @var Turn|null $turn */
            $turn = $game->turns()
                ->where('player_id', $player->id)
                ->orderByDesc('id')
                ->first();
            if (!$turn || ($turn->status ?? '') === 'completed') {
                $turn = $game->turns()->create([
                    'player_id' => $player->id,
                    'status' => 'completed',
                ]);
            }

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
                'to_player_id' => null, // bank
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
            if (($game->status ?? '') === 'completed') {
                throw new \RuntimeException('Game is completed');
            }
            // Only current player may buy units
            if (!$game->current_player || (int) $game->current_player->id !== (int) $player->id) {
                throw new \RuntimeException('You can only buy units on your turn');
            }
            // Ownership checks (scoped to this game)
            $asset = $this->assetForGame($game, $property);
            if (!$asset || (int) $asset->player_id !== (int) $player->id) {
                throw new \RuntimeException('You do not own this property');
            }
            if (method_exists($property, 'isRailroad') && $property->isRailroad()) {
                throw new \RuntimeException('Cannot buy units on railroads');
            }
            if (method_exists($property, 'isUtility') && $property->isUtility()) {
                throw new \RuntimeException('Cannot buy units on utilities');
            }
            // Prevent units if rent tiers or unit pricing are not fully defined
            if (method_exists($property, 'supportsUnits') && !$property->supportsUnits()) {
                throw new \RuntimeException('Units are not available for this property');
            }
            if (!method_exists($property, 'ownerOwnsFullColorSet') || !$property->ownerOwnsFullColorSet()) {
                throw new \RuntimeException('Must own all properties of this color to buy units');
            }

            $currentUnits = (int) ($asset->units ?? 0);
            if ($currentUnits >= 5) {
                throw new \RuntimeException('Maximum units reached on this property');
            }
            // Ensure next unit has a corresponding rent tier
            $nextUnits = $currentUnits + 1;
            if (method_exists($property, 'hasRentForUnits') && !$property->hasRentForUnits($nextUnits)) {
                throw new \RuntimeException('Units are not available for this property');
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
            $minUnits = (int) $ownedColorSet->map(fn(Property $p) => (int) ($this->assetForGame($game, $p)?->units ?? 0))->min();
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
            $asset->update(['units' => $currentUnits + 1]);

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
            if (($game->status ?? '') === 'completed') {
                throw new \RuntimeException('Game is completed');
            }
            // Only current player may sell units
            if (!$game->current_player || (int) $game->current_player->id !== (int) $player->id) {
                throw new \RuntimeException('You can only sell units on your turn');
            }
            $asset = $this->assetForGame($game, $property);
            if (!$asset || (int) $asset->player_id !== (int) $player->id) {
                throw new \RuntimeException('You do not own this property');
            }
            if ((int) ($asset->units ?? 0) <= 0) {
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
            $maxUnits = (int) $ownedColorSet->map(fn(Property $p) => (int) ($this->assetForGame($game, $p)?->units ?? 0))->max();
            if ((int) ($asset->units ?? 0) < $maxUnits) {
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
            $asset->decrement('units');

            return [
                'transaction_id' => $tx->id,
                'property_id' => $property->id,
                'units' => (int) ($this->assetForGame($game, $property)?->units ?? 0),
            ];
        });
    }

    /**
     * Mortgage a property: no units allowed, marks as mortgaged, grants mortgage cash.
     */
    public function mortgageProperty(Game $game, Player $player, Property $property): array
    {
        return DB::transaction(function () use ($game, $player, $property) {
            if (!$game->current_player || (int) $game->current_player->id !== (int) $player->id) {
                throw new \RuntimeException('You can only mortgage on your turn');
            }
            $asset = $this->assetForGame($game, $property);
            if (!$asset || (int) $asset->player_id !== (int) $player->id) {
                throw new \RuntimeException('You do not own this property');
            }
            if ((int) ($asset->units ?? 0) > 0) {
                throw new \RuntimeException('Sell all units before mortgaging');
            }
            if ((bool) ($asset->is_mortgaged ?? false)) {
                throw new \RuntimeException('Already mortgaged');
            }

            $amount = (int) ($property->mortgage_price ?? 0);
            if ($amount < 0) { $amount = 0; }

            $turn = $game->turns()->create([
                'player_id' => $player->id,
                'status' => 'completed',
            ]);
            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'completed',
            ]);
            if ($amount > 0) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => 'cash',
                    'item_id' => 0,
                    'amount' => $amount,
                    'from_player_id' => null,
                    'to_player_id' => $player->id,
                ]);
                $player->increment('cash', $amount);
            }

            $asset->update(['is_mortgaged' => true]);

            return [
                'transaction_id' => $tx->id,
                'property_id' => $property->id,
                'mortgaged' => true,
            ];
        });
    }

    /**
     * Unmortgage a property by paying unmortgage price.
     */
    public function unmortgageProperty(Game $game, Player $player, Property $property): array
    {
        return DB::transaction(function () use ($game, $player, $property) {
            if (!$game->current_player || (int) $game->current_player->id !== (int) $player->id) {
                throw new \RuntimeException('You can only unmortgage on your turn');
            }
            $asset = $this->assetForGame($game, $property);
            if (!$asset || (int) $asset->player_id !== (int) $player->id) {
                throw new \RuntimeException('You do not own this property');
            }
            if (!((bool) ($asset->is_mortgaged ?? false))) {
                throw new \RuntimeException('Property is not mortgaged');
            }

            $amount = (int) ($property->unmortgage_price ?? 0);
            if ((int) $player->cash < $amount) {
                throw new \RuntimeException('Insufficient funds to unmortgage');
            }

            $turn = $game->turns()->create([
                'player_id' => $player->id,
                'status' => 'completed',
            ]);
            $tx = $game->transactions()->create([
                'turn_id' => $turn->id,
                'status' => 'completed',
            ]);
            if ($amount > 0) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => 'cash',
                    'item_id' => 0,
                    'amount' => $amount,
                    'from_player_id' => $player->id,
                    'to_player_id' => null,
                ]);
                $player->decrement('cash', $amount);
            }

            $asset->update(['is_mortgaged' => false]);

            return [
                'transaction_id' => $tx->id,
                'property_id' => $property->id,
                'mortgaged' => false,
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
                $asset = $this->assetForGame($game, $property);
                if (!$asset || $asset->player_id !== $from->id) {
                    throw new \RuntimeException('You do not own this property');
                }
                // Disallow trading any property if its color set has units by the current owner
                $ownerOwnedProps = $game->board->properties
                    ->filter(fn (Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $from->id)
                    ->where('color', $property->color);
                $unitsInColor = (int) $ownerOwnedProps->sum(fn(Property $p) => (int) ($this->assetForGame($game, $p)?->units ?? 0));
                if ($unitsInColor > 0) {
                    throw new \RuntimeException('Cannot trade a property in a color where you have units');
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
                $asset->update(['player_id' => $to->id]);
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
                $asset = $this->assetForGame($game, $property);
                if (!$asset || $asset->player_id !== $from->id) {
                    throw new \RuntimeException('You do not own this property');
                }
                $ownerOwnedProps = $game->board->properties
                    ->filter(fn (Property $p) => ($this->assetForGame($game, $p)?->player_id ?? 0) === (int) $from->id)
                    ->where('color', $property->color);
                $unitsInColor = (int) $ownerOwnedProps->sum(fn(Property $p) => (int) ($this->assetForGame($game, $p)?->units ?? 0));
                if ($unitsInColor > 0) {
                    throw new \RuntimeException('Cannot trade a property in a color where you have units');
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
     * Create a pending trade with an arbitrary set of items (cash and/or properties) possibly in both directions.
     * Each item must include: type ('cash'|'property'), item_id (0 for cash), amount (cash only), from_player_id, to_player_id.
     */
    public function createTradeRequestItems(
        Game $game,
        Player $from,
        Player $to,
        array $items
    ): array {
        return DB::transaction(function () use ($game, $from, $to, $items) {
            if ($from->id === $to->id) {
                throw new \RuntimeException('Cannot trade with yourself');
            }

            if (empty($items)) {
                throw new \RuntimeException('Trade must include at least one item');
            }

            // Validate items
            foreach ($items as $idx => $item) {
                $type = (string) ($item['type'] ?? '');
                $itemId = (int) ($item['item_id'] ?? 0);
                $amount = (int) ($item['amount'] ?? 0);
                $fromId = (int) ($item['from_player_id'] ?? 0);
                $toId = (int) ($item['to_player_id'] ?? 0);

                if (!in_array($type, ['cash', 'property'], true)) {
                    throw new \RuntimeException("Invalid item type at index {$idx}");
                }
                if ($fromId === $toId) {
                    throw new \RuntimeException('Item cannot be to the same player');
                }
                if (!$game->players->firstWhere('id', $fromId) || !$game->players->firstWhere('id', $toId)) {
                    throw new \RuntimeException('Invalid players on trade');
                }

                if ($type === 'cash') {
                    if ($amount < 0) {
                        throw new \RuntimeException('Cash amount must be non-negative');
                    }
                } else { // property
                    if ($itemId <= 0) {
                        throw new \RuntimeException('Property id required');
                    }
                    /** @var Property $prop */
                    $prop = Property::query()->findOrFail($itemId);
                    if (!$game->board->properties->contains('id', $prop->id)) {
                        throw new \RuntimeException('Property not part of this game');
                    }
                    $asset = $this->assetForGame($game, $prop);
                    if (!$asset || (int) $asset->player_id !== $fromId) {
                        throw new \RuntimeException('You do not own this property');
                    }
                    // Disallow if any units exist in the color group for the current owner
                    $ownerOwnedProps = Property::query()
                        ->where('board_id', $prop->board_id)
                        ->whereRaw('LOWER(color) = ?', [strtolower((string) $prop->color)])
                        ->whereHas('item', fn($q) => $q->where('player_id', $fromId))
                        ->with('item')
                        ->get();
                    $unitsInColor = (int) $ownerOwnedProps->sum(fn(Property $p) => (int) ($this->assetForGame($game, $p)?->units ?? 0));
                    if ($unitsInColor > 0) {
                        throw new \RuntimeException('Cannot trade a property in a color where you have units');
                    }
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

            foreach ($items as $item) {
                $tx->items()->create([
                    'game_id' => $game->id,
                    'type' => (string) $item['type'],
                    'item_id' => (int) $item['item_id'],
                    'amount' => (int) ($item['amount'] ?? 0),
                    'from_player_id' => (int) $item['from_player_id'],
                    'to_player_id' => (int) $item['to_player_id'],
                ]);
            }

            return [
                'transaction_id' => $tx->id,
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
            $tx = $game->transactions()->with(['items', 'turn'])->where('id', $transactionId)->firstOrFail();
            if ($tx->status !== 'pending') {
                throw new \RuntimeException('Trade is not pending');
            }

            // Must be approver's turn
            if (!$game->current_player || $game->current_player->id !== $approver->id) {
                throw new \RuntimeException('Only the current player can approve a trade');
            }

            // Initiator (the player who created the trade/turn) cannot approve their own trade
            $initiatorId = (int) optional($tx->turn)->player_id;
            if ($initiatorId === (int) $approver->id) {
                throw new \RuntimeException('You cannot approve a trade you initiated');
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
                    $asset = $this->assetForGame($game, $property);
                    if (!$asset || (int) $asset->player_id !== (int) $item->from_player_id) {
                        throw new \RuntimeException('Property no longer owned by the expected player');
                    }
                    // Guard: sender must not have units on the color
                    $ownerOwnedProps = Property::query()
                        ->where('board_id', $property->board_id)
                        ->whereRaw('LOWER(color) = ?', [strtolower((string) $property->color)])
                        ->whereHas('item', fn($q) => $q->where('player_id', (int) $item->from_player_id))
                        ->with('item')
                        ->get();
                    $unitsInColor = (int) $ownerOwnedProps->sum(fn(Property $p) => (int) ($this->assetForGame($game, $p)?->units ?? 0));
                    if ($unitsInColor > 0) {
                        throw new \RuntimeException('Cannot trade a property in a color where you have units');
                    }
                    $asset->update(['player_id' => (int) $item->to_player_id]);
                }
            }

            $tx->update(['status' => 'completed']);

            // After approval, reject any other pending trades that include properties from this transaction
            $approvedPropertyIds = collect($tx->items)
                ->where('type', 'property')
                ->pluck('item_id')
                ->map(fn($id) => (int) $id)
                ->filter()
                ->values();

            if ($approvedPropertyIds->isNotEmpty()) {
                $conflictingTxIds = Transaction::query()
                    ->where('game_id', $game->id)
                    ->where('status', 'pending')
                    ->where('id', '!=', $tx->id)
                    ->whereHas('items', function ($q) use ($approvedPropertyIds) {
                        $q->where('type', 'property')
                          ->whereIn('item_id', $approvedPropertyIds);
                    })
                    ->pluck('id');

                if ($conflictingTxIds->isNotEmpty()) {
                    Transaction::query()->whereIn('id', $conflictingTxIds)->update(['status' => 'rejected']);
                }
            }

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


