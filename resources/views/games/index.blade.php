<x-layouts.app>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Games
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @foreach ($games as $game)
                <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 dark:text-gray-200 shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        <a href="/play/{{ $game->invite_code }}">
                            <span class="block">Play Game</span>
                            <span class="block">Status: {{ $game->status }}</span>
                            <span class="block">Invite Code: {{ $game->invite_code }}</span>
                            <span class="block">Board: {{ $game->board->name }}</span>
                            <span class="block">Players: {{ $game->players->count() }}</span>
                            <span class="block">Created by: {{ $game->createdBy->name }}</span>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-layouts.app>
