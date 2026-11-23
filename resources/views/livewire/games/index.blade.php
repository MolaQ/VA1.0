<div>
    <x-ui.page-header eyebrow="{{ __('Video analysis') }}" title="{{ __('Matches') }}" subtitle="{{ __('Every match with a YouTube recording and stats linked to the video.') }}">
        @if ($canCreate)
            <x-slot:actions><x-ui.button :href="route('games.create')" icon="plus">{{ __('Add match') }}</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row">
        <input type="search" wire:model.live.debounce.300ms="search" class="input sm:max-w-xs" placeholder="{{ __('Search opponent…') }}">
        <select wire:model.live="team" class="input sm:max-w-xs">
            <option value="">{{ __('All teams') }}</option>
            @foreach ($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </select>
    </div>

    @if ($games->isEmpty())
        <x-ui.empty icon="video" title="{{ __('No matches yet') }}" text="{{ __('Paste a YouTube link and the AI analysis will do the rest.') }}">
            @if ($canCreate)<x-ui.button :href="route('games.create')" icon="plus" class="mt-2">{{ __('Add match') }}</x-ui.button>@endif
        </x-ui.empty>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($games as $game)
                <x-game-card :game="$game" wire:key="game-{{ $game->id }}" style="animation-delay: {{ $loop->index * 40 }}ms" />
            @endforeach
        </div>
        <div class="mt-6">{{ $games->links() }}</div>
    @endif
</div>
