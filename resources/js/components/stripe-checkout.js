/**
 * Minimal Stripe Payment Element runner, shared by wallet top-up and
 * pay-first bookings.
 *
 * The server has already created the PaymentIntent for ITS amount; this only
 * collects the card and confirms. Returns the confirmed intent id, or null if
 * the rider closed the dialog / the payment failed (an error toast is shown).
 */

import { t } from '../i18n';

const STRIPE_SRC = 'https://js.stripe.com/v3/';

function loadStripeJs() {
    if (window.Stripe) return Promise.resolve(true);

    return new Promise((resolve) => {
        const existing = document.querySelector(`script[src="${STRIPE_SRC}"]`);
        if (existing) {
            existing.addEventListener('load', () => resolve(true));
            existing.addEventListener('error', () => resolve(false));
            return;
        }
        const script = document.createElement('script');
        script.src = STRIPE_SRC;
        script.onload = () => resolve(true);
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}

function buildDialog() {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 z-[60] flex items-center justify-center p-4';
    overlay.style.background = 'rgb(0 0 0 / 0.5)';

    overlay.innerHTML = `
        <div class="card w-full max-w-md p-5" style="box-shadow: var(--shadow-xl);">
            <div class="mb-4 flex items-center justify-between">
                <p class="text-base font-bold">${t('Card payment')}</p>
                <button type="button" data-stripe-close
                        class="grid h-8 w-8 place-items-center rounded-lg faint hover:muted-surface">&times;</button>
            </div>
            <div data-stripe-mount></div>
            <p data-stripe-error class="mt-3 hidden text-sm text-rose-600"></p>
            <button type="button" data-stripe-pay class="btn-primary mt-4 w-full">${t('Pay')}</button>
        </div>`;

    document.body.appendChild(overlay);
    return overlay;
}

/**
 * @param {{publishable_key: string, client_secret: string}} order
 * @returns {Promise<string|null>} the confirmed payment intent id, or null
 */
export async function runStripeCheckout(order, onError) {
    const loaded = await loadStripeJs();

    if (!loaded) {
        onError?.(t('Could not load the payment window. Check your connection.'));
        return null;
    }

    const stripe = window.Stripe(order.publishable_key);

    const brand = getComputedStyle(document.documentElement)
        .getPropertyValue('--color-brand-600').trim() || '#d92662';

    // Follow the panel theme — Stripe's light theme paints dark labels,
    // which vanish on our dark card.
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';

    const elements = stripe.elements({
        clientSecret: order.client_secret,
        appearance: {
            theme: dark ? 'night' : 'stripe',
            variables: { colorPrimary: brand, borderRadius: '12px' },
        },
    });

    const dialog = buildDialog();
    const errorEl = dialog.querySelector('[data-stripe-error]');
    const payButton = dialog.querySelector('[data-stripe-pay]');

    const paymentElement = elements.create('payment', { layout: 'tabs' });
    paymentElement.mount(dialog.querySelector('[data-stripe-mount]'));

    // A mount failure (bad publishable key, account mismatch, network) is
    // silent by default — the dialog just stays empty. Surface it instead.
    paymentElement.on('loaderror', (event) => {
        const errorEl = dialog.querySelector('[data-stripe-error]');
        errorEl.textContent = event?.error?.message
            ?? t('The card form could not load. Please check the Stripe keys in the admin panel.');
        errorEl.classList.remove('hidden');
        dialog.querySelector('[data-stripe-pay]').disabled = true;
    });

    return new Promise((resolve) => {
        const close = (value) => {
            dialog.remove();
            resolve(value);
        };

        dialog.querySelector('[data-stripe-close]').addEventListener('click', () => close(null));

        payButton.addEventListener('click', async () => {
            payButton.disabled = true;
            errorEl.classList.add('hidden');

            const { error, paymentIntent } = await stripe.confirmPayment({
                elements,
                redirect: 'if_required',
            });

            if (error) {
                errorEl.textContent = error.message ?? 'That payment did not go through.';
                errorEl.classList.remove('hidden');
                payButton.disabled = false;
                return;
            }

            if (paymentIntent?.status === 'succeeded') {
                close(paymentIntent.id);
                return;
            }

            errorEl.textContent = t('That payment has not completed.');
            errorEl.classList.remove('hidden');
            payButton.disabled = false;
        });
    });
}
