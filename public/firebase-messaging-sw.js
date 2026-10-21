/**
 * Firebase Cloud Messaging service worker.
 *
 * The page registers this with the Firebase web config in the query string
 * (all public-safe values), so nothing here needs a rebuild when the admin
 * or env values change.
 */
/* global firebase, importScripts, clients */

importScripts('https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js');

const params = new URLSearchParams(self.location.search);

firebase.initializeApp({
    apiKey: params.get('apiKey'),
    projectId: params.get('projectId'),
    messagingSenderId: params.get('senderId'),
    appId: params.get('appId'),
});

const messaging = firebase.messaging();

// The admin backend sends both notification and data payloads; show whatever
// arrives while no tab is focused.
messaging.onBackgroundMessage((payload) => {
    const title = payload.notification?.title ?? payload.data?.title ?? 'CabMe';
    const body = payload.notification?.body ?? payload.data?.body ?? '';

    self.registration.showNotification(title, {
        body,
        icon: '/favicon.ico',
        data: { url: '/app' },
    });
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/app';

    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((tabs) => {
        const open = tabs.find((tab) => tab.url.includes('/app'));
        return open ? open.focus() : clients.openWindow(url);
    }));
});
