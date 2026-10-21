import Alpine from 'alpinejs';
import { runGatewayCheckout, openPayPopup, verifyWithRetry } from './gateway-runners';
import { t } from '../i18n';

/**
 * Pay-first booking submit (parcel, rental).
 *
 * Cash and wallet submit straight through — the server settles those. A
 * gateway method runs the charge first: order (server sets the amount) →
 * the gateway's checkout UI → server verification, and only then does the
 * form submit. The booking endpoint takes the transaction id from the
 * verified session record, so nothing the form posts decides what was paid.
 *
 * Mix into a form component with: ...prepaidCheckout('parcel')
 * Requires on the host: paymentMethodId, an amount (via payAmount()), and
 * window.__methodKinds — {id: kind} rendered by the payment picker's page.
 */
export function prepaidCheckout(context) {
    return {
        payBusy: false,

        methodKind(id) {
            return (window.__methodKinds || {})[String(id)] ?? 'gateway';
        },

        async submitWithPayment(event) {
            const form = event.target;

            if (this.methodKind(this.paymentMethodId) !== 'gateway') {
                form.submit();
                return;
            }

            // Hosted-page gateways open in a popup, and popup blockers demand
            // the open happen inside this submit — before any awaits.
            const flow = (window.__methodFlows || {})[String(this.paymentMethodId)];
            const popup = flow === 'redirect' ? openPayPopup() : null;

            this.payBusy = true;

            try {
                const { data: order } = await window.axios.post('/app/pay/order', {
                    amount: this.payAmount(),
                    payment_method_id: this.paymentMethodId,
                    context,
                });

                const payload = await runGatewayCheckout(order, {
                    description: context === 'parcel' ? t('Parcel delivery') : t('Vehicle rental'),
                    popup,
                    onError: (message) => Alpine.store('toasts').error(message),
                });

                if (!payload) return;    // closed the window, or the runner reported why

                try {
                    await verifyWithRetry('/app/pay/verify', payload);
                    form.submit();
                } catch (e) {
                    Alpine.store('toasts').error(e.response?.data?.message ?? t('We could not confirm that payment.'));
                }
            } catch (e) {
                popup?.close?.();
                Alpine.store('toasts').error(e.response?.data?.message ?? t('Could not start the payment.'));
            } finally {
                this.payBusy = false;
            }
        },
    };
}

// Rental composes this with its inline x-data via a registered helper.
Alpine.data('prepaidCheckout', prepaidCheckout);
window.prepaidCheckout = prepaidCheckout;
