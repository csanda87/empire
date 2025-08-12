<?php

namespace App\Livewire;

use App\Events\DiceRolled;
use App\Events\GameUpdated;
use App\Models\Game;
use App\Models\Property;
use App\Services\GameEngine;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Component;

class PlayGame extends Component
{
    public string $inviteCode;
    public array $dice = [];
    public ?string $landingMessage = null;
    public ?int $pendingPaymentAmount = null;
    public ?int $pendingPaymentToPlayerId = null;
    public ?int $offerPropertyId = null;
    public ?int $tradeToPlayerId = null;
    public int $tradeGiveCashAmount = 0;
    public array $tradeGivePropertyIds = [];
    public int $tradeRequestCashAmount = 0;
    public array $tradeRequestPropertyIds = [];
    public ?string $error = null;
    public ?int $unitsPropertyId = null;

    public function mount(string $inviteCode): void
    {
        $this->inviteCode = $inviteCode;
    }

    public function joinGame(): void
    {
        $game = Game::query()
            ->with('players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        if (($game->status ?? '') !== 'waiting') {
            return;
        }

        $alreadyJoined = $game->players->contains(function ($p) {
            return (int) ($p->user_id ?? 0) === (int) auth()->id();
        });
        if ($alreadyJoined) {
            return;
        }

        $allColors = [
            'amber','blue','cyan','emerald','green','indigo','lime','pink','purple','red','rose','sky','teal','yellow',
        ];
        $usedColors = $game->players->pluck('color')->filter()->values()->all();
        $availableColor = collect($allColors)->first(function ($c) use ($usedColors) {
            return !in_array($c, $usedColors, true);
        }) ?? $allColors[0];

        $game->players()->create([
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
            'color' => $availableColor,
        ]);

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'player_joined'));
    }

    public function startGame(): void
    {
        $game = Game::query()
            ->with('players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        if (($game->status ?? '') !== 'waiting') {
            return;
        }

        // Allow the creator or the first joined player to start the game
        $firstPlayerUserId = (int) optional($game->players->sortBy('id')->first())->user_id;
        $isCreator = (int) ($game->created_by ?? 0) === (int) auth()->id();
        $isFirstPlayer = (int) auth()->id() === $firstPlayerUserId;
        $hasTwoOrMore = $game->players->count() >= 2;

        if (!($hasTwoOrMore && ($isCreator || $isFirstPlayer))) {
            return;
        }

        // Initialize starting cash for all players at the moment the game starts
        $game->players()->update(['cash' => 1500]);

        $game->update(['status' => 'in_progress']);

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'game_started'));
    }

    public function getListeners(): array
    {
        return [
            "echo-private:play.{$this->inviteCode},DiceRolled" => 'handleDiceRolled',
            "echo-private:play.{$this->inviteCode},GameUpdated" => '$refresh',
        ];
    }

    public function handleDiceRolled(array $event): void
    {
        $this->dice = $event['dice'] ?? [];
        // Trigger a re-render so the latest DB state is shown
        $this->dispatch('$refresh');
    }

    public function roll(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties', 'players', 'turns.rolls')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        if (($game->status ?? '') === 'waiting') {
            return;
        }

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        // Only the current player may roll
        if ($game->current_player && $game->current_player->id !== $player->id) {
            return;
        }

        $result = $engine->rollAndAdvance($game, $player);

        $this->dice = $result['dice'] ?? [];
        $this->landingMessage = $this->composeLandingMessage($result['space'] ?? null, $result['actions'] ?? [], $game->players);

        // If payment is required, surface minimal state for UI
        foreach (($result['actions'] ?? []) as $action) {
            if (($action['type'] ?? null) === 'payment_required') {
                $this->pendingPaymentAmount = (int) ($action['amount'] ?? 0);
                $this->pendingPaymentToPlayerId = (int) ($action['to_player_id'] ?? 0) ?: null;
                $this->error = 'You must pay $' . (int) ($action['amount'] ?? 0) . '. Sell units or mortgage properties to proceed.';
                break;
            }
        }

        // Capture purchase offer if present
        foreach (($result['actions'] ?? []) as $action) {
            if (($action['type'] ?? null) === 'offer_purchase') {
                $this->offerPropertyId = (int) ($action['property_id'] ?? 0);
                break;
            }
        }

        // Notify other players in realtime
        broadcast(new DiceRolled($this->inviteCode, $this->dice))->toOthers();

        // Re-render with fresh state
        $this->dispatch('$refresh');
    }

    // When a user buys or skips, we'll call resolvePendingDecision indirectly

    protected function composeLandingMessage($space, array $actions, $players = null): ?string
    {
        if (!$space) {
            return null;
        }

        // If a card was drawn, surface its message prominently
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === 'card_drawn') {
                $deck = (string) ($action['deck'] ?? 'Card');
                $msg = (string) ($action['message'] ?? '');
                $title = is_array($space) ? ($space['title'] ?? 'a card space') : 'a card space';
                return trim(sprintf('You landed on %s and drew a %s card: %s', $title, $deck, $msg));
            }
        }

        // If rent was paid on landing, prefer a clear rent message
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === 'rent_paid') {
                $amount = (int) ($action['amount'] ?? 0);
                $toId = (int) ($action['to'] ?? 0);
                $toName = 'opponent';
                if ($players) {
                    $owner = $players->firstWhere('id', $toId);
                    if ($owner) {
                        $toName = $owner->name;
                    }
                }
                $spaceTitle = is_array($space) ? ($space['title'] ?? 'a property') : 'a property';
                return sprintf('You landed on %s and paid $%d to %s.', $spaceTitle, $amount, $toName);
            }
        }

        // If you landed on your own property, make that explicit
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === 'landed_own_property') {
                $spaceTitle = is_array($space) ? ($space['title'] ?? 'a property') : 'a property';
                return sprintf('You landed on %s, which you own.', $spaceTitle);
            }
        }

        // If an action space made you pay, include the amount in the message (e.g., Tribute)
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === 'pay') {
                $amount = (int) ($action['amount'] ?? 0);
                $spaceTitle = is_array($space) ? ($space['title'] ?? 'an action space') : 'an action space';
                return sprintf('You landed on %s and paid $%d.', $spaceTitle, $amount);
            }
        }

        if (is_array($space) && ($space['type'] ?? null) === 'Property') {
            return sprintf('You landed on %s.', $space['title'] ?? 'a property');
        }

        if (is_array($space) && ($space['type'] ?? null) === 'ActionSpace') {
            return sprintf('You landed on %s.', $space['title'] ?? 'an action space');
        }

        return 'You moved.';
    }

    public function buyProperty(GameEngine $engine): void
    {
        if (!$this->offerPropertyId) {
            return;
        }

        $game = Game::query()
            ->with('board.properties', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') === 'waiting') { return; }
        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        $property = $game->board->properties->firstWhere('id', $this->offerPropertyId);
        if (!$property) {
            return;
        }

        // Guard: insufficient funds
        if ((int) $player->cash < (int) $property->price) {
            return;
        }

        $engine->purchaseProperty($game, $player, $property);
        // After decision, advance turn status depending on last roll
        $engine->resolvePendingDecision($game, $player);
        $this->offerPropertyId = null;
        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'purchase'));
    }

    public function skipPurchase(GameEngine $engine): void
    {
        if (!$this->offerPropertyId) {
            return;
        }

        $game = Game::query()
            ->with('players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') === 'waiting') { return; }

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        // Resolve pending decision without purchasing
        $engine->resolvePendingDecision($game, $player);
        $this->offerPropertyId = null;
        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'skip_purchase'));
    }

    public function createTradeRequest(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $from = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $to = $game->players->firstWhere('id', $this->tradeToPlayerId);

        if (!$to) {
            return;
        }

        // Build items for a two-sided trade
        $items = [];
        $giveCash = (int) $this->tradeGiveCashAmount;
        $requestCash = (int) $this->tradeRequestCashAmount;

        if ($giveCash > 0) {
            $items[] = [
                'type' => 'cash',
                'item_id' => 0,
                'amount' => $giveCash,
                'from_player_id' => (int) $from->id,
                'to_player_id' => (int) $to->id,
            ];
        }
        if ($requestCash > 0) {
            $items[] = [
                'type' => 'cash',
                'item_id' => 0,
                'amount' => $requestCash,
                'from_player_id' => (int) $to->id,
                'to_player_id' => (int) $from->id,
            ];
        }

        // Properties the initiator is offering
        foreach ((array) $this->tradeGivePropertyIds as $propId) {
            $propId = (int) $propId;
            $property = $game->board->properties->firstWhere('id', $propId);
            if ($property) {
                $items[] = [
                    'type' => 'property',
                    'item_id' => $property->id,
                    'amount' => 0,
                    'from_player_id' => (int) $from->id,
                    'to_player_id' => (int) $to->id,
                ];
            }
        }

        // Properties the initiator is requesting from the other player
        foreach ((array) $this->tradeRequestPropertyIds as $propId) {
            $propId = (int) $propId;
            $property = $game->board->properties->firstWhere('id', $propId);
            if ($property) {
                $items[] = [
                    'type' => 'property',
                    'item_id' => $property->id,
                    'amount' => 0,
                    'from_player_id' => (int) $to->id,
                    'to_player_id' => (int) $from->id,
                ];
            }
        }

        if (count($items) === 0) {
            return;
        }

        try {
            $engine->createTradeRequestItems($game, $from, $to, $items);
        } catch (\Throwable $e) {
            // Optionally set a flash/message state in the future
        }

        // Reset form
        $this->tradeToPlayerId = null;
        $this->tradeGiveCashAmount = 0;
        $this->tradeRequestCashAmount = 0;
        $this->tradeGivePropertyIds = [];
        $this->tradeRequestPropertyIds = [];

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'trade_created'));
    }

    public function payToLeaveJoint(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }
        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->payToLeaveJoint($game, $player);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'trade_approved'));
    }

    public function approveTrade(int $transactionId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns.transactions.items')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $approver = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->approveTrade($game, $transactionId, $approver);
        } catch (\Throwable $e) {
            // Optionally store an error message state
        }

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'trade_rejected'));
    }

    public function rejectTrade(int $transactionId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns.transactions.items')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $actor = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->rejectTrade($game, $transactionId, $actor);
        } catch (\Throwable $e) {
            // Optionally store an error message state
        }

        $this->dispatch('$refresh');
    }

    public function payNow(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns.rolls')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }
        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        if (!$this->pendingPaymentAmount || $this->pendingPaymentAmount <= 0) {
            return;
        }

        try {
            $engine->settlePendingPayment($game, $player, (int) $this->pendingPaymentAmount, $this->pendingPaymentToPlayerId);
            $this->pendingPaymentAmount = null;
            $this->pendingPaymentToPlayerId = null;
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'payment_settled'));
    }

    public function declareBankruptcy(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns.rolls')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }
        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->declareBankruptcy($game, $player);
            $this->pendingPaymentAmount = null;
            $this->pendingPaymentToPlayerId = null;
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'bankruptcy_declared'));
    }

    public function leaveGame(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->leaveGame($game, $player);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
        broadcast(new GameUpdated($this->inviteCode, 'player_left'));
    }

    public function buyUnit(int $propertyId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties.item', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $property = $game->board->properties->firstWhere('id', $propertyId);
        if (!$property) {
            return;
        }

        try {
            $engine->buyUnit($game, $player, $property);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
    }

    public function sellUnit(int $propertyId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties.item', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $property = $game->board->properties->firstWhere('id', $propertyId);
        if (!$property) {
            return;
        }

        try {
            $engine->sellUnit($game, $player, $property);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
    }

    public function mortgageProperty(int $propertyId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties.item', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $property = $game->board->properties->firstWhere('id', $propertyId);
        if (!$property) {
            return;
        }

        try {
            $engine->mortgageProperty($game, $player, $property);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
    }

    public function unmortgageProperty(int $propertyId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties.item', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        if (($game->status ?? '') !== 'in_progress') { return; }

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $property = $game->board->properties->firstWhere('id', $propertyId);
        if (!$property) {
            return;
        }

        try {
            $engine->unmortgageProperty($game, $player, $property);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
    }

    public function render(): ViewContract
    {
        $game = Game::query()
            ->has('board.properties')
            ->has('players')
            ->with(
                'board.properties.item',
                'players.assets.itemable',
                'turns.transactions.items.item',
                'turns.transactions.items.fromPlayer:id,name,color',
                'turns.transactions.items.toPlayer:id,name,color',
                'turns.player:id,name,color',
                'turns.rolls',
            )
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        // Hydrate pending payment state from DB on render to survive refreshes
        $me = $game->players->firstWhere('user_id', auth()->id());
        if ($me) {
            $awaitingTurn = $game->turns
                ->where('player_id', $me->id)
                ->sortByDesc('id')
                ->firstWhere('status', 'awaiting_decision');
            if ($awaitingTurn && (int) ($awaitingTurn->pending_payment_amount ?? 0) > 0) {
                $this->pendingPaymentAmount = (int) $awaitingTurn->pending_payment_amount;
                $this->pendingPaymentToPlayerId = $awaitingTurn->pending_payment_to_player_id ? (int) $awaitingTurn->pending_payment_to_player_id : null;
                $this->error = 'You must pay $' . (int) $this->pendingPaymentAmount . '. Sell units or mortgage properties to proceed.';
                // Clear any purchase offer if a payment is pending
                $this->offerPropertyId = null;
            } elseif ($awaitingTurn) {
                // No payment pending but a decision is required. If the player is on an unowned property,
                // rehydrate the offer to purchase so the Buy banner appears after refresh.
                $position = (int) ($me->position ?? 0);
                $spaces = collect($game->board->getSpaces($game))->flatten(1)->values();
                $space = $spaces[$position] ?? null;
                if ($space instanceof Property && !$space->item) {
                    $this->offerPropertyId = (int) $space->id;
                }
            } else {
                // No awaiting decision; ensure offer is cleared
                $this->offerPropertyId = null;
            }
        }

        return view('livewire.play-game', [
            'game' => $game,
        ]);
    }
}
