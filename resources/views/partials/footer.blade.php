@php
    // Footer logo, description and social links come from the admin's Web
    // Settings screen; each block is skipped when it isn't configured.
    $social = $settings->socialLinks();

    $socialIcons = [
        'facebook' => ['facebook', 'Facebook'],
        'instagram' => ['instagram', 'Instagram'],
        'x' => ['x-social', 'X'],
        'linkedin' => ['linkedin', 'LinkedIn'],
        'pinterest' => ['pinterest', 'Pinterest'],
        'youtube' => ['youtube', 'YouTube'],
    ];
@endphp

<footer class="relative mt-16" style="background-color: var(--surface);">
    {{-- Brand gradient hairline --}}
    <div class="h-[3px] w-full"
         style="background: linear-gradient(to right, transparent, var(--color-brand-500), var(--color-brand-700), transparent);"></div>

    <div class="mx-auto grid w-full max-w-6xl gap-10 px-4 pb-10 pt-14 md:grid-cols-[1.4fr_1fr_1fr_1fr]">

        <div>
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                @if ($settings->footerLogo())
                    <img src="{{ $settings->footerLogo() }}" alt="{{ $settings->appName() }}" class="h-9 w-auto max-w-[10rem] object-contain">
                @else
                    <span class="grid h-10 w-10 place-items-center rounded-xl text-lg font-extrabold"
                          style="background: linear-gradient(135deg, var(--color-brand-500), var(--color-brand-700)); color: var(--brand-contrast);">
                        {{ strtoupper(substr($settings->appName(), 0, 1)) }}
                    </span>
                    <span class="text-xl font-extrabold tracking-tight">{{ $settings->appName() }}</span>
                @endif
            </a>

            <p class="lede mt-4 max-w-xs text-sm">
                {{ $settings->footerDescription() ?: __('Rides, parcel delivery and vehicle rentals — booked in a few taps.') }}
            </p>

            @if ($social)
                <div class="mt-6 flex flex-wrap gap-2">
                    @foreach ($social as $network => $url)
                        @php [$icon, $label] = $socialIcons[$network] ?? ['pin', ucfirst($network)]; @endphp
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $label }}"
                           class="group grid h-10 w-10 place-items-center rounded-xl border border-token transition-all hover:-translate-y-0.5"
                           onmouseover="this.style.backgroundColor='rgb(var(--brand-rgb) / 0.1)'; this.style.borderColor='var(--color-brand-500)'"
                           onmouseout="this.style.backgroundColor=''; this.style.borderColor=''">
                            <x-icon :name="$icon" class="h-[18px] w-[18px] faint transition-colors group-hover:brand-text" />
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <h3 class="text-xs font-extrabold uppercase tracking-widest faint">{{ __('Services') }}</h3>
            <ul class="mt-4 space-y-2.5 text-sm font-medium">
                @if ($settings->serviceEnabled('ride'))
                    <li><a href="{{ route('app.ride.create') }}" class="transition-colors hover:brand-text">{{ __('Book a ride') }}</a></li>
                @endif
                @if ($settings->serviceEnabled('parcel'))
                    <li><a href="{{ route('app.parcel.create') }}" class="transition-colors hover:brand-text">{{ __('Send a parcel') }}</a></li>
                @endif
                @if ($settings->serviceEnabled('rental'))
                    <li><a href="{{ route('app.rental.create') }}" class="transition-colors hover:brand-text">{{ __('Rent a vehicle') }}</a></li>
                @endif
                <li><a href="{{ route('coverage') }}" class="transition-colors hover:brand-text">{{ __('Coverage areas') }}</a></li>
            </ul>
        </div>

        <div>
            <h3 class="text-xs font-extrabold uppercase tracking-widest faint">{{ __('Company') }}</h3>
            <ul class="mt-4 space-y-2.5 text-sm font-medium">
                <li><a href="{{ route('services') }}" class="transition-colors hover:brand-text">{{ __('How it works') }}</a></li>
                <li><a href="{{ route('faq') }}" class="transition-colors hover:brand-text">{{ __('FAQ') }}</a></li>
                <li><a href="{{ route('contact') }}" class="transition-colors hover:brand-text">{{ __('Contact us') }}</a></li>

                {{-- Anything else the admin publishes under CMS Pages. Slugs that
                     already have their own link above are skipped. --}}
                @foreach ($cmsPages ?? [] as $page)
                    @continue(in_array($page['cms_slug'] ?? '', ['contact-us', 'privacy', 'privacy-policy', 'terms', 'terms-and-conditions', 'faq'], true))
                    <li>
                        <a href="{{ route('page', $page['cms_slug']) }}" class="transition-colors hover:brand-text">
                            {{ $page['cms_name'] ?? $page['cms_slug'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            <h3 class="text-xs font-extrabold uppercase tracking-widest faint">{{ __('Legal') }}</h3>
            <ul class="mt-4 space-y-2.5 text-sm font-medium">
                <li><a href="{{ route('privacy') }}" class="transition-colors hover:brand-text">{{ __('Privacy policy') }}</a></li>
                <li><a href="{{ route('terms') }}" class="transition-colors hover:brand-text">{{ __('Terms & conditions') }}</a></li>
            </ul>
        </div>
    </div>

    
    <div class="border-t border-token">
        <div class="footer-bottom mx-auto w-full max-w-6xl px-4 py-5">
            <p class="footer-bottom__copy text-sm faint">
                &copy; {{ date('Y') }} {{ $settings->appName() }}. {{ __('All rights reserved.') }}
            </p>
            <div class="footer-bottom__apps">
                <a href="#" target="_blank" rel="noopener noreferrer">
                    <img src="{{ asset('images/appstore.png') }}"
                         alt="{{ __('Download on the App Store') }}" loading="lazy">
                </a>
                <a href="#" target="_blank" rel="noopener noreferrer">
                    <img src="{{ asset('images/playmarket.png') }}"
                         alt="{{ __('Get it on Google Play') }}" loading="lazy">
                </a>
            </div>
        </div>
    </div>
</footer>
