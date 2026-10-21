import Alpine from 'alpinejs';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { t } from '../i18n';

/**
 * Live ride tracking by polling.
 *
 * cabme-admin has laravel-websockets installed but registers no ride channels,
 * so there is nothing to subscribe to. This polls v1/user-recent-ride/ through
 * our own status route instead. If broadcast events are added to the admin
 * later, only this file needs to change.
 */
Alpine.data('rideTracker', (config = {}) => ({
    statusUrl: config.statusUrl,
    sosUrl: config.sosUrl,
    interval: config.interval ?? 5000,

    status: config.initialStatus ?? 'new',
    driver: config.initialDriver ?? null,
    otp: config.otp ?? null,
    active: true,

    map: null,
    driverMarker: null,
    pathLine: null,
    pathKey: null,
    timer: null,
    // Rentals only: set once the driver's final km has landed.
    payable: config.payable ?? false,

    init() {
        if (this.$refs.map) this.buildMap(config.pickup, config.drop);

        this.start();

        // Don't keep polling a backgrounded tab.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stop();
            } else {
                this.refresh();
                this.start();
            }
        });
    },

    start() {
        this.stop();
        this.timer = setInterval(() => this.refresh(), this.interval);
    },

    stop() {
        if (this.timer) clearInterval(this.timer);
        this.timer = null;
    },

    buildMap(pickup, drop) {
        this.map = L.map(this.$refs.map, { zoomControl: true, keyboard: false });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(this.map);

        // Same colours as the sheet's route dots: green pickup, brand-colour
        // drop. The drop was hardcoded pink here once, which stopped matching
        // the panel after rebranding — always read the live token instead.
        const brand = getComputedStyle(document.documentElement)
            .getPropertyValue('--color-brand-600').trim() || '#d92662';

        const points = [];

        if (pickup?.lat) {
            L.circleMarker([pickup.lat, pickup.lng], {
                radius: 8, color: '#12b76a', fillColor: '#12b76a', fillOpacity: 1, weight: 2,
            }).addTo(this.map).bindTooltip(t('Pickup'));
            points.push([pickup.lat, pickup.lng]);
        }

        if (drop?.lat) {
            L.circleMarker([drop.lat, drop.lng], {
                radius: 8, color: brand, fillColor: brand, fillOpacity: 1, weight: 2,
            }).addTo(this.map).bindTooltip(t('Drop-off'));
            points.push([drop.lat, drop.lng]);
        }

        // Road path pickup → drop, computed server-side. Fit to the whole
        // path when present — a long route bows far outside the straight line
        // between its endpoints. Once a driver is on the way, refresh()
        // replaces this with the leg the car is actually driving.
        const path = Array.isArray(config.path) && config.path.length > 1 ? config.path : null;

        if (path) {
            // Conventional map blue for the path (matches the booking map);
            // the brand colour stays on the drop marker.
            this.pathLine = L.polyline(path, { color: '#2563eb', weight: 5, opacity: 0.9 }).addTo(this.map);
        }

        const bounds = path ?? (points.length ? points : null);

        if (bounds) {
            this.map.fitBounds(bounds, { padding: [60, 60], maxZoom: 15 });
        } else {
            this.map.setView([20.5937, 78.9629], 5);
        }

        setTimeout(() => this.map.invalidateSize(), 200);
    },

    async refresh() {
        try {
            const { data } = await window.axios.get(this.statusUrl);

            if (!data.active) {
                this.active = false;
                this.stop();
                // The ride finished or was cancelled elsewhere; reload so the
                // server decides what this rider should see next.
                window.location.reload();
                return;
            }

            // A rental turns into a payment screen the moment its driver
            // files the final km — that block is server-rendered, so ask for
            // the page again rather than rebuilding it here.
            if (data.payable && !this.payable) {
                this.payable = true;
                this.stop();
                window.location.reload();
                return;
            }

            const previous = this.status;
            this.status = data.status;
            this.driver = data.driver;
            this.otp = data.otp;

            if (previous !== this.status && this.statusLabel) {
                Alpine.store('toasts').push(this.statusLabel, 'info');
            }

            this.placeDriver();
            this.placeLeg(data, previous !== this.status);
        } catch {
            // A dropped poll is not worth interrupting the rider over; the
            // next tick will pick things up again.
        }
    },

    placeDriver() {
        if (!this.map || !this.driver?.latitude) return;

        const position = [parseFloat(this.driver.latitude), parseFloat(this.driver.longitude)];

        if (this.driverMarker) {
            this.driverMarker.setLatLng(position);
        } else {
            // A car on a white chip, like the app — a plain dot read as "just
            // another marker" and QA thought tracking was missing.
            const car = L.divIcon({
                className: '',
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                html: `<div style="display:grid;place-items:center;width:36px;height:36px;border-radius:9999px;background:#fff;box-shadow:0 2px 10px rgb(0 0 0 / .4);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#111827" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 11l1.2-3.6A2 2 0 0 1 8.1 6h7.8a2 2 0 0 1 1.9 1.4L19 11"/>
                        <path d="M4 11h16a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-1"/>
                        <path d="M5 17H4a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1"/>
                        <path d="M8 17h8"/>
                        <circle cx="6.5" cy="17" r="1.6" fill="#111827"/>
                        <circle cx="17.5" cy="17" r="1.6" fill="#111827"/>
                    </svg>
                </div>`,
            });
            this.driverMarker = L.marker(position, { icon: car, zIndexOffset: 500 })
                .addTo(this.map).bindTooltip(t('Your driver'));
        }
    },

    /**
     * The road on the map follows the trip: driver → pickup while the driver
     * is coming, driver → destination once the rider is in the car. The
     * server re-routes only when the car has moved, and path_key tells us
     * when a redraw is actually needed.
     */
    placeLeg(data, statusChanged) {
        if (!this.map) return;
        if (data.path_key === this.pathKey && !statusChanged) return;

        const path = Array.isArray(data.path) && data.path.length > 1 ? data.path : null;

        if (path) {
            this.pathKey = data.path_key;
            if (this.pathLine) this.pathLine.setLatLngs(path);
            else this.pathLine = L.polyline(path, { color: '#2563eb', weight: 5, opacity: 0.9 }).addTo(this.map);

            // Re-frame on status transitions only — refitting on every move
            // would fight the rider's own panning.
            if (statusChanged) {
                this.map.fitBounds(path, { padding: [60, 60], maxZoom: 15 });
            }
        }
    },

    get statusLabel() {
        const label = {
            'new': 'Looking for a driver',
            'confirmed': 'Driver on the way',
            'on ride': 'On the way to your destination',
            'completed': 'Trip completed',
            'cancelled': 'Trip cancelled',
            'canceled': 'Trip cancelled',
        }[this.status];

        return label ? t(label) : this.status;
    },

    get statusClass() {
        return {
            'new': 'badge-new',
            'confirmed': 'badge-confirmed',
            'on ride': 'badge-onride',
            'completed': 'badge-completed',
            'cancelled': 'badge-cancelled',
            'canceled': 'badge-cancelled',
        }[this.status] ?? 'badge-neutral';
    },

    /**
     * "Maruti Swift" — the brand and model the admin joins onto the vehicle,
     * shown under the plate. Either half may be missing.
     */
    vehicleLabel(driver) {
        const vehicle = driver?.vehicle_details ?? {};

        return [vehicle.brand, vehicle.model].filter(Boolean).join(' ');
    },

    /**
     * Emergency alert. The ride id is what the admin needs; coordinates are
     * extra context, so a blocked geolocation must never stop the alert.
     */
    sos() {
        if (!this.sosUrl) return;
        const send = async (coords = null) => {
            try {
                const { data } = await window.axios.post(this.sosUrl, {
                    booking_id: config.rideId,
                    latitude: coords?.latitude ?? null,
                    longitude: coords?.longitude ?? null,
                });
                Alpine.store('toasts').success(data.message);
            } catch (e) {
                Alpine.store('toasts').error(e.response?.data?.message ?? t('Could not send the alert.'));
            }
        };

        if (!navigator.geolocation) {
            send();
            return;
        }

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => send(coords),
            () => send(),           // denied or unavailable — send anyway
            { timeout: 5000 },
        );
    },
}));
