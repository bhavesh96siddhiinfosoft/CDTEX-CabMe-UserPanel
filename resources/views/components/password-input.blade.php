@props([
    'id' => 'password',
    'name' => 'password',
    'label' => null,
    'autocomplete' => 'current-password',
    'required' => true,
    'minlength' => null,
])

{{-- Password with a show/hide eye. Plain input swap — no Alpine model, so it
     drops into any form (classic posts and JS-driven ones alike). --}}
<div x-data="{ reveal: false }">
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}</label>
    @endif

    <div class="relative">
        <input id="{{ $id }}" name="{{ $name }}" :type="reveal ? 'text' : 'password'" type="password"
               {{ $attributes->merge(['class' => 'w-full pe-11']) }}
               @if ($required) required @endif
               @if ($minlength) minlength="{{ $minlength }}" @endif
               autocomplete="{{ $autocomplete }}">

        <button type="button" @click="reveal = !reveal" tabindex="-1"
                class="absolute end-2 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-lg faint transition-colors hover:muted-surface"
                :aria-label="reveal ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'">
            {{-- eye / eye-off --}}
            <svg x-show="!reveal" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
            </svg>
            <svg x-show="reveal" x-cloak class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
            </svg>
        </button>
    </div>
</div>
