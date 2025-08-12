<div>
    <div class="flex items-center gap-4 mb-4">
        @php
            $me = $game->players->firstWhere('user_id', auth()->id());
            $isMyTurn = optional($game->current_player)->id === optional($me)->id;
            $lastRolledTurn = $game->turns->filter(fn($t) => $t->rolls->isNotEmpty())->first();
            $isAwaitingDecision = $isMyTurn && $lastRolledTurn && (int) $lastRolledTurn->player_id === (int) optional($me)->id && $lastRolledTurn->status === 'awaiting_decision';
            $canRoll = $isMyTurn && !$isAwaitingDecision;
            $isWaiting = $game->status === 'waiting';
        @endphp

        @unless ($isWaiting)
            <button
                wire:click="roll"
                @class(['px-4','py-2','rounded','text-white','disabled:opacity-50',
                    'bg-blue-600 hover:bg-blue-700' => $canRoll && $game->status !== 'completed',
                    'bg-gray-400 cursor-not-allowed' => !$canRoll || $game->status === 'completed',
                ])
                @disabled(!$canRoll || $game->status === 'completed')
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
        @endunless

        @php $me = $game->players->firstWhere('user_id', auth()->id()); $iAmBankrupt = (bool) optional($me)->is_bankrupt; @endphp
        <div class="ml-auto flex items-center gap-2">
            @php
                $me = $game->players->firstWhere('user_id', auth()->id());
                $canJoin = !$me && $game->status === 'waiting';
                $isCreatorOrFirst = ($game->created_by === auth()->id()) || (optional($game->players->sortBy('id')->first())->user_id === auth()->id());
                $canStart = $game->status === 'waiting' && $me && $isCreatorOrFirst && $game->players->count() >= 2;
            @endphp
            @if ($canJoin)
                <button
                    wire:click="joinGame"
                    class="px-3 py-1 rounded text-white bg-emerald-600 hover:bg-emerald-700"
                >Join Game</button>
            @endif
            @if ($canStart)
                <button
                    wire:click="startGame"
                    class="px-3 py-1 rounded text-white bg-indigo-600 hover:bg-indigo-700"
                >Start Game</button>
            @endif
            @if ($me)
                <button
                    x-data
                    x-on:click="if (confirm('Are you sure you want to leave this game? Your assets will be liquidated and you will be removed from play.')) { $wire.leaveGame() }"
                    @class(['px-3','py-1','rounded','text-white','disabled:opacity-50',
                        'bg-red-600 hover:bg-red-700' => !$iAmBankrupt,
                        'bg-gray-400 cursor-not-allowed' => $iAmBankrupt,
                    ])
                    @disabled($iAmBankrupt)
                >Leave Game</button>
            @endif
        </div>

        @unless ($isWaiting)
            <div class="flex gap-2 text-lg">
                @foreach($dice as $value)
                    <span class="inline-block w-12 h-12 leading-[3rem] text-center bg-gray-200 text-gray-800 rounded">{{ $value }}</span>
                @endforeach
            </div>
        @endunless
        {{-- No manual End Turn: turns auto-complete unless awaiting a decision --}}
    </div>

    @unless ($game->status === 'waiting')
    @if($landingMessage)
        <div class="mb-4 p-3 rounded bg-indigo-50 text-indigo-900 border border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-100 dark:border-indigo-800">
            {{ $landingMessage }}
        </div>
    @endif

    @if ($error)
        <div class="mb-4 p-3 rounded bg-amber-50 text-amber-900 border border-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:border-amber-800">
            {{ $error }}
        </div>
    @endif
    @endunless

    @php
        $me = $game->players->firstWhere('user_id', auth()->id());
        $isMyTurn = optional($game->current_player)->id === optional($me)->id;
        $lastRolledTurn = $game->turns->filter(fn($t) => $t->rolls->isNotEmpty())->first();
        $isAwaitingDecision = $isMyTurn && $lastRolledTurn && (int) $lastRolledTurn->player_id === (int) optional($me)->id && $lastRolledTurn->status === 'awaiting_decision';
    @endphp

    @if ($game->status !== 'waiting' && $isAwaitingDecision && $pendingPaymentAmount)
        <div class="mb-4 p-3 rounded bg-amber-50 text-amber-900 border border-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:border-amber-800">
            <div class="flex items-center justify-between">
                <div>You owe ${{ (int) $pendingPaymentAmount }}. Sell units or mortgage properties, then click Pay Now or declare bankruptcy.</div>
                <div class="flex gap-2">
                    <button
                        wire:click="payNow"
                        class="px-3 py-1 rounded bg-emerald-600 text-white disabled:opacity-50"
                        @disabled(((int) optional($me)->cash) < (int) $pendingPaymentAmount)
                    >Pay Now</button>
                    <button
                        wire:click="declareBankruptcy"
                        class="px-3 py-1 rounded bg-rose-700 text-white"
                    >Declare Bankruptcy</button>
                </div>
            </div>
        </div>
    @endif

    @php
        $me = $game->players->firstWhere('user_id', auth()->id());
        $isMyTurn = optional($game->current_player)->id === optional($me)->id;
        $inJoint = (bool) ($me->in_joint ?? false);
        $attempts = (int) ($me->joint_attempts ?? 0);
        $canPayToLeave = $isMyTurn && $inJoint && $attempts < 2 && (int) ($me->cash ?? 0) >= 50;
    @endphp

    @if ($game->status !== 'waiting' && $inJoint)
        <div class="mb-4 p-3 rounded bg-yellow-50 text-yellow-900 border border-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-100 dark:border-yellow-800">
            You're in The Joint. Attempts so far: {{ $attempts }}.
            @if ($canPayToLeave)
                <button wire:click="payToLeaveJoint" class="ml-2 px-3 py-1 bg-emerald-600 text-white rounded hover:bg-emerald-700">Pay $50 to Leave</button>
            @endif
        </div>
    @endif

    @if($game->status !== 'waiting' && $offerPropertyId)
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
                        'bg-green-600 hover:bg-green-700' => $canAffordPurchase && $game->status !== 'completed',
                        'bg-gray-400 cursor-not-allowed' => !$canAffordPurchase || $game->status === 'completed',
                    ])
                    @disabled(!$canAffordPurchase || $game->status === 'completed')
                    @if(!$canAffordPurchase) title="Insufficient funds" @endif
                >
                    Buy
                </button>
                <button wire:click="skipPurchase" @class(['px-3','py-1','rounded', 'bg-gray-200 text-gray-800 hover:bg-gray-300' => $game->status !== 'completed', 'bg-gray-300 cursor-not-allowed' => $game->status === 'completed']) @disabled($game->status === 'completed')>Skip</button>
                @if(!$canAffordPurchase)
                    <span class="text-sm text-yellow-800">You need ${{ $price }} but have ${{ (int) ($buyer->cash ?? 0) }}.</span>
                @endif
            </div>
        </div>
    @endif

    @if ($game->status !== 'waiting')
    <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-900/60 rounded border border-gray-200 dark:border-gray-700">
        <h3 class="font-semibold mb-2 text-gray-900 dark:text-gray-100">Trade</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm text-gray-700 dark:text-gray-300">To Player</label>
                <select wire:model="tradeToPlayerId" class="w-full border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600">
                    <option value="">Select player</option>
                    @foreach ($game->players as $p)
                        @if ($p->user_id !== auth()->id())
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100">You Give</label>
                <div class="mt-1 space-y-2">
                    <div>
                        <label class="block text-xs text-gray-700 dark:text-gray-300">Cash</label>
                        <input type="number" min="0" step="1" wire:model="tradeGiveCashAmount" class="w-full border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 dark:text-gray-300">Properties</label>
                        @php
                            $me = $game->players->firstWhere('user_id', auth()->id());
                            $ownedProps = $game->board->properties->filter(fn($prop) => optional($prop->item)->player_id === optional($me)->id);
                            $colorsWithUnits = $ownedProps
                                ->groupBy('color')
                                ->filter(function($propsInColor){
                                    return (int) $propsInColor->sum(fn($p) => (int) optional($p->item)->units) > 0;
                                })
                                ->keys()
                                ->toArray();
                            $myProps = $me?->assets
                                ->where('itemable_type', 'App\\Models\\Property')
                                ->pluck('itemable')
                                ->filter()
                                ->reject(fn($p) => in_array($p->color, $colorsWithUnits, true))
                                ->sortBy('title');
                        @endphp
                        <select multiple size="5" wire:model="tradeGivePropertyIds" class="w-full border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600">
                            @if ($myProps)
                                @foreach ($myProps as $prop)
                                    <option value="{{ $prop->id }}">{{ $prop->title }}</option>
                                @endforeach
                            @endif
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100">You Request</label>
                <div class="mt-1 space-y-2">
                    <div>
                        <label class="block text-xs text-gray-700 dark:text-gray-300">Cash</label>
                        <input type="number" min="0" step="1" wire:model="tradeRequestCashAmount" class="w-full border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 dark:text-gray-300">Properties</label>
                        @php
                            $othersProps = collect();
                            if ($game->players && $me) {
                                $othersProps = $game->players
                                    ->reject(fn($p) => $p->id === $me->id)
                                    ->flatMap(function($p) use ($game) {
                                        // Exclude any property whose owner has units on that color
                                        $ownerOwnedProps = $game->board->properties->filter(fn($prop) => optional($prop->item)->player_id === $p->id);
                                        $colorsWithUnits = $ownerOwnedProps
                                            ->groupBy('color')
                                            ->filter(fn($propsInColor) => (int) $propsInColor->sum(fn($x) => (int) optional($x->item)->units) > 0)
                                            ->keys()
                                            ->toArray();
                                        return $p->assets
                                            ->where('itemable_type', 'App\\Models\\Property')
                                            ->pluck('itemable')
                                            ->filter()
                                            ->reject(fn($prop) => in_array($prop->color, $colorsWithUnits, true));
                                    })
                                    ->filter()
                                    ->sortBy('title');
                            }
                        @endphp
                        <select multiple size="5" wire:model="tradeRequestPropertyIds" class="w-full border rounded p-1 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600">
                            @foreach ($othersProps as $prop)
                                <option value="{{ $prop->id }}">{{ $prop->title }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <button wire:click="createTradeRequest" class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600">Send Trade Request</button>
        </div>
    </div>
    @endif

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

    @if($game->status !== 'waiting' && $pendingTradesForMe->count())
        <div class="mb-6 p-4 bg-blue-50 text-blue-900 rounded border border-blue-200 dark:bg-blue-900/30 dark:text-blue-100 dark:border-blue-800">
            <h3 class="font-semibold mb-2">Pending Trades</h3>
            <ul class="space-y-3">
                @foreach ($pendingTradesForMe as $tx)
                    @php
                        $items = $tx->items;
                        $fromId = optional($tx->turn)->player_id;
                        $fromPlayer = $game->players->firstWhere('id', $fromId);
                        $isRecipient = $items->contains(fn($i) => (int) ($i->to_player_id ?? 0) === (int) optional($me)->id);
                        $isMyTurn = optional($game->current_player)->id === optional($me)->id;
                        $isInitiator = (int) optional($tx->turn)->player_id === (int) optional($me)->id;
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
                                            @php
                                                $propTitle = optional($i->item)->title ?? optional($game->board->properties->firstWhere('id', $i->item_id))->title;
                                            @endphp
                                            Property: {{ $propTitle ?? 'Property' }} from {{ $game->players->firstWhere('id', $i->from_player_id)?->name }} to {{ $game->players->firstWhere('id', $i->to_player_id)?->name }}
                                        @endif
                                        <br>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    wire:click="approveTrade({{ $tx->id }})"
                                    @class(['px-3','py-1','rounded','text-white','disabled:opacity-50',
                                        'bg-green-600 hover:bg-green-700' => $isRecipient && $isMyTurn && !$isInitiator,
                                        'bg-gray-400 cursor-not-allowed' => !($isRecipient && $isMyTurn && !$isInitiator),
                                    ])
                                    @disabled(!($isRecipient && $isMyTurn && !$isInitiator))
                                    title="Only the non-initiator recipient can approve on their turn"
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
            $spacesBySide = $game->board->getSpaces($game); // 4 sides x 10 spaces each (corners included as first item per side)
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
                                    <div class="flex items-center justify-between">
                                        <div class="flex -space-x-1">
                                            @if ($game->players->where('position', $pos)->count())
                                                @foreach ($game->players->where('position', $pos) as $player)
                                                    <span class="{{ $pieceColors[$player->color] }} inline-block size-5 sm:size-6 rounded-full ring-1 ring-white"></span>
                                                @endforeach
                                            @endif
                                        </div>
                                        @php
                                            // Resolve the Eloquent Property model for this board space
                                            // Use the space instance when it's a Property to avoid re-loading unscoped relations
                                            $propModel = $space instanceof \App\Models\Property ? $space : $game->board->properties->firstWhere('id', $space['id'] ?? null);
                                            $units = (int) optional(optional($propModel)->item)->units;
                                        @endphp
                                        @if ($propModel && $units > 0)
                                            <span class="inline-flex gap-0.5">
                                                @for ($i = 0; $i < $units; $i++)
                                                    <span class="inline-block size-2.5 sm:size-3 bg-gray-700 rounded-sm"></span>
                                                @endfor
                                            </span>
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
        @if ($game->status === 'completed' && $game->winner)
            <div class="mb-4 p-3 rounded bg-emerald-50 text-emerald-900 border border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:border-emerald-800">
                Winner: <strong>{{ $game->winner->name }}</strong>
            </div>
        @endif
        <div class="flex gap-4">
            <div class="w-full sm:w-1/2">
                <h3>Players State</h3>
                @foreach ($game->players as $player)
                    <div class="mt-4">
                        <span class="bg-{{ $player->color }}-700 inline-block size-6 rounded-full ring-1 ring-white"></span>
                        <p>{{ $player->name }}</p>
                        <p>${{ $player->cash }}</p>
                        <h4 class="font-bold mt-4">Properties</h4>
                        @php
                            $propertyGroups = $player->assets
                                ->where('itemable_type', 'App\\Models\\Property')
                                ->map(fn($a) => $a->itemable)
                                ->filter()
                                ->groupBy('color');
                        @endphp
                        @forelse ($propertyGroups as $color => $props)
                            <div class="mt-2">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block size-3 rounded" style="background: {{ $color }}"></span>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $color }}</span>
                                </div>
                                <ul class="ml-5 list-disc text-sm text-gray-900 dark:text-gray-100">
                                    @php
                                        // Determine if current viewer owns full set to show unit controls
                                        $viewer = $game->players->firstWhere('user_id', auth()->id());
                                        $ownsFullSet = false;
                                        if ($viewer) {
                                            $colorProps = $game->board->properties->where('color', $color);
                                            $ownedCount = $colorProps->filter(fn($prop) => optional($prop->item)->player_id === $viewer->id)->count();
                                            $ownsFullSet = $ownedCount === $colorProps->count() && $ownedCount > 0;
                                        }
                                    @endphp
                                    @php
                                        // For even-build UI state: compute min/max units among owned properties in this color
                                        $ownedColorProps = $game->board->properties
                                            ->where('color', $color)
                                            ->filter(fn($prop) => optional($prop->item)->player_id === optional($viewer)->id)
                                            ->values();
                                        $minUnitsColor = $ownedColorProps->map(fn($prop) => (int) optional($prop->item)->units)->min();
                                        $maxUnitsColor = $ownedColorProps->map(fn($prop) => (int) optional($prop->item)->units)->max();
                                    @endphp
                                    @foreach ($props as $p)
                                        <li class="flex items-center gap-2">
                                            <span>{{ $p->title }}</span>
                                            @php $units = (int) optional($p->item)->units; @endphp
                                            @if ($units > 0)
                                                <span class="inline-flex gap-0.5">
                                                    @for ($i = 0; $i < $units; $i++)
                                                        <span class="inline-block size-3 bg-gray-700 rounded-sm"></span>
                                                    @endfor
                                                </span>
                                            @endif
                                            @if ($game->status !== 'waiting' && $viewer && $viewer->id === optional($p->item)->player_id && $ownsFullSet && $p->supportsUnits())
                                                <span class="ml-2 inline-flex items-center gap-1">
                                                    <button
                                                        class="px-1.5 py-0.5 text-xs rounded bg-emerald-600 text-white disabled:opacity-50"
                                                        wire:click="buyUnit({{ $p->id }})"
                                                        @disabled(
                                                            !$isMyTurn || !$ownsFullSet ||
                                                            (int) optional($p->item)->units >= 5 ||
                                                            (int) $viewer->cash < (int) ($p->unit_price ?? 0) ||
                                                             (int) optional($p->item)->units > (int) $minUnitsColor ||
                                                             // Disable if property does not support units (missing rent tiers or unit price)
                                                             (is_callable([$p, 'supportsUnits']) ? !$p->supportsUnits() : false)
                                                        )
                                                          @if(!is_null($p->unit_price)) title="Buy unit for ${{ (int) $p->unit_price }}" @endif
                                                    >+ Unit</button>
                                                     <button
                                                        class="px-1.5 py-0.5 text-xs rounded bg-rose-600 text-white disabled:opacity-50"
                                                        wire:click="sellUnit({{ $p->id }})"
                                                        @disabled(
                                                            !$isMyTurn || $game->status === 'completed' ||
                                                            (int) optional($p->item)->units <= 0 ||
                                                            (int) optional($p->item)->units < (int) $maxUnitsColor
                                                        )
                                                          @if(!is_null($p->unit_price)) title="Sell unit for ${{ (int) floor(((int) $p->unit_price) / 2) }}" @endif
                                                    >- Unit</button>
                                                    @php $isMortgaged = (bool) optional($p->item)->is_mortgaged; @endphp
                                                    <button
                                                        class="px-1.5 py-0.5 text-xs rounded bg-yellow-600 text-white disabled:opacity-50"
                                                        wire:click="mortgageProperty({{ $p->id }})"
                                                        @disabled(!$isMyTurn || $game->status === 'completed' || $isMortgaged || (int) optional($p->item)->units > 0)
                                                        title="Mortgage for ${{ (int) ($p->mortgage_price ?? 0) }}"
                                                    >Mortgage</button>
                                                    <button
                                                        class="px-1.5 py-0.5 text-xs rounded bg-blue-600 text-white disabled:opacity-50"
                                                        wire:click="unmortgageProperty({{ $p->id }})"
                                                        @disabled(!$isMyTurn || $game->status === 'completed' || !$isMortgaged || (int) optional($me)->cash < (int) ($p->unmortgage_price ?? 0))
                                                        title="Unmortgage for ${{ (int) ($p->unmortgage_price ?? 0) }}"
                                                    >Unmortgage</button>
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">None</p>
                        @endforelse
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
                <ol class="list-decimal list-inside" reversed start="{{ $game->turns->count() }}">
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


