{{--
    Flash + validation messages, delivered as TOASTS rather than in-page
    banners (the user rejected banners). The messages queue on
    window.__flashQueue; the toasts store drains it when Alpine starts, so
    this works no matter where in the document the include lands.
--}}
@php
    $flashMessages = [];

    if (session('status')) {
        $flashMessages[] = ['type' => 'success', 'message' => session('status')];
    }

    foreach ($errors->all() as $flashError) {
        $flashMessages[] = ['type' => 'error', 'message' => $flashError];
    }
@endphp

@if ($flashMessages !== [])
    <script>
        (window.__flashQueue = window.__flashQueue || []).push(...@json($flashMessages));
        // Store already running (e.g. include rendered after Alpine started).
        window.__drainFlashQueue?.();
    </script>
@endif
