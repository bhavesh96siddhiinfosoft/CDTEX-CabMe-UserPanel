import Alpine from 'alpinejs';
import L from 'leaflet';
import { compose } from './compose';
import { t } from '../i18n';

// Leaflet's default marker images break under Vite's asset hashing, so the pins
// are drawn as inline divIcons instead of shipping the sprite.
const pin = (color, ring) => L.divIcon({
    className: '',
    html: `<span style="display:block;width:16px;height:16px;border-radius:9999px;
           background:${color};border:3px solid #fff;
           box-shadow:0 0 0 4px ${ring}, 0 2px 6px rgba(0,0,0,.25)"></span>`,
    iconSize: [16, 16],
    iconAnchor: [8, 8],
});

const brand = () => getComputedStyle(document.documentElement)
    .getPropertyValue('--color-brand-600').trim() || '#d92662';

// Route polylines are conventional map blue, not the brand colour — the same
// blue as the driver marker on the tracking screens.
const ROUTE_COLOR = '#2563eb';

const PICKUP_PIN = () => pin('#12b76a', 'rgba(18,183,106,.22)');
const DROP_PIN = () => pin(brand(), 'rgba(var(--brand-rgb), .22)');

/** Each end owns its own search box, results and debounce timer. */
const end = () => ({
    lat: null,
    lng: null,
    address: '',
    query: '',
    results: [],
    searching: false,
    timer: null,
});

/**
 * Two-ended location picker on a Leaflet/OSM map.
 *
 * Exported as a plain state factory so it can be composed with `bookingState`
 * into a single Alpine root — the map and the booking sheet are siblings in the
 * shell layout, so they must share one component instance.
 *
 * Geocoding uses Nominatim, which needs no key and matches the fallback the
 * admin itself uses.
 */
