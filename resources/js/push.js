import Alpine from 'alpinejs';
import { t } from './i18n';

/**
 * Web push via Firebase Cloud Messaging.
 *
 * The page provides window.__push (see partials/push.blade.php) with the
 * Firebase web config and the subscribe endpoint. The token this produces is
 * stored as the rider's fcm_id in the admin, so the admin's existing ride
 * notifications land in this browser.
 *
 * States: 'unsupported' | 'off' (config incomplete) | 'idle' (can ask) |
 * 'enabling' | 'ready' (subscribed) | 'denied' | 'error' (permission is
 * granted but the token could not be fetched — nothing to ask the rider).
 *
 * Only 'idle' offers the enable button, so it is shown exactly when the
 * browser would really prompt.
 */

const loadedScripts = new Map();

function loadScript(src) {
    if (!loadedScripts.has(src)) {
        loadedScripts.set(src, new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = () => resolve(true);
            script.onerror = () => {
                loadedScripts.delete(src);
                resolve(false);
            };
            document.head.appendChild(script);
        }));
    }

    return loadedScripts.get(src);
}

async function firebaseMessaging(config) {
    const base = 'https://www.gstatic.com/firebasejs/10.13.2';
    if (!(await loadScript(`${base}/firebase-app-compat.js`))) return null;
    if (!(await loadScript(`${base}/firebase-messaging-compat.js`))) return null;

    if (!window.firebase?.apps?.length) {
        window.firebase.initializeApp({
            apiKey: config.apiKey,
            projectId: config.projectId,
            authDomain: config.authDomain ?? `${config.projectId}.firebaseapp.com`,
            messagingSenderId: config.senderId,
            appId: config.appId,
        });
    }

    return window.firebase.messaging();
}

Alpine.store('push', {
    state: 'off',
    busy: false,

    config() {
        return window.__push ?? null;
    },

    usable() {
        const c = this.config();
        return !!(c && c.apiKey && c.appId && c.vapidKey && c.projectId && c.senderId
            && 'serviceWorker' in navigator && 'Notification' in window);
    },

    init() {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            this.state = 'unsupported';
            return;
        }
        if (!this.usable()) {
            this.state = 'off';
            return;
        }

        if (Notification.permission === 'denied') {
            this.state = 'denied';
            return;
        }

        // Already granted: refresh the token quietly so the admin always has
        // a live one for this browser. Stay in 'off' meanwhile — flipping
        // through 'idle' made the enable button flash on every page load.
        if (Notification.permission === 'granted') {
            this.subscribe(true);
            return;
        }

        this.state = 'idle';
    },

    async enable() {
        if (this.busy || !this.usable()) return;
        this.busy = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                this.state = permission === 'denied' ? 'denied' : 'idle';
                return;
            }
            await this.subscribe(false);
        } finally {
            this.busy = false;
        }
    },

    async subscribe(silent) {
        const config = this.config();
        this.state = 'enabling';

        // FCM's registration call can hang for minutes on a flaky push-service
        // connection; give it a hard deadline so the store never gets stuck.
        const deadline = (promise, ms) => Promise.race([
            promise,
            new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), ms)),
        ]);

        try {
            const messaging = await firebaseMessaging(config);
            if (!messaging) throw new Error('load');

            const query = new URLSearchParams({
                apiKey: config.apiKey,
                projectId: config.projectId,
                senderId: config.senderId,
                appId: config.appId,
            });
            const registration = await deadline(navigator.serviceWorker.register(`/firebase-messaging-sw.js?${query}`), 10000);
            await deadline(navigator.serviceWorker.ready, 10000);

            const token = await deadline(messaging.getToken({
                vapidKey: config.vapidKey,
                serviceWorkerRegistration: registration,
            }), 25000);
            if (!token) throw new Error('token');

            // `silent` also tells the server this is a routine token refresh,
            // so it must NOT send the "notifications enabled" hello again.
            await window.axios.post(config.subscribeUrl, { token, silent: !!silent });

            // Foreground messages have no system banner — surface as a toast.
            // Bind ONCE: a second subscribe (page revisit, manual enable after
            // the silent refresh) must not double every toast.
            if (!window.__cabmePushBound) {
                window.__cabmePushBound = true;
                messaging.onMessage((payload) => {
                    const title = payload.notification?.title ?? payload.data?.title;
                    const body = payload.notification?.body ?? payload.data?.body ?? '';
                    if (title) Alpine.store('toasts').push(`${title}${body ? ' — ' + body : ''}`, 'info');
                });
            }

            this.state = 'ready';
            if (!silent) Alpine.store('toasts').success(t('Ride alerts enabled.'));
        } catch (e) {
            // Keep silent failures diagnosable from the browser console.
            console.warn('[cabme push] subscribe failed:', e?.code ?? '', e?.message ?? e);

            // Only go back to askable if the browser would actually ask. Once
            // permission is granted the rider has done their part, and a
            // failed token refresh must not put 'Enable ride alerts' back on
            // the page — it reappeared on every reload and looked broken.
            this.state = Notification.permission === 'granted' ? 'error' : 'idle';

            if (!silent) {
                Alpine.store('toasts').error(e.response?.data?.message ?? t('Could not enable notifications.'));
            }
        }
    },
});

document.addEventListener('alpine:init', () => Alpine.store('push').init());
