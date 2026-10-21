import Alpine from 'alpinejs';
import intlTelInput from 'intl-tel-input';
import 'intl-tel-input/styles';

/**
 * Country-code phone field, matching the picker cabme-admin uses.
 *
 * The admin's endpoints validate `country_code` and `phone` as two separate
 * fields, so this keeps two hidden inputs in sync: the dial code ("+91") and
 * the national number the rider typed.
 *
 * The iti instance is kept on the DOM node, NOT on Alpine state: Alpine wraps
 * component data in a reactive Proxy, and calling a class method with private
 * fields (#x) through a Proxy throws "cannot read private member".
 */
Alpine.data('phoneInput', (config = {}) => ({
    init() {
        const input = this.$refs.tel;

        const iti = intlTelInput(input, {
            initialCountry: config.initialCountry ?? 'in',
            separateDialCode: true,
            allowDropdown: true,
            countrySearch: true,
            useFullscreenPopup: false,
            loadUtils: () => import('intl-tel-input/utils'),
        });

        input._iti = iti;

        const sync = () => {
            // v29 API: getSelectedCountry() replaced getSelectedCountryData().
            const data = iti.getSelectedCountry?.() ?? {};
            this.$refs.countryCode.value = data.dialCode ? `+${data.dialCode}` : '';
            // National digits only — the dial code travels separately.
            this.$refs.phone.value = input.value.replace(/[^\d]/g, '');
        };

        input.addEventListener('input', sync);
        input.addEventListener('countrychange', sync);
        sync();
    },
}));
