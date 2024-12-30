<div class="text-center dark:text-white">
    <h1>{{ $count }}</h1>
 
    <button wire:click="increment">+</button>
 
    <button wire:click="decrement">-</button>
</div>

@script
<script>
    Echo.private(`play.123`)
        .listen('DiceRolled', (e) => {
            // console.log(e.order.name);
            console.log('dice rolled');
        });
</script>
@endscript