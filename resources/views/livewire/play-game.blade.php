<div>
    <div class="flex items-center gap-4 mb-4">
        <button
            wire:click="roll"
            @class(['px-4','py-2','rounded','text-white','disabled:opacity-50',
                'bg-blue-600 hover:bg-blue-700' => optional($game->current_player)->user_id === auth()->id(),
                'bg-gray-400 cursor-not-allowed' => optional($game->current_player)->user_id !== auth()->id(),
            ])
            @disabled(optional($game->current_player)->user_id !== auth()->id())
        >
            Roll
        </button>
        <div class="text-sm text-gray-600 dark:text-gray-300">
            @if ($game->current_player)
                <span>Current turn: 
                    <span class="font-semibold">
                        <span class="bg-{{ $game->current_player->color }}-700 inline-block size-4 rounded-full align-middle"></span>
                        {{ $game->current_player->name }}
                    </span>
                </span>
            @endif
        </div>
        <div class="flex gap-2 text-lg">
            @foreach($dice as $value)
                <span class="inline-block w-12 h-12 leading-[3rem] text-center bg-gray-200 text-gray-800 rounded">{{ $value }}</span>
            @endforeach
        </div>
    </div>

    @if($offerPropertyId)
        @php
            $buyer = $game->players->firstWhere('user_id', auth()->id());
            $offerProperty = $game->board->properties->firstWhere('id', $offerPropertyId);
            $price = (int) ($offerProperty->price ?? 0);
            $canAffordPurchase = $buyer && $offerProperty && (int) ($buyer->cash ?? 0) >= $price;
        @endphp
        <div class="mb-4 p-4 bg-yellow-50 text-yellow-900 rounded">
            <p>You may purchase: <b>{{ $offerProperty?->title }}</b> for <b>${{ $price }}</b>.</p>
            <div class="mt-2 flex gap-2 items-center">
                <button
                    wire:click="buyProperty"
                    @class([
                        'px-3','py-1','rounded','text-white','disabled:opacity-50',
                        'bg-green-600 hover:bg-green-700' => $canAffordPurchase,
                        'bg-gray-400 cursor-not-allowed' => !$canAffordPurchase,
                    ])
                    @disabled(!$canAffordPurchase)
                    @if(!$canAffordPurchase) title="Insufficient funds" @endif
                >
                    Buy
                </button>
                <button wire:click="$set('offerPropertyId', null)" class="px-3 py-1 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">Skip</button>
                @if(!$canAffordPurchase)
                    <span class="text-sm text-yellow-800">You need ${{ $price }} but have ${{ (int) ($buyer->cash ?? 0) }}.</span>
                @endif
            </div>
        </div>
    @endif

    <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-900/60 rounded border border-gray-200 dark:border-gray-700">
        <h3 class="font-semibold mb-2 text-gray-900 dark:text-gray-100">Trade</h3>
        <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-end">
            <div>
                <label class="block text-sm text-gray-700 dark:text-gray-300">To Player</label>
                <select wire:model="tradeToPlayerId" class="border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600">
                    <option value="">Select player</option>
                    @foreach ($game->players as $p)
                        @if ($p->user_id !== auth()->id())
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-700 dark:text-gray-300">Cash</label>
                <input type="number" min="0" step="1" wire:model="tradeCashAmount" class="border rounded p-1 w-28 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600" />
            </div>
            <div>
                <label class="block text-sm text-gray-700 dark:text-gray-300">Property</label>
                <select wire:model="tradePropertyId" class="border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600">
                    <option value="">None</option>
                    @php
                        $me = $game->players->firstWhere('user_id', auth()->id());
                        $myPropertyAssetItems = $me?->assets->where('itemable_type', 'App\\Models\\Property')->pluck('itemable');
                    @endphp
                    @if ($myPropertyAssetItems)
                        @foreach ($myPropertyAssetItems as $prop)
                            <option value="{{ $prop->id }}">{{ $prop->title }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
            <div>
                <button wire:click="executeTrade" class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600">Execute Trade</button>
            </div>
        </div>
    </div>

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
                    @if (is_array($space) && ($space['type'] ?? null) === 'ActionSpace')
                        <div class="border text-xs">
                            <p>&nbsp;</p>
                            <div class="p-4">
                                <p>{{ $space['title'] }}</p>
                                <p class="mt-4">{{ $space['effect'] }}</p>
                            </div>
                            <div class="px-4 py-2">
                                <div class="flex -space-x-1">
                                    @php $pos = $index + ($sideIndex * 10); @endphp
                                    @if ($game->players->where('position', $pos)->count())
                                        @foreach ($game->players->where('position', $pos) as $player)
                                            <span class="{{ $pieceColors[$player->color] }} inline-block size-6 rounded-full ring-1 ring-white"></span>
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
                                    @php $pos = $index + ($sideIndex * 10); @endphp
                                    @if ($game->players->where('position', $pos)->count())
                                        @foreach ($game->players->where('position', $pos) as $player)
                                            <span class="{{ $pieceColors[$player->color] }} inline-block size-6 rounded-full ring-1 ring-white"></span>
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
                <h3>Players State</h3>
                @foreach ($game->players as $player)
                    <div class="mt-4">
                        <span class="bg-{{ $player->color }}-700 inline-block size-6 rounded-full ring-1 ring-white"></span>
                        <p>{{ $player->name }}</p>
                        <p>${{ $player->cash }}</p>
                        <h4 class="font-bold mt-4">Properties</h4>
                        @foreach ($player->assets->where('itemable_type', 'App\\Models\\Property') as $asset)
                            {{ $asset->itemable->title }}
                        @endforeach
                        @if ($player->assets->where('itemable_type', 'App\\Models\\Card')->count())
                            <h4 class="font-bold mt-4">Cards</h4>
                            @foreach ($player->assets->where('itemable_type', 'App\\Models\\Card') as $asset)
                                {{ $asset->itemable->message }}
                            @endforeach
                        @endif
                    </div>
                @endforeach
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
                                    @foreach ($roll->dice as $value)
                                        <p>{{ 'Dice ' . $loop->iteration . ': ' . $value }}</p>
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


