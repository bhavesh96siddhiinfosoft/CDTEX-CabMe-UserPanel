@props(['paginator'])

{{--
    Previous / Next with the page position between them.

    The admin's list endpoints return everything at once, so the slicing
    happens in the controller (see TripController::index); this only renders
    whatever LengthAwarePaginator it is handed.
--}}
@if ($paginator && $paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'mt-4 flex items-center justify-between gap-3']) }}>
        @if ($paginator->onFirstPage())
            <span class="rounded-xl border border-token px-3.5 py-2 text-sm font-semibold faint opacity-50">{{ __('Previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}"
               class="rounded-xl border border-token px-3.5 py-2 text-sm font-semibold transition-colors hover:muted-surface">{{ __('Previous') }}</a>
        @endif

        <span class="faint text-sm">
            {{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
        </span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}"
               class="rounded-xl border border-token px-3.5 py-2 text-sm font-semibold transition-colors hover:muted-surface">{{ __('Next') }}</a>
        @else
            <span class="rounded-xl border border-token px-3.5 py-2 text-sm font-semibold faint opacity-50">{{ __('Next') }}</span>
        @endif
    </div>
@endif
