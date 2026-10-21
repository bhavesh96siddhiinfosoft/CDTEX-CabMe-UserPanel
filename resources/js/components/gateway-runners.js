import { runStripeCheckout } from './stripe-checkout';
import { t } from '../i18n';

/**
 * One entry point for every payment gateway's browser step.
 *
 * The server has already started the charge for ITS amount; `order` is the
 * client payload from /pay/order (or the wallet's order route) and carries a
 * `gateway` discriminator. Each runner collects the payment in whatever UI
 * that gateway ships and resolves with the exact body our verify endpoint
 * expects — or null when the rider closed the window. Nothing a runner
 * returns is trusted: verification is server-side, against the gateway.
 */

const loadedScripts = new Map();

function loadScript(src, isReady) {
    if (isReady()) return Promise.resolve(true);

    if (!loadedScripts.has(src)) {
        loadedScripts.set(src, new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = () => resolve(true);
            script.onerror = () => {
                loadedScripts.delete(src);    // allow a retry after a network blip
                resolve(false);
            };
            document.head.appendChild(script);
        }));
    }

    return loadedScripts.get(src);
}

function brandColor() {
    return getComputedStyle(document.documentElement)
        .getPropertyValue('--color-brand-600').trim() || '#d92662';
}

const LOAD_FAILED = () => t('Could not load the payment window. Check your connection.');

function runRazorpay(order, { onError, description }) {
    return new Promise((resolve) => {
        const checkout = new window.Razorpay({
            key: order.key,
            order_id: order.order_id,
            amount: order.amount,
            currency: order.currency,
            name: order.name,
            description,
            prefill: order.prefill,
            theme: { color: brandColor() },
            handler: (response) => resolve({
                razorpay_order_id: response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature,
            }),
            modal: { ondismiss: () => resolve(null) },
        });

        checkout.on('payment.failed', (e) => {
            onError?.(e?.error?.description ?? t('That payment did not go through.'));
            resolve(null);
        });

        checkout.open();
    });
}

function runPaystack(order, { onError }) {
    return new Promise((resolve) => {
        // Amount and currency here are cosmetic — verification compares the
        // transaction Paystack recorded against the server's own figures.
        try {
            window.PaystackPop.setup({
                key: order.key,
                email: order.email,
                amount: order.amount,
                // currency deliberately omitted — the merchant's own applies.
                ...(order.currency ? { currency: order.currency } : {}),
                ref: order.reference,
                callback: (response) => resolve({ reference: response.reference ?? order.reference }),
                onClose: () => resolve(null),
            }).openIframe();
        } catch (e) {
            onError?.(e?.message ?? 'Paystack could not start.');
            resolve(null);
        }
    });
}

function runFlutterwave(order, { onError, description }) {
    return new Promise((resolve) => {
        try {
            const modal = window.FlutterwaveCheckout({
                public_key: order.public_key,
                tx_ref: order.tx_ref,
                amount: order.amount,
                currency: order.currency,
                customer: order.customer,
                customizations: { title: document.title, description },
                callback: (payment) => {
                    modal?.close?.();
                    if (String(payment?.status).toLowerCase() === 'successful' || payment?.transaction_id) {
                        resolve({ transaction_id: String(payment.transaction_id) });
                    } else {
                        onError?.(t('That payment did not go through.'));
                        resolve(null);
                    }
                },
                onclose: () => resolve(null),
            });
        } catch (e) {
            onError?.(e?.message ?? 'Flutterwave could not start.');
            resolve(null);
        }
    });
}

