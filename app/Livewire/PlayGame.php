<?php

namespace App\Livewire;

use App\Events\DiceRolled;
use App\Models\Game;
use App\Services\GameEngine;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Component;

class PlayGame extends Component
{
    public string $inviteCode;
    public array $dice = [];
    public ?int $offerPropertyId = null;
    public ?int $tradeToPlayerId = null;
    public int $tradeCashAmount = 0;
    public ?int $tradePropertyId = null;

    public function mount(string $inviteCode): void
    {
        $this->inviteCode = $inviteCode;
    }

    public function getListeners(): array
    {
        return [
            "echo-private:play.{$this->inviteCode},DiceRolled" => 'handleDiceRolled',
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
        $this->offerPropertyId = null;
        $this->dispatch('$refresh');
    }

    public function executeTrade(GameEngine $engine): void
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
            $engine->executeTrade($game, $from, $to, (int) $this->tradeCashAmount, $property);
        } catch (\Throwable $e) {
            // Optionally set a flash/message state in the future
        }

        // Reset form
        $this->tradeToPlayerId = null;
        $this->tradeCashAmount = 0;
        $this->tradePropertyId = null;

        $this->dispatch('$refresh');
    }

    public function render(): ViewContract
    {
        $game = Game::query()
            ->has('board.properties')
            ->has('players')
            ->with(
                'board.properties',
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
