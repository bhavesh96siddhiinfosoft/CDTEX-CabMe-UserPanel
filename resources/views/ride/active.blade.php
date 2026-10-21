@extends('layouts.shell')

@section('title', __('Your ride'))

@php
    $pickup = ['lat' => $ride['latitude_depart'] ?? null, 'lng' => $ride['longitude_depart'] ?? null];
    $drop = ['lat' => $ride['latitude_arrivee'] ?? null, 'lng' => $ride['longitude_arrivee'] ?? null];
    $rideId = (int) ($ride['id'] ?? 0);
@endphp

@section('shell-attrs')
    x-data="rideTracker({
        statusUrl: '{{ route('app.ride.status') }}',
        sosUrl: '{{ route('app.sos') }}',
        interval: {{ $pollInterval }},
        rideId: {{ $rideId }},
        initialStatus: @js($ride['statut'] ?? 'new'),
        initialDriver: @js($ride['driver'] ?? null),
        otp: @js($ride['otp'] ?? null),
        pickup: @js($pickup),
        drop: @js($drop),
        path: @js($path),
    })"
@endsection

@section('map')
    <div class="shell-map" x-ref="map"></div>
@endsection

@section('sheet')
    {{-- Gateway kinds for the Pay Now checkout --}}
    <script>
        window.__methodKinds = @js(collect($paymentMethods)->pluck('kind', 'id'));
        window.__methodFlows = @js(collect($paymentMethods)->pluck('flow', 'id'));
    </script>

    <div class="shell-sheet">
        <div class="shell-sheet-body space-y-4">

            @include('partials.flash')

            {{-- Status ---------------------------------------------------- --}}
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">{{ __('Booking') }}</p>
                    <p class="text-xl font-extrabold">{{ $ride['booking_number'] ?? '—' }}</p>
                </div>
                <span :class="statusClass" x-text="statusLabel"></span>
            </div>

            {{-- Searching state gets a live pulse so it doesn't look frozen. --}}
            <template x-if="status === 'new'">
                <div class="flex items-center gap-3 rounded-2xl p-4 brand-tint">
                    <span class="relative flex h-2.5 w-2.5 shrink-0">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60"
                              style="background-color: var(--color-brand-600);"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full"
                              style="background-color: var(--color-brand-600);"></span>
                    </span>
                    <p class="text-sm font-semibold">{{ __('Finding you a driver nearby…') }}</p>
                </div>
            </template>

            {{-- The driver needs this code to start the trip. --}}
            <template x-if="otp && status !== 'on ride'">
                <div class="rounded-2xl p-4 sunken-surface">
                    <p class="eyebrow">{{ __('Start code') }}</p>
                    <p class="mt-1 text-3xl font-extrabold tracking-[0.25em]" x-text="otp"></p>
                    <p class="lede mt-1 text-xs">{{ __('Share this with your driver to begin the trip.') }}</p>
                </div>
            </template>

            {{-- Driver, once the admin assigns one. --}}
            <div class="rounded-2xl border border-token p-4" x-show="driver" x-cloak>
                <div class="flex items-center gap-3">
                    <img :src="driver?.image" alt="" class="h-12 w-12 rounded-xl object-cover sunken-surface">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-x-2 font-bold">
                            <span class="truncate" x-text="`${driver?.prenom ?? ''} ${driver?.nom ?? ''}`.trim()"></span>
                            <x-driver-rating expression="driver" />
                        </p>
                        <p class="lede truncate text-sm">
                            <span x-text="driver?.vehicle_details?.numberplate ?? ''"></span>
                        </p>
                        {{-- Brand and model, under the plate. --}}
                        <p class="lede truncate text-xs" x-text="vehicleLabel(driver)" x-show="vehicleLabel(driver)"></p>
                    </div>
                    <template x-if="driver?.phone">
                        <a :href="`tel:${driver.phone}`" class="btn-soft shrink-0">{{ __('Call') }}</a>
                    </template>
                </div>

                <x-ride-chat :order-id="$rideId" />
            </div>

            {{-- Route ----------------------------------------------------- --}}
            <div class="space-y-3 rounded-2xl border border-token p-4">
                <div class="flex items-start gap-3 text-sm">
                    <span class="dot-pickup"></span>
                    <span>{{ $ride['depart_name'] ?? '—' }}</span>
                </div>
                {{-- Stops, in the order the driver takes them. --}}
                @php
                    $rideStops = $ride['stops'] ?? [];
                    $rideStops = is_string($rideStops) ? (json_decode($rideStops, true) ?: []) : $rideStops;
                    $rideStops = array_values(array_filter(is_array($rideStops) ? $rideStops : [], 'is_array'));
                @endphp

                @foreach ($rideStops as $index => $stop)
                    <div class="flex items-start gap-3 text-sm">
                        <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full border-2"
                              style="border-color: var(--text-muted);"></span>
                        <span>
                            <span class="eyebrow block">{{ __('Stop :number', ['number' => $index + 1]) }}</span>
                            {{ $stop['location'] ?? $stop['address'] ?? $stop['name'] ?? '—' }}
                        </span>
                    </div>
                @endforeach

                <div class="flex items-start gap-3 text-sm">
                    <span class="dot-drop"></span>
                    <span>{{ $ride['destination_name'] ?? '—' }}</span>
                </div>

                @if (($ride['distance'] ?? null) !== null)
                    <p class="lede border-t border-token pt-3 text-sm">
                        {{ $ride['distance'] }} {{ $ride['distance_unit'] ?? $settings->distanceUnit() }}
                        @php
                            // "203 min" reads as nonsense on long trips.
                            $mins = (int) ($ride['duree'] ?? 0);
                            $durationLabel = $mins >= 60
                                ? intdiv($mins, 60).' '.__('hr').($mins % 60 ? ' '.($mins % 60).' '.__('min') : '')
                                : ($mins ? $mins.' '.__('min') : null);
                        @endphp
                        @if ($durationLabel) · {{ __('about :duration', ['duration' => $durationLabel]) }} @endif
                    </p>
                @endif
            </div>

            {{-- After ~60s of searching, offer to raise the fare — the app's
                 "No drivers accepted yet" sheet, chips and all. --}}
            <div x-show="status === 'new'" x-cloak
                 x-data="{
                     offerBump: false,
                     custom: false,
                     bumpTimer: null,
                     arm() {
                         clearTimeout(this.bumpTimer);
                         this.offerBump = false;
                         this.custom = false;
                         this.bumpTimer = setTimeout(() => this.offerBump = true, 60000);
                     },
                 }"
                 x-init="arm()">
                <div x-show="offerBump" x-cloak x-collapse>
                    <div class="rounded-2xl border border-token p-5 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-amber-100 dark:bg-amber-500/15">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-amber-500 text-white shadow-lg shadow-amber-500/40">
                                {{-- bolt --}}
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 4.9 12.5a.5.5 0 0 0 .4.8H11l-1 8 8.1-10.5a.5.5 0 0 0-.4-.8H13l1-8z"/></svg>
                            </span>
                        </span>

                        <p class="mt-3 text-lg font-extrabold">{{ __('No drivers accepted yet') }}</p>
                        <p class="lede mt-0.5 text-sm">{{ __('Increase your fare to find a driver faster') }}</p>

                        <div class="mt-4 flex items-baseline justify-between rounded-xl px-4 py-3 sunken-surface">
                            <span class="faint text-sm">{{ __('Current fare') }}</span>
                            <span class="font-extrabold">{{ $settings->money($ride['montant'] ?? 0) }}</span>
                        </div>

                        {{-- Same presets as the app, middle one highlighted. --}}
                        <div class="mt-3 grid grid-cols-3 gap-2">
                            @foreach ([10, 20, 30] as $i => $bump)
                                <form method="POST" action="{{ route('app.ride.increase-fare', $rideId) }}">
                                    @csrf
                                    <input type="hidden" name="amount" value="{{ $bump }}">
                                    <button type="submit"
                                            class="w-full rounded-xl border px-2 py-3 transition-all hover:-translate-y-0.5 {{ $i === 1 ? '' : 'border-token' }}"
                                            @if ($i === 1) style="border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.08)" @endif>
                                        <span class="block font-extrabold">+{{ $settings->money($bump) }}</span>
                                        <span class="faint block text-[11px]">{{ $settings->money((float) ($ride['montant'] ?? 0) + $bump) }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>

                        <button type="button" class="btn-ghost mt-2 w-full" @click="custom = !custom">
                            {{ __('Enter custom amount') }}
                        </button>

                        <form x-show="custom" x-cloak x-collapse method="POST"
                              action="{{ route('app.ride.increase-fare', $rideId) }}" class="mt-2 flex gap-2">
                            @csrf
                            <input type="number" name="amount" min="1" step="1" class="w-full"
                                   placeholder="{{ __('Add to fare') }}" required>
                            <button type="submit" class="btn-primary shrink-0">{{ __('Raise') }}</button>
                        </form>

                        <button type="button" class="faint mt-2 w-full text-sm font-semibold hover:opacity-70"
                                @click="arm()">
                            {{ __('Keep waiting') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- Pay Now — the app's ride payment screen. Once the driver has
                 started the trip, paying is what completes the ride: cash and
                 wallet settle inside the admin's completion call; a gateway
                 charges the server-computed total first. --}}
            <div x-show="status === 'on ride'" x-cloak
                 x-data="ridePay({
                     orderUrl: '{{ route('app.ride.pay.order', $rideId) }}',
                     methodId: @js((string) ($ride['id_payment_method'] ?? '')),
                 })"
                 class="rounded-2xl border-2 p-4" style="border-color: var(--color-brand-600);">
                <p class="text-sm font-bold">{{ __('Pay for this ride') }}</p>
                <p class="lede mt-0.5 text-sm">{{ __('Your trip is underway — pay any time before you hop out.') }}</p>

                <x-billing-summary :bill="$due" :cost-label="__('Ride cost')"
                                   class="mt-3 border-t border-token pt-3" />

                <div class="mt-3">
                    <x-payment-picker :methods="$paymentMethods" model="methodId" :label="__('Pay with')" cols="grid-cols-3" />
                </div>

                {{-- BookingCheckout resolves the method server-side; the
                     gateway path lands here only after a verified charge. --}}
                <form x-ref="completeForm" method="POST" action="{{ route('app.ride.complete', $rideId) }}">
                    @csrf
                    <input type="hidden" name="payment_method_id" :value="methodId">
                </form>

                {{-- Cash only records the choice; the driver confirms the money
                     and completes the trip. --}}
                <form x-ref="methodForm" method="POST" action="{{ route('app.ride.payment-method', $rideId) }}">
                    @csrf
                    <input type="hidden" name="payment_method_id" :value="methodId">
                </form>

                <button type="button" class="btn-primary btn-lg mt-3 w-full" :disabled="!methodId || payBusy" @click="pay()">
                    <span x-show="!payBusy && !isCash()">{{ __('Pay now') }} · {{ $settings->money($due['total']) }}</span>
                    <span x-show="!payBusy && isCash()" x-cloak>{{ __('Pay the driver in cash') }} · {{ $settings->money($due['total']) }}</span>
                    <span x-show="payBusy" x-cloak>{{ __('Processing payment…') }}</span>
                </button>
            </div>

            {{-- Payment method, changeable until the trip starts — once it is
                 on ride the Pay Now block above owns the method choice. --}}
            <div x-show="status !== 'completed' && status !== 'cancelled' && status !== 'on ride'" x-cloak
                 x-data="{
                     changing: false,
                     paymentMethodId: @js((string) ($ride['id_payment_method'] ?? '')),
                 }"
                 class="rounded-2xl border border-token p-4">
                {{-- Whole row toggles, with a rotating chevron like the FAQ. --}}
                <button type="button" class="flex w-full items-center justify-between gap-3 text-start"
                        @click="changing = !changing">
                    <span>
                        <span class="block text-sm font-bold">{{ __('Payment') }}</span>
                        @php
                            $currentMethod = collect($paymentMethods)->firstWhere('id', (int) ($ride['id_payment_method'] ?? 0));
                        @endphp
                        <span class="faint block text-sm">{{ $currentMethod['label'] ?? __('Not set') }}</span>
                    </span>
                    <x-icon name="chevron-down" class="h-4 w-4 shrink-0 faint transition-transform"
                            ::class="changing && 'rotate-180'" />
                </button>

                <form x-show="changing" x-cloak x-collapse method="POST"
                      action="{{ route('app.ride.payment-method', $rideId) }}" class="mt-3 space-y-3">
                    @csrf
                    <x-payment-picker :methods="$paymentMethods" label="" cols="grid-cols-3" />
                    {{-- Solid primary: btn-soft's tint disappears against light
                         brand colours and read as plain text. --}}
                    <button type="submit" class="btn-primary w-full">{{ __('Update payment method') }}</button>
                </form>

            </div>

            {{-- Cancel — one tap, exactly as the app does it (no reason, no
                 alert). Gone once the trip is underway: the on-ride screen's
                 job is paying, not cancelling. --}}
            <form method="POST" action="{{ route('app.ride.cancel', $rideId) }}"
                  x-show="status !== 'on ride'" x-cloak
                  x-data="{ cancelling: false }" @submit="cancelling = true">
                @csrf
                <button type="submit" class="btn-danger w-full" :disabled="cancelling">
                    <span x-show="!cancelling">{{ __('Cancel') }}</span>
                    <span x-show="cancelling" x-cloak>{{ __('Cancelling…') }}</span>
                </button>
            </form>
        </div>

        <div class="shell-sheet-foot">
            <div class="mb-3 flex items-baseline justify-between">
                <span class="text-sm font-medium faint">{{ __('Fare') }}</span>
                <span class="text-2xl font-extrabold">{{ $settings->money($ride['montant'] ?? 0) }}</span>
            </div>

            {{-- SOS appears once the driver has picked the rider up (the app
                 does the same); it records the driver's position, which only
                 means anything mid-trip. --}}
            <button type="button" @click="sos()"
                    x-show="status === 'on ride'" x-cloak
                    class="btn-danger w-full">
                {{ __('Emergency SOS') }}
            </button>
        </div>
    </div>
@endsection