function runPaypal(order, { onError }) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[60] flex items-center justify-center p-4';
        overlay.style.background = 'rgb(0 0 0 / 0.5)';
        overlay.innerHTML = `
            <div class="card w-full max-w-md p-5" style="box-shadow: var(--shadow-xl);">
                <div class="mb-4 flex items-center justify-between">
                    <p class="text-base font-bold">${t('Pay with PayPal')}</p>
                    <button type="button" data-pp-close
                            class="grid h-8 w-8 place-items-center rounded-lg faint hover:muted-surface">&times;</button>
                </div>
                <p class="faint mb-3 text-sm">${t('Total')}: ${order.currency} ${order.amount}</p>
                <div data-pp-buttons></div>
            </div>`;
        document.body.appendChild(overlay);

        const close = (value) => {
            overlay.remove();
            resolve(value);
        };

        overlay.querySelector('[data-pp-close]').addEventListener('click', () => close(null));

        window.paypal.Buttons({
            // The order was created server-side for the server's amount; the
            // buttons only collect approval for that exact order.
            createOrder: () => order.order_id,
            onApprove: () => close({ order_id: order.order_id }),
            onCancel: () => close(null),
            onError: (err) => {
                onError?.(err?.message ?? 'PayPal could not process that payment.');
                close(null);
            },
        }).render(overlay.querySelector('[data-pp-buttons]'));
    });
}

/* ------------------------------------------------------ redirect gateways */

/**
 * Open the payment popup NOW, inside the click/submit handler — popup
 * blockers refuse a window.open that happens after an await. Call this
 * before starting the charge when the picked method's flow is 'redirect'.
 */
export function openPayPopup() {
    const popup = window.open('', 'cabme_pay', 'width=480,height=760');

    try {
        popup?.document.write('<p style="font-family:system-ui;padding:2rem;text-align:center">Connecting to the payment provider…</p>');
    } catch { /* cross-origin after a previous run — fine */ }

    return popup;
}

/**
 * Hosted-page checkout in the popup. Resolves with the return-page query
 * (relayed via postMessage from /app/pay/return) or null when the rider
 * closes the popup without finishing.
 */
