@extends('layouts.shell')

@section('title', __('Book a ride'))

@php
    $currency = $settings->currency();
@endphp

{{-- The map and the sheet are siblings, so the Alpine root has to sit on the
     shell wrapper (see layouts/shell) and span both. --}}
@section('shell-attrs')
    x-data="mapBooking({
        quoteUrl: '{{ route('app.ride.quote') }}',
        optionsKey: 'vehicle_types',
        defaultPaymentMethod: @js((string) ($paymentMethods[0]['id'] ?? '')),
        initial: @js($initialRoute),
        coupons: @js(collect($coupons)->map(fn ($c) => [
            'id' => (string) ($c['id'] ?? ''),
            'discount' => (float) ($c['discount'] ?? 0),
            'percent' => strtolower($c['type'] ?? '') === 'percentage',
        ])->values()),
    })"
@endsection

@section('map')
    <div class="shell-map" x-ref="map"></div>
@endsection

@section('sheet')
    <form method="POST" action="{{ route('app.ride.store') }}" class="shell-sheet">
        @csrf

        <div class="shell-sheet-body space-y-4">

            @include('partials.flash')

            {{-- Route entry ------------------------------------------------ --}}
            <div>
                <h1 class="text-lg font-bold">{{ __('Where to?') }}</h1>
                <p class="lede mt-0.5 text-sm">{{ __('Tap the map or search an address.') }}</p>
            </div>

            <x-route-inputs :stops="true" />

            {{-- Fare options ----------------------------------------------- --}}
            <template x-if="!ready">
                <div class="rounded-2xl border border-dashed border-token px-4 py-8 text-center">
                    <p class="lede text-sm">{{ __('Set both ends to see fares.') }}</p>
                </div>
            </template>

            <template x-if="loading">
                <div class="space-y-2">
                    <template x-for="i in 3" :key="i">
                        <div class="h-16 animate-pulse rounded-xl sunken-surface"></div>
                    </template>
                </div>
            </template>


            <template x-if="route && !loading">
                <div class="flex items-center gap-2 text-sm faint">
                    <span class="font-semibold text-[var(--text)]" x-text="route.distance + ' ' + route.unit"></span>
                    <template x-if="durationLabel">
                        <span>· <span x-text="durationLabel"></span></span>
                    </template>
                    <template x-if="route.estimated">
                        <span class="badge-neutral">{{ __('estimate') }}</span>
                    </template>
                </div>
            </template>

            <div class="space-y-2" x-show="options.length && !loading" x-cloak>
                <template x-for="option in options" :key="option.id">
                    <button type="button" @click="select(option)"
                            class="flex w-full items-center gap-3 rounded-xl border p-3 text-start transition-all"
                            :style="selected?.id === option.id
                                ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.07)'
                                : 'border-color: var(--border)'">
                        <img :src="option.image" alt="" class="h-9 w-12 shrink-0 object-contain">
                        <span class="min-w-0 flex-1">
                            {{-- type_vehicule stores the name in `libelle` and has no seat count. --}}
                            <span class="block truncate text-sm font-bold" x-text="option.libelle ?? option.name"></span>
                            <template x-if="option.pricing_source === 'none'">
                                <span class="block text-xs text-amber-600">{{ __('No fare configured for this zone') }}</span>
                            </template>
                        </span>
                        <span class="shrink-0 text-end">
                            <span class="block text-sm font-bold" x-text="fareOf(option) != null ? cabmeMoney(fareOf(option)) : '—'"></span>
                        </span>
                    </button>
                </template>
            </div>

            {{-- Trip options ----------------------------------------------- --}}
            <div x-show="ready" x-cloak class="space-y-3 border-t border-token pt-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="passengers" class="label">{{ __('Passengers') }}</label>
                        <input id="passengers" name="passengers" type="number" min="1" max="20"
                               class="w-full" x-model.number="passengers" required>
                    </div>
                    <div>
                        <label for="children" class="label">{{ __('Children') }}</label>
                        <input id="children" name="children" type="number" min="0" max="20"
                               class="w-full" x-model.number="children" required>
                    </div>
                </div>

                <x-payment-picker :methods="$paymentMethods" />

                <x-coupon-picker :coupons="$coupons" />
            </div>

            {{-- Filled in by the picker and the quote. --}}
            <input type="hidden" name="pickup_lat" :value="pickup.lat">
            <input type="hidden" name="pickup_lng" :value="pickup.lng">
            <input type="hidden" name="drop_lat" :value="drop.lat">
            <input type="hidden" name="drop_lng" :value="drop.lng">
            <input type="hidden" name="pickup_address" :value="pickup.address">
            <input type="hidden" name="drop_address" :value="drop.address">
            <input type="hidden" name="distance" :value="route?.distance">
            <input type="hidden" name="duration" :value="route?.duration ?? 0">
            <input type="hidden" name="vehicle_type_id" :value="selected?.id">
            <input type="hidden" name="sub_total" :value="subTotal">
            <input type="hidden" name="surge_multiplier" :value="selected?.surge_multiplier ?? 1">
            <input type="hidden" name="stops"
                   :value="placedStops.length ? JSON.stringify(placedStops.map(s => ({latitude: s.lat, longitude: s.lng, address: s.address}))) : ''">
        </div>

        <div class="shell-sheet-foot">
            {{-- Coupon savings, shown the moment one is picked. The admin
                 stores the coupon on the booking and settles the discount at
                 completion, so this is the rider's expected total. --}}
            <template x-if="discountAmount > 0">
                <div class="mb-1.5 space-y-1">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="faint">{{ __('Fare') }}</span>
                        <span class="faint line-through" x-text="cabmeMoney(subTotal)"></span>
                    </div>
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-semibold text-emerald-600"
                              x-text="'{{ __('Coupon') }} − ' + cabmeMoney(discountAmount)"></span>
                    </div>
                </div>
            </template>

            <div class="mb-3 flex items-baseline justify-between">
                <span class="text-sm font-medium faint"
                      x-text="discountAmount > 0 ? '{{ __('Estimated total') }}' : '{{ __('Estimated fare') }}'"></span>
                <span class="text-2xl font-extrabold"
                      x-text="estimatedTotal != null ? cabmeMoney(estimatedTotal) : '—'"></span>
            </div>

            <button type="submit" class="btn-primary btn-lg w-full" :disabled="!canSubmit">
                <span x-show="!selected">{{ __('Choose a vehicle') }}</span>
                <span x-show="selected" x-cloak>{{ __('Book now') }}</span>
            </button>
        </div>
    </form>
@endsection
