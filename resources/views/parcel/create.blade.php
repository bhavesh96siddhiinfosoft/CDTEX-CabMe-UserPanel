@extends('layouts.shell')

@section('title', __('Send a parcel'))

@php
    $currency = $settings->currency();
    $unit = $settings->distanceUnit();

    // The admin's category rows carry their own artwork and blurb; the fare is
    // NOT per category (it is per km + per kg for the zone), so prices belong
    // on the summary step, once the route and weight are known.
    $categoryRows = collect($categories)->map(fn ($c) => [
        'id' => (string) ($c['id'] ?? ''),
        'title' => $c['title'] ?? __('Parcel'),
        'description' => $c['description'] ?? '',
        'image' => $c['image'] ?? null,
    ])->filter(fn ($c) => $c['id'] !== '')->values();
@endphp

@section('shell-attrs')
    x-data="mapBooking({
        quoteUrl: '{{ route('app.parcel.quote') }}',
        optionsKey: 'categories',
        defaultPaymentMethod: @js((string) ($paymentMethods[0]['id'] ?? '')),
        checkout: 'parcel',
        coupons: @js(collect($coupons)->map(fn ($c) => [
            'id' => (string) ($c['id'] ?? ''),
            'discount' => (float) ($c['discount'] ?? 0),
            'percent' => strtolower($c['type'] ?? '') === 'percentage',
        ])->values()),
    })"
@endsection

@section('map')
    <div class="shell-map" x-ref="map"></div>
@endsection

