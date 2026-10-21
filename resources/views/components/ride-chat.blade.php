@props([
    // The booking this thread belongs to (rides, parcels and rentals each
    // have their own), and who the rider is talking to.
    'orderId' => null,
    'driverExpression' => 'driver',
])

{{--
    Chat with the driver, on the same Firestore thread the apps use. Sits
    beside Call in the driver card and opens in place, so the rider never
    leaves the tracking screen.

    The driver is only reachable once one is assigned, so the whole block is
    bound to the tracker's `driver` object.
--}}
<div x-data="rideChat({
         riderId: @js((string) $rider->id()),
         riderName: @js($rider->name()),
         riderPhoto: @js($rider->get('photo_path') ?? ''),
         orderId: @js((string) $orderId),
         driverId: String({{ $driverExpression }}?.id ?? ''),
         driverName: `${ {{ $driverExpression }}?.prenom ?? '' } ${ {{ $driverExpression }}?.nom ?? '' }`.trim(),
         driverPhoto: {{ $driverExpression }}?.image ?? '',
         notifyUrl: '{{ route('app.chat.notify') }}',
     })"
     x-effect="driverId = String({{ $driverExpression }}?.id ?? '')"
     @destroy="destroy()"
     class="mt-3">

    <button type="button" @click="toggle()"
            class="btn-ghost w-full"
            :aria-expanded="open">
        <x-icon name="chat" class="h-4 w-4" />
        <span x-show="!open">{{ __('Message driver') }}</span>
        <span x-show="open" x-cloak>{{ __('Hide chat') }}</span>
    </button>

    <div x-show="open" x-cloak x-collapse class="mt-3">
        <div class="rounded-2xl border border-token">

            {{-- The thread. Scrolls on its own so the sheet stays put. --}}
            <div x-ref="thread" class="max-h-64 space-y-2 overflow-y-auto overscroll-contain p-3">
                <template x-if="failed">
                    <p class="lede py-6 text-center text-sm">{{ __('Chat is unavailable right now.') }}</p>
                </template>

                <template x-if="!failed && !ready">
                    <p class="lede py-6 text-center text-sm">{{ __('Loading messages…') }}</p>
                </template>

                <template x-if="ready && messages.length === 0">
                    <p class="lede py-6 text-center text-sm">{{ __('No messages yet') }}</p>
                </template>

                <template x-for="line in messages" :key="line.id">
                    <div class="flex" :class="line.mine ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[80%] rounded-2xl px-3 py-2"
                             :style="line.mine
                                 ? 'background-color: var(--color-brand-600); color: var(--brand-contrast)'
                                 : 'background-color: var(--surface-muted)'">
                            <p class="text-sm" x-text="line.message"></p>
                            <p class="mt-0.5 text-[10px] opacity-70" x-text="stamp(line.at)"></p>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Composer --}}
            <form class="flex items-center gap-2 border-t border-token p-2" @submit.prevent="send()">
                <input type="text" class="w-full" x-model="draft" maxlength="1000"
                       placeholder="{{ __('Type a message…') }}" :disabled="failed">
                <button type="submit" class="btn-primary shrink-0" :disabled="!draft.trim() || sending || failed">
                    <span x-show="!sending">{{ __('Send') }}</span>
                    <span x-show="sending" x-cloak>{{ __('Sending…') }}</span>
                </button>
            </form>
        </div>
    </div>
</div>
