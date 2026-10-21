@extends('layouts.app')

@section('title', __('Refer & earn'))
@section('container', 'mx-auto w-full max-w-2xl px-4 py-8')

@section('content')
    @php
        $code = is_array($referral)
            ? ($referral['referral_code'] ?? $referral['code'] ?? null)
            : $referral;

        // What each successful referral pays, straight from the admin.
        $reward = is_array($referral) ? ($referral['referral_amount'] ?? null) : null;

        $shareUrl = $code ? route('register').'?ref='.urlencode($code) : null;
    @endphp

    <x-page-header :title="__('Refer & earn')" :subtitle="__('Share your code — you both get rewarded.')" />

    @if ($code)
        <div class="card-pad text-center"
             x-data="{
                 copy(text, label) {
                     navigator.clipboard.writeText(text)
                         .then(() => $store.toasts.success(label + ' copied'))
                         .catch(() => $store.toasts.error('Could not copy to clipboard'));
                 }
             }">
            @if ($reward !== null && (float) $reward > 0)
                <p class="text-sm font-semibold">
                    {{ __('Earn :amount for each friend who signs up and completes their first ride.', [
                        'amount' => $settings->money($reward),
                    ]) }}
                </p>
            @endif

            <p class="lede mt-4 text-sm">{{ __('Your referral code') }}</p>
            <p class="mt-2 text-4xl font-bold tracking-[0.2em]">{{ $code }}</p>

            <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                <button type="button" class="btn-ghost flex-1"
                        @click="copy(@js($code), 'Code')">{{ __('Copy code') }}</button>
                <button type="button" class="btn-primary flex-1"
                        @click="copy(@js($shareUrl), 'Invite link')">{{ __('Copy invite link') }}</button>
            </div>
        </div>

        <div class="card-pad mt-4">
            <h2 class="text-base font-semibold">{{ __('How it works') }}</h2>
            <ol class="faint mt-3 space-y-2 text-sm">
                <li>1. {{ __('Share your code with a friend.') }}</li>
                <li>2. {{ __('They enter it when creating their account.') }}</li>
                <li>3. {{ __('You both get credited once they complete their first trip.') }}</li>
            </ol>
        </div>
    @else
        <x-empty-state :title="__('No referral code yet')"
                       :message="__('Your referral code will appear here once it has been issued.')"
                       icon="gift" />
    @endif
@endsection