@section('sheet')
    {{-- Gateway kinds for the checkout interceptor --}}
    <script>
        window.__methodKinds = @js(collect($paymentMethods)->pluck('kind', 'id'));
        window.__methodFlows = @js(collect($paymentMethods)->pluck('flow', 'id'));
    </script>

    {{--
        The app's parcel flow, step for step: what you are sending → who is
        sending and receiving it → what it costs and how it is paid. All fields
        stay in the DOM (x-show, not x-if) so every value posts regardless of
        the step showing; a server-side validation bounce reopens on step 3,
        where booking and payment errors surface.
    --}}
    <form method="POST" action="{{ route('app.parcel.store') }}" enctype="multipart/form-data" class="shell-sheet"
          x-data="{
              step: {{ $errors->any() ? 3 : 1 }},
              categoryId: @js(old('category_id', '')),
              category: null,

              stepOk(container) {
                  for (const field of container.querySelectorAll('input, select, textarea')) {
                      if (!field.reportValidity()) return false;
                  }
                  return true;
              },

              pick(row) {
                  this.categoryId = row.id;
                  this.category = row;
                  {{-- Keep the quote pinned to this category once prices land.
                       Parent state needs `this.` inside a method body: only
                       attribute expressions get Alpine's scope chain. --}}
                  this.selected = this.options.find(o => String(o.id) === String(row.id)) ?? { id: row.id };
              },

              next() {
                  if (this.step === 1 && this.categoryId) {
                      this.step = 2;
                  } else if (this.step === 2 && this.stepOk(this.$refs.details) && this.ready) {
                      this.step = 3;
                      this.requote();
                  }
              },
          }"
          @submit.prevent="step === 3 ? submitWithPayment($event) : next()">
        @csrf

        <div class="shell-sheet-body space-y-4">

            @include('partials.flash')

            <div>
                <h1 class="text-lg font-bold">{{ __('Send a parcel') }}</h1>
                <p class="lede mt-0.5 text-sm">{{ __('Door to door, tracked the whole way.') }}</p>
            </div>

            {{-- Step rail --}}
            <div class="flex items-center gap-2">
                @foreach ([1 => __('Category'), 2 => __('Details'), 3 => __('Payment')] as $n => $label)
                    <button type="button" class="flex flex-1 flex-col items-center gap-1.5"
                            @click="if ({{ $n }} < step) step = {{ $n }}">
                        <span class="h-1.5 w-full rounded-full transition-colors"
                              :style="step >= {{ $n }} ? 'background-color: var(--color-brand-600)' : 'background-color: var(--border)'"></span>
                        <span class="text-[11px] font-bold uppercase tracking-wide"
                              :class="step >= {{ $n }} ? '' : 'faint'">{{ $label }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Step 1 — What are you sending? ----------------------------- --}}
            <div x-show="step === 1" class="space-y-3">
                <h2 class="text-sm font-bold">{{ __('What do you want to send?') }}</h2>

                @if ($categoryRows->isEmpty())
                    <div class="rounded-2xl border border-dashed border-token px-4 py-8 text-center">
                        <p class="lede text-sm">{{ __('No parcel types are available right now.') }}</p>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($categoryRows as $row)
                            <button type="button" @click="pick(@js($row))"
                                    class="flex w-full items-center gap-3 rounded-2xl border p-3 text-start transition-all hover:-translate-y-0.5"
                                    :style="String(categoryId) === '{{ $row['id'] }}'
                                        ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.07)'
                                        : 'border-color: var(--border)'">
                                @if ($row['image'])
                                    <img src="{{ $row['image'] }}" alt="" class="h-10 w-10 shrink-0 rounded-xl object-contain">
                                @else
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl brand-tint brand-text">
                                        <x-icon name="package" class="h-5 w-5" />
                                    </span>
                                @endif

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-bold">{{ $row['title'] }}</span>
                                    @if ($row['description'])
                                        <span class="faint mt-0.5 block line-clamp-2 text-xs">{{ $row['description'] }}</span>
                                    @endif
                                </span>

                                <template x-if="String(categoryId) === '{{ $row['id'] }}'">
                                    <x-icon name="check" class="h-5 w-5 shrink-0 brand-text" />
                                </template>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Step 2 — Sender and receiver ------------------------------- --}}
            <div x-show="step === 2" x-cloak x-ref="details" class="space-y-5">

                {{-- Photos of the parcel, first, as in the app. Picks accumulate:
                     choosing more photos adds to the ones already attached
                     rather than replacing them, and each can be removed on its
                     own. The strip scrolls sideways, with arrows for anyone
                     without a trackpad. --}}
                <div x-data="{
                        max: 5,
                        shots: [],

                        add(event) {
                            const picked = Array.from(event.target.files ?? []);

                            for (const file of picked) {
                                const already = this.shots.some(s =>
                                    s.file.name === file.name
                                    && s.file.size === file.size
                                    && s.file.lastModified === file.lastModified);

                                if (!already && this.shots.length < this.max) {
                                    this.shots.push({ file, url: URL.createObjectURL(file) });
                                }
                            }

                            this.sync();
                        },

                        remove(index) {
                            URL.revokeObjectURL(this.shots[index].url);
                            this.shots.splice(index, 1);
                            this.sync();
                        },

                        {{-- The input is the thing that actually posts, so rebuild
                             its FileList from what is on screen. --}}
                        sync() {
                            const bag = new DataTransfer();
                            this.shots.forEach(s => bag.items.add(s.file));
                            this.$refs.input.files = bag.files;
                        },

                        scroll(direction) {
                            this.$refs.strip.scrollBy({ left: direction * 160, behavior: 'smooth' });
                        },
                     }">
                    <label for="images" class="label">{{ __('Parcel photos') }}
                        <span class="faint font-normal">{{ __('(up to 5)') }}</span>
                    </label>
                    <input id="images" name="images[]" type="file" accept="image/*" multiple class="w-full text-sm"
                           x-ref="input" @change="add($event)">

                    <div x-show="shots.length" x-cloak class="relative mt-3">
                        <div x-ref="strip"
                             class="flex gap-2 overflow-x-auto scroll-smooth pb-1"
                             style="scrollbar-width: thin;">
                            <template x-for="(shot, index) in shots" :key="shot.url">
                                <div class="relative shrink-0">
                                    <img :src="shot.url" :alt="shot.file.name"
                                         class="h-24 w-24 rounded-xl border border-token object-cover">

                                    <button type="button" @click="remove(index)"
                                            class="absolute -end-1 -top-1 grid h-6 w-6 place-items-center rounded-full border border-token text-sm font-bold"
                                            style="background-color: var(--surface-raised); box-shadow: var(--shadow-sm);"
                                            :aria-label="'{{ __('Remove') }} ' + shot.file.name">&times;</button>
                                </div>
                            </template>
                        </div>

                        {{-- Only worth showing once the strip can actually move. --}}
                        <template x-if="shots.length > 3">
                            <div>
                                <button type="button" @click="scroll(-1)"
                                        class="absolute start-1 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full border border-token"
                                        style="background-color: var(--surface-raised); box-shadow: var(--shadow-sm);"
                                        aria-label="{{ __('Scroll left') }}">
                                    <x-icon name="arrow-right" class="h-4 w-4 rotate-180" />
                                </button>

                                <button type="button" @click="scroll(1)"
                                        class="absolute end-1 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full border border-token"
                                        style="background-color: var(--surface-raised); box-shadow: var(--shadow-sm);"
                                        aria-label="{{ __('Scroll right') }}">
                                    <x-icon name="arrow-right" class="h-4 w-4" />
                                </button>
                            </div>
                        </template>

                        <p class="lede mt-1 text-xs">
                            <span x-text="shots.length"></span>/<span x-text="max"></span>
                            {{ __('photo(s) selected') }}
                        </p>
                    </div>
                </div>
                {{-- Sender ------------------------------------------------- --}}
                <div class="space-y-3 rounded-2xl border border-token p-4">
                    <h2 class="text-sm font-bold">{{ __('Sender Information') }}</h2>

                    <x-route-inputs only="pickup" pickup-label="{{ __('Sender Location') }}"
                                    pickup-placeholder="{{ __('Search collection address') }}" />

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="sender_name" class="label">{{ __('Name') }}</label>
                            <input id="sender_name" name="sender_name" type="text" class="w-full" required
                                   placeholder="{{ __('Enter sender name') }}"
                                   value="{{ old('sender_name', $rider->name()) }}">
                        </div>
                        <div>
                            <label for="sender_phone" class="label">{{ __('Mobile Number') }}</label>
                            <input id="sender_phone" name="sender_phone" type="tel" class="w-full" required
                                   placeholder="1234 5678 89"
                                   value="{{ old('sender_phone', $rider->get('phone')) }}">
                        </div>
                        <div>
                            <label for="pickup_date" class="label">{{ __('Pickup date') }}</label>
                            <input id="pickup_date" name="pickup_date" type="date" class="w-full" required
                                   min="{{ now()->toDateString() }}" value="{{ old('pickup_date', now()->toDateString()) }}">
                        </div>
                        <div>
                            <label for="pickup_time" class="label">{{ __('Time') }}</label>
                            <input id="pickup_time" name="pickup_time" type="time" class="w-full" required
                                   value="{{ old('pickup_time', now()->addHour()->format('H:i')) }}">
                        </div>
                    </div>

                    {{-- Height and weight: the weight is what the fare is
                         priced on, so changing it re-prices the delivery. --}}
                    <div>
                        <span class="label">{{ __('Parcel Height and Weight') }}</span>
                        <div class="grid grid-cols-2 gap-3">
                            <select x-model="dimension" class="w-full" required aria-label="{{ __('Height (ft)') }}">
                                <option value="">{{ __('Height (ft)') }}</option>
                                @for ($ft = 1; $ft <= 60; $ft++)
                                    <option value="{{ $ft }}">{{ $ft }} {{ __('ft.') }}</option>
                                @endfor
                            </select>

                            <select x-model="weight" @change="requote()" class="w-full" required
                                    aria-label="{{ __('Weight (kg)') }}">
                                <option value="">{{ __('Weight (kg)') }}</option>
                                @for ($kg = 1; $kg <= 60; $kg++)
                                    <option value="{{ $kg }}">{{ $kg }} {{ __('Kg') }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="note" class="label">{{ __('Notes (Optional)') }}</label>
                        <textarea id="note" name="note" rows="2" class="w-full"
                                  placeholder="{{ __('Enter Notes') }}">{{ old('note') }}</textarea>
                    </div>
                </div>

                {{-- Receiver ----------------------------------------------- --}}
                <div class="space-y-3 rounded-2xl border border-token p-4">
                    <h2 class="text-sm font-bold">{{ __('Receiver Information') }}</h2>

                    <x-route-inputs only="drop" drop-label="{{ __('Receiver Location') }}"
                                    drop-placeholder="{{ __('Select Receiver Location') }}" />

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="receiver_name" class="label">{{ __('Name') }}</label>
                            <input id="receiver_name" name="receiver_name" type="text" class="w-full" required
                                   placeholder="{{ __('Enter receiver name') }}" value="{{ old('receiver_name') }}">
                        </div>
                        <div>
                            <label for="receiver_phone" class="label">{{ __('Mobile Number') }}</label>
                            <input id="receiver_phone" name="receiver_phone" type="tel" class="w-full" required
                                   placeholder="1234 5678 89" value="{{ old('receiver_phone') }}">
                        </div>
                        <div>
                            <label for="receive_date" class="label">{{ __('Delivery date') }}</label>
                            <input id="receive_date" name="receive_date" type="date" class="w-full" required
                                   min="{{ now()->toDateString() }}" value="{{ old('receive_date', now()->toDateString()) }}">
                        </div>
                        <div>
                            <label for="receive_time" class="label">{{ __('Time') }}</label>
                            <input id="receive_time" name="receive_time" type="time" class="w-full" required
                                   value="{{ old('receive_time', now()->addHours(3)->format('H:i')) }}">
                        </div>
                    </div>
                </div>

                <template x-if="!ready">
                    <p class="lede text-center text-sm">{{ __('Set both locations to price the delivery.') }}</p>
                </template>
            </div>

            {{-- Step 3 — Details, billing and payment ---------------------- --}}
            <div x-show="step === 3" x-cloak class="space-y-4">

                {{-- About the parcel --}}
                <div class="space-y-2 rounded-2xl border border-token p-4 text-sm">
                    <h2 class="text-sm font-bold">{{ __('About Parcel Details') }}</h2>

                    <div class="flex items-start gap-3">
                        <span class="dot-pickup"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="pickup.address || '—'"></span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="dot-drop"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="drop.address || '—'"></span>
                    </div>

                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 border-t border-token pt-2">
                        <span class="faint">{{ __('Parcel type') }}</span>
                        <span class="text-end font-semibold" x-text="category?.title ?? '—'"></span>

                        <span class="faint">{{ __('Distance') }}</span>
                        <span class="text-end font-semibold"
                              x-text="route?.distance ? route.distance + ' {{ $unit }}' : '—'"></span>

                        <span class="faint">{{ __('Weight') }}</span>
                        <span class="text-end font-semibold" x-text="weight ? weight + ' {{ __('Kg') }}' : '—'"></span>

                        <template x-if="dimension">
                            <span class="faint">{{ __('Height') }}</span>
                        </template>
                        <template x-if="dimension">
                            <span class="text-end font-semibold" x-text="dimension + ' {{ __('ft.') }}'"></span>
                        </template>
                    </div>
                </div>

                <x-coupon-picker :coupons="$coupons" />

                {{-- Billing — the same rows the admin bills at completion. --}}
                <div class="space-y-1.5 rounded-2xl border border-token p-4 text-sm">
                    <h2 class="mb-1 text-sm font-bold">{{ __('Payment Details') }}</h2>

                    <div class="flex items-baseline justify-between">
                        <span class="faint">{{ __('Shipping Cost') }}</span>
                        <span class="font-semibold"
                              x-text="subTotal != null ? cabmeMoney(subTotal) : '—'"></span>
                    </div>

                    <template x-if="discountAmount > 0">
                        <div class="flex items-baseline justify-between text-emerald-600">
                            <span>{{ __('Discount') }}</span>
                            <span class="font-semibold" x-text="'−' + cabmeMoney(discountAmount)"></span>
                        </div>
                    </template>

                    <template x-for="tax in taxes" :key="tax.libelle">
                        <div class="flex items-baseline justify-between">
                            <span class="faint">
                                <span x-text="tax.libelle"></span>
                                <span x-text="String(tax.type).toLowerCase() === 'percentage' ? ' (' + tax.value + '%)' : ''"></span>
                            </span>
                            <span class="font-semibold" x-text="cabmeMoney(
                                String(tax.type).toLowerCase() === 'percentage'
                                    ? ((Number(subTotal) - discountAmount) * Number(tax.value) / 100)
                                    : Number(tax.value)
                            )"></span>
                        </div>
                    </template>

                    <div class="flex items-baseline justify-between border-t border-token pt-2">
                        <span class="font-bold">{{ __('Total Payable Amount') }}</span>
                        <span class="text-lg font-extrabold"
                              x-text="chargeTotal != null ? cabmeMoney(chargeTotal) : '—'"></span>
                    </div>
                </div>

                <x-payment-picker :methods="$paymentMethods" />
            </div>

            <input type="hidden" name="pickup_lat" :value="pickup.lat">
            <input type="hidden" name="pickup_lng" :value="pickup.lng">
            <input type="hidden" name="drop_lat" :value="drop.lat">
            <input type="hidden" name="drop_lng" :value="drop.lng">
            <input type="hidden" name="pickup_address" :value="pickup.address">
            <input type="hidden" name="drop_address" :value="drop.address">
            <input type="hidden" name="distance" :value="route?.distance">
            <input type="hidden" name="category_id" :value="categoryId">
            <input type="hidden" name="weight" :value="weight">
            <input type="hidden" name="dimension" :value="dimension">
            {{-- The SUBTOTAL, as the app posts it: the admin stores it with the
                 coupon and its tax snapshot and bills the rest at completion. --}}
            <input type="hidden" name="amount" :value="subTotal">
        </div>

        <div class="shell-sheet-foot">
            {{-- Only on the payment step: on Category and Details there is
                 nothing priced yet, and the row sat there reading '—'. --}}
            <div class="mb-3 flex items-baseline justify-between" x-show="step === 3" x-cloak>
                <span class="text-sm font-medium faint">{{ __('Total Payable Amount') }}</span>
                <span class="text-2xl font-extrabold"
                      x-text="chargeTotal != null ? cabmeMoney(chargeTotal) : '—'"></span>
            </div>

            <div class="flex gap-2">
                <button type="button" class="btn-ghost" x-show="step > 1" x-cloak @click="step--">
                    {{ __('Back') }}
                </button>

                <button type="button" class="btn-primary btn-lg flex-1" x-show="step < 3" @click="next()"
                        :disabled="(step === 1 && !categoryId) || (step === 2 && !ready)">
                    <span x-show="step === 1 && !categoryId">{{ __('Choose a parcel type') }}</span>
                    <span x-show="!(step === 1 && !categoryId)">{{ __('Continue') }}</span>
                </button>

                <button type="submit" class="btn-primary btn-lg flex-1" x-show="step === 3" x-cloak
                        :disabled="!canSubmit || payBusy">
                    <span x-show="payBusy" x-cloak>{{ __('Processing payment…') }}</span>
                    <span x-show="!payBusy">{{ __('Confirm Parcel') }}</span>
                </button>
            </div>
        </div>
    </form>
@endsection
