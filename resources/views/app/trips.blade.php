@extends('layouts.app')

@section('title', __('My trips'))

@section('content')
    <x-page-header :title="__('My trips')" :subtitle="__('Everything you\'ve booked with us.')" />

    {{-- Only offer tabs for services the admin has switched on. --}}
    @php
        $tabs = array_filter([
            'ride' => __('Rides'),
            'parcel' => __('Parcels'),
            'rental' => __('Rentals'),
        ], fn ($label, $key) => $settings->serviceEnabled($key), ARRAY_FILTER_USE_BOTH);
    @endphp

    {{-- Segmented tabs. The active one used text-white, which is unreadable
         on a light brand colour — the contrast token flips with the brand,
         and each tab carries its own icon so the three read apart at a
         glance rather than as three words in a box. --}}
    <div class="mb-6 flex gap-1 rounded-2xl p-1 muted-surface" role="tablist">
        @php
            $tabIcons = ['ride' => 'car', 'parcel' => 'package', 'rental' => 'key'];
        @endphp

        @foreach ($tabs as $key => $label)
            @php $active = $type === $key; @endphp

            <a href="{{ route('app.trips', $key) }}" role="tab" @if ($active) aria-selected="true" @endif
               class="flex flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-center text-sm font-bold transition-all
                      {{ $active ? '' : 'faint hover:opacity-70' }}"
               @if ($active)
                   style="background-color: var(--color-brand-600); color: var(--brand-contrast); box-shadow: var(--shadow-sm);"
               @endif>
                <x-icon :name="$tabIcons[$key] ?? 'car'" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ $label }}</span>
            </a>
        @endforeach
    </div>

    {{-- Search and filters. They live in the url, so a filtered list can be
         shared or bookmarked and paging keeps them. --}}
    <x-list-filters :action="route('app.trips', $type)" />

    @if ($error)
        <script>
            (window.__flashQueue = window.__flashQueue || []).push({ type: 'error', message: @json($error) });
            window.__drainFlashQueue?.();
        </script>
    @endif

    @forelse ($trips as $trip)
        @php
            $id = $trip['id'] ?? null;
            $status = $trip['statut'] ?? $trip['status'] ?? '';
            $from = $trip['depart_name'] ?? $trip['source'] ?? $trip['source_adrs'] ?? null;
            $to = $trip['destination_name'] ?? $trip['destination'] ?? $trip['destination_adrs'] ?? null;
            $amount = $trip['montant'] ?? $trip['amount'] ?? null;
            $when = $trip['creer'] ?? $trip['created_at'] ?? null;

            $tripStops = $trip['stops'] ?? [];
            $tripStops = is_string($tripStops) ? (json_decode($tripStops, true) ?: []) : $tripStops;
            $tripStops = array_filter(is_array($tripStops) ? $tripStops : [], 'is_array');
        @endphp

        <a href="{{ $id ? route('app.trips.show', [$type, $id]) : '#' }}"
           class="card mb-3 flex items-start gap-4 p-4 transition-shadow hover:shadow-lg">

            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full muted-surface">
                <x-icon :name="['ride' => 'car', 'parcel' => 'package', 'rental' => 'key'][$type] ?? 'car'" class="h-5 w-5 faint" />
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold">{{ $trip['booking_number'] ?? '#'.$id }}</span>
                    <x-status-badge :status="$status" />
                </div>

                @if ($from || $to)
                    <p class="lede mt-1 truncate text-sm">
                        {{ $from }}@if ($to) → {{ $to }} @endif
                        @if (count($tripStops))
                            <span class="eyebrow ms-1">{{ trans_choice('{1} +1 stop|[2,*] +:count stops', count($tripStops), ['count' => count($tripStops)]) }}</span>
                        @endif
                    </p>
                @endif

                @if ($when)
                    <p class="lede mt-1 text-xs">
                        {{ \Illuminate\Support\Carbon::parse($when)->format('d M Y, g:i A') }}
                    </p>
                @endif
            </div>

            @if ($amount !== null)
                <span class="whitespace-nowrap font-bold">{{ $settings->money($amount) }}</span>
            @endif
        </a>
    @empty
        <x-empty-state :title="__('No trips yet')"
                       :message="__('Once you book, everything shows up here with its receipt and status.')"
                       icon="receipt">
            @if ($settings->serviceEnabled('ride'))
                <a href="{{ route('app.ride.create') }}" class="btn-primary mt-2">{{ __('Book your first ride') }}</a>
            @endif
        </x-empty-state>
    @endforelse

    <x-pager :paginator="$trips" />
@endsection
