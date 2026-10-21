@php
    // Null unless the admin has enabled the bar in Web Settings.
    $cookie = $settings->cookieConsent();
@endphp

@if ($cookie)
    {{-- Dismissal is a per-browser preference, so localStorage is the right home
         for it — there is no rider account involved and nothing to sync. --}}
    <div x-data="{
             show: false,
             init() {
                 try { this.show = localStorage.getItem('cabme_cookie_ack') !== '1'; }
                 catch (e) { this.show = true; }
             },
             accept() {
                 try { localStorage.setItem('cabme_cookie_ack', '1'); } catch (e) {}
                 this.show = false;
             },
         }"
         x-show="show"
         x-cloak
         x-transition.opacity.duration.300ms
         class="fixed inset-x-0 bottom-0 z-50 p-3 sm:p-4">
        <div class="card mx-auto flex w-full max-w-3xl flex-col gap-3 p-4 sm:flex-row sm:items-center"
             style="box-shadow: var(--shadow-xl);">
            <p class="flex-1 text-sm faint">
                {{ $cookie['message'] }}
                @if ($cookie['policyUrl'])
                    <a href="{{ $cookie['policyUrl'] }}" target="_blank" rel="noopener noreferrer"
                       class="font-semibold underline brand-text">{{ __('Learn more') }}</a>
                @endif
            </p>

            <button type="button" class="btn-primary shrink-0" @click="accept()">
                {{ $cookie['buttonText'] }}
            </button>
        </div>
    </div>
@endif
