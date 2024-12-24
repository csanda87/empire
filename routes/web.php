<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/play/{invite_code}', function ($invite_code) {
        $game = App\Models\Game::query()
            ->has('board.properties')
            ->has('players')
            ->with('board.properties', 'players')
            ->where('invite_code', $invite_code)
            ->firstOrFail();
        // return $game->players->pluck('position', 'id');
        return view('play', [
            'game' => $game,
        ]);
    });

    Route::resource('/games', GameController::class)->only(['index', 'create', 'store', 'show']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
