<div class="text-center dark:text-white">
    <h1>{{ $count }}</h1>
 
    <button wire:click="increment">+</button>
 
    <button wire:click="decrement">-</button>

    <hr>

    <button @click="$dispatch('dice-rolled', { title: 'Post Title' })">test</button>
    {{-- <button @click="alert('hi')">test2</button> --}}

    {{-- <button wire:click="broadcastThing">test</button> --}}
</div>

@script
<script>
    document.addEventListener('livewire:init', () => {
        console.log('hi from init');

        // Runs after Livewire is loaded but before it's initialized
        // on the page...
    })
    console.log('hi from counter');
    
</script>
@endscript