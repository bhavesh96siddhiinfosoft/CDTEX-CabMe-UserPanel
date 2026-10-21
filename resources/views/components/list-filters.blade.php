@props([
    'action',
    'placeholder' => null,
    'amount' => true,
])

{{--
    Search and date/amount filters for a list.

    Submits as a GET form, so the filters live in the url and survive paging
    (the paginator appends them to its links). Only the fields with something
    in them are sent, which keeps shared links short.
--}}
@php
    $has = request()->hasAny(['q', 'from', 'to', 'min', 'max'])
        && collect(request()->only(['q', 'from', 'to', 'min', 'max']))->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

<form method="GET" action="{{ $action }}" class="card-pad mb-4"
      x-data="{ open: {{ $has ? 'true' : 'false' }} }">

    <div class="flex flex-wrap items-center gap-2">
        <div class="relative min-w-0 flex-1">
            <input type="search" name="q" value="{{ request('q') }}"
                   class="w-full"
                   placeholder="{{ $placeholder ?? __('Search by order number or address') }}">
        </div>

        <button type="button" class="btn-ghost shrink-0" @click="open = !open">
            {{ __('Filters') }}
        </button>

        <button type="submit" class="btn-primary shrink-0">{{ __('Search') }}</button>
    </div>

    <div x-show="open" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="filter-from" class="label">{{ __('From date') }}</label>
            <input id="filter-from" type="date" name="from" value="{{ request('from') }}" class="w-full">
        </div>

        <div>
            <label for="filter-to" class="label">{{ __('To date') }}</label>
            <input id="filter-to" type="date" name="to" value="{{ request('to') }}" class="w-full">
        </div>

        @if ($amount)
            <div>
                <label for="filter-min" class="label">{{ __('Min amount') }}</label>
                <input id="filter-min" type="number" step="0.01" min="0" name="min"
                       value="{{ request('min') }}" class="w-full" placeholder="0">
            </div>

            <div>
                <label for="filter-max" class="label">{{ __('Max amount') }}</label>
                <input id="filter-max" type="number" step="0.01" min="0" name="max"
                       value="{{ request('max') }}" class="w-full" placeholder="{{ __('Any') }}">
            </div>
        @endif
    </div>

    @if ($has)
        <div class="mt-3">
            <a href="{{ $action }}" class="text-sm font-bold brand-text hover:opacity-70">{{ __('Clear filters') }}</a>
        </div>
    @endif
</form>
