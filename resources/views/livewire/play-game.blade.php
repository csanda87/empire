<div>
    <div class="flex items-center gap-4 mb-4">
        @php
            $me = $game->players->firstWhere('user_id', auth()->id());
            $isMyTurn = optional($game->current_player)->id === optional($me)->id;
            $lastRolledTurn = $game->turns->filter(fn($t) => $t->rolls->isNotEmpty())->sortByDesc('id')->first();
            $isAwaitingDecision = $isMyTurn && $lastRolledTurn && (int) $lastRolledTurn->player_id === (int) optional($me)->id && $lastRolledTurn->status === 'awaiting_decision';
            $canRoll = $isMyTurn && !$isAwaitingDecision;
        @endphp
        <button
            wire:click="roll"
            @class(['px-4','py-2','rounded','text-white','disabled:opacity-50',
                'bg-blue-600 hover:bg-blue-700' => $canRoll,
                'bg-gray-400 cursor-not-allowed' => !$canRoll,
            ])
            @disabled(!$canRoll)
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
        {{-- No manual End Turn: turns auto-complete unless awaiting a decision --}}
    </div>

    @if($landingMessage)
        <div class="mb-4 p-3 rounded bg-indigo-50 text-indigo-900 border border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-100 dark:border-indigo-800">
            {{ $landingMessage }}
        </div>
    @endif

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
                <button wire:click="skipPurchase" class="px-3 py-1 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">Skip</button>
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
                <button wire:click="createTradeRequest" class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600">Send Trade Request</button>
            </div>
        </div>
    </div>

    @php
        $me = $game->players->firstWhere('user_id', auth()->id());
        $pendingTradesForMe = $game->turns
            ->flatMap(fn($t) => $t->transactions)
            ->filter(function ($tx) use ($me) {
                if ($tx->status !== 'pending') return false;
                $items = $tx->items ?? collect();
                return $items->contains(fn($i) => (int) ($i->to_player_id ?? 0) === (int) optional($me)->id
                    || (int) ($i->from_player_id ?? 0) === (int) optional($me)->id);
            })
            ->values();
    @endphp

    @if($pendingTradesForMe->count())
        <div class="mb-6 p-4 bg-blue-50 text-blue-900 rounded border border-blue-200 dark:bg-blue-900/30 dark:text-blue-100 dark:border-blue-800">
            <h3 class="font-semibold mb-2">Pending Trades</h3>
            <ul class="space-y-3">
                @foreach ($pendingTradesForMe as $tx)
                    @php
                        $items = $tx->items;
                        $fromId = optional($items->first())->from_player_id;
                        $fromPlayer = $game->players->firstWhere('id', $fromId);
                        $isRecipient = $items->contains(fn($i) => (int) ($i->to_player_id ?? 0) === (int) optional($me)->id);
                        $isMyTurn = optional($game->current_player)->id === optional($me)->id;
                    @endphp
                    <li class="p-3 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div class="text-sm">
                                <div class="font-medium">From: {{ $fromPlayer?->name ?? 'Unknown' }}</div>
                                <div>
                                    @foreach ($items as $i)
                                        @if ($i->type === 'cash' && $i->amount)
                                            Cash: ${{ $i->amount }} from {{ $game->players->firstWhere('id', $i->from_player_id)?->name }} to {{ $game->players->firstWhere('id', $i->to_player_id)?->name }}
                                        @elseif ($i->type === 'property')
                                            Property: {{ $i->item?->title }} from {{ $game->players->firstWhere('id', $i->from_player_id)?->name }} to {{ $game->players->firstWhere('id', $i->to_player_id)?->name }}
                                        @endif
                                        <br>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    wire:click="approveTrade({{ $tx->id }})"
                                    @class(['px-3','py-1','rounded','text-white','disabled:opacity-50',
                                        'bg-green-600 hover:bg-green-700' => $isRecipient && $isMyTurn,
                                        'bg-gray-400 cursor-not-allowed' => !($isRecipient && $isMyTurn),
                                    ])
                                    @disabled(!($isRecipient && $isMyTurn))
                                    title="Only the recipient can approve on their turn"
                                >Approve</button>
                                <button
                                    wire:click="rejectTrade({{ $tx->id }})"
                                    class="px-3 py-1 rounded text-white bg-red-600 hover:bg-red-700"
                                >Reject</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

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
        @php
            $gridSize = 11; // 11x11 grid => 40 outer cells (perimeter)
            $spacesBySide = $game->board->getSpaces(); // 4 sides x 10 spaces each (corners included as first item per side)
            $boardGrid = [];

            for ($side = 0; $side < 4; $side++) {
                $sideSpaces = $spacesBySide[$side] ?? [];
                for ($i = 0; $i < count($sideSpaces); $i++) {
                    switch ($side) {
                        case 0: // bottom row, from right to left, excluding bottom-left corner (which starts side 1)
                            $r = $gridSize - 1; // 10
                            $c = ($gridSize - 1) - $i; // 10..1
                            break;
                        case 1: // left column, from bottom to top, excluding top-left corner (which starts side 2)
                            $r = ($gridSize - 1) - $i; // 10..1
                            $c = 0;
                            break;
                        case 2: // top row, from left to right, excluding top-right corner (which starts side 3)
                            $r = 0;
                            $c = 0 + $i; // 0..9
                            break;
                        case 3: // right column, from top to bottom, excluding bottom-right corner (which is side 0 start)
                            $r = 0 + $i; // 0..9
                            $c = $gridSize - 1; // 10
                            break;
                    }

                    $pos = $i + ($side * 10); // 0..39 linear position used by engine
                    $boardGrid[$r][$c] = ['space' => $sideSpaces[$i], 'pos' => $pos];
                }
            }
        @endphp

        <div class="grid grid-cols-11 grid-rows-11 gap-0">
            @for ($r = 0; $r < $gridSize; $r++)
                @for ($c = 0; $c < $gridSize; $c++)
                    @php $cell = $boardGrid[$r][$c] ?? null; @endphp
                    @if ($cell)
                        @php
                            $space = $cell['space'];
                            $pos = $cell['pos'];
                        @endphp

                        @if (is_array($space) && ($space['type'] ?? null) === 'ActionSpace')
                            <div class="border text-[10px] sm:text-xs aspect-square flex flex-col">
                                <p class="h-1 bg-gray-200">&nbsp;</p>
                                <div class="p-2 sm:p-3 grow">
                                    <p class="font-medium truncate" title="{{ $space['title'] }}">{{ $space['title'] }}</p>
                                    <p class="mt-2 text-gray-500 truncate" title="{{ $space['effect'] }}">{{ $space['effect'] }}</p>
                                </div>
                                <div class="px-2 sm:px-3 py-2">
                                    <div class="flex -space-x-1">
                                        @if ($game->players->where('position', $pos)->count())
                                            @foreach ($game->players->where('position', $pos) as $player)
                                                <span class="{{ $pieceColors[$player->color] }} inline-block size-5 sm:size-6 rounded-full ring-1 ring-white"></span>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="border text-[10px] sm:text-xs aspect-square flex flex-col">
                                <p style="background: {{ $space['color'] }}" class="h-1">&nbsp;</p>
                                <div class="p-2 sm:p-3 grow">
                                    <p class="font-medium truncate" title="{{ $space['title'] }}">{{ $space['title'] }}</p>
                                    <p class="mt-2 text-gray-500 truncate">{{ $space['price'] }}</p>
                                </div>
                                <div class="px-2 sm:px-3 py-2">
                                    <div class="flex -space-x-1">
                                        @if ($game->players->where('position', $pos)->count())
                                            @foreach ($game->players->where('position', $pos) as $player)
                                                <span class="{{ $pieceColors[$player->color] }} inline-block size-5 sm:size-6 rounded-full ring-1 ring-white"></span>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="border-0"></div>
                    @endif
                @endfor
            @endfor
        </div>
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


