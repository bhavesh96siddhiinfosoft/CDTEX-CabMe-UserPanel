import Alpine from 'alpinejs';
import { t } from '../i18n';

const fail = (message) => Alpine.store('toasts').error(message);

/**
 * Phone-OTP and Google sign-in on the auth pages.
 *
 * Firebase Authentication runs here in the browser (SMS code / Google
 * popup); the resulting ID token goes to /auth/firebase where it is
 * VERIFIED server-side before any admin call — nothing this component
 * claims is trusted by itself.
 *
 * Firebase objects (auth instance, confirmation result, reCAPTCHA verifier)
 * live in module scope, NOT in Alpine state: Alpine's reactive Proxy breaks
 * their private internals, the same trap intl-tel-input had.
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

let firebaseAuth = null;
let confirmation = null;
let recaptcha = null;

async function auth() {
    if (firebaseAuth) return firebaseAuth;

    const config = window.__firebase;
    if (!config) return null;

    const base = 'https://www.gstatic.com/firebasejs/10.13.2';
    if (!(await loadScript(`${base}/firebase-app-compat.js`))) return null;
    if (!(await loadScript(`${base}/firebase-auth-compat.js`))) return null;

    if (!window.firebase.apps.length) {
        window.firebase.initializeApp({
            apiKey: config.apiKey,
            projectId: config.projectId,
            authDomain: config.authDomain ?? `${config.projectId}.firebaseapp.com`,
            messagingSenderId: config.senderId,
            appId: config.appId,
        });
    }

    firebaseAuth = window.firebase.auth();

    return firebaseAuth;
}

Alpine.data('firebaseLogin', (config = {}) => ({
    available: !!window.__firebase,
    // The register page starts on phone verification — the app's signup
    // order: verify the number first, personal details after.
    tab: config.tab ?? 'email',
    busy: false,

    otpSent: false,
    code: '',
    resendIn: 0,
    resendTimer: null,

    // Registration completion for first-time riders.
    registerMode: null,          // 'phone' | 'google'
    idToken: null,
    verifiedPhone: null,         // the split the OTP actually verified
    form: { firstname: '', lastname: '', email: '', password: '', password_confirmation: '', referral_code: '' },

    /** Two phone-fields exist on the page (OTP + Google completion) — scope. */
    phoneParts(container = '[data-phone-login]') {
        const scope = this.$root.querySelector(container) ?? this.$root;
        return {
            phone: scope.querySelector('input[name="phone"]')?.value ?? '',
            country_code: scope.querySelector('input[name="country_code"]')?.value ?? '',
        };
    },

    async sendCode() {
        const { phone, country_code } = this.phoneParts();

        if (!phone) {
            fail(t('Enter your mobile number first.'));
            return;
        }

        this.busy = true;
        try {
            const instance = await auth();
            if (!instance) throw new Error('unavailable');

            if (!recaptcha) {
                // A dedicated zero-height anchor: the send button hides on the
                // code screen, and reCAPTCHA must not live on a hidden node.
                recaptcha = new window.firebase.auth.RecaptchaVerifier('recaptcha-anchor', { size: 'invisible' });
            }

            confirmation = await instance.signInWithPhoneNumber(`${country_code}${phone}`, recaptcha);
            this.otpSent = true;
            this.code = '';
            this.startResendCountdown();
        } catch (e) {
            recaptcha?.clear?.();
            recaptcha = null;
            fail(this.firebaseMessage(e));
        } finally {
            this.busy = false;
        }
    },

    /** A failed attempt invalidates the whole OTP session — resending is the
     *  recovery path, so it gets a real button (rate-limited client-side). */
    startResendCountdown() {
        clearInterval(this.resendTimer);
        this.resendIn = 30;
        this.resendTimer = setInterval(() => {
            if (--this.resendIn <= 0) clearInterval(this.resendTimer);
        }, 1000);
    },

    async resend() {
        if (this.resendIn > 0 || this.busy) return;
        confirmation = null;
        await this.sendCode();    // the phone inputs keep their value
    },

    async verifyCode() {
        if (!confirmation || this.code.trim().length < 4) return;

        this.busy = true;
        try {
            const result = await confirmation.confirm(this.code.trim());
            const idToken = await result.user.getIdToken();
            await this.exchange(idToken, this.phoneParts());
        } catch (e) {
            fail(this.firebaseMessage(e));

            // A wrong or expired code kills the whole verification session on
            // Firebase's side — even the right code fails after that. Free
            // the resend button so the rider can recover immediately.
            if (e?.code === 'auth/code-expired' || e?.code === 'auth/invalid-verification-code') {
                clearInterval(this.resendTimer);
                this.resendIn = 0;
            }
        } finally {
            this.busy = false;
        }
    },

    async google() {
        this.busy = true;
        try {
            const instance = await auth();
            if (!instance) throw new Error('unavailable');

            const provider = new window.firebase.auth.GoogleAuthProvider();
            const result = await instance.signInWithPopup(provider);
            const idToken = await result.user.getIdToken();
            await this.exchange(idToken, {});
        } catch (e) {
            if (e?.code !== 'auth/popup-closed-by-user' && e?.code !== 'auth/cancelled-popup-request') {
                fail(this.firebaseMessage(e));
            }
        } finally {
            this.busy = false;
        }
    },

    /** Hand the verified identity to the panel; sign in or start registration. */
    async exchange(idToken, phoneParts) {
        try {
            const { data } = await window.axios.post('/auth/firebase', { id_token: idToken, ...phoneParts });

            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            if (data.register) {
                this.idToken = idToken;
                this.registerMode = data.mode;
                // The number the OTP verified must travel to registration too.
                this.verifiedPhone = phoneParts.phone ? { ...phoneParts } : null;
                this.form = {
                    firstname: data.prefill?.firstname ?? '',
                    lastname: data.prefill?.lastname ?? '',
                    email: data.prefill?.email ?? '',
                    password: '',
                    password_confirmation: '',
                    referral_code: '',
                };
            }
        } catch (e) {
            fail(e.response?.data?.message ?? t('Could not sign you in. Please try again.'));
        }
    },

    async completeRegistration() {
        // Instant feedback beats a round-trip to a validator.
        if (!this.form.firstname.trim() || !this.form.lastname.trim()) {
            fail(t('Enter your name.'));
            return;
        }
        if (this.registerMode === 'phone' && !this.form.email.trim()) {
            fail(t('Enter your email address.'));
            return;
        }
        if (this.registerMode === 'google' && !this.phoneParts('[data-phone-register]').phone) {
            fail(t('Enter your mobile number first.'));
            return;
        }
        // A chosen password means the account works with email + password
        // sign-in too — the app collects one at signup, so the web does.
        if (this.form.password.length < 6) {
            fail(t('Enter a password (at least 6 characters).'));
            return;
        }
        if (this.form.password !== this.form.password_confirmation) {
            fail(t('Passwords do not match.'));
            return;
        }

        this.busy = true;
        try {
            const body = { id_token: this.idToken, ...this.form };

            if (this.registerMode === 'google') {
                // The completion form renders a phone-field; read its inputs.
                Object.assign(body, this.phoneParts('[data-phone-register]'));
            } else if (this.verifiedPhone) {
                // Phone sign-up: the number the OTP verified, not form input.
                Object.assign(body, this.verifiedPhone);
            }

            const { data } = await window.axios.post('/auth/firebase/register', body);

            if (data.redirect) window.location.href = data.redirect;
        } catch (e) {
            fail(e.response?.data?.message ?? t('Could not create your account. Please try again.'));
        } finally {
            this.busy = false;
        }
    },

    firebaseMessage(e) {
        const known = {
            'auth/invalid-phone-number': t('That phone number does not look right.'),
            'auth/too-many-requests': t('Too many attempts — please wait a little and try again.'),
            'auth/invalid-verification-code': t('That code is not correct.'),
            'auth/code-expired': t('That code has expired. Send a new one.'),
        };

        return known[e?.code] ?? e?.message ?? t('Could not sign you in. Please try again.');
    },
}));
