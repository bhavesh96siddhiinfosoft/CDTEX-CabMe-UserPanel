{{--
    Phone number + OTP verification (shared by the login page and the
    register wizard). Lives inside a firebaseLogin component scope.
--}}
<div x-show="!otpSent" data-phone-login>
    <x-phone-field :label="__('Mobile number')" :required="false" />
    <button id="otp-send" type="button" class="btn-primary mt-4 w-full"
            :disabled="busy" @click="sendCode()">
        <span x-show="!busy">{{ __('Send code') }}</span>
        <span x-show="busy" x-cloak>{{ __('Sending…') }}</span>
    </button>
</div>

<div x-show="otpSent" x-cloak>
    <label for="otp-code" class="label">{{ __('Verification code') }}</label>
    <input id="otp-code" type="text" inputmode="numeric" maxlength="6" x-model="code"
           class="w-full text-center text-2xl font-extrabold tracking-[0.4em]" placeholder="••••••">
    <p class="lede mt-2 text-sm">{{ __('We sent a code by SMS to your phone.') }}</p>

    <button type="button" class="btn-primary mt-4 w-full"
            :disabled="busy || code.trim().length < 6" @click="verifyCode()">
        <span x-show="!busy">{{ __('Verify') }}</span>
        <span x-show="busy" x-cloak>{{ __('Checking…') }}</span>
    </button>

    <div class="mt-2 grid grid-cols-2 gap-2">
        <button type="button" class="btn-ghost" :disabled="busy || resendIn > 0" @click="resend()">
            <span x-show="resendIn <= 0">{{ __('Resend code') }}</span>
            <span x-show="resendIn > 0" x-cloak x-text="'{{ __('Resend code') }} (' + resendIn + ')'"></span>
        </button>
        <button type="button" class="btn-ghost" @click="otpSent = false; code = ''">
            {{ __('Use a different number') }}
        </button>
    </div>
</div>
