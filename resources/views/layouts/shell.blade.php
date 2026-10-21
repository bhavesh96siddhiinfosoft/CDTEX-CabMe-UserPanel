<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ ($isRtl ?? false) ? 'rtl' : 'ltr' }}" class="shell-locked">
<head>
    @include('partials.head')

    {{-- Critical layout, inlined deliberately.
         This markup is rendered fresh on every request, so shipping the frame
         rules here means a cached or stale stylesheet cannot break the shell.
         Getting this wrong collapses the page to half height and lets the
         document scroll behind the map, so it is not left to the bundle. --}}
    <style>
        html { height: 100%; overflow: hidden; }
        body {
            position: fixed;
            /* Longhands first: `inset` is the shorthand, and if a browser does
               not apply it the body collapses to content height and the shell
               shrinks to a sliver. The longhands cost nothing and remove that
               failure mode entirely. */
            top: 0; right: 0; bottom: 0; left: 0;
            inset: 0;
            width: 100%; height: 100%;
            margin: 0;
            display: flex; flex-direction: column;
            overflow: hidden; overscroll-behavior: none;
        }
        body > header { flex: none; }
        .shell {
            flex: 1 1 auto;
            min-height: 0;
            /* Explicit fallback for the same reason: if flex sizing does not
               resolve, the shell still gets a usable height. */
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        .shell-sheet-body { overscroll-behavior: contain; }
    </style>
</head>
{{--
    Map-first app shell: the map fills the viewport below the header and the
    booking / tracking sheet floats over it. No footer and no page scroll — the
    sheet scrolls internally instead.
--}}
<body class="shell-frame" data-build="{{ \App\Support\BuildStamp::current() }}">

    @include('partials.header')
    @include('partials.api-down')

    {{-- The Alpine root lives here so it covers both the map and the sheet,
         which are siblings. Pages supply it via the shell-attrs section. --}}
    <div class="shell" @yield('shell-attrs')>
        @yield('map')
        @yield('sheet')
    </div>

    @include('partials.toasts')

    @stack('scripts')
</body>
</html>
