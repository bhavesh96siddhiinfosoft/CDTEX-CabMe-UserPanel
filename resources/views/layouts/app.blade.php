<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ ($isRtl ?? false) ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.head')
    
</head>
<body class="min-h-screen flex flex-col" data-build="{{ \App\Support\BuildStamp::current() }}">

    @include('partials.header')
    @include('partials.api-down')

    <main class="flex-1">
        @yield('hero')

        <div class="@yield('container', 'mx-auto w-full max-w-6xl px-4 py-8 sm:py-10')">
            @include('partials.flash')
            @yield('content')
        </div>
    </main>

    @include('partials.footer')
    @include('partials.toasts')
    @include('partials.cookie-consent')

    @stack('scripts')
</body>
</html>
