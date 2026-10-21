@extends('layouts.app')

@section('title', __('Ride, deliver, rent'))
@section('container', 'w-full')

@section('content')
    {{-- Map hero ---------------------------------------------------------
         Guests compose their trip here; fares need a rider token, so the CTA
         carries the route through sign-in (PageController::start). --}}
    <section class="relative overflow-hidden"
             style="height: calc(100dvh - 4rem); min-height: 34rem;"
             x-data="placePicker()">

        <div class="absolute inset-0 z-0" x-ref="map"></div>

        {{-- Booking card, floating over the map --}}
        <div class="pointer-events-none absolute inset-0 z-10">
            <div class="mx-auto flex h-full w-full max-w-7xl items-center px-4">
                <form method="POST" action="{{ route('home.start') }}"
                      class="card pointer-events-auto w-full max-w-sm p-5"
                      style="box-shadow: var(--shadow-xl);">
                    @csrf

                    <span class="badge brand-tint brand-text">{{ __('Rides · Parcels · Rentals') }}</span>

                    <h1 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight">
                        {{ __('Your ride,') }}<br><span class="brand-text">{{ __('on the way.') }}</span>
                    </h1>

                    <p class="lede mt-2 text-sm">{{ __("Set your route — we'll show the fare before you book.") }}</p>

                    {{-- Route entry: one input per end --}}
                    <div class="mt-5">
                        <x-route-inputs />
                    </div>

                    {{-- Ride options, Uber-style: guests see what they can
                         book right away; the fare needs a sign-in. Every row
                         submits the same stash-and-sign-in flow as the CTA. --}}
                    @if (! empty($vehicleTypes))
                        <div class="mt-4" x-show="ready" x-cloak>
                            <div class="flex items-baseline justify-between">
                                <p class="text-sm font-bold">{{ __('Choose a ride') }}</p>
                                <p class="lede text-xs">{{ __('Sign in to see fares') }}</p>
                            </div>

                            <div class="mt-2 max-h-44 space-y-1.5 overflow-y-auto pe-1">
                                @foreach ($vehicleTypes as $type)
                                    <button type="submit"
                                            class="flex w-full items-center gap-3 rounded-xl border border-token p-2.5 text-start transition-all hover:-translate-y-px hover:muted-surface">
                                        @if (! empty($type['image']))
                                            <img src="{{ $type['image'] }}" alt="" class="h-8 w-12 shrink-0 object-contain" loading="lazy">
                                        @else
                                            <span class="grid h-8 w-12 shrink-0 place-items-center"><x-icon name="car" class="h-6 w-6 faint" /></span>
                                        @endif
                                        <span class="min-w-0 flex-1 truncate text-sm font-bold">{{ $type['libelle'] ?? __('Vehicle') }}</span>
                                        <x-icon name="arrow-right" class="h-4 w-4 faint" />
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <input type="hidden" name="pickup_lat" :value="pickup.lat">
                    <input type="hidden" name="pickup_lng" :value="pickup.lng">
                    <input type="hidden" name="drop_lat" :value="drop.lat">
                    <input type="hidden" name="drop_lng" :value="drop.lng">
                    <input type="hidden" name="pickup_address" :value="pickup.address">
                    <input type="hidden" name="drop_address" :value="drop.address">

                    <button type="submit" class="btn-primary btn-lg mt-4 w-full" :disabled="!ready">
                        <span x-show="!ready">{{ __('Set pickup and drop-off') }}</span>
                        <span x-show="ready" x-cloak>{{ __('See fares') }}</span>
                    </button>

                    <p class="lede mt-3 text-center text-xs">
                        {{ __('Already with us?') }}
                        <a href="{{ route('login') }}" class="font-semibold brand-text">{{ __('Sign in') }}</a>
                    </p>
                </form>
            </div>
        </div>

        {{-- Other services, tucked bottom-right on desktop --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-5 z-10 hidden lg:block">
            <div class="mx-auto flex w-full max-w-7xl justify-end px-4">
                <div class="pointer-events-auto flex gap-2">
                    @php
                        $quick = [
                            'parcel' => [__('Send a parcel'), 'package', 'app.parcel.create'],
                            'rental' => [__('Rent a vehicle'), 'key', 'app.rental.create'],
                        ];
                    @endphp

                    @foreach ($quick as $key => [$label, $icon, $routeName])
                        @if ($settings->serviceEnabled($key))
                            <a href="{{ route('login') }}"
                               class="card flex items-center gap-2.5 px-4 py-3 text-sm font-semibold transition-all hover:-translate-y-0.5"
                               style="box-shadow: var(--shadow-lg);">
                                <x-icon :name="$icon" class="h-5 w-5" />{{ $label }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- How it works ----------------------------------------------------- --}}
    <section class="border-y border-token py-20" style="background-color: var(--surface);">
        <div class="mx-auto w-full max-w-6xl px-4">
            <h2 class="text-3xl font-extrabold tracking-tight">{{ __("Three taps and you're moving") }}</h2>

            <div class="mt-10 grid gap-10 sm:grid-cols-3">
                @foreach ([
                    [__('Set your route'), __('Drop a pin or search an address. We price the trip before you commit.')],
                    [__('Pick and confirm'), __('Compare vehicles and fares, choose how to pay, and book.')],
                    [__('Track to the door'), __('Follow your driver live, then rate the trip when you arrive.')],
                ] as $i => [$title, $blurb])
                    <div>
                        <span class="grid h-10 w-10 place-items-center rounded-2xl text-sm font-extrabold brand-bg">{{ $i + 1 }}</span>
                        <h3 class="mt-5 text-lg font-bold">{{ $title }}</h3>
                        <p class="lede mt-2 text-sm">{{ $blurb }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Services ---------------------------------------------------------- --}}
    <section class="mx-auto w-full max-w-6xl px-4 py-20">
        <h2 class="text-3xl font-extrabold tracking-tight">{{ __('One account, three ways to move') }}</h2>

        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            @php
                $tiles = [
                    'ride'   => [__('Book a ride'), __('Cabs on demand, fare shown upfront.'), 'car'],
                    'parcel' => [__('Send a parcel'), __('Same-day delivery, door to door.'), 'package'],
                    'rental' => [__('Rent a vehicle'), __('By the hour or by the day.'), 'key'],
                ];
            @endphp

            @foreach ($tiles as $key => [$title, $blurb, $icon])
                @if ($settings->serviceEnabled($key))
                    <a href="{{ route('login') }}" class="card-pad transition-all hover:-translate-y-0.5">
                        <span class="grid h-12 w-12 place-items-center rounded-2xl brand-tint brand-text"><x-icon :name="$icon" class="h-6 w-6" /></span>
                        <h3 class="mt-4 font-bold">{{ $title }}</h3>
                        <p class="lede mt-1.5 text-sm">{{ $blurb }}</p>
                    </a>
                @endif
            @endforeach
        </div>

        <dl class="mt-14 grid max-w-xl grid-cols-3 gap-6 border-t border-token pt-8">
            @foreach ([[__('Upfront'), __('pricing')], [__('Live'), __('tracking')], ['24/7', __('support')]] as [$value, $label])
                <div>
                    <dt class="text-2xl font-extrabold">{{ $value }}</dt>
                    <dd class="faint text-sm">{{ $label }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endsection
