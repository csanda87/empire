<x-layouts.app>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Games
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <section>
                <header>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Create Game
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Create a game and invite your friends to play.
                    </p>
                </header>
                <form method="post" action="/games" class="mt-6 space-y-6">
                    @csrf
                    <div>
                        <x-input-label for="invite_code" value="Invite Code" />
                        <x-text-input id="invite_code" name="invite_code" type="text" class="mt-1 block w-full" :value="old('invite_code', strtoupper(str()->random(3)))" required autofocus />
                        <x-input-error class="mt-2" :messages="$errors->get('invite_code')" />
                    </div>
                    <div>
                        <x-primary-button>Create</x-primary-button>
                    </div>
                </form>
            </section>            
        </div>
    </div>
</x-layouts.app>
