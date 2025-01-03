<div class="text-center dark:text-white">
    <h1>{{ $count }}</h1>
 
    <button wire:click="increment">+</button>
 
    <button wire:click="decrement">-</button>

    <hr>

    <button wire:click="broadcastThing">test</button>
</div>

@script
<script>
    console.log('hi from counter');
    Echo.private(`play.123`)
        .listen('DiceRolled', (e) => {
            // console.log(e.order.name);
            console.log('dice rolled');
        });
    Echo.join(`chat.123`)
        .here((users) => {
            console.log(users);
        })
        .joining((user) => {
            console.log(user.name);
        })
        .leaving((user) => {
            console.log(user.name);
        })
        .error((error) => {
            console.error(error);
        });
</script>
@endscript