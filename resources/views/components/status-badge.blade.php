@props(['status'])

@php
    // Maps the admin's `statut` values onto the pill styles in app.css.
    $key = strtolower(trim((string) $status));

    $class = match ($key) {
        'new', 'pending', 'initiated' => 'badge-new',
        'confirmed', 'accepted' => 'badge-confirmed',
        'on ride', 'onride', 'picked up', 'processing' => 'badge-onride',
        'completed', 'complete' => 'badge-completed',
        'cancelled', 'canceled', 'rejected' => 'badge-cancelled',
        default                 => 'badge-neutral',
    };

    $label = match ($key) {
        'new'       => __('Finding a driver'),
        // Complaint states, which share these pills.
        'initiated'  => __('Submitted'),
        'processing' => __('In review'),
        'confirmed' => __('Driver assigned'),
        'on ride'   => __('In progress'),
        default     => ucfirst($key ?: __('Unknown')),
    };
@endphp

<span class="{{ $class }}">{{ $label }}</span>
