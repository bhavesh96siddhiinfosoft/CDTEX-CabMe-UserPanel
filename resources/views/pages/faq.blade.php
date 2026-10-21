@extends('layouts.app')

@section('title', __('Frequently asked questions'))
@section('container', 'mx-auto w-full max-w-3xl px-4 py-8')

@section('content')
    <x-page-header :title="__('Frequently asked questions')"
                   :subtitle="__('Short answers to the things people ask most.')" />

    @php
        $faqs = [
            [__('How is my fare calculated?'),
             __('Fares combine a base charge for your vehicle type with a per-distance rate for your service area. During busy periods fares can be higher, and the price you see before you book is the price you pay.')],
            [__('Can I book a ride for someone else?'),
             __('Yes. Set the pickup point to where they are and share the booking start code with them so the driver can begin the trip.')],
            [__('What happens if no driver accepts?'),
             __('We keep searching for the configured wait window. If nobody accepts, you can raise the offered fare from the tracking screen to re-notify drivers nearby, or cancel at no charge.')],
            [__('How do I cancel?'),
             __('Open the trip from your dashboard or trip history and choose Cancel. Cancellation charges may apply once a driver has been assigned.')],
            [__('Which payment methods can I use?'),
             __('The methods your operator has enabled — typically cash, card and wallet. You can change the method on a booking before the trip starts.')],
            [__('How do I get a receipt?'),
             __('Every completed trip has a full fare breakdown under My trips, including taxes and any discount applied.')],
            [__('My driver has my item — what do I do?'),
             __('Raise a request under Help & support with the booking number and we will put you in touch.')],
        ];
    @endphp

    <div class="space-y-2">
        @foreach ($faqs as $i => [$question, $answer])
            <div class="card overflow-hidden" x-data="{ open: {{ $i === 0 ? 'true' : 'false' }} }">
                <button type="button" @click="open = !open"
                        class="flex w-full items-center gap-3 p-4 text-start font-semibold">
                    <span class="flex-1">{{ $question }}</span>
                    <span class="faint transition-transform" :class="open && 'rotate-45'">+</span>
                </button>

                <div x-show="open" x-collapse>
                    <p class="lede px-4 pb-4 text-sm">{{ $answer }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card-pad mt-6 text-center">
        <p class="text-sm font-medium">{{ __('Still stuck?') }}</p>
        <p class="lede mt-1 text-sm">{{ __('Our team answers support requests around the clock.') }}</p>
        <a href="{{ $rider->check() ? route('app.support') : route('contact') }}" class="btn-primary mt-4">
            {{ __('Contact support') }}
        </a>
    </div>
@endsection
