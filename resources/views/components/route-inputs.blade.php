@props([
    'pickupLabel' => __('Pickup'),
    'dropLabel' => __('Drop-off'),
    'pickupPlaceholder' => __('Search pickup point'),
    'dropPlaceholder' => __('Search destination'),
    'swap' => true,
    'stops' => false,
    // 'pickup' or 'drop' renders that end alone — the parcel wizard keeps the
    // two locations in separate sender and receiver sections.
    'only' => null,
])

@php
    $ends = [
        ['pickup', $pickupLabel, $pickupPlaceholder, 'dot-pickup'],
        ['drop', $dropLabel, $dropPlaceholder, 'dot-drop'],
    ];

    if ($only) {
        $ends = array_values(array_filter($ends, fn ($end) => $end[0] === $only));
    }
@endphp

{{-- Each end owns its own search box, so the rider is never guessing which
     field the one shared input is currently editing. --}}
<div class="space-y-2">
    @foreach ($ends as [$end, $labelText, $placeholder, $dot])

        <div class="relative">
            <label for="{{ $end }}-search" class="mb-1 flex items-center gap-2">
                <span class="{{ $dot }} !mt-0"></span>
                <span class="eyebrow">{{ $labelText }}</span>
            </label>

            <div class="relative">
                {{-- Shows the chosen address until the rider types over it. --}}
                <input id="{{ $end }}-search" type="search" autocomplete="off"
                       class="w-full pe-9"
                       placeholder="{{ $placeholder }}"
                       x-model="{{ $end }}.query"
                       @input="onQueryInput('{{ $end }}')"
                       @focus="active = '{{ $end }}'"
                       :placeholder="{{ $end }}.address || '{{ $placeholder }}'"
                       :class="{{ $end }}.address && !{{ $end }}.query ? 'placeholder:text-[var(--text)] placeholder:font-medium' : ''">

                {{-- Clear, once this end has a point --}}
                <button type="button"
                        x-show="{{ $end }}.lat !== null || {{ $end }}.query"
                        x-cloak
                        @click="clear('{{ $end }}')"
                        class="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-lg faint transition-colors hover:muted-surface"
                        aria-label="{{ __('Clear :field', ['field' => strtolower($labelText)]) }}">&times;</button>
            </div>

            {{-- Results for this end only --}}
            <div x-show="{{ $end }}.results.length" x-cloak
                 class="card absolute inset-x-0 top-full z-30 mt-1.5 max-h-56 overflow-auto p-1.5"
                 style="box-shadow: var(--shadow-lg);">
                <template x-for="result in {{ $end }}.results" :key="result.place_id">
                    <button type="button" @click="choose('{{ $end }}', result)"
                            class="block w-full rounded-lg px-3 py-2 text-start text-sm transition-colors hover:muted-surface"
                            x-text="result.display_name"></button>
                </template>
            </div>
        </div>

        @if ($end === 'pickup' && $stops)
            {{-- Intermediate stops (max 3), between pickup and drop-off. --}}
            <template x-for="(stop, index) in stops" :key="index">
                <div class="relative">
                    <label class="mb-1 flex items-center gap-2">
                        <span class="mt-0 h-2.5 w-2.5 shrink-0 rounded-full"
                              style="background-color: #f79009; box-shadow: 0 0 0 3px rgb(247 144 9 / 0.18);"></span>
                        <span class="eyebrow"
                              x-text="'Stop ' + (index + 1)"></span>
                    </label>

                    <div class="relative">
                        <input type="search" autocomplete="off" class="w-full pe-9"
                               x-model="stop.query" @input="onStopQuery(index)"
                               :placeholder="stop.address || '{{ __('Search a stop') }}'"
                               :class="stop.address && !stop.query ? 'placeholder:text-[var(--text)] placeholder:font-medium' : ''">
                        <button type="button" @click="removeStop(index)"
                                class="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-lg faint transition-colors hover:muted-surface"
                                aria-label="{{ __('Remove stop') }}">&times;</button>
                    </div>

                    <div x-show="stop.results.length" x-cloak
                         class="card absolute inset-x-0 top-full z-30 mt-1.5 max-h-56 overflow-auto p-1.5"
                         style="box-shadow: var(--shadow-lg);">
                        <template x-for="result in stop.results" :key="result.place_id">
                            <button type="button" @click="chooseStop(index, result)"
                                    class="block w-full rounded-lg px-3 py-2 text-start text-sm transition-colors hover:muted-surface"
                                    x-text="result.display_name"></button>
                        </template>
                    </div>
                </div>
            </template>
        @endif

        @if ($end === 'pickup' && $swap && ! $only)
            <div class="flex items-center gap-2">
                @if ($stops)
                    <button type="button" @click="addStop()" x-show="stops.length < 3"
                            class="rounded-lg border border-dashed px-2.5 py-1 text-xs font-semibold faint transition-colors hover:muted-surface"
                            style="border-color: var(--border-strong);">+ {{ __('Add stop') }}</button>
                @endif
                <div class="h-px flex-1" style="background: var(--border);"></div>
                <button type="button" @click="swap()"
                        class="grid h-7 w-7 place-items-center rounded-lg border border-token text-xs transition-colors hover:muted-surface"
                        title="{{ __('Swap pickup and drop-off') }}">⇅</button>
                <div class="h-px flex-1" style="background: var(--border);"></div>
            </div>
        @endif
    @endforeach
</div>
