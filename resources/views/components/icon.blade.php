@props(['name', 'class' => 'h-5 w-5'])

@php
    // Single-stroke line icons, sized by the caller. Keeping them inline avoids
    // an icon-font request and lets them inherit currentColor.
    $paths = [
        'car' => '<path d="M5 17H3v-5l2-5h14l2 5v5h-2M5 17a2 2 0 1 0 4 0M5 17h4m10 0a2 2 0 1 1-4 0m4 0h-4M3 12h18M7 7l-.6 1.5"/>',
        'package' => '<path d="M21 8v8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="3.5"/><path d="m10 13 7.5-7.5M15 8l2 2M18 5l2 2"/>',
        'receipt' => '<path d="M4 2v20l2-1.5L8 22l2-1.5L12 22l2-1.5L16 22l2-1.5L20 22V2l-2 1.5L16 2l-2 1.5L12 2l-2 1.5L8 2 6 3.5 4 2Z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'wallet' => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><circle cx="17" cy="13" r="1"/>',
        'gift' => '<path d="M20 12v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-8M2 8h20v4H2zM12 8v13"/><path d="M12 8H7.5a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8Zm0 0h4.5a2.5 2.5 0 0 0 0-5C13 3 12 8 12 8Z"/>',
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'ticket' => '<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a3 3 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a3 3 0 0 0 0-6Z"/><path d="M14 5v2M14 11v2M14 17v2"/>',
        'bank' => '<path d="M3 21h18M4 10h16M5 10V7l7-4 7 4v3M7 10v11M12 10v11M17 10v11"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3ZM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15Z"/>',
        'facebook' => '<path d="M14 8h2.5V5H14a4 4 0 0 0-4 4v2H8v3h2v7h3v-7h2.3l.7-3H13V9a1 1 0 0 1 1-1Z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
        'x-social' => '<path d="M4 4l7.2 9.3L4.4 20h2.6l5.4-5.4L16.8 20H20l-7.5-9.7L18.9 4h-2.6l-4.8 4.9L7.2 4H4Z"/>',
        'linkedin' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10v7M8 7v.01M12 17v-4a2 2 0 0 1 4 0v4M12 10v1.5"/>',
        'pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M11 16.5c.5-2 .8-3.4 1-4.2m0 0c-.2-.5-.6-1.1-.4-1.9a1.6 1.6 0 0 1 3.1.4c0 1.2-.8 2-1.9 2-.3 0-.6-.1-.8-.5Zm.3-6.2a4.2 4.2 0 0 1 3.3 6.8c-1 1.2-2.7 1.3-3.4.4"/>',
        'youtube' => '<path d="M21 8.5a3 3 0 0 0-2.1-2.1C17 6 12 6 12 6s-5 0-6.9.4A3 3 0 0 0 3 8.5 31 31 0 0 0 2.8 12 31 31 0 0 0 3 15.5a3 3 0 0 0 2.1 2.1C7 18 12 18 12 18s5 0 6.9-.4a3 3 0 0 0 2.1-2.1A31 31 0 0 0 21.2 12 31 31 0 0 0 21 8.5Z"/><path d="m10 9.75 4.5 2.25L10 14.25v-4.5Z"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? $paths['pin'] !!}
</svg>
