<x-app-layout>
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
                    <hr class="mt-4">
                    @php
                        $pieceColors = [
                            'amber' => 'bg-amber-700',
                            'blue' => 'bg-blue-700',
                            'cyan' => 'bg-cyan-700',
                            'emerald' => 'bg-emerald-700',
                            'green' => 'bg-green-700',
                            'indigo' => 'bg-indigo-700',
                            'lime' => 'bg-lime-700',
                            'pink' => 'bg-pink-700',
                            'purple' => 'bg-purple-700',
                            'red' => 'bg-red-700',
                            'rose' => 'bg-rose-700',
                            'sky' => 'bg-sky-700',
                            'teal' => 'bg-teal-700',
                            'yellow' => 'bg-yellow-700',
                        ];
                    @endphp
                    <div class="mt-4">
                        @foreach ($game->board->getSpaces() as $sideIndex => $sides)
                            <div class="grid grid-cols-10">
                                @foreach ($sides as $index => $space)
                                    @if ($space['type'] === 'ActionSpace')
                                        <div class="border text-xs">
                                            <p>&nbsp;</p>
                                            <div class="p-4">
                                                <p>{{ $space['title'] }}</p>
                                                <p class="mt-4">{{ $space['effect'] }}</p>
                                            </div>
                                            <div class="px-4 py-2">
                                                <div class="flex -space-x-1">
                                                    @if ($game->players->where('position', $index + ($sideIndex * 10))->count())
                                                        @foreach ($game->players->where('position', $index + ($sideIndex * 10)) as $player)
                                                            <span class="bg-{{ $player->color }}-700 inline-block size-6 rounded-full ring-1 ring-white"></span>
                                                        @endforeach
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="border text-xs">
                                            <p style="background: {{ $space['color'] }}">&nbsp;</p>
                                            <div class="p-4">
                                                <p>{{ $space['title'] }}</p>
                                                <p class="mt-4">{{ $space['price'] }}</p>
                                            </div>
                                            <div class="px-4 py-2">
                                                <div class="flex -space-x-1">
                                                    @if ($game->players->where('position', $index + ($sideIndex * 10))->count())
                                                        @foreach ($game->players->where('position', $index + ($sideIndex * 10)) as $player)
                                                            <span class="bg-{{ $player->color }}-700 inline-block size-6 rounded-full ring-1 ring-white"></span>
                                                        @endforeach
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4">
                        <div class="flex gap-4">
                            <div class="w-full sm:w-1/2">
                                Players State
                            </div>
                            <div class="w-full sm:w-1/2">
                                <h3>Turns</h3>
                                <ol class="list-decimal list-inside">
                                    @foreach ($game->turns as $turn)
                                        <li>
                                            <span class="bg-{{ $turn->player->color }}-700 inline-block size-6 rounded-full ring-1 ring-white"></span>
                                            {{ $turn->player->name }}
                                            <hr>
                                            @if ($turn->rolls->count())
                                                <h3>Rolls</h3>
                                                @foreach ($turn->rolls as $roll)
                                                    @foreach ($roll->dice as $dice)
                                                        <p>{{ 'Dice ' . $loop->iteration . ': ' . $dice }}</p>
                                                    @endforeach
                                                    <br>
                                                @endforeach
                                            @endif
                                            <hr>
                                            @if ($turn->transactions->count())    
                                                @foreach ($turn->transactions as $transaction)
                                                    {{ $transaction->status }}
                                                    <hr>
                                                    @if ($transaction->items->count())
                                                        @foreach ($transaction->items as $item)
                                                            {{-- {{ $item->type }} <br> --}}
                                                            {{ $item->amount }} <br>
                                                            {{ $item->item->title }} <br>
                                                            From: {{ $item->fromPlayer->name }} <br>
                                                            To: {{ $item->toPlayer->name }} <br>
                                                        @endforeach
                                                    @endif
                                                @endforeach
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
