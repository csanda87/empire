<x-layouts.app>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Play
        </h2>
    </x-slot>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div>
                        Game Status: <b>{{ $game->status }}</b>
                    </div>
                    <div>
                        Invite Code: <b>{{ $game->invite_code }}</b>
                    </div>
                    <div>
                        Board: <b>{{ $game->board->name }}</b>
                        @if ($game->board->description)
                            <p>{{ $game->board->description }}</p>
                        @endif
                    </div>

                    <livewire:play-game :invite-code="$game->invite_code" />
                </div>
            </div>
        </div>
    </div>
    </div>
</x-layouts.app>
