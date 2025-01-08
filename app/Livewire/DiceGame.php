<?php

namespace App\Livewire;

use Livewire\Component;
use App\Events\DiceRolled;

class DiceGame extends Component
{
    public $inviteCode;
    public $dice = [];

    public function mount($inviteCode)
    {
        $this->inviteCode = $inviteCode;
    }

    public function getListeners()
    {
        return [
            // Public Channel
            // "echo:orders,OrderShipped" => 'rollDice',
 
            // Private Channel
            "echo-private:play.{$this->inviteCode},DiceRolled" => 'updateDice',
            // "echo-private:orders,OrderShipped" => 'rollDice',
 
            // Presence Channel
            // "echo-presence:orders,OrderShipped" => 'rollDice',
            // "echo-presence:orders,here" => 'rollDice',
            // "echo-presence:orders,joining" => 'rollDice',
            // "echo-presence:orders,leaving" => 'rollDice',
        ];
    }

    public function rollDice()
    {
        $this->dice = [
            rand(1, 6),
            rand(1, 6),
        ];

        // broadcast(new DiceRolled($this->inviteCode, $this->dice));
        try {
            broadcast(new DiceRolled($this->inviteCode, $this->dice))->toOthers();
            // logger()->info('Dice rolled event broadcasted', [
            //     'inviteCode' => $this->inviteCode,
            //     'dice' => $this->dice
            // ]);
        } catch (\Exception $e) {
            dd('error', $e);
            // logger()->error('Broadcasting failed', [
            //     'error' => $e->getMessage()
            // ]);
        }
    }

    public function updateDice($event)
    {
        $this->dice = $event['dice'];
    }

    public function render()
    {
        return view('livewire.dice-game');
    }
}