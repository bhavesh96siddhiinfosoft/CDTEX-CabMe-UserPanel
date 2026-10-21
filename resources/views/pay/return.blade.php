<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Finishing payment') }} — {{ config('app.name') }}</title>
    {{-- A standalone popup: it loads none of the app's CSS, so it carries its
         own tokens. The rider's choice lives in localStorage (the theme store
         persists it there); the system preference is the fallback. --}}
    <style>
        :root { color-scheme: light; --bg: #f6f6f7; --card: #ffffff; --ink: #1c1c1e; --muted: #6b6b70; --link: #2563eb; }

        @media (prefers-color-scheme: dark) {
            :root { color-scheme: dark; --bg: #0b0d12; --card: #171b23; --ink: #f2f4f7; --muted: #98a2b3; --link: #84a9ff; }
        }

        html[data-theme='light'] { color-scheme: light; --bg: #f6f6f7; --card: #ffffff; --ink: #1c1c1e; --muted: #6b6b70; --link: #2563eb; }
        html[data-theme='dark'] { color-scheme: dark; --bg: #0b0d12; --card: #171b23; --ink: #f2f4f7; --muted: #98a2b3; --link: #84a9ff; }

        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: var(--bg); color: var(--ink); }
        .card { background: var(--card); border-radius: 16px; padding: 32px 40px; box-shadow: 0 10px 30px rgb(0 0 0 / 0.25); text-align: center; max-width: 22rem; }
        .muted { color: var(--muted); }
        a { color: var(--link); }
    </style>

    <script>
        // Before paint, so the popup never flashes the wrong theme.
        try {
            var saved = localStorage.getItem('cabme_theme');
            if (saved) document.documentElement.setAttribute('data-theme', saved);
        } catch (e) { /* private mode: fall back to the system preference */ }
    </script>
</head>
<body>
    <div class="card">
        <p style="font-weight:700">{{ __('Finishing payment') }}…</p>
        <p id="hint" class="muted" style="font-size:14px">{{ __('You can close this window.') }}</p>
        <p id="fallback" style="display:none;font-size:14px"><a href="{{ url('/app/wallet') }}">{{ __('Return to the app') }}</a></p>
    </div>

    <script>
        // Hand the gateway's return parameters to the tab that started the
        // payment; the real verification happens server-side there.
        (function () {
            var params = {};
            new URLSearchParams(window.location.search).forEach(function (value, key) { params[key] = value; });

            if (window.opener && !window.opener.closed) {
                window.opener.postMessage({ cabmePayment: true, params: params }, window.location.origin);
                window.close();
                // Some browsers refuse window.close(); leave the hint visible.
            } else {
                document.getElementById('fallback').style.display = 'block';
            }
        })();
    </script>
</body>
</html>
