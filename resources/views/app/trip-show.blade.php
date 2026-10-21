@extends('layouts.app')

@section('title', __('Trip details'))
@section('container', 'mx-auto w-full max-w-3xl px-4 py-8')

@section('content')
    @php
        $status = $trip['statut'] ?? $trip['status'] ?? '';
        $from = $trip['depart_name'] ?? $trip['source'] ?? $trip['source_adrs'] ?? null;
        $to = $trip['destination_name'] ?? $trip['destination'] ?? $trip['destination_adrs'] ?? null;
        $amount = $trip['montant'] ?? $trip['amount'] ?? null;
        $when = $trip['creer'] ?? $trip['created_at'] ?? null;
        $driver = $trip['driver'] ?? null;

        // The admin decodes `stops` for us, but an older payload can still
        // arrive as the raw json string it stores.
        $stops = $trip['stops'] ?? [];
        $stops = is_string($stops) ? (json_decode($stops, true) ?: []) : $stops;
        $stops = array_values(array_filter(is_array($stops) ? $stops : [], 'is_array'));
        $isComplete = in_array(strtolower($status), ['completed', 'complete'], true);

        // What the rider actually used, worked out the way the admin bills it
        // (RentalCompleteController): distance beyond the package's included
        // km, and minutes beyond its included hours. Null until the driver
        // files the final km, when there is nothing consumed to report yet.
        $rentalExtras = (function () use ($trip) {
            $package = $trip['package_details'] ?? [];
            $finalKm = (float) ($trip['complete_km'] ?? 0) - (float) ($trip['current_km'] ?? 0);

            if ($finalKm <= 0) {
                return null;
            }

            $extraKm = max(0, $finalKm - (float) ($package['includedDistance'] ?? 0));

            $minutes = 0;

            if (! empty($trip['start_date']) && ! empty($trip['end_date'])) {
                try {
                    $start = \Illuminate\Support\Carbon::parse(trim($trip['start_date'].' '.($trip['start_time'] ?? '')));
                    $end = \Illuminate\Support\Carbon::parse(trim($trip['end_date'].' '.($trip['end_time'] ?? '')));
                    $minutes = abs($end->diffInMinutes($start));
                } catch (\Throwable) {
                    $minutes = 0;
                }
            }

            $extraMinutes = max(0, $minutes - (float) ($package['includedHours'] ?? 0) * 60);

            return [
                'km' => $extraKm,
                'km_charge' => $extraKm * (float) ($package['extraKmFare'] ?? 0),
                'minutes' => $extraMinutes,
                'minute_charge' => $extraMinutes * (float) ($package['extraMinuteFare'] ?? 0),
            ];
        })();

        // Rental packages arrive whole, so every figure the rider agreed to
        // (included hours and distance, and what the extras cost) is here.
        $package = $trip['package_details'] ?? [];

        $stamp = function (?string $date, ?string $time) {
            if (! $date) {
                return null;
            }

            try {
                return \Illuminate\Support\Carbon::parse(trim($date.' '.$time))->format('d M Y, g:i A');
            } catch (\Throwable) {
                return trim($date.' '.$time);
            }
        };

        // The admin sends parcel photos as a json array of file names already
        // resolved to urls; an order with none sends an empty array.
        $photos = $trip['parcel_image'] ?? [];
        $photos = is_string($photos) ? (json_decode($photos, true) ?: []) : $photos;
        $photos = array_values(array_filter(is_array($photos) ? $photos : []));
    @endphp

    <a href="{{ route('app.trips', $type) }}" class="faint mb-4 inline-block text-sm hover:opacity-70">← {{ __('Back to trips') }}</a>

    <div class="card-pad">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold">{{ $trip['booking_number'] ?? '#'.($trip['id'] ?? '') }}</h1>
                @if ($when)
                    <p class="lede mt-1 text-sm">
                        {{ \Illuminate\Support\Carbon::parse($when)->format('d M Y, g:i A') }}
                    </p>
                @endif
            </div>
            <x-status-badge :status="$status" />
        </div>

        @if ($from || $to)
            <div class="mt-5 space-y-3">
                @if ($from)
                    <div class="flex items-start gap-2 text-sm">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-emerald-500"></span>
                        <span>
                            @if ($type === 'parcel')
                                <span class="eyebrow block">{{ __('Sender Location') }}</span>
                            @endif
                            {{ $from }}
                        </span>
                    </div>
                @endif
                {{-- Intermediate stops, in the order the driver takes them. --}}
                @foreach ($stops as $index => $stop)
                    <div class="flex items-start gap-2 text-sm">
                        <span class="mt-1 grid h-2.5 w-2.5 shrink-0 place-items-center rounded-full border-2"
                              style="border-color: var(--text-muted);"></span>
                        <span>
                            <span class="eyebrow block">{{ __('Stop :number', ['number' => $index + 1]) }}</span>
                            {{ $stop['location'] ?? $stop['address'] ?? $stop['name'] ?? '—' }}
                        </span>
                    </div>
                @endforeach

                @if ($to)
                    <div class="flex items-start gap-2 text-sm">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: var(--color-brand-600);"></span>
                        <span>
                            @if ($type === 'parcel')
                                <span class="eyebrow block">{{ __('Receiver Location') }}</span>
                            @endif
                            {{ $to }}
                        </span>
                    </div>
                @endif
            </div>
        @endif

        @if ($type === 'ride' && ($trip['distance'] ?? null) !== null)
            <p class="lede mt-4 border-t border-token pt-3 text-sm">
                {{ $trip['distance'] }} {{ $trip['distance_unit'] ?? $settings->distanceUnit() }}
                @if (! empty($trip['duree'])) · {{ __('about :duration', ['duration' => $trip['duree'].' '.__('min')]) }} @endif
            </p>
        @endif
    </div>

    {{-- Parcel: who is sending and receiving, when, and what is in the box. --}}
    @if ($type === 'parcel')
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="card-pad mt-4">
                <h2 class="text-base font-semibold">{{ __('Sender Information') }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Name') }}</dt>
                        <dd class="text-end">{{ $trip['sender_name'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Mobile Number') }}</dt>
                        <dd class="text-end">{{ $trip['sender_phone'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Pickup') }}</dt>
                        <dd class="text-end">{{ $stamp($trip['parcel_date'] ?? null, $trip['parcel_time'] ?? null) ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="card-pad mt-4">
                <h2 class="text-base font-semibold">{{ __('Receiver Information') }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Name') }}</dt>
                        <dd class="text-end">{{ $trip['receiver_name'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Mobile Number') }}</dt>
                        <dd class="text-end">{{ $trip['receiver_phone'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Delivery') }}</dt>
                        <dd class="text-end">{{ $stamp($trip['receive_date'] ?? null, $trip['receive_time'] ?? null) ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="card-pad mt-4">
            <h2 class="text-base font-semibold">{{ __('Parcel') }}</h2>

            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="faint">{{ __('Parcel type') }}</dt>
                    <dd class="text-end">{{ $trip['parcel_type'] ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="faint">{{ __('Weight') }}</dt>
                    <dd class="text-end">{{ ($trip['parcel_weight'] ?? null) !== null ? $trip['parcel_weight'].' '.__('Kg') : '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="faint">{{ __('Height') }}</dt>
                    <dd class="text-end">{{ ($trip['parcel_dimension'] ?? null) !== null && $trip['parcel_dimension'] !== '' ? $trip['parcel_dimension'].' '.__('ft.') : '—' }}</dd>
                </div>
                @if (! empty($trip['note']))
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Notes (Optional)') }}</dt>
                        <dd class="text-end">{{ $trip['note'] }}</dd>
                    </div>
                @endif
                @if (($trip['distance'] ?? null) !== null)
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Distance') }}</dt>
                        <dd class="text-end">{{ $trip['distance'] }} {{ $trip['distance_unit'] ?? $settings->distanceUnit() }}</dd>
                    </div>
                @endif
            </dl>

            @if ($photos)
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($photos as $photo)
                        <a href="{{ $photo }}" target="_blank" rel="noopener">
                            <img src="{{ $photo }}" alt="{{ __('Parcel photos') }}"
                                 class="h-20 w-20 rounded-xl object-cover sunken-surface" loading="lazy">
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Rental: the package the rider bought, and what it includes. --}}
    @if ($type === 'rental')
        <div class="card-pad mt-4">
            <h2 class="text-base font-semibold">{{ __('Rental package') }}</h2>

            <div class="mt-3">
                <p class="font-bold">
                    {{ $package['title'] ?? __('Package') }}
                    @if (! empty($trip['vehicle_name'])) · {{ $trip['vehicle_name'] }} @endif
                </p>
                @if (! empty($package['description']))
                    <p class="lede mt-0.5 text-sm">{{ $package['description'] }}</p>
                @endif
            </div>

            <dl class="mt-3 space-y-2 border-t border-token pt-3 text-sm">
                @isset($package['baseFare'])
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Package price') }}</dt>
                        <dd class="text-end font-semibold">{{ $settings->money($package['baseFare']) }}</dd>
                    </div>
                @endisset
                @isset($package['includedHours'])
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Included hours') }}</dt>
                        <dd class="text-end">{{ $package['includedHours'] }} {{ __('hr') }}</dd>
                    </div>
                @endisset
                @isset($package['includedDistance'])
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Included distance') }}</dt>
                        <dd class="text-end">{{ $package['includedDistance'] }} {{ $trip['distance_unit'] ?: $settings->distanceUnit() }}</dd>
                    </div>
                @endisset
                {{-- Once the trip is closed these are what the rider actually
                     used; until then only the rate is known. --}}
                @isset($package['extraKmFare'])
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ $rentalExtras ? __('Extra distance') : __('Extra distance rate') }}</dt>
                        <dd class="text-end">
                            @if ($rentalExtras)
                                {{ $rentalExtras['km'] + 0 }} {{ $trip['distance_unit'] ?: $settings->distanceUnit() }}
                                @if ($rentalExtras['km_charge'] > 0)
                                    <span class="faint">· {{ $settings->money($rentalExtras['km_charge']) }}</span>
                                @endif
                            @else
                                {{ $settings->money($package['extraKmFare']) }} / {{ $trip['distance_unit'] ?: $settings->distanceUnit() }}
                            @endif
                        </dd>
                    </div>
                @endisset
                @isset($package['extraMinuteFare'])
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ $rentalExtras ? __('Extra time') : __('Extra time rate') }}</dt>
                        <dd class="text-end">
                            @if ($rentalExtras)
                                {{ $rentalExtras['minutes'] + 0 }} {{ __('min') }}
                                @if ($rentalExtras['minute_charge'] > 0)
                                    <span class="faint">· {{ $settings->money($rentalExtras['minute_charge']) }}</span>
                                @endif
                            @else
                                {{ $settings->money($package['extraMinuteFare']) }} / {{ __('min') }}
                            @endif
                        </dd>
                    </div>
                @endisset
            </dl>

            <dl class="mt-3 space-y-2 border-t border-token pt-3 text-sm">
                @if (! empty($trip['start_date']))
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Start') }}</dt>
                        <dd class="text-end">{{ $stamp($trip['start_date'], $trip['start_time'] ?? null) }}</dd>
                    </div>
                @endif
                @if (! empty($trip['end_date']))
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('End') }}</dt>
                        <dd class="text-end">{{ $stamp($trip['end_date'], $trip['end_time'] ?? null) }}</dd>
                    </div>
                @endif
                {{-- Only meaningful once the driver has filed the final km. --}}
                @if ((float) ($trip['complete_km'] ?? 0) > 0)
                    <div class="flex justify-between gap-3">
                        <dt class="faint">{{ __('Distance travelled') }}</dt>
                        <dd class="text-end">
                            {{ (float) $trip['complete_km'] - (float) ($trip['current_km'] ?? 0) }}
                            {{ $trip['distance_unit'] ?: $settings->distanceUnit() }}
                        </dd>
                    </div>
                @endif
            </dl>
        </div>
    @endif

    {{-- Fare breakdown, worked out the way the admin bills it: the stored
         amount, minus the coupon it was booked with, plus its own tax
         snapshot. --}}
    @php
        $bill = \App\Support\BookingBill::due(
            (float) ($amount ?? 0),
            $trip['discount_type'] ?? null,
            $trip['tax'] ?? null,
        );

        $costLabel = match ($type) {
            'parcel' => __('Delivery cost'),
            'rental' => __('Rental charge'),
            default => __('Ride cost'),
        };
    @endphp

    <div class="card-pad mt-4">
        <h2 class="text-base font-semibold">{{ __('Billing summary') }}</h2>

        <dl class="mt-3 space-y-2 text-sm">
            {{-- Surge is deliberately not shown. The apps never surface the
                 multiplier either — the rider sees the fare it produced, not
                 the factor. It is still sent to the admin with the booking. --}}

            <x-billing-summary :bill="$bill" :cost-label="$costLabel" compact />

            @isset($trip['payment_method'])
                <div class="flex justify-between border-t border-token pt-2">
                    <dt class="faint">{{ __('Paid with') }}</dt>
                    <dd>{{ $trip['payment_method'] }}</dd>
                </div>
            @endisset
        </dl>
    </div>

    @if ($driver)
        <div class="card-pad mt-4">
            <h2 class="text-base font-semibold">{{ __('Driver') }}</h2>
            <div class="mt-3 flex items-center gap-3">
                <img src="{{ $driver['image'] ?? '' }}" alt="" class="h-12 w-12 rounded-full object-cover muted-surface">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-x-2 font-semibold">
                        <span>{{ trim(($driver['prenom'] ?? '').' '.($driver['nom'] ?? '')) }}</span>
                        <x-driver-rating :driver="$driver" />
                    </p>
                    <p class="lede text-sm">
                        {{ $driver['vehicle_details']['numberplate'] ?? '' }}
                    </p>
                    @php
                        $vehicle = $driver['vehicle_details'] ?? [];
                        $vehicleLabel = trim(implode(' ', array_filter([
                            $vehicle['brand'] ?? null,
                            $vehicle['model'] ?? null,
                        ])));
                    @endphp
                    @if ($vehicleLabel !== '')
                        {{-- Brand and model, under the plate. --}}
                        <p class="lede text-xs">{{ $vehicleLabel }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Rating, once the trip is done. An existing review can be changed: the
         admin keys them on booking + driver and replaces in place, so the same
         form serves both cases. --}}
    @if ($isComplete)
        @php
            $hasReview = ! empty($review);
            $savedRating = (int) ($review['rating'] ?? 0);
            $savedComment = $review['comment'] ?? '';
        @endphp

        <div class="card-pad mt-4"
             x-data="{
                 rating: {{ $savedRating }},
                 editing: {{ ! $hasReview || $errors->any() ? 'true' : 'false' }},
             }">

            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold">
                    {{ $hasReview ? __('Your review') : __('Rate this trip') }}
                </h2>

                @if ($hasReview)
                    <button type="button" class="btn-ghost" x-show="!editing" x-cloak
                            @click="editing = true">{{ __('Edit review') }}</button>
                @endif
            </div>

            @if ($hasReview)
                <div x-show="!editing">
                    <p class="mt-2 text-amber-400">
                        {{ str_repeat('★', $savedRating) }}{{ str_repeat('☆', 5 - $savedRating) }}
                    </p>
                    @if ($savedComment !== '')
                        <p class="lede mt-2 text-sm">{{ $savedComment }}</p>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('app.trips.review', [$type, $trip['id'] ?? 0]) }}"
                  class="mt-3" x-show="editing" x-cloak>
                @csrf

                <div class="flex gap-1">
                    <template x-for="star in [1,2,3,4,5]" :key="star">
                        <button type="button" @click="rating = star"
                                class="text-3xl transition-transform hover:scale-110"
                                :class="star <= rating ? 'text-amber-400' : 'faint'"
                                :aria-label="`Rate ${star} out of 5`">★</button>
                    </template>
                </div>

                <input type="hidden" name="rating" :value="rating">

                <textarea name="comment" rows="3" class="mt-3 w-full"
                          placeholder="{{ __('Tell us how it went (optional)') }}">{{ old('comment', $savedComment) }}</textarea>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary" x-bind:disabled="rating === 0">
                        {{ $hasReview ? __('Update review') : __('Submit review') }}
                    </button>

                    @if ($hasReview)
                        <button type="button" class="btn-ghost"
                                @click="editing = false; rating = {{ $savedRating }}">{{ __('Cancel') }}</button>
                    @endif
                </div>
            </form>
        </div>
    @endif

    {{-- Something went wrong? One complaint per booking, which is all the
         admin accepts, and only once the trip is over. --}}
    @if ($isComplete)
        @php
            // The admin serialises complaint dates as d-m-Y h:i A, which
            // Carbon would otherwise read as a US month-first date.
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

        <div class="card-pad mt-4">
            @if ($complaint)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">{{ __('Your complaint') }}</h2>
                    <x-status-badge :status="$complaint['status'] ?? 'initiated'" />
                </div>

                <p class="mt-3 font-semibold">{{ $complaint['title'] ?? '' }}</p>

                @if (! empty($complaint['description']))
                    <p class="lede mt-1 text-sm">{{ $complaint['description'] }}</p>
                @endif

                @if ($raised)
                    <p class="lede mt-3 text-xs">{{ __('Raised on :date', ['date' => $raised]) }}</p>
                @endif
            @else
                <div x-data="{ open: {{ $errors->any() ? 'true' : 'false' }}, sending: false }">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold">{{ __('Something wrong with this trip?') }}</h2>
                            <p class="lede mt-0.5 text-sm">
                                {{ __('Tell us what happened and our team will look into it.') }}
                            </p>
                        </div>

                        <button type="button" class="btn-ghost shrink-0" x-show="!open" @click="open = true">
                            {{ __('Report an issue') }}
                        </button>
                    </div>

                    <form x-show="open" x-cloak method="POST"
                          action="{{ route('app.trips.complaint', [$type, $trip['id'] ?? 0]) }}"
                          class="mt-4 space-y-3" @submit="sending = true">
                        @csrf

                        <div>
                            <label for="complaint-title" class="label">{{ __('Subject') }}</label>
                            <input id="complaint-title" name="title" type="text" class="w-full" required
                                   maxlength="255" value="{{ old('title') }}"
                                   placeholder="{{ __('What went wrong?') }}">
                        </div>

                        <div>
                            <label for="complaint-description" class="label">{{ __('Message') }}</label>
                            <textarea id="complaint-description" name="description" rows="4" class="w-full" required
                                      maxlength="2000"
                                      placeholder="{{ __('Give us the details') }}">{{ old('description') }}</textarea>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="btn-primary" :disabled="sending">
                                <span x-show="!sending">{{ __('Submit complaint') }}</span>
                                <span x-show="sending" x-cloak>{{ __('Submitting…') }}</span>
                            </button>
                            <button type="button" class="btn-ghost" @click="open = false">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>
    @endif
    {{-- Track and cancel share one row: two stacked full-width buttons pushed
         the page down for no reason. --}}
    @php
        $canTrack = in_array($type, ['parcel', 'rental'], true)
            && in_array(strtolower($status), ['new', 'confirmed', 'on ride'], true);
        $canCancel = in_array(strtolower($status), ['new', 'confirmed'], true);
    @endphp

    @if ($canTrack || $canCancel)
        <div class="mt-4 flex gap-2">
            @if ($canTrack)
                <a href="{{ route('app.'.$type.'.track', $trip['id'] ?? 0) }}" class="btn-primary flex-1">
                    {{ __('Track live') }}
                </a>
            @endif

            @if ($canCancel)
                {{-- One tap, as in the app — no alert and no reason to pick. --}}
                <form method="POST" action="{{ route('app.'.$type.'.cancel', $trip['id'] ?? 0) }}" class="flex-1"
                      x-data="{ cancelling: false }" @submit="cancelling = true">
                    @csrf
                    <button type="submit" class="btn-danger w-full" :disabled="cancelling">
                        <span x-show="!cancelling">{{ __('Cancel') }}</span>
                        <span x-show="cancelling" x-cloak>{{ __('Cancelling…') }}</span>
                    </button>
                </form>
            @endif
        </div>
    @endif
@endsection
