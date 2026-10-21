@extends('layouts.app')

@section('title', __('How it works'))

@section('content')
    <x-page-header :title="__('How it works')"
                   :subtitle="__('One account for rides, deliveries and rentals.')" />

    @php
        $services = [
            'ride' => [
                'title' => __('Rides'),
                'icon' => 'car',
                'blurb' => __('Point-to-point cab trips with a fare quoted before you book.'),
                'points' => [
                    __('Compare vehicle types and fares side by side'),
                    __('Live driver tracking from pickup to drop-off'),
                    __('A start code the driver needs to begin the trip'),
                    __('Pay by cash, card or wallet'),
                ],
                'route' => 'app.ride.create',
            ],
            'parcel' => [
                'title' => __('Parcel delivery'),
                'icon' => 'package',
                'blurb' => __('Send packages across town with a scheduled pickup window.'),
                'points' => [
                    __('Sender and receiver details captured up front'),
                    __('Photograph the package before it leaves'),
                    __('Choose a pickup and delivery time'),
                    __('Track the courier the whole way'),
                ],
                'route' => 'app.parcel.create',
            ],
            'rental' => [
                'title' => __('Vehicle rental'),
                'icon' => 'key',
                'blurb' => __('Book a vehicle with a driver by the hour or by the day.'),
                'points' => [
                    __('Hourly and daily packages with included distance'),
                    __('Pick your vehicle type'),
                    __('Schedule a start and end time'),
                    __('Extend or settle extras at the end'),
                ],
                'route' => 'app.rental.create',
            ],
        ];
    @endphp

    <div class="space-y-4">
        @foreach ($services as $key => $service)
            @if ($settings->serviceEnabled($key))
                <div class="card-pad">
                    <div class="flex flex-wrap items-start gap-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl brand-tint brand-text"><x-icon :name="$service['icon']" class="h-6 w-6" /></span>

                        <div class="min-w-[16rem] flex-1">
                            <h2 class="text-lg font-bold">{{ $service['title'] }}</h2>
                            <p class="lede mt-1 text-sm">{{ $service['blurb'] }}</p>

                            <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                                @foreach ($service['points'] as $point)
                                    <li class="flex items-start gap-2 text-sm">
                                        <span class="mt-0.5 text-emerald-500"><x-icon name="check" class="h-4 w-4" /></span>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <a href="{{ $rider->check() ? route($service['route']) : route('login') }}"
                           class="btn-primary">{{ __('Book now') }}</a>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endsection