export function placePickerState(config = {}) {
    return {
        map: null,
        markers: { pickup: null, drop: null },
        line: null,

        pickup: end(),
        drop: end(),

        /**
         * Intermediate stops (max 3, like the app). Each is an `end()` plus
         * its own marker; the admin stores whatever JSON we send, so keys
         * follow its own latitude/longitude/address convention.
         */
        stops: [],
        stopMarkers: [],

        active: 'pickup',        // which end a map click sets

        initPicker() {
            // keyboard: false stops Leaflet giving the container a tabindex.
            // A focusable map means clicking it focuses the element, and the
            // browser then scrolls that element into view — which dragged the
            // whole page and left a blank strip under the shell.
            this.map = L.map(this.$refs.map, {
                zoomControl: true,
                attributionControl: true,
                keyboard: false,
            }).setView(config.center ?? [20.5937, 78.9629], config.zoom ?? 5);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.map);

            this.map.on('click', (e) => this.setPoint(this.active, e.latlng.lat, e.latlng.lng, null, true));

            // A route carried over from the public landing page.
            const initial = config.initial ?? {};
            let restored = false;

            for (const which of ['pickup', 'drop']) {
                const point = initial[which];
                if (point && point.lat != null && point.lng != null) {
                    this.setPoint(which, parseFloat(point.lat), parseFloat(point.lng), point.address ?? null);
                    restored = true;
                }
            }

            if (!restored) this.locate();

            // The map often mounts behind a sheet; Leaflet needs a nudge once
            // its container has its final size or it renders as a grey box.
            this.$nextTick(() => setTimeout(() => this.map.invalidateSize(), 200));
            window.addEventListener('resize', () => this.map && this.map.invalidateSize());
        },

        /** Centre on the browser's location and use it as the default pickup. */
        locate() {
            if (!navigator.geolocation) return;

            navigator.geolocation.getCurrentPosition(
                ({ coords }) => {
                    this.map.setView([coords.latitude, coords.longitude], 14);
                    if (!this.pickup.lat) {
                        this.setPoint('pickup', coords.latitude, coords.longitude, null, true);
                    }
                },
                () => {},
                { timeout: 8000 },
            );
        },

        /* ------------------------------------------------------- searching */

        onQueryInput(which) {
            const target = this[which];
            clearTimeout(target.timer);

            if (target.query.trim().length < 3) {
                target.results = [];
                return;
            }

            // Nominatim asks for at most one request per second.
            target.timer = setTimeout(() => this.search(which), 450);
        },

        async search(which) {
            const target = this[which];
            target.searching = true;

            try {
                const url = new URL('https://nominatim.openstreetmap.org/search');
                url.searchParams.set('q', target.query);
                url.searchParams.set('format', 'json');
                url.searchParams.set('limit', '6');

                const response = await fetch(url, {
                    headers: { 'Accept-Language': document.documentElement.lang || 'en' },
                });
                target.results = response.ok ? await response.json() : [];
            } catch {
                target.results = [];
            } finally {
                target.searching = false;
            }
        },

        choose(which, result) {
            this.setPoint(which, parseFloat(result.lat), parseFloat(result.lon), result.display_name);
            this[which].query = '';
            this[which].results = [];
        },

        clear(which) {
            const target = this[which];
            target.lat = target.lng = null;
            target.address = '';
            target.query = '';
            target.results = [];

            if (this.markers[which]) {
                this.map.removeLayer(this.markers[which]);
                this.markers[which] = null;
            }

            if (this.line) {
                this.map.removeLayer(this.line);
                this.line = null;
            }

            this.placesChanged();
        },

        /* --------------------------------------------------------- points */

        setPoint(which, lat, lng, address = null, reverse = false) {
            const target = this[which];

            target.lat = lat;
            target.lng = lng;
            if (address) target.address = address;

            if (this.markers[which]) {
                this.markers[which].setLatLng([lat, lng]);
            } else {
                this.markers[which] = L.marker([lat, lng], {
                    icon: which === 'drop' ? DROP_PIN() : PICKUP_PIN(),
                    draggable: true,
                }).addTo(this.map);

                this.markers[which].on('dragend', (e) => {
                    const { lat: dLat, lng: dLng } = e.target.getLatLng();
                    this.setPoint(which, dLat, dLng, null, true);
                });
            }

            if (reverse) this.reverse(which, lat, lng);

            this.drawLine();
            this.fit();

            // After a map tap on pickup, the next tap should place the drop.
            if (which === 'pickup' && !this.drop.lat && !config.singlePoint) {
                this.active = 'drop';
            }

            this.placesChanged();
        },

        async reverse(which, lat, lng) {
            const target = this[which];
            target.address = t('Locating…');

            try {
                const url = new URL('https://nominatim.openstreetmap.org/reverse');
                url.searchParams.set('lat', lat);
                url.searchParams.set('lon', lng);
                url.searchParams.set('format', 'json');

                const response = await fetch(url);
                const data = response.ok ? await response.json() : null;
                target.address = data?.display_name ?? `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            } catch {
                target.address = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            }

            this.placesChanged();
        },

        /**
         * Straight connector — immediate feedback while the quote is in
         * flight; replaced by the road path as soon as one arrives. Solid
         * route blue (the user rejected the dashed brand-colour line): the
         * conventional look for a path on a map, and it matches the driver
         * marker on the tracking screens.
         */
        drawLine() {
            if (!this.ready) return;

            const points = [[this.pickup.lat, this.pickup.lng], [this.drop.lat, this.drop.lng]];

            if (this.line) {
                this.line.setLatLngs(points);
                this.line.setStyle({ opacity: 0.7, weight: 4 });
                return;
            }

            this.line = L.polyline(points, {
                color: ROUTE_COLOR, weight: 4, opacity: 0.7,
            }).addTo(this.map);
        },

        /** The actual road path from the routing provider, solid and fitted. */
        drawRoute(geometry) {
            if (!Array.isArray(geometry) || geometry.length < 2 || !this.map) return;

            if (this.line) {
                this.line.setLatLngs(geometry);
                this.line.setStyle({ opacity: 0.9, weight: 5 });
            } else {
                this.line = L.polyline(geometry, {
                    color: ROUTE_COLOR, weight: 5, opacity: 0.9,
                }).addTo(this.map);
            }

            const padding = window.innerWidth >= 1024 ? [[80, 440], [80, 80]] : [[80, 80], [420, 80]];
            this.map.fitBounds(geometry, {
                paddingTopLeft: padding[0], paddingBottomRight: padding[1],
            });
        },

        fit() {
            const points = [this.pickup, this.drop].filter((p) => p.lat != null).map((p) => [p.lat, p.lng]);

            // Leave room for the sheet so the route isn't hidden behind it.
            const padding = window.innerWidth >= 1024 ? [[80, 440], [80, 80]] : [[80, 80], [420, 80]];

            if (points.length === 2) {
                this.map.fitBounds(points, { paddingTopLeft: padding[0], paddingBottomRight: padding[1], maxZoom: 15 });
            } else if (points.length === 1) {
                this.map.setView(points[0], Math.max(this.map.getZoom(), 14));
            }
        },

        swap() {
            const a = { lat: this.pickup.lat, lng: this.pickup.lng, address: this.pickup.address };
            const b = { lat: this.drop.lat, lng: this.drop.lng, address: this.drop.address };

            if (a.lat != null) this.setPoint('drop', a.lat, a.lng, a.address);
            if (b.lat != null) this.setPoint('pickup', b.lat, b.lng, b.address);
        },

        /* ---------------------------------------------------------- stops */

        addStop() {
            if (this.stops.length >= 3) return;
            this.stops.push(end());
            this.stopMarkers.push(null);
        },

        removeStop(index) {
            if (this.stopMarkers[index]) this.map.removeLayer(this.stopMarkers[index]);
            this.stops.splice(index, 1);
            this.stopMarkers.splice(index, 1);
            this.placesChanged();
        },

        onStopQuery(index) {
            const stop = this.stops[index];
            clearTimeout(stop.timer);
            if (stop.query.trim().length < 3) { stop.results = []; return; }
            stop.timer = setTimeout(async () => {
                try {
                    const url = new URL('https://nominatim.openstreetmap.org/search');
                    url.searchParams.set('q', stop.query);
                    url.searchParams.set('format', 'json');
                    url.searchParams.set('limit', '5');
                    const response = await fetch(url);
                    stop.results = response.ok ? await response.json() : [];
                } catch { stop.results = []; }
            }, 450);
        },

        chooseStop(index, result) {
            const stop = this.stops[index];
            stop.lat = parseFloat(result.lat);
            stop.lng = parseFloat(result.lon);
            stop.address = result.display_name;
            stop.query = '';
            stop.results = [];

            const amber = '#f79009';
            if (this.stopMarkers[index]) {
                this.stopMarkers[index].setLatLng([stop.lat, stop.lng]);
            } else {
                this.stopMarkers[index] = L.marker([stop.lat, stop.lng], {
                    icon: pin(amber, 'rgba(247,144,9,.22)'),
                    draggable: true,
                }).addTo(this.map);
                this.stopMarkers[index].on('dragend', (e) => {
                    const { lat, lng } = e.target.getLatLng();
                    stop.lat = lat; stop.lng = lng;
                    this.placesChanged();
                });
            }

            this.placesChanged();
        },

        /** Set stops with coordinates, in order — what the quote and booking use. */
        get placedStops() {
            return this.stops.filter((s) => s.lat != null);
        },

        get ready() {
            return this.pickup.lat != null && this.drop.lat != null;
        },

        /**
         * Composed components override this to re-quote. Standalone usage still
         * emits the DOM event, which the rental form listens for.
         */
        placesChanged() {
            this.$dispatch('places-changed', { pickup: this.pickup, drop: this.drop });
        },
    };
}

// Standalone registration — used by the rental form and the public landing
// page. Composed rather than spread so `ready` stays a live getter.
Alpine.data('placePicker', (config = {}) => compose(
    placePickerState(config),
    {
        init() {
            this.initPicker();
        },
    },
));
