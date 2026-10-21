@php
    // Nav follows the admin's active_services flag, so a service switched off
    // there disappears here without a deploy.
    $services = [
        'ride'   => ['label' => __('Book a ride'), 'icon' => 'car', 'route' => 'app.ride.create'],
        'parcel' => ['label' => __('Send a parcel'), 'icon' => 'package', 'route' => 'app.parcel.create'],
        'rental' => ['label' => __('Rent a vehicle'), 'icon' => 'key', 'route' => 'app.rental.create'],
    ];

    $menuLinks = [
        ['label' => __('How it works'), 'icon' => 'sparkle', 'route' => 'services'],
        ['label' => __('FAQ'), 'icon' => 'chat', 'route' => 'faq'],
    ];
@endphp

<header class="sticky top-0 z-30 border-b border-token backdrop-blur-md"
        style="height: 4rem; background-color: color-mix(in srgb, var(--surface) 85%, transparent);"
        x-data="{ open: false }">
    <div class="relative mx-auto flex h-16 w-full max-w-6xl items-center gap-3 px-4">

        <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
            @if ($settings->headerLogo())
                <img src="{{ $settings->headerLogo() }}" alt="{{ $settings->appName() }}" class="h-8 w-auto max-w-[9rem] object-contain">
            @else
                <span class="grid h-9 w-9 place-items-center rounded-xl text-[15px] font-extrabold"
                      style="background: linear-gradient(135deg, var(--color-brand-500), var(--color-brand-700)); color: var(--brand-contrast); box-shadow: 0 4px 12px -4px rgb(var(--brand-rgb) / 0.6);">
                    {{ strtoupper(substr($settings->appName(), 0, 1)) }}
                </span>
                <span class="text-[17px] font-extrabold tracking-tight">{{ $settings->appName() }}</span>
            @endif
        </a>

        {{-- Segmented service switcher. In normal flow (flex-1 + centred), so
             it can never overlap the controls on the right the way the old
             absolutely-centred nav did once the language button widened them. --}}
        <nav class="hidden flex-1 items-center justify-center gap-1 whitespace-nowrap xl:flex">
            @foreach ($services as $key => $service)
                @if ($settings->serviceEnabled($key))
                    @php $isActive = request()->routeIs($service['route']); @endphp
                    <a href="{{ route($service['route']) }}"
                       class="flex items-center gap-1.5 whitespace-nowrap rounded-xl px-3 py-1.5 text-sm font-bold transition-all
                              {{ $isActive ? '' : 'faint hover:text-[var(--text)]' }}"
                       @if ($isActive)
                           style="background-color: var(--color-brand-600); color: var(--brand-contrast); box-shadow: 0 4px 10px -3px rgb(var(--brand-rgb) / 0.55);"
                       @endif>
                        <x-icon :name="$service['icon']" class="h-4 w-4" />
                        {{ $service['label'] }}
                    </a>
                @endif
            @endforeach

            @foreach ($menuLinks as $link)
                @php $isActive = request()->routeIs($link['route']); @endphp
                <a href="{{ route($link['route']) }}"
                   class="flex items-center gap-1.5 whitespace-nowrap rounded-xl px-3 py-1.5 text-sm font-bold transition-all
                          {{ $isActive ? '' : 'faint hover:text-[var(--text)]' }}"
                   @if ($isActive)
                       style="background-color: var(--color-brand-600); color: var(--brand-contrast); box-shadow: 0 4px 10px -3px rgb(var(--brand-rgb) / 0.55);"
                   @endif>
                    <x-icon :name="$link['icon']" class="h-4 w-4" />
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-1.5">

            @if (count($appLanguages ?? []) > 1)
                @php
                    $currentLanguage = collect($appLanguages)->firstWhere('code', app()->getLocale())
                        ?? $appLanguages[0];
                @endphp
                <div class="relative" x-data="{ lang: false }" @click.outside="lang = false">
                    <button type="button" @click="lang = !lang"
                            class="flex h-9 items-center gap-2 rounded-xl border border-token px-2.5 transition-colors hover:muted-surface"
                            aria-label="{{ __('Change language') }}">
                        @if ($currentLanguage['flag'])
                            <img src="{{ $currentLanguage['flag'] }}" alt="" class="h-3.5 w-5 rounded-[3px] object-cover">
                        @else
                            <x-icon name="globe" class="h-4 w-4" />
                        @endif
                        <span class="hidden text-sm font-semibold sm:block">{{ $currentLanguage['name'] }}</span>
                        <x-icon name="chevron-down" class="h-3 w-3 faint" />
                    </button>

                    <div x-show="lang" x-cloak x-transition.opacity.duration.150ms
                         class="card absolute end-0 mt-2 w-44 overflow-hidden p-1.5"
                         style="box-shadow: var(--shadow-lg);">
                        @foreach ($appLanguages as $language)
                            @php $isCurrent = app()->getLocale() === $language['code']; @endphp
                            <form method="POST" action="{{ route('locale.switch') }}">
                                @csrf
                                <input type="hidden" name="locale" value="{{ $language['code'] }}">
                                <button type="submit"
                                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:muted-surface {{ $isCurrent ? 'brand-text' : '' }}">
                                    @if ($language['flag'])
                                        <img src="{{ $language['flag'] }}" alt="" class="h-4 w-5 rounded-sm object-cover">
                                    @endif
                                    <span class="flex-1 text-start">{{ $language['name'] }}</span>
                                    @if ($isCurrent)
                                        <x-icon name="check" class="h-4 w-4" />
                                    @endif
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif

            <button type="button" x-data @click="$store.theme.toggle()"
                    class="grid h-9 w-9 place-items-center rounded-xl border border-token transition-colors hover:muted-surface"
                    aria-label="{{ __('Toggle theme') }}">
                <span x-show="$store.theme.current === 'light'"><x-icon name="moon" class="h-4 w-4" /></span>
                <span x-show="$store.theme.current === 'dark'" x-cloak><x-icon name="sun" class="h-4 w-4" /></span>
            </button>

            @if ($rider->check())
                <div class="relative" x-data="{ menu: false }" @click.outside="menu = false">
                    <button type="button" @click="menu = !menu"
                            class="flex items-center gap-2 rounded-xl p-1 pe-2 transition-colors hover:muted-surface">
                        @if ($rider->photo())
                            <img src="{{ $rider->photo() }}" alt=""
                                 class="h-8 w-8 rounded-xl object-cover ring-2 ring-offset-1"
                                 style="--tw-ring-color: rgb(var(--brand-rgb) / 0.5); --tw-ring-offset-color: var(--surface);">
                        @else
                            {{-- A filled chip, like the logo mark on the other
                                 end of the bar. The initial used to be brand-800 on
                                 a 20%-tint wash, which for a light brand (amber) is
                                 a pale letter on near-white. --}}
                            <span class="grid h-8 w-8 place-items-center rounded-xl text-sm font-extrabold"
                                  style="background: linear-gradient(135deg, var(--color-brand-500), var(--color-brand-700)); color: var(--brand-contrast);">
                                {{ strtoupper(substr($rider->name(), 0, 1)) }}
                            </span>
                        @endif
                        <span class="hidden text-sm font-bold lg:block">{{ explode(' ', $rider->name())[0] }}</span>
                        <x-icon name="chevron-down" class="hidden h-3.5 w-3.5 faint lg:block" />
                    </button>

                    <div x-show="menu" x-cloak x-transition.opacity.duration.150ms
                         class="card absolute end-0 mt-2 w-60 overflow-hidden p-1.5"
                         style="box-shadow: var(--shadow-lg);">
                        <div class="border-b border-token px-3 pb-2.5 pt-1.5">
                            <p class="truncate text-sm font-bold">{{ $rider->name() }}</p>
                            <p class="lede truncate text-xs">{{ $rider->get('email') }}</p>
                        </div>

                        @foreach ([
                            ['app.home', __('Dashboard'), 'sparkle'],
                            ['app.trips', __('My trips'), 'receipt'],
                            ['app.wallet', __('Wallet'), 'wallet'],
                            ['app.referrals', __('Refer & earn'), 'gift'],
                            ['app.profile', __('Profile'), 'user'],
                            ['app.support', __('Help & support'), 'chat'],
                        ] as [$name, $label, $micon])
                            <a href="{{ route($name) }}"
                               class="mt-0.5 flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:muted-surface">
                                <x-icon :name="$micon" class="h-4 w-4 faint" />
                                {{ $label }}
                            </a>
                        @endforeach

                        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-token pt-1">
                            @csrf
                            <button type="submit"
                                    class="w-full rounded-lg px-3 py-2 text-start text-sm font-semibold transition-colors hover:muted-surface" style="color: var(--danger)">
                                {{ __('Sign out') }}
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('login') }}" class="btn-ghost hidden sm:inline-flex">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="btn-primary">{{ __('Get started') }}</a>
            @endif

            <button type="button" @click="open = !open"
                    class="grid h-9 w-9 place-items-center rounded-xl border border-token xl:hidden hover:muted-surface"
                    aria-label="{{ __('Menu') }}">☰</button>
        </div>
    </div>

    {{-- Mobile nav --}}
    <div x-show="open" x-cloak x-collapse class="border-t border-token xl:hidden" style="background-color: var(--surface);">
        <nav class="mx-auto flex max-w-6xl flex-col gap-0.5 px-4 py-3">
            @foreach ($services as $key => $service)
                @if ($settings->serviceEnabled($key))
                    <a href="{{ route($service['route']) }}"
                       class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-bold hover:muted-surface">
                        <x-icon :name="$service['icon']" class="h-4 w-4 faint" />
                        {{ $service['label'] }}
                    </a>
                @endif
            @endforeach

            @foreach ($menuLinks as $link)
                <a href="{{ route($link['route']) }}"
                   class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-bold hover:muted-surface">
                    <x-icon :name="$link['icon']" class="h-4 w-4 faint" />
                    {{ $link['label'] }}
                </a>
            @endforeach

            @if ($rider->check())
                <a href="{{ route('app.trips') }}" class="rounded-xl px-3 py-2.5 text-sm font-bold hover:muted-surface">{{ __('My trips') }}</a>
                <a href="{{ route('app.wallet') }}" class="rounded-xl px-3 py-2.5 text-sm font-bold hover:muted-surface">
                    {{ __('Wallet') }} · {{ $settings->money($rider->balance()) }}
                </a>
            @else
                <a href="{{ route('login') }}" class="rounded-xl px-3 py-2.5 text-sm font-bold hover:muted-surface">{{ __('Sign in') }}</a>
            @endif
        </nav>
    </div>
</header>
