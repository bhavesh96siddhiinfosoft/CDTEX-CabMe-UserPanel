@extends('layouts.app')

@section('title', __('Reset your password'))
@section('container', 'mx-auto w-full max-w-md px-4 py-12')

@section('content')
    <div class="card-pad">
        <h1 class="text-xl font-bold tracking-tight">{{ __('Reset your password') }}</h1>
        <p class="lede mt-1 text-sm">{{ __("Enter your email and we'll send you a verification code.") }}</p>

        <form method="POST" action="{{ route('password.forgot') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="label">{{ __('Email address') }}</label>
                <input id="email" name="email" type="email" class="w-full" required autofocus
                       value="{{ old('email') }}" autocomplete="email">
            </div>

            <button type="submit" class="btn-primary w-full">{{ __('Send reset code') }}</button>
        </form>

        <p class="lede mt-6 text-center text-sm">
            <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:opacity-70">{{ __('Back to sign in') }}</a>
        </p>
    </div>
@endsection
