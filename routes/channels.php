<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('play.{inviteCode}', function ($user, $inviteCode) {
    return true;
    // $game = App\Models\Game::with('players')->where('invite_code', $invite_code)->firstOrFail();
    
    // return $game->players->contains('user_id', $user->id);
});

Broadcast::channel('chat.{roomId}', function ($user, $roomId) {
    // if ($user->canJoinRoom($roomId)) {
        return ['id' => $user->id, 'name' => $user->name];
    // }
});