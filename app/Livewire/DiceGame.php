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

    public function rollDice()
    {
        $this->dice = [
            rand(1, 6),
            rand(1, 6),
        ];

        broadcast(new DiceRolled($this->inviteCode, $this->dice));
    }

    public function render()
    {
        return view('livewire.dice-game');
    }
}