<div x-data="diceGame">
    <button 
        {{-- @click="$wire.dispatch('dice-rolled', { inviteCode: '123' })" --}}
        wire:click="rollDice"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
    >
        Roll Dice
    </button>

    <div class="mt-4">
        {{-- <template x-if="dice.length" x-for="die in dice">
            <span x-text="die" class="inline-block w-12 h-12 text-center leading-12 bg-gray-200 text-gray-800 rounded mr-2">
            </span>
        </template> --}}
        @foreach($dice as $value)
            <span class="inline-block w-12 h-12 text-center leading-12 bg-gray-200 text-gray-800 rounded mr-2">
                {{ $value }}
            </span>
        @endforeach
    </div>
</div>

@script
<script>
    Alpine.data('diceGame', () => ({
        dice: [],

        init() {
            console.log('Alpine init started');
            console.log('Attempting to connect to channel: dice.{{ $inviteCode }}');
            
            Echo.private(`play.{{ $inviteCode }}`)
                .listen('DiceRolled', (event) => {
                    console.log('DiceRolled event received:', event.dice);
                    // this.dice = event.dice;
                    // this.$wire.dice = event.dice;
                })
                .subscribed(() => {
                    console.log('Successfully subscribed to channel');
                })
                .error((error) => {
                    console.error('Channel subscription error:', error);
                });
        }
    }));
</script>
@endscript