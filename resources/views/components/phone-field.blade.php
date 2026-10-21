@props([
    'label' => __('Mobile number'),
    'phone' => '',
    'countryCode' => '+91',
    'required' => true,
    // Pages with a top-of-form error summary suppress the inline messages.
    'showErrors' => true,
])

{{-- Flag + dial-code picker (intl-tel-input), matching cabme-admin's phone
     field. Submits `country_code` and `phone` separately, exactly as the
     admin's register/update validation expects. --}}
<div x-data="phoneInput({ dialCode: @js($countryCode) })">
    <label for="tel-visible" class="label">{{ $label }}</label>

    <input id="tel-visible" x-ref="tel" type="tel" class="w-full"
           value="{{ $phone }}" placeholder="9876543210"
           autocomplete="tel-national" @if ($required) required @endif>

    <input type="hidden" name="country_code" x-ref="countryCode" value="{{ $countryCode }}">
    <input type="hidden" name="phone" x-ref="phone" value="{{ $phone }}">

</div>
