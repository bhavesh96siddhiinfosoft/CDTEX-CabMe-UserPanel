{{--
    Shared <head>. The brand palette is derived server-side from the colour set
    in the admin's Web Settings screen and injected here, so it applies on the
    first paint rather than flashing the default after JS runs.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>@yield('title', __('Ride, deliver, rent')) — {{ $settings->appName() }}</title>
<meta name="description" content="@yield('description', __('Book a cab, send a parcel or rent a vehicle with :app.', ['app' => $settings->appName()]))">
<meta name="theme-color" content="{{ $settings->brandColor() }}">

@if ($settings->favicon())
    <link rel="icon" href="{{ $settings->favicon() }}">
@endif

{{-- Set the theme before first paint so dark mode never flashes white. --}}
<script>
    (function () {
        var stored = localStorage.getItem('cabme_theme');
        var dark = stored ? stored === 'dark'
            : window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    })();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
{{-- Two families: Plus Jakarta Sans sets the headings, Inter everything else. --}}
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

@include('partials.i18n')
@include('partials.money')
@include('partials.push')

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Overrides the brand scale compiled into the stylesheet. --}}
<style>:root{ {!! $settings->palette()->cssVariables() !!} }</style>

<style>
        .footer-bottom { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
        .footer-bottom__copy { margin: 0; text-align: center; }
        .footer-bottom__apps { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; justify-content: center; }
        .footer-bottom__apps a { display: inline-block; transition: transform .15s ease; }
        .footer-bottom__apps a:hover { transform: translateY(-2px); }
        .footer-bottom__apps img { display: block; height: 40px; width: auto; }
        @media (min-width: 640px) {
            .footer-bottom { flex-direction: row; justify-content: space-between; }
            .footer-bottom__copy { text-align: start; }
        }
    </style>

@stack('head')
