<?php

namespace App\Http\Controllers;

use App\Models\Game;

class GameController extends Controller
{
    public function index()
    {
        $games = App\Models\Game::with('board', 'players')->get();

        return view('games.index', [
            'games' => $games,
        ]);
    }

    public function create()
    {
        $boards = App\Models\Board::get();

        return view('games.create', [
            'boards' => $boards,
        ]);
    }

    public function store()
    {
        return request()->all();
    }

    public function show(Game $game)
    {
        return $game;
    }
}
