@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null])
<div {{ $attributes->merge(['class' => 'block']) }}>
    @if ($label)<label @if ($for) for="{{ $for }}" @endif class="label">{{ $label }}</label>@endif
    {{ $slot }}
    @if ($error)
        @error($error)<p class="mt-1.5 text-xs font-medium text-danger-400">{{ $message }}</p>@enderror
    @endif
    @if ($hint)<p class="mt-1.5 text-xs text-subtle">{{ $hint }}</p>@endif
</div>
