<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('play.{invite_code}', function ($user, $invite_code) {
    return true;
    // $game = App\Models\Game::with('players')->where('invite_code', $invite_code)->firstOrFail();
    
    // return $game->players->contains('user_id', $user->id);
});
