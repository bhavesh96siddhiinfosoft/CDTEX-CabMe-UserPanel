@extends('layouts.app')

@section('title', __('Dashboard'))
@section('container', 'w-full')

@section('content')
    @php
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
    @endphp

    {{-- Hero band: brand wash + greeting, services docked into it. --}}
    <section class="relative overflow-hidden border-b border-token" style="background-color: var(--surface);">
        <div class="pointer-events-none absolute inset-0"
             style="background:
                radial-gradient(42rem 22rem at 8% -30%, rgb(var(--brand-rgb) / 0.18), transparent 60%),
                radial-gradient(30rem 18rem at 100% 0%, rgb(var(--brand-rgb) / 0.10), transparent 55%);"></div>

        <div class="relative mx-auto w-full max-w-6xl px-4 pb-10 pt-10">

            <div class="flex items-center gap-2">
                <x-icon name="sparkle" class="h-5 w-5 brand-text" />
                <p class="eyebrow">{{ $greeting }}</p>
            </div>
            <h1 class="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">
                {{ $rider->get('prenom', __('there')) }}, {{ __('where to today?') }}
            </h1>

            {{-- Web push opt-in, shown only while it can still be asked for. --}}
            <button type="button" x-data x-show="$store.push.state === 'idle'" x-cloak
                    @click="$store.push.enable()" :disabled="$store.push.busy"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl border border-token px-3 py-2 text-sm font-semibold transition-colors hover:muted-surface">
                <x-icon name="chat" class="h-4 w-4 brand-text" />
                {{ __('Enable ride alerts') }}
            </button>

            {{-- An in-progress ride outranks everything else. --}}
            @if ($activeRide)
                <a href="{{ route('app.ride.active') }}"
                   class="group mt-6 flex items-center gap-4 rounded-2xl p-4 text-start transition-all hover:-translate-y-0.5"
                   style="background: linear-gradient(120deg, var(--color-brand-600), var(--color-brand-800)); color: var(--brand-contrast); box-shadow: var(--shadow-lg);">
                    <span class="relative flex h-3 w-3 shrink-0">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-50"></span>
                        <span class="relative inline-flex h-3 w-3 rounded-full bg-current"></span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold">{{ __('Ride in progress') }} — {{ $activeRide['booking_number'] ?? '' }}</span>
                        <span class="block truncate text-sm opacity-85">
                            {{ $activeRide['depart_name'] ?? '' }} → {{ $activeRide['destination_name'] ?? '' }}
                        </span>
                    </span>
                    <span class="shrink-0 font-semibold transition-transform group-hover:translate-x-0.5">{{ __('Track') }} →</span>
                </a>
            @endif

            {{-- Offers — at the very top, as the admin's banners are meant to
                 be seen (QA ask). --}}
            @if (! empty($banners))
                <div class="mt-6 -mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2">
                    @foreach ($banners as $banner)
                        <div class="group relative h-44 w-[19rem] shrink-0 snap-start overflow-hidden rounded-3xl sm:w-[22rem]"
                             style="box-shadow: var(--shadow-md);">
                            <img src="{{ $banner['image'] ?? '' }}" alt="{{ $banner['title'] ?? __('Offer') }}"
                                 class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                 loading="lazy">
                            <span class="absolute inset-0"
                                  style="background: linear-gradient(to top, rgb(0 0 0 / 0.55), transparent 55%);"></span>
                            @if (! empty($banner['title']))
                                <span class="absolute bottom-4 left-4 right-4">
                                    <span class="block text-base font-extrabold text-white drop-shadow">
                                        {{ $banner['title'] }}
                                    </span>
                                    @if (! empty($banner['description']))
                                        <span class="mt-0.5 line-clamp-1 block text-xs font-medium text-white/85">
                                            {{ $banner['description'] }}
                                        </span>
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Service cards --}}
            @php
                $tiles = [
                    'ride'   => [__('Book a ride'), __('A cab at your door in minutes'), 'car', 'app.ride.create'],
                    'parcel' => [__('Send a parcel'), __('Same-day, door to door'), 'package', 'app.parcel.create'],
                    'rental' => [__('Rent a vehicle'), __('Hourly & daily, driver included'), 'key', 'app.rental.create'],
                ];
            @endphp

            <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tiles as $key => [$title, $blurb, $icon, $routeName])
                    @if ($settings->serviceEnabled($key))
                        <a href="{{ route($routeName) }}"
                           class="group relative overflow-hidden rounded-3xl border border-token p-6 transition-all hover:-translate-y-1"
                           style="background-color: var(--surface-raised); box-shadow: var(--shadow-md);">
                            {{-- Decorative brand blob --}}
                            <span class="pointer-events-none absolute -right-8 -top-8 h-28 w-28 rounded-full opacity-70 transition-transform duration-300 group-hover:scale-125"
                                  style="background: radial-gradient(circle, rgb(var(--brand-rgb) / 0.16), transparent 70%);"></span>

                            <span class="relative grid h-14 w-14 place-items-center rounded-2xl brand-bg"
                                  style="box-shadow: 0 8px 20px -6px rgb(var(--brand-rgb) / 0.55);">
                                <x-icon :name="$icon" class="h-7 w-7" />
                            </span>

                            {{-- Title and blurb are set in different faces — the
                                 heading in the display font, the line under it
                                 in the text font — so they read as two things. --}}
                            <h2 class="relative mt-5 text-lg font-extrabold">{{ $title }}</h2>
                            <p class="lede relative mt-1.5 text-sm">{{ $blurb }}</p>

                            <span class="relative mt-4 inline-flex items-center gap-1.5 text-sm font-bold brand-text">
                                {{ __('Get started') }}
                                <x-icon name="arrow-right" class="h-4 w-4 transition-transform group-hover:translate-x-1" />
                            </span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    <div class="mx-auto w-full max-w-6xl px-4 py-10">

        {{-- Quick actions: useful numbers, not bare links. --}}
        <div class="grid gap-4 sm:grid-cols-3">
            <a href="{{ route('app.wallet') }}"
               class="group flex items-center gap-4 rounded-2xl border border-token p-5 transition-all hover:-translate-y-0.5"
               style="background-color: var(--surface-raised); box-shadow: var(--shadow-sm);">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl brand-tint brand-text">
                    <x-icon name="wallet" class="h-6 w-6" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="eyebrow block">{{ __('Wallet balance') }}</span>
                    <span class="block text-xl font-extrabold">{{ $settings->money($rider->balance()) }}</span>
                </span>
                <x-icon name="arrow-right" class="h-4 w-4 shrink-0 faint transition-transform group-hover:translate-x-1" />
            </a>

            <a href="{{ route('app.trips') }}"
               class="group flex items-center gap-4 rounded-2xl border border-token p-5 transition-all hover:-translate-y-0.5"
               style="background-color: var(--surface-raised); box-shadow: var(--shadow-sm);">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl brand-tint brand-text">
                    <x-icon name="receipt" class="h-6 w-6" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="eyebrow block">{{ __('My trips') }}</span>
                    <span class="block text-sm font-bold">{{ __('History & receipts') }}</span>
                </span>
                <x-icon name="arrow-right" class="h-4 w-4 shrink-0 faint transition-transform group-hover:translate-x-1" />
            </a>

            <a href="{{ route('app.referrals') }}"
               class="group flex items-center gap-4 rounded-2xl border border-token p-5 transition-all hover:-translate-y-0.5"
               style="background-color: var(--surface-raised); box-shadow: var(--shadow-sm);">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl brand-tint brand-text">
                    <x-icon name="gift" class="h-6 w-6" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="eyebrow block">{{ __('Refer & earn') }}</span>
                    <span class="block text-sm font-bold">{{ __('Invite friends, get credit') }}</span>
                </span>
                <x-icon name="arrow-right" class="h-4 w-4 shrink-0 faint transition-transform group-hover:translate-x-1" />
            </a>
        </div>

    </div>
@endsection
