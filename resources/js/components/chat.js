import Alpine from 'alpinejs';
import { t } from '../i18n';

/**
 * Rider ↔ driver chat, on the same Firestore thread the apps use.
 *
 * The apps store a conversation under `conversation/{id}` with the messages in
 * a `thread` subcollection, where the document id is the two participant ids
 * and the order id joined — the SMALLER id first, compared as STRINGS, which
 * is how Dart compares them. "9" sorts after "10" that way, so the comparison
 * has to stay a string one or the web would open a different thread than the
 * driver's app.
 */

let firestore = null;

/** Pull in a Firebase compat bundle once, resolving false if it will not load. */
function loadScript(src) {
    return new Promise((resolve) => {
        if (document.querySelector(`script[src="${src}"]`)) return resolve(true);

        const script = document.createElement('script');
        script.src = src;
        script.onload = () => resolve(true);
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}

async function db() {
    if (firestore) return firestore;

    const config = window.__firebase;
    if (!config) return null;

    // Nothing Firebase is loaded on a tracking page until a rider opens a
    // chat — and firestore-compat needs app-compat in place before it runs,
    // which is why it cannot simply be dropped in on its own.
    const base = 'https://www.gstatic.com/firebasejs/10.13.2';
    if (!(await loadScript(`${base}/firebase-app-compat.js`))) return null;
    if (!(await loadScript(`${base}/firebase-firestore-compat.js`))) return null;
    if (!window.firebase?.firestore) return null;

    if (!window.firebase.apps.length) {
        window.firebase.initializeApp({
            apiKey: config.apiKey,
            projectId: config.projectId,
            authDomain: config.authDomain ?? `${config.projectId}.firebaseapp.com`,
            messagingSenderId: config.senderId,
            appId: config.appId,
        });
    }

    firestore = window.firebase.firestore();

    return firestore;
}

Alpine.data('rideChat', (config = {}) => ({
    open: false,
    ready: false,
    failed: false,
    sending: false,
    draft: '',
    messages: [],
    unsubscribe: null,

    me: String(config.riderId ?? ''),
    driverId: String(config.driverId ?? ''),
    orderId: String(config.orderId ?? ''),
    driverName: config.driverName ?? '',

    /** The thread both sides agree on: smaller id, order id, larger id. */
    get threadId() {
        const [a, b] = this.me < this.driverId
            ? [this.me, this.driverId]
            : [this.driverId, this.me];

        return `${a}-${this.orderId}-${b}`;
    },

    /**
     * Which side of the thread a message belongs on.
     *
     * The addressee decides: a message addressed to someone other than me is
     * one I sent. Reading senderId alone is fragile — when the other client
     * writes that field in a shape this one does not match, every bubble ends
     * up on the same side, which is exactly what happened.
     */
    isMine(row) {
        const sender = String(row.senderId ?? row.sender_id ?? '');
        const receiver = String(row.receiverId ?? row.receiver_id ?? '');

        if (receiver !== '' && receiver !== sender) {
            return receiver !== this.me;
        }

        return sender === this.me;
    },

    async toggle() {
        this.open = !this.open;

        if (this.open && !this.unsubscribe) {
            await this.listen();
        }

        if (this.open) {
            this.$nextTick(() => this.scrollDown());
        }
    },

    async listen() {
        const store = await db();

        if (!store) {
            this.failed = true;
            return;
        }

        try {
            this.unsubscribe = store
                .collection('conversation')
                .doc(this.threadId)
                .collection('thread')
                .orderBy('created', 'asc')
                .onSnapshot(
                    (snapshot) => {
                        this.messages = snapshot.docs.map((doc) => {
                            const row = doc.data();

                            return {
                                id: doc.id,
                                // Who a message is ADDRESSED to decides the side.
                                // Going by senderId alone put every bubble on the
                                // right whenever the other client wrote that field
                                // in a shape this one did not recognise; the
                                // receiver is unambiguous — anything addressed to
                                // me came from the driver.
                                mine: this.isMine(row),
                                message: row.message ?? '',
                                // Firestore hands back a Timestamp; a message
                                // written a moment ago may not have one yet.
                                at: row.created?.toDate ? row.created.toDate() : null,
                            };
                        });

                        this.ready = true;
                        this.$nextTick(() => this.scrollDown());
                    },
                    () => { this.failed = true; },
                );
        } catch {
            this.failed = true;
        }
    },

    async send() {
        const message = this.draft.trim();

        if (!message || this.sending) return;

        const store = await db();

        if (!store) {
            this.failed = true;
            return;
        }

        this.sending = true;
        this.draft = '';

        try {
            const now = new Date();
            const id = (window.crypto?.randomUUID?.() ?? String(now.getTime()));
            const thread = store.collection('conversation').doc(this.threadId);

            // Same two writes the apps make: the inbox row that lists the
            // conversation, and the message itself.
            await thread.set({
                id: this.threadId,
                senderId: this.me,
                receiverId: this.driverId,
                message,
                orderId: this.orderId,
                created: now,
                senderName: config.riderName ?? '',
                senderPhoto: config.riderPhoto ?? '',
                receiverName: this.driverName,
                receiverPhoto: config.driverPhoto ?? '',
            }, { merge: true });

            await thread.collection('thread').doc(id).set({
                id,
                senderId: this.me,
                receiverId: this.driverId,
                message,
                orderId: this.orderId,
                created: now,
                videoThumbnail: '',
                url: { url: '', mime: '' },
                type: 'text',
            });

            // The driver may not have the app open; the panel pushes from the
            // server, where the driver's token can be looked up safely.
            window.axios.post(config.notifyUrl, {
                driver_id: this.driverId,
                order_id: this.orderId,
                message,
            }).catch(() => { /* the message is sent either way */ });
        } catch {
            this.draft = message;    // hand it back rather than losing it
            Alpine.store('toasts').error(t('Could not send that message.'));
        } finally {
            this.sending = false;
        }
    },

    scrollDown() {
        const box = this.$refs.thread;

        if (box) box.scrollTop = box.scrollHeight;
    },

    stamp(at) {
        if (!at) return '';

        return at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    },

    destroy() {
        this.unsubscribe?.();
        this.unsubscribe = null;
    },
}));
