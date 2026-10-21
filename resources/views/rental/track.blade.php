@extends('layouts.shell')

@section('title', __('Your rental'))

@php
    $pickup = ['lat' => $order['lat_source'] ?? null, 'lng' => $order['lng_source'] ?? null];
    $rentalId = (int) ($order['id'] ?? 0);
    $package = $order['package_details'] ?? [];
    $status = strtolower((string) ($order['status'] ?? 'new'));
@endphp

@section('shell-attrs')
    x-data="rideTracker({
        statusUrl: '{{ route('app.rental.status', $rentalId) }}',
        otp: @js($otp),
        interval: {{ $pollInterval }},
        rideId: {{ $rentalId }},
        initialStatus: @js($order['status'] ?? 'new'),
        initialDriver: @js($order['driver'] ?? null),
        pickup: @js($pickup),
        drop: null,
        path: null,
        payable: @js($payable),
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

            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">{{ __('Rental') }}</p>
                    <p class="text-xl font-extrabold">{{ $order['booking_number'] ?? '#'.$rentalId }}</p>
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
                    <p class="text-sm font-semibold">{{ __('Finding a driver for your rental…') }}</p>
                </div>
            </template>

            {{-- The driver enters this to start the rental. Shown only while the
                 admin has ride OTP switched on — rentals follow the same
                 setting rather than one of their own. --}}
            <template x-if="otp && status === 'confirmed'">
                <div class="rounded-2xl p-4 sunken-surface">
                    <p class="eyebrow">{{ __('Start code — share with your driver') }}</p>
                    <p class="mt-1 text-3xl font-extrabold tracking-[0.25em]" x-text="otp"></p>
                </div>
            </template>

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

                <x-ride-chat :order-id="$rentalId" />
            </div>

            <div class="space-y-3 rounded-2xl border border-token p-4">
                <div class="flex items-start gap-3 text-sm">
                    <span class="dot-pickup"></span>
                    <span>{{ $order['depart_name'] ?? '—' }}</span>
                </div>

                <div class="border-t border-token pt-3 text-sm">
                    <p class="font-bold">
                        {{ $package['title'] ?? __('Rental package') }}
                        @if (! empty($order['vehicle_name'])) · {{ $order['vehicle_name'] }} @endif
                    </p>
                    @if (! empty($package['includedHours']) || ! empty($package['includedDistance']))
                        <p class="lede mt-1">
                            @if (! empty($package['includedHours'])) {{ $package['includedHours'] }} {{ __('hrs') }} @endif
                            @if (! empty($package['includedDistance'])) · {{ $package['includedDistance'] }} {{ $order['distance_unit'] ?? __('km') }} {{ __('included') }} @endif
                        </p>
                    @endif
                </div>

                @if (! empty($order['start_date']))
                    <div class="border-t border-token pt-3 text-sm faint">
                        {{ \Illuminate\Support\Carbon::parse($order['start_date'].' '.($order['start_time'] ?? ''))->format('d M, g:i A') }}
                        →
                        {{ \Illuminate\Support\Carbon::parse(($order['end_date'] ?? $order['start_date']).' '.($order['end_time'] ?? ''))->format('d M, g:i A') }}
                    </div>
                @endif
            </div>

            {{-- Pay Now — a rental is paid at the END, once the driver has
                 filed the final km: only then is the bill final (base fare
                 plus any extra distance and time). --}}
            @if ($payable)
                <div x-data="ridePay({
                         orderUrl: '{{ route('app.rental.pay.order', $rentalId) }}',
                         methodId: @js((string) ($order['id_payment_method'] ?? '')),
                     })"
                     class="rounded-2xl border-2 p-4" style="border-color: var(--color-brand-600);">
                    <p class="text-sm font-bold">{{ __('Pay for this rental') }}</p>
                    <p class="lede mt-0.5 text-sm">{{ __('Your driver has confirmed the distance travelled.') }}</p>

                    <x-billing-summary :bill="$due" :cost-label="__('Rental charge')"
                                       class="mt-3 border-t border-token pt-3" />

                    <div class="mt-3">
                        <x-payment-picker :methods="$paymentMethods" model="methodId" :label="__('Pay with')" cols="grid-cols-3" />
                    </div>

                    <form x-ref="completeForm" method="POST" action="{{ route('app.rental.complete', $rentalId) }}">
                        @csrf
                        <input type="hidden" name="payment_method_id" :value="methodId">
                    </form>

                    {{-- Cash only records the choice; the driver collects it and
                         closes the rental, exactly as on a ride. --}}
                    <form x-ref="methodForm" method="POST" action="{{ route('app.rental.payment-method', $rentalId) }}">
                        @csrf
                        <input type="hidden" name="payment_method_id" :value="methodId">
                    </form>

                    <button type="button" class="btn-primary btn-lg mt-3 w-full" :disabled="!methodId || payBusy" @click="pay()">
                        <span x-show="!payBusy && !isCash()">{{ __('Pay now') }} · {{ $settings->money($due['total']) }}</span>
                        <span x-show="!payBusy && isCash()" x-cloak>{{ __('Pay the driver in cash') }} · {{ $settings->money($due['total']) }}</span>
                        <span x-show="payBusy" x-cloak>{{ __('Processing payment…') }}</span>
                    </button>
                </div>
            @endif

            <a href="{{ route('app.trips.show', ['rental', $rentalId]) }}" class="btn-ghost w-full">
                {{ __('Booking details') }}
            </a>

            {{-- Cancel — one tap, exactly as the app does it: no alert, no
                 reason to fill in. Nothing was charged at booking, so there
                 is nothing to refund either. --}}
            @if (in_array($status, ['new', 'confirmed'], true))
                <form method="POST" action="{{ route('app.rental.cancel', $rentalId) }}"
                      x-data="{ cancelling: false }" @submit="cancelling = true">
                    @csrf
                    <button type="submit" class="btn-danger w-full" :disabled="cancelling">
                        <span x-show="!cancelling">{{ __('Cancel') }}</span>
                        <span x-show="cancelling" x-cloak>{{ __('Cancelling…') }}</span>
                    </button>
                </form>
            @endif
        </div>

        <div class="shell-sheet-foot">
            <div class="flex items-baseline justify-between">
                <span class="text-sm font-medium faint">{{ __('Amount') }}</span>
                <span class="text-2xl font-extrabold">{{ $settings->money($order['amount'] ?? 0) }}</span>
            </div>
        </div>
    </div>
@endsection
