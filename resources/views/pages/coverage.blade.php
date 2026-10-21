@extends('layouts.app')

@section('title', __('Coverage areas'))

@section('content')
    <x-page-header :title="__('Where we operate')"
                   :subtitle="__('Bookings are only accepted with a pickup point inside one of these zones.')" />

    @if (empty($zones))
        <x-empty-state :title="__('Coverage information unavailable')"
                       :message="__('We couldn\'t load the service areas right now. Please try again shortly.')"
                       icon="pin" />
    @else
        {{-- Zones come back from the admin as polygon point lists. --}}
        <div class="card mb-6 overflow-hidden"
             x-data="{
                 zones: @js(array_values($zones)),

                 init() {
                     const map = L.map(this.$refs.map).setView([20.5937, 78.9629], 5);

                     L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                         attribution: '&copy; OpenStreetMap contributors',
                         maxZoom: 19,
                     }).addTo(map);

                     const brand = getComputedStyle(document.documentElement)
                         .getPropertyValue('--color-brand-600').trim() || '#d92662';
                     const drawn = [];

                     this.zones.forEach((zone) => {
                         const raw = typeof zone.area === 'string' ? JSON.parse(zone.area || '[]') : (zone.area ?? []);
                         const points = (raw || [])
                             .map((p) => [parseFloat(p.lat ?? p.latitude), parseFloat(p.lng ?? p.longitude)])
                             .filter((p) => !isNaN(p[0]) && !isNaN(p[1]));

                         if (points.length < 3) return;

                         L.polygon(points, { color: brand, weight: 2, fillOpacity: 0.12 })
                             .addTo(map)
                             .bindTooltip(zone.name ?? 'Service area');

                         drawn.push(...points);
                     });

                     if (drawn.length) map.fitBounds(drawn, { padding: [40, 40] });

                     setTimeout(() => map.invalidateSize(), 200);
                 },
             }"
             x-init="init()">
            <div x-ref="map" class="h-[460px] w-full"></div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($zones as $zone)
                <div class="card-pad">
                    <h2 class="font-semibold">{{ $zone['name'] ?? __('Service area') }}</h2>
                    @if (! empty($zone['description']))
                        <p class="lede mt-1 text-sm">{{ $zone['description'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
