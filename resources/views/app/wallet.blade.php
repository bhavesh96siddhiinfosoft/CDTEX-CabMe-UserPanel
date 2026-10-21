@extends('layouts.app')

@section('title', __('Wallet'))
@section('container', 'mx-auto w-full max-w-3xl px-4 py-8')

@section('content')
    <x-page-header :title="__('Wallet')" :subtitle="__('Top up once, then pay for trips in a tap.')" />

    <div class="card-pad mb-6 text-white"
         style="background: linear-gradient(135deg, var(--color-brand-600), var(--color-brand-800));">
        <p class="text-sm opacity-80">{{ __('Available balance') }}</p>
        <p class="mt-1 text-4xl font-bold">{{ $settings->money($balance) }}</p>
    </div>

    @if ($error)
        <script>
            (window.__flashQueue = window.__flashQueue || []).push({ type: 'error', message: @json($error) });
            window.__drainFlashQueue?.();
        </script>
    @endif

    <div class="card-pad mb-6">
        <h2 class="text-base font-semibold">{{ __('Add money') }}</h2>
        <p class="lede mt-1 text-sm">{{ __('Pay by card or UPI — the amount is verified with the gateway before your wallet is credited.') }}</p>

        <script>window.__methodFlows = @js(collect($paymentMethods)->pluck('flow', 'id'));</script>

        <div class="mt-5 space-y-5"
             x-data="walletTopup({
                 orderUrl: '{{ route('app.wallet.razorpay.order') }}',
                 verifyUrl: '{{ route('app.wallet.razorpay.verify') }}',
                 methodId: @js((string) ($paymentMethods[0]['id'] ?? '')),
                 gatewaysReady: @js($gatewaysReady),
             })">

            <div>
                <label for="amount" class="label">{{ __('Amount') }}</label>
                <div class="flex max-w-xl gap-3">
                    <input id="amount" type="number" min="1" step="1" class="w-full max-w-xs"
                           placeholder="0" x-model.number="amount" required>
                    <button type="button" class="btn-primary btn-lg shrink-0 px-10" :disabled="!canPay" @click="pay()">
                        <span x-show="!busy">{{ __('Top up') }}</span>
                        <span x-show="busy" x-cloak>{{ __('Processing…') }}</span>
                    </button>
                </div>
            </div>

            {{-- Quick amounts --}}
            <div class="flex flex-wrap gap-2">
                @foreach ([100, 250, 500, 1000, 2000] as $preset)
                    <button type="button" @click="amount = {{ $preset }}"
                            class="rounded-xl border border-token px-3.5 py-2 text-sm font-semibold transition-colors hover:muted-surface"
                            :style="amount === {{ $preset }}
                                ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.08)' : ''">
                        {{ $settings->money($preset) }}
                    </button>
                @endforeach
            </div>

            {{-- Cash and the wallet itself are filtered out upstream: neither
                 can fund a top-up. --}}
            <x-payment-picker :methods="$paymentMethods" model="methodId" :label="__('Pay with')"
                              cols="grid-cols-2 sm:grid-cols-3 lg:grid-cols-4" />


            @unless ($gatewaysReady)
                <p class="text-xs faint">
                    {{ __('Online payments are not configured yet — add gateway credentials in the admin panel under Settings → Payment.') }}
                </p>
            @endunless
        </div>
    </div>

    {{-- The id is the pager's fragment target, so page links land on the
         list instead of scrolling back to the top-up form. --}}
    <h2 id="history" class="mb-3 scroll-mt-24 text-base font-semibold">{{ __('History') }}</h2>

    <x-list-filters :action="route('app.wallet')"
                    :placeholder="__('Search by note or payment method')" />

    @forelse ($history as $entry)
        @php
            $credited = ($entry['is_credited'] ?? 0) == 1;
        @endphp

        <div class="card mb-2 flex items-center gap-4 p-4">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm
                         {{ $credited
                             ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                             : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' }}">
                {{ $credited ? '↓' : '↑' }}
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">
                    {{ $entry['note'] ?? ($credited ? __('Money added') : __('Trip payment')) }}
                </p>
                <p class="lede text-xs">
                    @isset($entry['created_at'])
                        {{ \Illuminate\Support\Carbon::parse($entry['created_at'])->format('d M Y, g:i A') }}
                    @endisset
                    @if (! empty($entry['payment_method']))
                        · <span class="font-semibold">{{ $entry['payment_method'] }}</span>
                    @endif
                </p>
            </div>

            <span class="whitespace-nowrap font-semibold {{ $credited ? 'text-emerald-600' : '' }}">
                {{ $credited ? '+' : '−' }}{{ $settings->money($entry['amount'] ?? 0) }}
            </span>
        </div>
    @empty
        <x-empty-state :title="__('No transactions yet')"
                       :message="__('Top-ups and trip payments will appear here.')" icon="wallet" />
    @endforelse

    <x-pager :paginator="$history" />
@endsection
