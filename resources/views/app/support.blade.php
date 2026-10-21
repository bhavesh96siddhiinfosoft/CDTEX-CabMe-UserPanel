@extends('layouts.app')

@section('title', __('Help & support'))
@section('container', 'mx-auto w-full max-w-3xl px-4 py-8')

@section('content')
    <x-page-header :title="__('Help & support')"
                   :subtitle="__('Everything you have raised with us, and where it stands.')" />

    {{-- There is no free-form complaint: the admin keys every one to a booking
         and accepts a single complaint per booking, so riders raise them from
         the trip itself. --}}
    <div class="card-pad mb-6">
        <h2 class="text-base font-semibold">{{ __('Have a problem with a trip?') }}</h2>
        <p class="lede mt-1 text-sm">
            {{ __('Open the trip it is about and tap “Report an issue”. You can raise one complaint per booking.') }}
        </p>
        <a href="{{ route('app.trips') }}" class="btn-primary mt-4">{{ __('Go to my trips') }}</a>
    </div>

    <h2 class="mb-3 text-base font-semibold">{{ __('Your complaints') }}</h2>

    <x-list-filters :action="route('app.support')"
                    :placeholder="__('Search by subject or booking')"
                    :amount="false" />

    @forelse ($complaints as $complaint)
        @php
            $type = $complaint['booking_type'] ?? null;
            $bookingId = $complaint['booking_id'] ?? null;

            // d-m-Y h:i A is what the admin serialises; Carbon would read it
            // month-first without the explicit format.
            $raised = null;

            if (! empty($complaint['created_at'])) {
                try {
                    $raised = \Illuminate\Support\Carbon::createFromFormat('d-m-Y h:i A', $complaint['created_at'])
                        ->format('d M Y, g:i A');
                } catch (\Throwable) {
                    $raised = $complaint['created_at'];
                }
            }
        @endphp

        <div class="card mb-2 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold">{{ $complaint['title'] ?? __('Complaint') }}</p>
                <x-status-badge :status="$complaint['status'] ?? 'initiated'" />
            </div>

            @if (! empty($complaint['description']))
                <p class="lede mt-2 text-sm">{{ $complaint['description'] }}</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                @if ($type && $bookingId)
                    <a href="{{ route('app.trips.show', [$type, $bookingId]) }}"
                       class="text-xs font-bold brand-text hover:opacity-70">
                        {{ __(':type #:id', ['type' => ucfirst($type), 'id' => $bookingId]) }} →
                    </a>
                @endif

                @if ($raised)
                    <p class="lede text-xs">{{ $raised }}</p>
                @endif
            </div>
        </div>
    @empty
        <x-empty-state :title="__('No complaints yet')"
                       :message="__('Anything you raise from a trip will show up here with its status.')" icon="chat" />
    @endforelse

    <x-pager :paginator="$complaints" />
@endsection
