import Alpine from 'alpinejs';
import { runGatewayCheckout, openPayPopup, verifyWithRetry } from './gateway-runners';
import { t } from '../i18n';

/**
 * Pay Now for an active ride (status "on ride") — the app's ride payment
 * screen.
 *
 * Wallet and gateway payments ARE the completion: the wallet debit happens
 * inside the admin's completion call, and a gateway runs the shared charge
 * pipeline first — order (server sets the amount) → the gateway's checkout
 * UI → /app/pay/verify — and only then completes.
 *
 * Cash is different and must not complete anything: the rider hands the
 * money over, the DRIVER confirms it and closes the trip. Picking cash here
 * only records the choice on the booking.
 */
Alpine.data('ridePay', (config = {}) => ({
    methodId: config.methodId ?? '',
    payBusy: false,

    methodKind() {
        return (window.__methodKinds || {})[String(this.methodId)] ?? 'gateway';
    },

    isCash() {
        return this.methodKind() === 'offline';
    },

    async pay() {
        if (this.payBusy || !this.methodId) return;

        // Cash: record the method and stop. The driver collects the fare,
        // confirms it on their side and completes the ride.
        if (this.isCash()) {
            this.payBusy = true;
            this.$refs.methodForm.submit();
            return;
        }

        if (this.methodKind() !== 'gateway') {
            this.payBusy = true;
            this.$refs.completeForm.submit();
            return;
        }

        // Hosted-page gateways open in a popup, and popup blockers demand
        // the open happen inside this click — before any awaits.
        const flow = (window.__methodFlows || {})[String(this.methodId)];
        const popup = flow === 'redirect' ? openPayPopup() : null;

        this.payBusy = true;

        try {
            const { data: order } = await window.axios.post(config.orderUrl, {
                payment_method_id: this.methodId,
            });

            const payload = await runGatewayCheckout(order, {
                description: t('Ride payment'),
                popup,
                onError: (message) => Alpine.store('toasts').error(message),
            });

            if (!payload) return;    // closed the window, or the runner reported why

            try {
                await verifyWithRetry('/app/pay/verify', payload);
                this.$refs.completeForm.submit();
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
}));
