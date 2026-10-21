{{--
    Step 2 of signup (shared by the login page and the register wizard):
    personal details after the identity is verified — name, email (phone
    signups), phone (Google signups), password + confirm, referral code.
    Bound to the firebaseLogin component's `form`.
--}}
<p class="text-sm font-semibold">{{ __('Almost there — a few details to finish creating your account.') }}</p>

<div class="grid grid-cols-2 gap-3">
    <input type="text" class="w-full" x-model="form.firstname" placeholder="{{ __('First name') }}">
    <input type="text" class="w-full" x-model="form.lastname" placeholder="{{ __('Last name') }}">
</div>

<template x-if="registerMode === 'phone'">
    <input type="email" class="w-full" x-model="form.email" placeholder="{{ __('Email address') }}">
</template>

<div x-show="registerMode === 'google'" data-phone-register>
    <x-phone-field :label="__('Mobile number')" :required="false" />
</div>

{{-- A password so the account also signs in with email + password. --}}
<div class="grid grid-cols-2 gap-3" x-data="{ r1: false, r2: false }">
    <div class="relative">
        <input :type="r1 ? 'text' : 'password'" type="password" class="w-full pe-10" minlength="6"
               x-model="form.password" placeholder="{{ __('Password') }}" autocomplete="new-password">
        <button type="button" @click="r1 = !r1" tabindex="-1"
                class="absolute end-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-lg faint hover:muted-surface">
            <svg x-show="!r1" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg x-show="r1" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
        </button>
    </div>
    <div class="relative">
        <input :type="r2 ? 'text' : 'password'" type="password" class="w-full pe-10" minlength="6"
               x-model="form.password_confirmation" placeholder="{{ __('Confirm password') }}" autocomplete="new-password">
        <button type="button" @click="r2 = !r2" tabindex="-1"
                class="absolute end-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-lg faint hover:muted-surface">
            <svg x-show="!r2" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg x-show="r2" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
        </button>
    </div>
</div>

<input type="text" class="w-full" x-model="form.referral_code"
       placeholder="{{ __('Referral code') }} {{ __('(optional)') }}">

<button type="button" class="btn-primary w-full" :disabled="busy" @click="completeRegistration()">
    <span x-show="!busy">{{ __('Create account') }}</span>
    <span x-show="busy" x-cloak class="inline-flex items-center gap-2">
        <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
        {{ __('Creating…') }}
    </span>
</button>
