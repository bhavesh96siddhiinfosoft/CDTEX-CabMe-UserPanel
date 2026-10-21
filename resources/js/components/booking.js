/**
 * Quote + selection state for the ride and parcel booking forms.
 *
 * Deliberately does NOT own `pickup`/`drop` — when composed with
 * `placePickerState` the picker owns them and this reads them, so the two
 * halves cannot drift apart.
 *
 * `quoteUrl` returns { route, vehicle_types } for rides and
 * { route, categories } for parcels; the shapes are interchangeable here.
 */
import { t } from '../i18n';

export function bookingState(config = {}) {
    return {
        quoteUrl: config.quoteUrl,
        optionsKey: config.optionsKey ?? 'vehicle_types',

        route: null,
        options: [],
        selected: null,
        loading: false,
        error: null,
        quoteToken: 0,

        passengers: 1,
        children: 0,
        couponId: '',

        // Parcel: the admin prices per km AND per kg, so the weight is part
        // of the quote, not just of the booking. Rides leave it empty and
        // quoteExtra() then sends nothing.
        weight: '',
        dimension: '',
        note: '',
        paymentMethodId: config.defaultPaymentMethod ?? '',

        async quote() {
            this.loading = true;
            this.error = null;

            // Dragging a pin fires several quotes in a row; only the newest
            // result is allowed to land.
            const token = ++this.quoteToken;

            try {
                const { data } = await window.axios.post(this.quoteUrl, {
                    pickup_lat: this.pickup.lat,
                    pickup_lng: this.pickup.lng,
                    drop_lat: this.drop.lat,
                    drop_lng: this.drop.lng,
                    // Multi-stop rides: route (and price) via each stop in order.
                    stops: (this.placedStops ?? []).map((s) => ({
                        latitude: s.lat, longitude: s.lng, address: s.address,
                    })),
                    // A parcel is priced per km AND per kg, so its weight has
                    // to go with the quote.
                    ...(this.quoteExtra?.() ?? {}),
                });

                if (token !== this.quoteToken) return;

                this.route = data.route;
                this.options = data[this.optionsKey] ?? [];
                // Pay-first bookings are billed with tax; the server sends the
                // rows the admin will store so the breakdown is truthful.
                this.taxes = data.taxes ?? [];

                // Composed with the place picker, the dashed placeholder line
                // becomes the actual road path the server routed.
                if (this.route?.geometry && typeof this.drawRoute === 'function') {
                    this.drawRoute(this.route.geometry);
                }

                // Keep the rider's choice across re-quotes when it still exists.
                const previous = this.selected?.id;
                this.selected = this.options.find((o) => o.id === previous) ?? this.options[0] ?? null;
            } catch (e) {
                if (token !== this.quoteToken) return;

                this.error = true;    // keeps the fare list hidden
                Alpine.store('toasts').error(e.response?.data?.message ?? t('Could not price this trip. Please try again.'));
                this.options = [];
                this.selected = null;
            } finally {
                if (token === this.quoteToken) this.loading = false;
            }
        },

        select(option) {
            this.selected = option;
        },

        /** Extra quote parameters for this booking type. */
        quoteExtra() {
            return this.weight === '' ? {} : { weight: Number(this.weight) || 0 };
        },

        /** Re-price when something the fare depends on changes. */
        requote() {
            if (this.ready) this.quote();
        },

        /** "203" → "3 hr 23 min". Long trips read as nonsense in raw minutes. */
        get durationLabel() {
            const total = Math.round(Number(this.route?.duration ?? 0));

            if (!total) return null;
            if (total < 60) return `${total} min`;

            const hours = Math.floor(total / 60);
            const minutes = total % 60;

            return minutes ? `${hours} hr ${minutes} min` : `${hours} hr`;
        },

        /** The fare the admin quoted for this option, whichever key it used. */
        fareOf(option) {
            return option?.base_fare ?? option?.total ?? option?.amount ?? option?.price ?? null;
        },

        get subTotal() {
            return this.fareOf(this.selected);
        },

        /* ----------------------------------------------------- coupons */

        // [{id, discount, percent}] passed by the page; display-only maths —
        // the admin stores the coupon on the booking and applies it at
        // completion, so `sub_total` stays the undiscounted fare.
        coupons: config.coupons ?? [],

        get selectedCoupon() {
            if (!this.couponId) return null;

            return this.coupons.find((c) => String(c.id) === String(this.couponId)) ?? null;
        },

        get discountAmount() {
            const coupon = this.selectedCoupon;
            const base = Number(this.subTotal);

            if (!coupon || !base) return 0;

            const value = Number(coupon.discount) || 0;
            const off = coupon.percent ? (base * value) / 100 : value;

            return Math.min(Math.round(off * 100) / 100, base);
        },

        /* -------------------------------------------------------- taxes */

        // [{libelle, value, type}] from the quote; empty for rides, which are
        // taxed by the admin at completion rather than charged up front.
        taxes: [],

        get taxAmount() {
            const base = Number(this.subTotal) - this.discountAmount;

            if (!base || !this.taxes.length) return 0;

            const total = this.taxes.reduce((sum, tax) => sum + (
                String(tax.type).toLowerCase() === 'percentage'
                    ? (Number(tax.value) || 0) * base / 100
                    : (Number(tax.value) || 0)
            ), 0);

            return Math.round(total * 100) / 100;
        },

        /** The figure actually charged: (fare − coupon) + taxes. */
        get chargeTotal() {
            const base = Number(this.subTotal);

            if (!base) return null;

            return Math.round((base - this.discountAmount + this.taxAmount) * 100) / 100;
        },

        /** What the rider is estimated to pay after the coupon. */
        get estimatedTotal() {
            const base = Number(this.subTotal);

            if (!base) return null;

            return Math.round((base - this.discountAmount) * 100) / 100;
        },

        get canSubmit() {
            return !this.loading && this.selected != null && this.route != null;
        },
    };
}
