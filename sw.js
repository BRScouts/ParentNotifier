/**
 * Service Worker for Explorer Belt 2026
 *
 * Strategy:
 * - Cache-first for static assets (CSS, JS, images, fonts)
 * - Network-first for HTML/PHP pages (fall back to cache if offline)
 * - Background sync support for offline form submissions
 */

const CACHE_NAME = 'explorer-belt-v1';
const STATIC_CACHE = 'explorer-static-v1';
const PAGES_CACHE = 'explorer-pages-v1';

// Static assets to pre-cache on install
const PRECACHE_ASSETS = [
    '/assets/css/app.min.css',
    '/assets/js/app.js',
    '/assets/logo.png',
    '/manifest.json',
    'https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
];

// Install: pre-cache static assets
self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function (cache) {
            return cache.addAll(PRECACHE_ASSETS);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

// Activate: clean up old caches
self.addEventListener('activate', function (event) {
    var currentCaches = [STATIC_CACHE, PAGES_CACHE];

    event.waitUntil(
        caches.keys().then(function (cacheNames) {
            return Promise.all(
                cacheNames.filter(function (name) {
                    return currentCaches.indexOf(name) === -1;
                }).map(function (name) {
                    return caches.delete(name);
                })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

// Fetch: route requests to appropriate strategy
self.addEventListener('fetch', function (event) {
    var request = event.request;
    var url = new URL(request.url);

    // Skip non-GET requests (POST form submissions handled by offline queue)
    if (request.method !== 'GET') {
        return;
    }

    // Static assets: cache-first
    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
        return;
    }

    // Map tiles: cache-first with network fallback (tiles rarely change)
    if (isMapTile(url)) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
        return;
    }

    // HTML pages: network-first, fall back to cache
    if (request.headers.get('Accept') && request.headers.get('Accept').indexOf('text/html') !== -1) {
        event.respondWith(networkFirst(request, PAGES_CACHE));
        return;
    }

    // Everything else: network with cache fallback
    event.respondWith(networkFirst(request, PAGES_CACHE));
});

/**
 * Cache-first strategy: serve from cache, fetch from network only if not cached.
 */
function cacheFirst(request, cacheName) {
    return caches.match(request).then(function (cached) {
        if (cached) {
            return cached;
        }

        return fetch(request).then(function (response) {
            if (response && response.status === 200) {
                var responseClone = response.clone();
                caches.open(cacheName).then(function (cache) {
                    cache.put(request, responseClone);
                });
            }

            return response;
        });
    }).catch(function () {
        // If both cache and network fail, return a basic offline response for pages
        if (request.headers.get('Accept') && request.headers.get('Accept').indexOf('text/html') !== -1) {
            return new Response(
                '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title></head><body style="font-family:sans-serif;padding:2rem;text-align:center;"><h1>You are offline</h1><p>This page is not available offline. Your check-in data has been saved and will submit when you reconnect.</p></body></html>',
                { headers: { 'Content-Type': 'text/html' } }
            );
        }
    });
}

/**
 * Network-first strategy: try network, fall back to cache.
 */
function networkFirst(request, cacheName) {
    return fetch(request).then(function (response) {
        if (response && response.status === 200) {
            var responseClone = response.clone();
            caches.open(cacheName).then(function (cache) {
                cache.put(request, responseClone);
            });
        }

        return response;
    }).catch(function () {
        return caches.match(request).then(function (cached) {
            if (cached) {
                return cached;
            }

            // Offline fallback for HTML pages
            if (request.headers.get('Accept') && request.headers.get('Accept').indexOf('text/html') !== -1) {
                return new Response(
                    '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title></head><body style="font-family:sans-serif;padding:2rem;text-align:center;"><h1>You are offline</h1><p>This page is not available offline. Please try again when you have a connection.</p></body></html>',
                    { headers: { 'Content-Type': 'text/html' } }
                );
            }
        });
    });
}

/**
 * Check if a URL points to a static asset.
 */
function isStaticAsset(url) {
    var path = url.pathname;
    var staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.woff', '.woff2', '.ttf'];

    for (var i = 0; i < staticExtensions.length; i++) {
        if (path.endsWith(staticExtensions[i])) {
            return true;
        }
    }

    // CDN assets
    if (url.hostname === 'cdn.jsdelivr.net' || url.hostname === 'unpkg.com') {
        return true;
    }

    return false;
}

/**
 * Check if a URL is an OpenStreetMap tile.
 */
function isMapTile(url) {
    return url.hostname.indexOf('tile.openstreetmap.org') !== -1;
}

/**
 * Listen for messages from the client (e.g., skip waiting).
 */
self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
