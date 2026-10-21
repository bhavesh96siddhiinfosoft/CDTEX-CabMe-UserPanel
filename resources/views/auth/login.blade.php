@extends('layouts.app')

@section('title', __('Sign in'))
@section('container', 'mx-auto w-full max-w-md px-4 py-12')

@section('content')
    <div class="card-pad" x-data="firebaseLogin()">
        <h1 class="text-xl font-bold tracking-tight">{{ __('Welcome back') }}</h1>
        <p class="lede mt-1 text-sm">{{ __('Sign in to book rides, send parcels and manage your trips.') }}</p>

        {{-- Email / Phone switch — phone appears only when Firebase Auth is configured. --}}
        <div class="mt-5 grid grid-cols-2 gap-1 rounded-xl p-1 sunken-surface" x-show="available && !registerMode" x-cloak>
            <button type="button" @click="tab = 'email'"
                    class="rounded-lg px-3 py-2 text-sm font-bold transition-all"
                    :style="tab === 'email' ? 'background-color: var(--color-brand-600); color: var(--brand-contrast); box-shadow: var(--shadow-sm)' : ''"
                    :class="tab === 'email' ? '' : 'faint'">{{ __('Email') }}</button>
            <button type="button" @click="tab = 'phone'"
                    class="rounded-lg px-3 py-2 text-sm font-bold transition-all"
                    :style="tab === 'phone' ? 'background-color: var(--color-brand-600); color: var(--brand-contrast); box-shadow: var(--shadow-sm)' : ''"
                    :class="tab === 'phone' ? '' : 'faint'">{{ __('Phone') }}</button>
        </div>


        {{-- Email + password ------------------------------------------------ --}}
        <form method="POST" action="{{ route('login') }}" class="mt-4 space-y-4"
              x-show="tab === 'email' && !registerMode">
            @csrf

            <div>
                <label for="email" class="label">{{ __('Email address') }}</label>
                <input id="email" name="email" type="email" class="w-full" required autofocus
                       autocomplete="email" value="{{ old('email') }}">
            </div>

            <div>
                <x-password-input id="password" name="password" :label="__('Password')" />
                {{-- Below the field, as in the app's login screen. --}}
                <a href="{{ route('password.forgot') }}"
                   class="mt-1.5 inline-block text-sm font-semibold text-brand-600 hover:opacity-70">{{ __('Forgot password?') }}</a>
            </div>

            <button type="submit" class="btn-primary w-full">{{ __('Sign in') }}</button>
        </form>

        {{-- Phone OTP -------------------------------------------------------- --}}
        <div class="mt-4 space-y-4" x-show="tab === 'phone' && !registerMode" x-cloak>
            @include('auth._phone-otp')
        </div>

        {{-- First-time riders: finish the profile the admin requires. -------- --}}
        <div class="mt-4 space-y-4" x-show="registerMode" x-cloak>
            @include('auth._complete-profile')
        </div>

        {{-- Google ----------------------------------------------------------- --}}
        <div x-show="available && !registerMode" x-cloak>
            <div class="mt-5 flex items-center gap-3">
                <span class="h-px flex-1" style="background-color: var(--border);"></span>
                <span class="faint text-xs font-bold uppercase tracking-wide">{{ __('or') }}</span>
                <span class="h-px flex-1" style="background-color: var(--border);"></span>
            </div>

            <button type="button" @click="google()" :disabled="busy"
                    class="btn mt-4 flex w-full items-center justify-center gap-2.5 border border-token font-semibold transition-colors hover:muted-surface">
                <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.9-.1-1.5-.3-2.2H12v4.1h6.5c-.1 1.1-.8 2.7-2.4 3.8l-.02.15 3.5 2.7.24.03c2.2-2.05 3.5-5.1 3.5-8.6z"/><path fill="#34A853" d="M12 24c3.2 0 5.9-1.1 7.9-2.9l-3.7-2.9c-1 .7-2.4 1.2-4.2 1.2a7.2 7.2 0 0 1-6.8-5l-.14.01-3.65 2.8-.05.14A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.2 14.4a7.4 7.4 0 0 1 0-4.7l-.01-.16L1.5 6.7l-.12.06a12 12 0 0 0 0 10.6l3.8-3z"/><path fill="#EB4335" d="M12 4.6c2.3 0 3.9 1 4.8 1.9L20 3.2A11.9 11.9 0 0 0 12 0 12 12 0 0 0 1.4 6.7l3.8 3A7.2 7.2 0 0 1 12 4.6z"/></svg>
                {{ __('Continue with Google') }}
            </button>
        </div>

        <p class="lede mt-6 text-center text-sm">
            {{ __('New to :app?', ['app' => $settings->appName()]) }}
            <a href="{{ route('register') }}" class="font-semibold text-brand-600 hover:opacity-70">{{ __('Create an account') }}</a>
        </p>

        {{-- Invisible reCAPTCHA anchor: must exist and never be display:none. --}}
        <div id="recaptcha-anchor" class="h-0 overflow-hidden"></div>
    </div>
@endsection
