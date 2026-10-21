@extends('layouts.app')

@section('title', __('Contact us'))
@section('container', 'mx-auto w-full max-w-2xl px-4 py-8')

@section('content')
    <x-page-header :title="__('Contact us')" :subtitle="__('We\'re here whenever you need us.')" />

    <div class="grid gap-4 sm:grid-cols-2">
        @php
            // The admin sends these as contact_us_* in v1/settings/.
            $email = $settings->get('contact_us_email', $settings->get('email'));
            $phone = $settings->get('contact_us_phone');

            // The admin keeps the dial code as a separate setting, so show the
            // number the way someone would actually dial it.
            $dialCode = $settings->get('default_country_phonecode');
            $phoneDisplay = $dialCode && $phone ? $dialCode.' '.$phone : $phone;
            $address = $settings->get('contact_us_address');
        @endphp

        @if ($email)
            <a href="mailto:{{ $email }}" class="card-pad transition-shadow hover:shadow-lg">
                <span class="grid h-11 w-11 place-items-center rounded-2xl brand-tint brand-text"><x-icon name="mail" class="h-5 w-5" /></span>
                <h2 class="mt-3 font-semibold">{{ __('Email us') }}</h2>
                <p class="lede mt-1 break-all text-sm">{{ $email }}</p>
            </a>
        @endif

        @if ($phone)
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phoneDisplay) }}" class="card-pad transition-shadow hover:shadow-lg">
                <span class="grid h-11 w-11 place-items-center rounded-2xl brand-tint brand-text"><x-icon name="phone" class="h-5 w-5" /></span>
                <h2 class="mt-3 font-semibold">{{ __('Call us') }}</h2>
                <p class="lede mt-1 text-sm">{{ $phoneDisplay }}</p>
            </a>
        @endif
    </div>

    @if ($address)
        <div class="card-pad mt-4">
            <h2 class="font-semibold">{{ __('Our office') }}</h2>
            <p class="lede mt-1 whitespace-pre-line text-sm">{{ $address }}</p>
        </div>
    @endif

    <div class="card-pad mt-4">
        <h2 class="font-semibold">{{ __('Have a question about a trip?') }}</h2>
        <p class="lede mt-1 text-sm">
            {{ __('Signed-in riders can raise a request against a specific booking, which reaches our team with the trip details already attached.') }}
        </p>

        <a href="{{ $rider->check() ? route('app.support') : route('login') }}" class="btn-primary mt-4">
            {{ $rider->check() ? __('Raise a request') : __('Sign in to raise a request') }}
        </a>
    </div>
@endsection
