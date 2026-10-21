<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ ($isRtl ?? false) ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.head')
</head>
{{--
    Shown while the admin has the customer side switched off. Deliberately
    standalone: no header, no footer, no navigation — there is nothing to
    navigate to — but it keeps the brand palette and theme so it still looks
    like the same product.
--}}
<body class="grid min-h-screen place-items-center px-4 py-10">

    <main class="w-full max-w-lg text-center">
        @if ($settings->headerLogo())
            <img src="{{ $settings->headerLogo() }}" alt="{{ $settings->appName() }}"
                 class="mx-auto mb-8 h-10 w-auto max-w-[11rem] object-contain">
        @else
            <p class="mb-8 text-xl font-extrabold tracking-tight">{{ $settings->appName() }}</p>
        @endif

        <div class="card-pad">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl brand-tint brand-text">
                <x-icon name="clock" class="h-7 w-7" />
            </span>

            <h1 class="mt-5 text-2xl font-extrabold tracking-tight">{{ __('We will be back shortly') }}</h1>

            <p class="lede mx-auto mt-2 max-w-sm text-sm">
                {{ __(':app is down for scheduled maintenance. Bookings already in progress are safe — please check back in a little while.', ['app' => $settings->appName()]) }}
            </p>

            <a href="{{ url()->current() }}" class="btn-primary mt-6">{{ __('Try again') }}</a>
        </div>

        @if ($settings->get('contact_email'))
            <p class="lede mt-6 text-xs">
                {{ __('Need help?') }}
                <a href="mailto:{{ $settings->get('contact_email') }}" class="font-bold brand-text">
                    {{ $settings->get('contact_email') }}
                </a>
            </p>
        @endif
    </main>

</body>
</html>
