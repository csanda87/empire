<?php

namespace App\Livewire;

use App\Events\DiceRolled;
use App\Events\GameUpdated;
use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Component;

class PlayGame extends Component
{
    public string $inviteCode;
    public array $dice = [];
    public ?string $landingMessage = null;
    public ?int $offerPropertyId = null;
    public ?int $tradeToPlayerId = null;
    public int $tradeCashAmount = 0;
    public ?int $tradePropertyId = null;
    public ?string $error = null;
    public ?int $unitsPropertyId = null;

    public function mount(string $inviteCode): void
    {
        $this->inviteCode = $inviteCode;
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

        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        // Only the current player may roll
        if ($game->current_player && $game->current_player->id !== $player->id) {
            return;
        }

        $result = $engine->rollAndAdvance($game, $player);

        $this->dice = $result['dice'] ?? [];
        $this->landingMessage = $this->composeLandingMessage($result['space'] ?? null, $result['actions'] ?? [], $game->players);

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

        $from = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $to = $game->players->firstWhere('id', $this->tradeToPlayerId);

        if (!$to) {
            return;
        }

        $property = null;
        if ($this->tradePropertyId) {
            $property = $game->board->properties->firstWhere('id', $this->tradePropertyId);
        }

        try {
            $engine->createTradeRequest($game, $from, $to, (int) $this->tradeCashAmount, $property);
        } catch (\Throwable $e) {
            // Optionally set a flash/message state in the future
        }

        // Reset form
        $this->tradeToPlayerId = null;
        $this->tradeCashAmount = 0;
        $this->tradePropertyId = null;

        $this->dispatch('$refresh');
    }

    public function payToLeaveJoint(GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();
        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->payToLeaveJoint($game, $player);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->dispatch('$refresh');
    }

    public function approveTrade(int $transactionId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns.transactions.items')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        $approver = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->approveTrade($game, $transactionId, $approver);
        } catch (\Throwable $e) {
            // Optionally store an error message state
        }

        $this->dispatch('$refresh');
    }

    public function rejectTrade(int $transactionId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('players', 'turns.transactions.items')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

        $actor = $game->players()->where('user_id', auth()->id())->firstOrFail();

        try {
            $engine->rejectTrade($game, $transactionId, $actor);
        } catch (\Throwable $e) {
            // Optionally store an error message state
        }

        $this->dispatch('$refresh');
    }

    public function buyUnit(int $propertyId, GameEngine $engine): void
    {
        $game = Game::query()
            ->with('board.properties.item', 'players')
            ->where('invite_code', $this->inviteCode)
            ->firstOrFail();

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

        return view('livewire.play-game', [
            'game' => $game,
        ]);
    }
}
