@props([
    'methods' => [],
    'model' => 'paymentMethodId',
    'name' => 'payment_method_id',
    'label' => __('Payment'),
    // Kept for callers that still pass it; the picker is a list now, so the
    // column count no longer applies.
    'cols' => null,
])

@php
    use App\Support\PaymentMethods;

    // Most gateways ship a logo from the admin. The few that don't fall back to
    // a neutral icon — never a coloured placeholder pretending to be a brand.
    $fallbackIcon = fn (string $label) => match (true) {
        PaymentMethods::isOffline($label) => 'cash',
        PaymentMethods::isInternal($label) => 'wallet',
        str_contains(strtolower($label), 'fast') => 'bank',
        default => 'card',
    };
@endphp

@if (! empty($methods))
    <div>
        @if ($label)
            <span class="label">{{ $label }}</span>
        @endif

        {{-- A list, not a grid of cards: logo on the left, name and what it
             charges in on the right, one method per row. Capped height with
             its own scroll so a long list never swallows the sheet. --}}
        <div class="max-h-[17rem] space-y-2 overflow-y-auto overscroll-contain pe-1">
            @foreach ($methods as $method)
                @php
                    // An empty wallet is still listed — the rider should see
                    // it is there and why they cannot use it — but it must
                    // not be choosable.
                    $isWallet = ($method['kind'] ?? '') === 'wallet';
                    $spent = $isWallet && $rider->balance() <= 0;
                @endphp

                <label class="relative block {{ $spent ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                    <input type="radio" name="{{ $name }}" value="{{ $method['id'] }}"
                           x-model="{{ $model }}" class="peer sr-only" @disabled($spent)>

                    <span class="flex items-center gap-3 rounded-2xl border p-2.5 transition-all peer-focus-visible:ring-2 {{ $spent ? 'opacity-55' : '' }}"
                          :style="String({{ $model }}) === '{{ $method['id'] }}'
                              ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.08); box-shadow: var(--shadow-sm)'
                              : 'border-color: var(--border)'">

                        {{-- Logos are drawn for light backgrounds, so they sit
                             on a white chip — otherwise dark-on-transparent
                             marks disappear in dark mode. PaymentLogos has
                             one consistent set, shown at its own size so it
                             stays sharp. --}}
                        <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-xl"
                              style="background-color: #fff; box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.06);">
                            @if (! empty($method['logo']))
                                <img src="{{ $method['logo'] }}" alt="{{ $method['label'] }}"
                                     class="h-10 w-10 object-contain" loading="lazy">
                            @else
                                <x-icon :name="$fallbackIcon($method['label'])" class="h-5 w-5 text-neutral-500" />
                            @endif
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $method['label'] }}</span>

                            @if (($method['kind'] ?? '') === 'wallet')
                                {{-- What is actually in the wallet, so the
                                     rider can see whether it covers this
                                     booking before picking it. --}}
                                <span class="faint mt-0.5 block text-[11px] font-bold">
                                    {{ __('Balance') }}: {{ $settings->money($rider->balance()) }}
                                    @if ($spent) · {{ __('Top up to use it') }} @endif
                                </span>
                            @elseif (! empty($method['charged_in']))
                                {{-- Region-locked gateway: the money moves in
                                     its own currency, whatever the displayed
                                     one. --}}
                                <span class="mt-0.5 block text-[11px] font-bold brand-text">
                                    {{ __('charged in :currency', ['currency' => $method['charged_in']]) }}
                                </span>
                            @endif
                        </span>

                        {{-- A radio mark, so the chosen row is obvious without
                             relying on the tint alone. --}}
                        <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full border transition-colors {{ $spent ? 'invisible' : '' }}"
                              :style="String({{ $model }}) === '{{ $method['id'] }}'
                                  ? 'border-color: var(--color-brand-600); background-color: var(--color-brand-600)'
                                  : 'border-color: var(--border-strong)'">
                            <x-icon name="check" class="h-3 w-3" ::style="String({{ $model }}) === '{{ $method['id'] }}'
                                ? 'color: var(--brand-contrast)' : 'color: transparent'" />
                        </span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>
@endif
