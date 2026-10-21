@extends('layouts.app')

@section('title', __('Choose a new password'))
@section('container', 'mx-auto w-full max-w-md px-4 py-12')

@section('content')
    <div class="card-pad">
        <h1 class="text-xl font-bold tracking-tight">{{ __('Choose a new password') }}</h1>
        <p class="lede mt-1 text-sm">{{ __('Enter the code we emailed you, then pick a new password.') }}</p>

        <form method="POST" action="{{ route('password.reset') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="label">{{ __('Email address') }}</label>
                <input id="email" name="email" type="email" class="w-full" required
                       value="{{ old('email', $email) }}" autocomplete="email">
            </div>

            <div>
                <label for="otp" class="label">{{ __('Verification code') }}</label>
                <input id="otp" name="otp" type="text" inputmode="numeric" class="w-full tracking-widest"
                       required autofocus autocomplete="one-time-code">
            </div>

            <x-password-input id="password" name="password" :label="__('New password')"
                              autocomplete="new-password" minlength="6" />

            <x-password-input id="password_confirmation" name="password_confirmation"
                              :label="__('Confirm new password')" autocomplete="new-password" minlength="6" />

            <button type="submit" class="btn-primary w-full">{{ __('Update password') }}</button>
        </form>
    </div>
@endsection
