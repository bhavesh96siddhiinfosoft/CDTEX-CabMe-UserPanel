@extends('layouts.shell')

@section('title', __('Track your parcel'))

@php
    $pickup = ['lat' => $order['lat_source'] ?? null, 'lng' => $order['lng_source'] ?? null];
    $drop = ['lat' => $order['lat_destination'] ?? null, 'lng' => $order['lng_destination'] ?? null];
    $parcelId = (int) ($order['id'] ?? 0);
@endphp

@section('shell-attrs')
    x-data="rideTracker({
        statusUrl: '{{ route('app.parcel.status', $parcelId) }}',
        interval: {{ $pollInterval }},
        rideId: {{ $parcelId }},
        initialStatus: @js($order['status'] ?? $order['statut'] ?? 'new'),
        initialDriver: @js($order['driver'] ?? null),
        pickup: @js($pickup),
        drop: @js($drop),
        path: @js($path),
    })"
@endsection

@section('map')
    <div class="shell-map" x-ref="map"></div>
@endsection

@section('sheet')
    <div class="shell-sheet">
        <div class="shell-sheet-body space-y-4">

            @include('partials.flash')

            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">{{ __('Parcel') }}</p>
                    <p class="text-xl font-extrabold">#{{ $parcelId }}</p>
                </div>
                <span :class="statusClass" x-text="statusLabel"></span>
            </div>

            <template x-if="status === 'new'">
                <div class="flex items-center gap-3 rounded-2xl p-4 brand-tint">
                    <span class="relative flex h-2.5 w-2.5 shrink-0">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60"
                              style="background-color: var(--color-brand-600);"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full"
                              style="background-color: var(--color-brand-600);"></span>
                    </span>
                    <p class="text-sm font-semibold">{{ __('Finding a courier nearby…') }}</p>
                </div>
            </template>

            {{-- No delivery code here: the verification code belongs to the ride
                 flow, where it is gated on the admin's show_ride_otp setting. --}}

            <div class="rounded-2xl border border-token p-4" x-show="driver" x-cloak>
                <div class="flex items-center gap-3">
                    <img :src="driver?.image" alt="" class="h-12 w-12 rounded-xl object-cover sunken-surface">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-x-2 font-bold">
                            <span class="truncate" x-text="`${driver?.prenom ?? ''} ${driver?.nom ?? ''}`.trim()"></span>
                            <x-driver-rating expression="driver" />
                        </p>
                        <p class="lede truncate text-sm" x-text="driver?.vehicle_details?.numberplate ?? ''"></p>
                        {{-- Brand and model, under the plate. --}}
                        <p class="lede truncate text-xs" x-text="vehicleLabel(driver)" x-show="vehicleLabel(driver)"></p>
                    </div>
                    <template x-if="driver?.phone">
                        <a :href="`tel:${driver.phone}`" class="btn-soft shrink-0">{{ __('Call') }}</a>
                    </template>
                </div>

                <x-ride-chat :order-id="$parcelId" />
            </div>

            <div class="space-y-3 rounded-2xl border border-token p-4">
                <div class="flex items-start gap-3 text-sm">
                    <span class="dot-pickup"></span>
                    <span>{{ $order['source'] ?? $order['source_adrs'] ?? '—' }}</span>
                </div>
                <div class="flex items-start gap-3 text-sm">
                    <span class="dot-drop"></span>
                    <span>{{ $order['destination'] ?? $order['destination_adrs'] ?? '—' }}</span>
                </div>

                <div class="border-t border-token pt-3 text-sm faint">
                    {{ $order['parcel_type'] ?? __('Parcel') }}
                    @if (! empty($order['parcel_weight'])) · {{ $order['parcel_weight'] }} {{ __('kg') }} @endif
                    · {{ __('to :name', ['name' => $order['receiver_name'] ?? '—']) }}
                </div>
            </div>

            <a href="{{ route('app.trips.show', ['parcel', $parcelId]) }}" class="btn-ghost w-full">
                {{ __('Order details') }}
            </a>
        </div>

        <div class="shell-sheet-foot">
            <div class="flex items-baseline justify-between">
                <span class="text-sm font-medium faint">{{ __('Amount') }}</span>
                <span class="text-2xl font-extrabold">{{ $settings->money($order['amount'] ?? 0) }}</span>
            </div>
        </div>
    </div>
@endsection
