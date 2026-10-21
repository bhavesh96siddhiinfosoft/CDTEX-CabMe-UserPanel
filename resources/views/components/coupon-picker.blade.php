@props(['coupons' => [], 'model' => 'couponId', 'name' => 'coupon_id'])

@php
    // Normalise once so the markup stays readable. `discription` is the
    // admin's spelling; `type` is "Fixed" or "Percentage".
    $items = collect($coupons)->map(fn ($c) => [
        'id' => (string) ($c['id'] ?? ''),
        'code' => $c['code'] ?? 'COUPON',
        'discount' => $c['discount'] ?? null,
        'percent' => strtolower($c['type'] ?? '') === 'percentage',
        'blurb' => $c['discription'] ?? $c['description'] ?? null,
        'expires' => ! empty($c['expire_at']) && $c['expire_at'] !== '0000-00-00'
            ? \Illuminate\Support\Carbon::parse($c['expire_at'])->format('d M Y')
            : null,
    ])->filter(fn ($c) => $c['id'] !== '')->values();
@endphp

@if ($items->isNotEmpty())
    <div x-data="{
             open: false,

             /**
              * Collapse the list and bring the summary row back into view.
              * Without this the sheet stays where the now-removed list had
              * scrolled it, which reads as being stuck at the bottom.
              */
             close() {
                 this.open = false;

                 this.$nextTick(() => {
                     const body = this.$el.closest('.shell-sheet-body');
                     if (!body) return;

                     // Let x-collapse finish before correcting the scroll.
                     setTimeout(() => {
                         const offset = this.$el.offsetTop - 16;
                         body.scrollTo({ top: Math.max(0, Math.min(offset, body.scrollHeight - body.clientHeight)), behavior: 'smooth' });
                     }, 220);
                 });
             },
         }">
        <span class="label">{{ __('Coupon') }}</span>

        {{-- Collapsed summary: a single tappable row, so the sheet stays calm
             until the rider actually wants to browse offers. --}}
        <button type="button" @click="open ? close() : open = true"
                class="flex w-full items-center gap-3 rounded-2xl border border-token p-3 text-start transition-colors hover:muted-surface"
                :class="{{ $model }} ? 'ring-1' : ''"
                :style="{{ $model }} ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.06)' : ''">

            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
                  :class="{{ $model }} ? 'brand-bg' : 'muted-surface faint'">
                <x-icon name="ticket" class="h-[18px] w-[18px]" />
            </span>

            <span class="min-w-0 flex-1">
                <template x-if="!{{ $model }}">
                    <span class="block text-sm font-semibold">{{ __('Apply a coupon') }}</span>
                </template>

                @foreach ($items as $c)
                    <template x-if="String({{ $model }}) === '{{ $c['id'] }}'">
                        <span class="block">
                            <span class="block text-sm font-bold tracking-wide">{{ $c['code'] }}</span>
                            <span class="block text-xs brand-text">
                                {{ $c['percent'] ? __(':amount% off', ['amount' => $c['discount']]) : __(':amount off', ['amount' => $settings->money($c['discount'])]) }}
                            </span>
                        </span>
                    </template>
                @endforeach
            </span>

            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 faint transition-transform"
                    ::class="open && 'rotate-180'" />
        </button>

        <div x-show="open" x-cloak x-collapse class="mt-2 space-y-2">

            {{-- Ticket-style cards: the notch and dashed spine read as a voucher
                 rather than another form row. --}}
            @foreach ($items as $c)
                {{-- Close on click, not change: re-picking the option that is
                     already selected fires no change event, which left the list
                     stuck open and the sheet pinned at full scroll. --}}
                <label class="relative block cursor-pointer">
                    <input type="radio" name="{{ $name }}" value="{{ $c['id'] }}"
                           x-model="{{ $model }}" class="peer sr-only" @click="close()">

                    <span class="flex items-stretch overflow-hidden rounded-2xl border transition-all"
                          :style="String({{ $model }}) === '{{ $c['id'] }}'
                              ? 'border-color: var(--color-brand-600); background-color: rgb(var(--brand-rgb) / 0.06); box-shadow: var(--shadow-sm)'
                              : 'border-color: var(--border)'">

                        {{-- Value stub. Wide enough for a formatted currency
                             amount ("$15.00") without wrapping onto two lines. --}}
                        <span class="flex w-[5.75rem] shrink-0 flex-col items-center justify-center gap-0.5 px-2 py-4 text-center brand-tint">
                            <span class="block w-full truncate text-[15px] font-extrabold leading-tight brand-text">
                                {{ $c['percent'] ? $c['discount'].'%' : $settings->money($c['discount']) }}
                            </span>
                            <span class="text-[10px] font-bold uppercase tracking-wider faint">{{ __('off') }}</span>
                        </span>

                        <span class="my-3 border-l border-dashed" style="border-color: var(--border-strong);"></span>

                        <span class="min-w-0 flex-1 px-3.5 py-3">
                            <span class="block truncate text-sm font-bold tracking-wide">{{ $c['code'] }}</span>

                            @if ($c['blurb'])
                                <span class="mt-1 block text-xs leading-relaxed faint">{{ $c['blurb'] }}</span>
                            @endif

                            @if ($c['expires'])
                                <span class="mt-1.5 block text-[11px] faint">{{ __('Valid until') }} {{ $c['expires'] }}</span>
                            @endif
                        </span>
                    </span>
                </label>
            @endforeach

            {{-- Clear selection. This one starts already checked, so it would
                 never fire a change event at all — hence @click. --}}
            <label class="relative block cursor-pointer">
                <input type="radio" name="{{ $name }}" value="" x-model="{{ $model }}"
                       class="peer sr-only" @click="close()">
                <span class="block rounded-2xl border border-dashed px-3 py-2.5 text-center text-sm font-semibold faint transition-colors hover:muted-surface"
                      style="border-color: var(--border-strong);">
                    {{ __('No coupon') }}
                </span>
            </label>
        </div>
    </div>
@endif
