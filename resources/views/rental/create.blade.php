@extends('layouts.shell')

@section('title', __('Rent a vehicle'))

@php
    $currency = $settings->currency();

    // Packages belong to a vehicle type; the rider sees only the ones for the
    // vehicle they picked, so the list is filtered in the browser from this.
    $packageRows = collect($packages)->map(fn ($p) => [
        'id' => (string) ($p['id'] ?? ''),
        'vehicleTypeId' => (string) ($p['vehicleTypeId'] ?? ''),
        'title' => $p['title'] ?? __('Package'),
        'description' => $p['description'] ?? '',
        'baseFare' => (float) ($p['baseFare'] ?? 0),
        'includedHours' => $p['includedHours'] ?? null,
        'includedDistance' => $p['includedDistance'] ?? null,
        'extraKmFare' => $p['extraKmFare'] ?? null,
    ])->filter(fn ($p) => $p['id'] !== '')->values();
@endphp

{{-- Rental has no drop-off, so the picker runs in single-point mode. --}}
@section('shell-attrs')
    x-data="placePicker({ singlePoint: true })"
@endsection

@section('map')
    <div class="shell-map" x-ref="map"></div>
@endsection

@section('sheet')
    <form method="POST" action="{{ route('app.rental.store') }}" class="shell-sheet"
          x-data="{
              vehicleTypeId: @js(old('vehicle_type_id', '')),
              packageId: @js(old('package_id', '')),
              couponId: '',
              paymentMethodId: @js((string) ($paymentMethods[0]['id'] ?? '')),
              packages: @js($packageRows),
              coupons: @js(collect($coupons)->map(fn ($c) => [
                  'id' => (string) ($c['id'] ?? ''),
                  'discount' => (float) ($c['discount'] ?? 0),
                  'percent' => strtolower($c['type'] ?? '') === 'percentage',
              ])->values()),

              {{-- Only this vehicle's packages, as the app does it. --}}
              get availablePackages() {
                  if (!this.vehicleTypeId) return [];
                  return this.packages.filter(p => String(p.vehicleTypeId) === String(this.vehicleTypeId));
              },

              get selectedPackage() {
                  return this.packages.find(p => String(p.id) === String(this.packageId)) ?? null;
              },

              {{-- The package's base fare. Extra distance and time are added by
                   the driver at the end, and the coupon comes off then too, so
                   this is the booking figure, not the final bill. --}}
              get amount() {
                  return this.selectedPackage ? this.selectedPackage.baseFare : null;
              },

              get canSubmit() {
                  return pickup.lat != null && this.vehicleTypeId !== '' && this.packageId !== '';
              },

              pickVehicle(id) {
                  this.vehicleTypeId = String(id);
                  {{-- A package from another vehicle must not survive the switch. --}}
                  if (!this.availablePackages.some(p => String(p.id) === String(this.packageId))) {
                      this.packageId = '';
                  }
              },
          }">
        @csrf

        <div class="shell-sheet-body space-y-4">

            @include('partials.flash')

            <div>
                <h1 class="text-lg font-bold">{{ __('Rent a vehicle') }}</h1>
                <p class="lede mt-0.5 text-sm">{{ __('By the hour or by the day, driver included.') }}</p>
            </div>

            {{-- 1. Where ----------------------------------------------------- --}}
            <div class="relative">
                <label for="pickup-search" class="mb-1 flex items-center gap-2">
                    <span class="dot-pickup !mt-0"></span>
                    <span class="eyebrow">{{ __('Pick me up at') }}</span>
                </label>

                <div class="relative">
                    <input id="pickup-search" type="search" autocomplete="off" class="w-full pe-9"
                           x-model="pickup.query" @input="onQueryInput('pickup')"
                           :placeholder="pickup.address || '{{ __('Search pickup address') }}'"
                           :class="pickup.address && !pickup.query ? 'placeholder:text-[var(--text)] placeholder:font-medium' : ''">

                    <button type="button" x-show="pickup.lat !== null || pickup.query" x-cloak
                            @click="clear('pickup')"
                            class="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-lg faint transition-colors hover:muted-surface"
                            aria-label="{{ __('Clear pickup') }}">&times;</button>
                </div>

                <div x-show="pickup.results.length" x-cloak
                     class="card absolute inset-x-0 top-full z-30 mt-1.5 max-h-56 overflow-auto p-1.5"
                     style="box-shadow: var(--shadow-lg);">
                    <template x-for="result in pickup.results" :key="result.place_id">
                        <button type="button" @click="choose('pickup', result)"
                                class="block w-full rounded-lg px-3 py-2 text-start text-sm transition-colors hover:muted-surface"
                                x-text="result.display_name"></button>
                    </template>
                </div>
            </div>

            {{-- 2. Which vehicle --------------------------------------------- --}}
            <div>
                <h2 class="mb-2 text-sm font-bold">{{ __('Choose a vehicle') }}</h2>

                @if (empty($vehicleTypes))
                    <div class="rounded-2xl border border-dashed border-token px-4 py-8 text-center">
                        <p class="lede text-sm">{{ __('No vehicles are available right now.') }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach ($vehicleTypes as $type)
                            @php $tid = (string) ($type['id'] ?? ''); @endphp
                            @continue($tid === '')

                            <button type="button" @click="pickVehicle('{{ $tid }}')"
                                    class="flex flex-col items-center gap-2 rounded-2xl border px-2 py-3 text-center transition-all"
                                    :style="String(vehicleTypeId) === '{{ $tid }}'
                                        ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.08)'
                                        : 'border-color: var(--border)'">
                                @if (! empty($type['image']))
                                    <img src="{{ $type['image'] }}" alt="" class="h-8 w-auto max-w-[3.5rem] object-contain">
                                @else
                                    <x-icon name="car" class="h-6 w-6 faint" />
                                @endif
                                <span class="w-full truncate text-[13px] font-semibold leading-tight">
                                    {{ $type['libelle'] ?? __('Vehicle') }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <input type="hidden" name="vehicle_type_id" :value="vehicleTypeId">
            </div>

            {{-- 3. Which package (for that vehicle) --------------------------- --}}
            <div>
                <h2 class="mb-2 text-sm font-bold">{{ __('Choose a package') }}</h2>

                <template x-if="!vehicleTypeId">
                    <div class="rounded-2xl border border-dashed border-token px-4 py-8 text-center">
                        <p class="lede text-sm">{{ __('Pick a vehicle to see its packages.') }}</p>
                    </div>
                </template>

                <template x-if="vehicleTypeId && availablePackages.length === 0">
                    <div class="rounded-2xl border border-dashed border-token px-4 py-8 text-center">
                        <p class="lede text-sm">{{ __('No packages for this vehicle yet.') }}</p>
                    </div>
                </template>

                <div class="space-y-2" x-show="availablePackages.length" x-cloak>
                    <template x-for="pkg in availablePackages" :key="pkg.id">
                        <button type="button" @click="packageId = pkg.id"
                                class="flex w-full items-start gap-3 rounded-xl border p-3 text-start transition-all"
                                :style="String(packageId) === String(pkg.id)
                                    ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.07)'
                                    : 'border-color: var(--border)'">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-bold" x-text="pkg.title"></span>

                                {{-- The package's own description, under its name. --}}
                                <template x-if="pkg.description">
                                    <span class="faint mt-0.5 block text-xs leading-relaxed" x-text="pkg.description"></span>
                                </template>

                                <span class="faint mt-1 block text-xs">
                                    <template x-if="pkg.includedHours">
                                        <span x-text="pkg.includedHours + ' {{ __('hr') }}'"></span>
                                    </template>
                                    <template x-if="pkg.includedDistance">
                                        <span x-text="' · ' + pkg.includedDistance + ' {{ $settings->distanceUnit() }}'"></span>
                                    </template>
                                    <template x-if="pkg.extraKmFare">
                                        <span x-text="' · +' + cabmeMoney(pkg.extraKmFare) + '/{{ $settings->distanceUnit() }} {{ __('after') }}'"></span>
                                    </template>
                                </span>
                            </span>

                            <span class="shrink-0 text-sm font-bold"
                                  x-text="cabmeMoney(pkg.baseFare)"></span>
                        </button>
                    </template>
                </div>

                <input type="hidden" name="package_id" :value="packageId">
            </div>

            {{-- 4. When it starts. No end time: the driver files the real one
                 with the final km at the end of the rental. ----------------- --}}
            <div class="space-y-3 border-t border-token pt-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="start_date" class="label">{{ __('Start') }}</label>
                        <input id="start_date" name="start_date" type="date" class="w-full" required
                               min="{{ now()->toDateString() }}" value="{{ old('start_date', now()->toDateString()) }}">
                    </div>
                    <div>
                        <label for="start_time" class="label">{{ __('Time') }}</label>
                        <input id="start_time" name="start_time" type="time" class="w-full" required
                               value="{{ old('start_time', now()->addHour()->format('H:i')) }}">
                    </div>
                </div>

                {{-- Nothing is charged here; this is the method the rider
                     intends to pay with, and it can still be changed when the
                     bill is final. --}}
                <x-payment-picker :methods="$paymentMethods" :label="__('Pay later with')" />

                <x-coupon-picker :coupons="$coupons" />
            </div>

            <input type="hidden" name="pickup_lat" :value="pickup.lat">
            <input type="hidden" name="pickup_lng" :value="pickup.lng">
            <input type="hidden" name="pickup_address" :value="pickup.address">
            {{-- The package's base fare: extras and the coupon are settled by
                 the admin when the rental is paid for at the end. --}}
            <input type="hidden" name="amount" :value="amount">
        </div>

        <div class="shell-sheet-foot">
            <div class="mb-3 flex items-baseline justify-between">
                <span class="text-sm font-medium faint">{{ __('Package price') }}</span>
                <span class="text-2xl font-extrabold"
                      x-text="amount != null ? cabmeMoney(amount) : '—'"></span>
            </div>

            <p class="lede mb-3 text-xs">
                {{ __('You pay at the end, once the driver confirms the distance travelled.') }}
            </p>

            <button type="submit" class="btn-primary btn-lg w-full" :disabled="!canSubmit">
                {{ __('Book rental') }}
            </button>
        </div>
    </form>
@endsection