function runRedirect(order, { popup, onError }) {
    if (!popup || popup.closed) {
        onError?.(t('Please allow pop-ups to use this payment method.'));
        return Promise.resolve(null);
    }

    if (order.flow === 'form' && order.fields) {
        // PayFast-style: POST a form into the popup.
        const doc = popup.document;
        const form = doc.createElement('form');
        form.method = 'POST';
        form.action = order.url;
        Object.entries(order.fields).forEach(([name, value]) => {
            const input = doc.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        doc.body.appendChild(form);
        form.submit();
    } else {
        popup.location = order.url;
    }

    return new Promise((resolve) => {
        let settled = false;

        const finish = (value) => {
            if (settled) return;
            settled = true;
            window.removeEventListener('message', onMessage);
            clearInterval(watcher);
            try { if (!popup.closed) popup.close(); } catch { /* already gone */ }
            resolve(value);
        };

        const onMessage = (event) => {
            if (event.origin !== window.location.origin || !event.data?.cabmePayment) return;
            finish(event.data.params ?? {});
        };

        window.addEventListener('message', onMessage);

        const watcher = setInterval(() => {
            if (popup.closed) {
                // Give a just-posted message a moment to arrive first.
                setTimeout(() => finish(null), 800);
                clearInterval(watcher);
            }
        }, 500);
    });
}

/* -------------------------------------------------------------- Foloosi */

function runFoloosi(order, { onError }) {
    return new Promise((resolve) => {
        loadScript('https://www.foloosi.com/js/foloosipay.v2.js', () => !!window.Foloosipay).then((loaded) => {
            if (!loaded) { onError?.(LOAD_FAILED()); return resolve(null); }

            let settled = false;
            const finish = (value) => {
                if (settled) return;
                settled = true;
                window.removeEventListener('message', onMessage);
                resolve(value);
            };

            // The widget reports through postMessage. Its word alone credits
            // nothing — the server re-reads the transaction from Foloosi.
            const onMessage = (event) => {
                const status = event?.data?.status;
                if (status === 'success') {
                    const txn = event.data?.data?.transaction_no ?? event.data?.transaction_no ?? '';
                    finish(txn ? { transaction_no: String(txn) } : null);
                } else if (status === 'error' || status === 'failed' || status === 'closed') {
                    if (status !== 'closed') onError?.(t('That payment did not go through.'));
                    finish(null);
                }
            };

            window.addEventListener('message', onMessage);

            try {
                new window.Foloosipay({
                    reference_token: order.reference_token,
                    merchant_key: order.merchant_key,
                }).open();
            } catch (e) {
                onError?.(e?.message ?? t('Could not start the payment.'));
                finish(null);
            }
        });
    });
}

/* ------------------------------------------------------------ MTN MoMo */

let waitOverlay = null;

function showWaitOverlay(message) {
    disposeWaitOverlay();
    waitOverlay = document.createElement('div');
    waitOverlay.className = 'fixed inset-0 z-[60] flex items-center justify-center p-4';
    waitOverlay.style.background = 'rgb(0 0 0 / 0.5)';
    waitOverlay.innerHTML = `
        <div class="card w-full max-w-sm p-6 text-center" style="box-shadow: var(--shadow-xl);">
            <p class="animate-pulse text-base font-bold">${message}</p>
        </div>`;
    document.body.appendChild(waitOverlay);
}

function disposeWaitOverlay() {
    waitOverlay?.remove();
    waitOverlay = null;
}

/**
 * POST the verify body, tolerating asynchronous settlement: a 202 means the
 * gateway has not settled yet — poll until it does (or ~3 minutes pass).
 * Resolves with the verify response data; throws the axios error otherwise.
 */
export async function verifyWithRetry(url, payload) {
    try {
        for (let attempt = 0; attempt < 45; attempt++) {
            const response = await window.axios.post(url, payload);
            if (response.status !== 202) return response.data;

            // Waiting on an asynchronous confirmation (ITN, MoMo approval) —
            // say so instead of leaving a silent spinner. MoMo's own overlay
            // stays; this only fills the gap for the others.
            if (!waitOverlay) showWaitOverlay(t('Waiting for payment confirmation…'));

            await new Promise((r) => setTimeout(r, 4000));
        }
        const timeout = new Error('timeout');
        timeout.response = { data: { message: t('That payment has not completed.') } };
        throw timeout;
    } finally {
        disposeWaitOverlay();
    }
}

/**
 * @param {object} order   client payload from the server's order endpoint
 * @param {{onError?: (message: string) => void, description?: string, popup?: Window|null}} options
 * @returns {Promise<object|null>} body for the verify endpoint, or null
 */
export async function runGatewayCheckout(order, options = {}) {
    const { onError } = options;

    // Hosted-page gateways share one popup flow, whatever the brand.
    if (order.flow === 'redirect' || order.flow === 'form') {
        return runRedirect(order, options);
    }

    if (order.gateway === 'mtnmomo') {
        options.popup?.close?.();
        showWaitOverlay(t('Confirm the payment on your phone.'));
        return {};    // settlement is asynchronous — verifyWithRetry polls it
    }

    if (order.gateway === 'foloosi') {
        options.popup?.close?.();
        return runFoloosi(order, options);
    }

    // In-page gateways don't need the pre-opened popup.
    options.popup?.close?.();

    switch (order.gateway) {
        case 'stripe': {
            const intentId = await runStripeCheckout(order, onError);
            return intentId ? { payment_intent_id: intentId } : null;
        }

        case 'razorpay': {
            const loaded = await loadScript('https://checkout.razorpay.com/v1/checkout.js', () => !!window.Razorpay);
            if (!loaded) { onError?.(LOAD_FAILED()); return null; }
            return runRazorpay(order, options);
        }

        case 'paystack': {
            const loaded = await loadScript('https://js.paystack.co/v1/inline.js', () => !!window.PaystackPop);
            if (!loaded) { onError?.(LOAD_FAILED()); return null; }
            return runPaystack(order, options);
        }

        case 'flutterwave': {
            const loaded = await loadScript('https://checkout.flutterwave.com/v3.js', () => !!window.FlutterwaveCheckout);
            if (!loaded) { onError?.(LOAD_FAILED()); return null; }
            return runFlutterwave(order, options);
        }

        case 'paypal': {
            const src = 'https://www.paypal.com/sdk/js?client-id='
                + encodeURIComponent(order.client_id)
                + '&currency=' + encodeURIComponent(order.currency)
                + '&intent=capture';
            const loaded = await loadScript(src, () => !!window.paypal?.Buttons);
            if (!loaded) { onError?.(LOAD_FAILED()); return null; }
            return runPaypal(order, options);
        }

        default:
            onError?.(t('That payment method is not supported in the browser yet.'));
            return null;
    }
}
