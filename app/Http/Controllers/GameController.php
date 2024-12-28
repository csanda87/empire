<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Game;

class GameController extends Controller
{
    public function index()
    {
        $games = Game::with('board', 'players', 'createdBy')->get();

        return view('games.index', [
            'games' => $games,
        ]);
    }

    public function create()
    {
        $boards = Board::orderBy('name')->get();

        return view('games.create', [
            'boards' => $boards,
        ]);
    }

    public function store()
    {
        $validated = request()->validate([
            'invite_code' => 'required|unique:games,invite_code',
        ]);

        $game = new Game($validated);
        $game->board_id = 1;
        $game->status = 'waiting';
        $game->created_by = auth()->id();
        $game->save();

        $game->players()->create([
            'user_id' => auth()->id(),
            'name' => auth()->user()->name,
            'color' => 'blue',
        ]);

        return redirect('/play/' . $game->invite_code);
    }
}
