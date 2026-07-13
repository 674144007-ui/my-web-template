// sw.js — DISABLED (Phase 7 debug)
// Service Worker ถูก disable ชั่วคราว
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.map(k => {
                console.log('[SW] Deleting cache:', k);
                return caches.delete(k);
            })))
            .then(() => self.clients.claim())
    );
});
// ไม่มี fetch handler = ทุก request ไป network โดยตรง 100%
