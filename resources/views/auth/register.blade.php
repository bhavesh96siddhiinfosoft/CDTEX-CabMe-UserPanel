@extends('layouts.app')

@section('title', __('Create your account'))
@section('container', 'mx-auto w-full max-w-lg px-4 py-12')

@php
    // Same test partials/push.blade.php uses to publish window.__firebase:
    // with phone auth configured, signup is the app's order — verify the
    // number first, personal details after. Without it, the classic form.
    $fb = config('cabme.firebase');
    $phoneSignup = $fb['api_key'] && $fb['app_id'] && app(\App\Support\FirebaseCredentials::class)->get();
@endphp

@section('content')
@if ($phoneSignup)
    <div class="card-pad" x-data="firebaseLogin({ tab: 'phone' })">
        <h1 class="text-xl font-bold tracking-tight">{{ __('Create your account') }}</h1>
        <p class="lede mt-1 text-sm">{{ __('It takes less than a minute.') }}</p>

        {{-- Step rail: verify the phone, then the details. --}}
        <div class="mt-5 flex items-center gap-2">
            @foreach ([__('Verify phone'), __('Your details')] as $i => $step)
                <div class="flex flex-1 flex-col items-center gap-1.5">
                    <span class="h-1.5 w-full rounded-full transition-colors"
                          :style="({{ $i }} === 0 || registerMode) ? 'background-color: var(--color-brand-600)' : 'background-color: var(--border)'"></span>
                    <span class="text-[11px] font-bold uppercase tracking-wide"
                          :class="({{ $i }} === 0 || registerMode) ? '' : 'faint'">{{ $step }}</span>
                </div>
            @endforeach
        </div>

        {{-- Step 1 — phone + OTP (an existing number simply signs in). --}}
        <div class="mt-5 space-y-4" x-show="!registerMode">
            @include('auth._phone-otp')

            <div class="flex items-center gap-3">
                <span class="h-px flex-1" style="background-color: var(--border);"></span>
                <span class="faint text-xs font-bold uppercase tracking-wide">{{ __('or') }}</span>
                <span class="h-px flex-1" style="background-color: var(--border);"></span>
            </div>

            <button type="button" @click="google()" :disabled="busy"
                    class="btn flex w-full items-center justify-center gap-2.5 border border-token font-semibold transition-colors hover:muted-surface">
                <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.9-.1-1.5-.3-2.2H12v4.1h6.5c-.1 1.1-.8 2.7-2.4 3.8l-.02.15 3.5 2.7.24.03c2.2-2.05 3.5-5.1 3.5-8.6z"/><path fill="#34A853" d="M12 24c3.2 0 5.9-1.1 7.9-2.9l-3.7-2.9c-1 .7-2.4 1.2-4.2 1.2a7.2 7.2 0 0 1-6.8-5l-.14.01-3.65 2.8-.05.14A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.2 14.4a7.4 7.4 0 0 1 0-4.7l-.01-.16L1.5 6.7l-.12.06a12 12 0 0 0 0 10.6l3.8-3z"/><path fill="#EB4335" d="M12 4.6c2.3 0 3.9 1 4.8 1.9L20 3.2A11.9 11.9 0 0 0 12 0 12 12 0 0 0 1.4 6.7l3.8 3A7.2 7.2 0 0 1 12 4.6z"/></svg>
                {{ __('Continue with Google') }}
            </button>
        </div>

        {{-- Step 2 — personal details for the new account. --}}
        <div class="mt-5 space-y-4" x-show="registerMode" x-cloak>
            @include('auth._complete-profile')
        </div>

        <p class="lede mt-6 text-center text-xs">
            {{ __('By continuing you agree to our') }}
            <a href="{{ route('terms') }}" class="underline">{{ __('Terms') }}</a> {{ __('and') }}
            <a href="{{ route('privacy') }}" class="underline">{{ __('Privacy Policy') }}</a>.
        </p>

        <p class="lede mt-4 text-center text-sm">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:opacity-70">{{ __('Sign in') }}</a>
        </p>

        {{-- Invisible reCAPTCHA anchor: must exist and never be display:none. --}}
        <div id="recaptcha-anchor" class="h-0 overflow-hidden"></div>
    </div>
@else
    <div class="card-pad">
        <h1 class="text-xl font-bold tracking-tight">{{ __('Create your account') }}</h1>
        <p class="lede mt-1 text-sm">{{ __('It takes less than a minute.') }}</p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4"
              x-data="{ creating: false }" @submit="creating = true">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="firstname" class="label">{{ __('First name') }}</label>
                    <input id="firstname" name="firstname" type="text" class="w-full" required
                           value="{{ old('firstname') }}" autocomplete="given-name">
                </div>
                <div>
                    <label for="lastname" class="label">{{ __('Last name') }}</label>
                    <input id="lastname" name="lastname" type="text" class="w-full" required
                           value="{{ old('lastname') }}" autocomplete="family-name">
                </div>
            </div>

            <div>
                <label for="email" class="label">{{ __('Email address') }}</label>
                <input id="email" name="email" type="email" class="w-full" required
                       value="{{ old('email') }}" autocomplete="email">
            </div>

            <x-phone-field :phone="old('phone')" :country-code="old('country_code', '+91')" :show-errors="false" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-password-input id="password" name="password" :label="__('Password')"
                                  autocomplete="new-password" minlength="6" />
                <x-password-input id="password_confirmation" name="password_confirmation"
                                  :label="__('Confirm password')" autocomplete="new-password" minlength="6" />
            </div>

            <div>
                <label for="referral_code" class="label">{{ __('Referral code') }} <span class="faint font-normal">{{ __('(optional)') }}</span></label>
                <input id="referral_code" name="referral_code" type="text" class="w-full"
                       value="{{ old('referral_code', request('ref')) }}">
            </div>

            <button type="submit" class="btn-primary w-full" :disabled="creating">
                <span x-show="!creating">{{ __('Create account') }}</span>
                <span x-show="creating" x-cloak class="inline-flex items-center gap-2">
                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                    {{ __('Creating…') }}
                </span>
            </button>

            <p class="lede text-center text-xs">
                {{ __('By continuing you agree to our') }}
                <a href="{{ route('terms') }}" class="underline">{{ __('Terms') }}</a> {{ __('and') }}
                <a href="{{ route('privacy') }}" class="underline">{{ __('Privacy Policy') }}</a>.
            </p>
        </form>

        <p class="lede mt-6 text-center text-sm">
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:opacity-70">{{ __('Sign in') }}</a>
        </p>
    </div>
@endif
@endsection
