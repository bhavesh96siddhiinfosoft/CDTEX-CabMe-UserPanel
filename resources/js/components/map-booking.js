import Alpine from 'alpinejs';
import { placePickerState } from './place-picker';
import { bookingState } from './booking';
import { compose } from './compose';
import { prepaidCheckout } from './prepaid-checkout';

/**
 * The map-first booking screen: picker and quote as one Alpine root.
 *
 * They have to share an instance because the shell layout renders the map and
 * the booking sheet as siblings, and an Alpine component can only reach its own
 * subtree. The picker owns pickup/drop; this re-quotes whenever they change.
 */
Alpine.data('mapBooking', (config = {}) => compose(
    placePickerState(config),
    bookingState(config),
    // Pay-first contexts (parcel) intercept submit to charge gateways first.
    config.checkout ? prepaidCheckout(config.checkout) : {},
    {
        init() {
            this.initPicker();
        },

        /**
         * Amount a pay-first checkout charges: (fare − coupon) + taxes.
         * Pay-first bookings settle NOW, so unlike rides — where the admin
         * applies coupon and tax at completion — both must land on the charge
         * itself. Without taxes in the quote this is simply the discounted
         * fare, which is what rides use.
         */
        payAmount() {
            return this.chargeTotal ?? this.estimatedTotal ?? this.subTotal;
        },

        /** Called by the picker on every pin move, address pick or reverse geocode. */
        placesChanged() {
            if (this.ready) this.quote();
        },
    },
));
