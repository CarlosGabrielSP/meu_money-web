/*
 * Minimal service worker for PWA installation (RNF-003).
 * No fetch handler and no cache: the app does not work offline.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});
