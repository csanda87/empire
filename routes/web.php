<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Livewire\Counter;
 
Route::get('/counter', Counter::class);

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes();

Route::get('/test-reverb', function () {
    return [
        'connected' => app('reverb')->getHost() === env('REVERB_HOST', '127.0.0.1'),
        'port' => env('REVERB_PORT', 6001),
    ];
});

Route::get('/', function () {
    // $invite_code = '123';
    // $game = App\Models\Game::with('players')->where('invite_code', $invite_code)->firstOrFail();
    // dd($game->players->contains('user_id', 3));
    // App\Events\DiceRolled::dispatch();
    return view('welcome');
});

Route::get('/dashboard', function () {
    // return redirect('/games');
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/play/{invite_code}', function ($invite_code) {
        $game = App\Models\Game::query()
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
            ->where('invite_code', $invite_code)
            ->firstOrFail();

        return view('play', [
            'game' => $game,
        ]);
    });

    Route::resource('/games', GameController::class)->only(['index', 'create', 'store']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
