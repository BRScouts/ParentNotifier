/**
 * Service Worker - Explorer Belt Live
 * Handles push notifications for leaders.
 */

const CACHE_VERSION = 'exbelt-v1';

// Install - activate immediately
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// Activate - claim clients immediately
self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Push notification received
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch (e) {
        payload = {
            title: 'Explorer Belt Live',
            body: event.data.text(),
            icon: '/assets/logo.webp',
            url: '/dashboard.php',
        };
    }

    const title = payload.title || 'Explorer Belt Live';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/assets/logo.webp',
        badge: '/assets/logo.webp',
        tag: payload.tag || 'exbelt-notification',
        renotify: true,
        requireInteraction: payload.requireInteraction || false,
        data: {
            url: payload.url || '/dashboard.php',
            timestamp: Date.now(),
        },
        actions: payload.actions || [],
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Notification click - open or focus the relevant page
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data?.url || '/dashboard.php';

    // Handle action buttons
    if (event.action === 'review') {
        event.waitUntil(openOrFocus(targetUrl));
        return;
    }

    if (event.action === 'dismiss') {
        return;
    }

    event.waitUntil(openOrFocus(targetUrl));
});

// Helper: open the URL or focus an existing tab
function openOrFocus(url) {
    const fullUrl = new URL(url, self.location.origin).href;

    return self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then((clients) => {
            // Try to find an existing tab with a matching URL
            for (const client of clients) {
                if (client.url === fullUrl && 'focus' in client) {
                    return client.focus();
                }
            }

            // Try to find any tab on our origin
            for (const client of clients) {
                if (client.url.startsWith(self.location.origin) && 'navigate' in client) {
                    return client.navigate(fullUrl).then((c) => c.focus());
                }
            }

            // Open a new window
            return self.clients.openWindow(fullUrl);
        });
}

// Notification close (optional analytics hook)
self.addEventListener('notificationclose', (event) => {
    // Could send analytics here in the future
});
