@props([
    // Server-rendered: the driver array straight from the API.
    'driver' => null,
    // Live screens: the JS expression holding the driver (e.g. "driver"),
    // since the trackers receive it from polling rather than from Blade.
    'expression' => null,
])

{{--
    The driver's average rating, shown beside their name.

    conducteur.average_rating is a varchar, so it arrives as a string like
    "4.5"; a driver with no reviews yet has null and gets nothing rather than
    a misleading zero.
--}}
@if ($expression)
    <template x-if="Number({{ $expression }}?.average_rating ?? 0) > 0">
        <span class="inline-flex items-center gap-1 whitespace-nowrap text-xs font-bold">
            <span class="text-amber-400">★</span>
            <span x-text="Number({{ $expression }}.average_rating).toFixed(1)"></span>
            <template x-if="Number({{ $expression }}?.review_count ?? 0) > 0">
                <span class="faint font-medium" x-text="'(' + {{ $expression }}.review_count + ')'"></span>
            </template>
        </span>
    </template>
@elseif ((float) ($driver['average_rating'] ?? 0) > 0)
    <span class="inline-flex items-center gap-1 whitespace-nowrap text-xs font-bold">
        <span class="text-amber-400">★</span>
        <span>{{ number_format((float) $driver['average_rating'], 1) }}</span>
        @if ((int) ($driver['review_count'] ?? 0) > 0)
            <span class="faint font-medium">({{ $driver['review_count'] }})</span>
        @endif
    </span>
@endif
