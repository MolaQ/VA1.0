<div class="mx-auto max-w-5xl">
    <x-ui.page-header :eyebrow="$game ? __('Editing') : __('New match')" :title="$game ? $game->title() : __('Add match')" subtitle="{{ __('Paste a YouTube link: AI analysis recognises player actions and computes the stats.') }}" />

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-5">
        <x-ui.card class="lg:col-span-3" title="{{ __('Match details') }}">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.field label="{{ __('Team') }}" for="team_id" error="team_id">
                    <select id="team_id" wire:model="team_id" class="input">
                        @foreach ($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}{{ $t->season ? ' · '.$t->season : '' }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="{{ __('Opponent') }}" for="opponent" error="opponent">
                    <input id="opponent" wire:model="opponent" class="input" placeholder="{{ __('e.g. Volley Team Kraków') }}" required>
                </x-ui.field>
                <x-ui.field label="{{ __('Date and time') }}" for="played_at" error="played_at">
                    <input id="played_at" type="datetime-local" wire:model="played_at" class="input" required>
                </x-ui.field>
                <x-ui.field label="{{ __('Venue') }}" for="venue" error="venue">
                    <input id="venue" wire:model="venue" class="input" placeholder="{{ __('Sports hall…') }}">
                </x-ui.field>
                <div class="sm:col-span-2">
                    <span class="label">{{ __('Host') }}</span>
                    <div class="grid grid-cols-2 gap-2 rounded-xl bg-surface-2 p-1">
                        <button type="button" wire:click="$set('is_home', true)" @class(['rounded-lg py-2 text-sm font-semibold transition', 'bg-surface-3 text-fg shadow' => $is_home, 'text-muted' => ! $is_home])>{{ __('Home') }}</button>
                        <button type="button" wire:click="$set('is_home', false)" @class(['rounded-lg py-2 text-sm font-semibold transition', 'bg-surface-3 text-fg shadow' => ! $is_home, 'text-muted' => $is_home])>{{ __('Away') }}</button>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="lg:col-span-2" title="{{ __('Recording') }}">
            <x-ui.field label="{{ __('YouTube link') }}" for="youtube_url" error="youtube_url">
                <input id="youtube_url" wire:model.live.debounce.400ms="youtube_url" class="input" placeholder="https://www.youtube.com/watch?v=…">
            </x-ui.field>
            <div class="mt-4 aspect-video overflow-hidden rounded-xl border border-line bg-surface-3">
                @if ($this->previewId)
                    <img src="{{ \App\Support\YouTube::thumbnail($this->previewId) }}" alt="{{ __('Recording preview') }}" class="size-full object-cover animate-fade-up">
                @else
                    <div class="grid size-full place-items-center text-sm text-subtle">{{ __('The preview appears after pasting a link') }}</div>
                @endif
            </div>
            <label class="mt-4 flex items-start gap-3 rounded-xl border border-line bg-surface-2 p-3 text-sm">
                <input type="checkbox" wire:model="analyze" class="mt-0.5 size-4 accent-brand-500">
                <span><span class="font-semibold">{{ __('Run AI analysis') }}</span><br><span class="text-muted">{{ __('Stats will stream in live during the analysis.') }}</span></span>
            </label>
        </x-ui.card>

        <div class="flex justify-end gap-2 lg:col-span-5">
            <x-ui.button variant="ghost" :href="$game ? route('games.show', $game) : route('games.index')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" icon="check" wire:loading.attr="disabled">{{ __('Save match') }}</x-ui.button>
        </div>
    </form>
</div>
