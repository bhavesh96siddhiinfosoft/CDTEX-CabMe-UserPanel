@extends('layouts.app')

@section('title', __('Profile'))
@section('container', 'mx-auto w-full max-w-2xl px-4 py-8')

@section('content')
    <x-page-header :title="__('Profile')" :subtitle="__('Manage your details and account security.')" />

    <div class="card-pad mb-4">
        <h2 class="text-base font-semibold">{{ __('Your details') }}</h2>

        <form method="POST" action="{{ route('app.profile.update') }}"
              enctype="multipart/form-data" class="mt-4 space-y-4">
            @csrf

            <div class="flex items-center gap-4">
                @if ($rider->photo())
                    <img src="{{ $rider->photo() }}" alt="" class="h-16 w-16 rounded-full object-cover">
                @else
                    <span class="grid h-16 w-16 place-items-center rounded-full text-xl font-semibold muted-surface">
                        {{ strtoupper(substr($rider->name(), 0, 1)) }}
                    </span>
                @endif

                <div class="flex-1">
                    <label for="photo" class="label">{{ __('Profile photo') }}</label>
                    <input id="photo" name="photo" type="file" accept="image/*" class="w-full text-sm">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="firstname" class="label">{{ __('First name') }}</label>
                    <input id="firstname" name="firstname" type="text" class="w-full" required
                           value="{{ old('firstname', $rider->get('prenom')) }}">
                </div>
                <div>
                    <label for="lastname" class="label">{{ __('Last name') }}</label>
                    <input id="lastname" name="lastname" type="text" class="w-full" required
                           value="{{ old('lastname', $rider->get('nom')) }}">
                </div>
            </div>

            <div>
                <label for="email" class="label">{{ __('Email address') }}</label>
                <input id="email" name="email" type="email" class="w-full" required
                       value="{{ old('email', $rider->get('email')) }}">
            </div>

            <div>
                <x-phone-field :phone="old('phone', $rider->get('phone'))"
                               :country-code="old('country_code', $rider->get('country_code', '+91'))" />
            </div>

            <button type="submit" class="btn-primary">{{ __('Save changes') }}</button>
        </form>
    </div>

    <div class="card-pad mb-4">
        <h2 class="text-base font-semibold">{{ __('Change password') }}</h2>

        <form method="POST" action="{{ route('app.profile.password') }}" class="mt-4 space-y-4">
            @csrf

            <div>
                <label for="current_password" class="label">{{ __('Current password') }}</label>
                <input id="current_password" name="current_password" type="password" class="w-full" required
                       autocomplete="current-password">
            </div>

            <div>
                <label for="new_password" class="label">{{ __('New password') }}</label>
                <input id="new_password" name="password" type="password" class="w-full" required
                       minlength="6" autocomplete="new-password">
            </div>

            <div>
                <label for="password_confirmation" class="label">{{ __('Confirm new password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="w-full" required minlength="6" autocomplete="new-password">
            </div>

            <button type="submit" class="btn-ghost">{{ __('Update password') }}</button>
        </form>
    </div>

    <div class="card-pad border-rose-200 dark:border-rose-900">
        <h2 class="text-base font-semibold" style="color: var(--danger)">{{ __('Delete account') }}</h2>
        <p class="lede mt-1 text-sm">
            {{ __('This permanently removes your account and trip history. It cannot be undone.') }}
        </p>

        <form method="POST" action="{{ route('app.profile.destroy') }}" class="mt-4"
              x-data
              @submit="if (! confirm('Permanently delete your account? This cannot be undone.')) $event.preventDefault()">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-danger">
                {{ __('Delete my account') }}
            </button>
        </form>
    </div>
@endsection
