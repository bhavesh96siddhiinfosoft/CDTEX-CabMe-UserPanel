@props([
    // The bill as App\Support\BookingBill::due() returns it.
    'bill' => [],
    'costLabel' => null,
    'compact' => false,
])

@php
    $rows = is_array($bill) ? $bill : [];
    $taxes = $rows['taxes'] ?? [];
@endphp

{{-- One breakdown for every booking type: what the trip cost, what the coupon
     took off, each tax BY NAME with how it was worked out, then the total. --}}
<dl {{ $attributes->merge(['class' => 'space-y-1.5 text-sm']) }}>
    <div class="flex items-baseline justify-between">
        <dt class="faint">{{ $costLabel ?? __('Ride cost') }}</dt>
        <dd class="font-semibold">{{ $settings->money($rows['amount'] ?? 0) }}</dd>
    </div>

    @if (($rows['discount'] ?? 0) > 0)
        <div class="flex items-baseline justify-between text-emerald-600">
            <dt>{{ __('Discount') }}</dt>
            <dd class="font-semibold">−{{ $settings->money($rows['discount']) }}</dd>
        </div>
    @endif

    @foreach ($taxes as $tax)
        <div class="flex items-baseline justify-between">
            <dt class="faint">
                {{ $tax['libelle'] ?: __('Tax') }}
                {{-- How this tax is charged, so the number is explainable. --}}
                <span class="text-xs">
                    ({{ $tax['type'] === 'Percentage'
                        ? rtrim(rtrim(number_format($tax['value'], 2, '.', ''), '0'), '.').'%'
                        : __('Fixed') }})
                </span>
            </dt>
            <dd class="font-semibold">{{ $settings->money($tax['amount']) }}</dd>
        </div>
    @endforeach

    <div class="flex items-baseline justify-between border-t border-token pt-2">
        <dt class="font-bold">{{ __('Total') }}</dt>
        <dd class="{{ $compact ? 'text-base' : 'text-lg' }} font-extrabold">
            {{ $settings->money($rows['total'] ?? 0) }}
        </dd>
    </div>
</dl>
