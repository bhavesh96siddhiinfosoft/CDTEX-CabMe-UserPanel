import Alpine from 'alpinejs';
import { runGatewayCheckout, openPayPopup, verifyWithRetry } from './gateway-runners';
import { t } from '../i18n';

/**
 * Wallet top-up through whichever gateway the rider picked.
 *
 * This deliberately never tells the server what to credit. It asks for an
 * order (the amount is fixed server-side against it), runs the gateway's
 * checkout, and hands the gateway's identifiers back for verification —
 * money is credited only after the server has confirmed the charge with the
 * gateway directly.
 */
Alpine.data('walletTopup', (config = {}) => ({
    orderUrl: config.orderUrl,
    verifyUrl: config.verifyUrl,
    gatewaysReady: config.gatewaysReady ?? false,

    amount: null,
    methodId: config.methodId ?? '',
    busy: false,

    get canPay() {
        return !this.busy && this.gatewaysReady && this.methodId && Number(this.amount) >= 1;
    },

    async pay() {
        this.busy = true;

        // Hosted-page gateways open in a popup, and popup blockers demand
        // the open happen inside this click — before any awaits.
        const flow = (window.__methodFlows || {})[String(this.methodId)];
        const popup = flow === 'redirect' ? openPayPopup() : null;

        try {
            const { data: order } = await window.axios.post(this.orderUrl, {
                amount: this.amount,
                payment_method_id: this.methodId,
            });

            const payload = await runGatewayCheckout(order, {
                description: t('Wallet top-up'),
                popup,
                onError: (message) => Alpine.store('toasts').error(message),
            });

            if (!payload) return;    // closed the window, or the runner reported why

            try {
                const data = await verifyWithRetry(this.verifyUrl, payload);
                Alpine.store('toasts').success(t('Wallet topped up.'));
                window.location.href = data.redirect;
            } catch (e) {
                // The money may have left the rider's account, so this message
                // has to be specific rather than a generic retry.
                Alpine.store('toasts').error(e.response?.data?.message ?? t('We could not confirm that payment.'));
            }
        } catch (e) {
            popup?.close?.();
            Alpine.store('toasts').error(e.response?.data?.message ?? t('Could not start the payment. Please try again.'));
        } finally {
            this.busy = false;
        }
    },
}));
