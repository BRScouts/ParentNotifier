/**
 * Push Notification Subscription Manager
 * Handles service worker registration, permission request, and subscription management.
 *
 * Usage:
 *   PushManager.init({ vapidPublicKey: '...', subscribeEndpoint: '/push_subscribe.php' });
 *   PushManager.subscribe();
 *   PushManager.unsubscribe();
 */
var ExBeltPush = (function () {
    'use strict';

    var config = {
        vapidPublicKey: '',
        subscribeEndpoint: '/push_subscribe.php',
        swPath: '/sw.js',
    };

    var state = {
        registration: null,
        subscription: null,
        isSubscribed: false,
        isSupported: false,
    };

    /**
     * Convert a URL-safe base64 VAPID key to a Uint8Array for the subscribe call.
     */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);

        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    /**
     * Convert an ArrayBuffer to URL-safe base64 (no padding).
     * Required for sending p256dh and auth keys to the server.
     */
    function arrayBufferToBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';

        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }

        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /**
     * Check if push notifications are supported in this browser.
     */
    function isSupported() {
        return 'serviceWorker' in navigator &&
               'PushManager' in window &&
               'Notification' in window;
    }

    /**
     * Initialise: register the service worker and check existing subscription state.
     */
    function init(options) {
        if (options.vapidPublicKey) config.vapidPublicKey = options.vapidPublicKey;
        if (options.subscribeEndpoint) config.subscribeEndpoint = options.subscribeEndpoint;
        if (options.swPath) config.swPath = options.swPath;

        state.isSupported = isSupported();

        if (!state.isSupported) {
            updateUI('unsupported');
            return Promise.resolve(false);
        }

        return navigator.serviceWorker.register(config.swPath)
            .then(function (registration) {
                state.registration = registration;
                return registration.pushManager.getSubscription();
            })
            .then(function (subscription) {
                state.subscription = subscription;
                state.isSubscribed = subscription !== null;
                updateUI(state.isSubscribed ? 'subscribed' : 'unsubscribed');
                return state.isSubscribed;
            })
            .catch(function (error) {
                console.warn('[Push] Init failed:', error);
                updateUI('error');
                return false;
            });
    }

    /**
     * Subscribe the user to push notifications.
     */
    function subscribe() {
        if (!state.isSupported || !state.registration) {
            return Promise.reject(new Error('Push not supported or not initialised'));
        }

        var applicationServerKey = urlBase64ToUint8Array(config.vapidPublicKey);

        return state.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey,
        })
        .then(function (subscription) {
            state.subscription = subscription;
            state.isSubscribed = true;
            updateUI('subscribed');
            return sendSubscriptionToServer(subscription, 'subscribe');
        })
        .catch(function (error) {
            console.error('[Push] Subscribe failed:', error);

            if (Notification.permission === 'denied') {
                updateUI('denied');
            } else {
                updateUI('error');
            }

            return false;
        });
    }

    /**
     * Unsubscribe from push notifications.
     */
    function unsubscribe() {
        if (!state.subscription) {
            state.isSubscribed = false;
            updateUI('unsubscribed');
            return Promise.resolve(true);
        }

        var endpoint = state.subscription.endpoint;

        return state.subscription.unsubscribe()
            .then(function () {
                state.subscription = null;
                state.isSubscribed = false;
                updateUI('unsubscribed');
                return sendSubscriptionToServer({ endpoint: endpoint }, 'unsubscribe');
            })
            .catch(function (error) {
                console.error('[Push] Unsubscribe failed:', error);
                return false;
            });
    }

    /**
     * Send subscription data to our server.
     */
    function sendSubscriptionToServer(subscription, action) {
        var body = {
            action: action,
        };

        if (action === 'subscribe') {
            var key = subscription.getKey('p256dh');
            var auth = subscription.getKey('auth');

            body.endpoint = subscription.endpoint;
            body.p256dh_key = key ? arrayBufferToBase64Url(key) : '';
            body.auth_key = auth ? arrayBufferToBase64Url(auth) : '';
        } else {
            body.endpoint = subscription.endpoint || subscription;
        }

        return fetch(config.subscribeEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Server returned ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            return data.success || false;
        })
        .catch(function (error) {
            console.error('[Push] Server sync failed:', error);
            return false;
        });
    }

    /**
     * Update UI elements based on subscription state.
     * Looks for elements with data-push-role attributes.
     */
    function updateUI(status) {
        var btn = document.querySelector('[data-push-role="toggle"]');
        var statusEl = document.querySelector('[data-push-role="status"]');

        if (btn) {
            switch (status) {
                case 'subscribed':
                    btn.textContent = 'Disable Notifications';
                    btn.disabled = false;
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-outline');
                    break;
                case 'unsubscribed':
                    btn.textContent = 'Enable Notifications';
                    btn.disabled = false;
                    btn.classList.remove('btn-outline');
                    btn.classList.add('btn-primary');
                    break;
                case 'denied':
                    btn.textContent = 'Notifications Blocked';
                    btn.disabled = true;
                    break;
                case 'unsupported':
                    btn.textContent = 'Not Supported';
                    btn.disabled = true;
                    break;
                case 'error':
                    btn.textContent = 'Enable Notifications';
                    btn.disabled = false;
                    break;
            }
        }

        if (statusEl) {
            switch (status) {
                case 'subscribed':
                    statusEl.textContent = 'Push notifications are enabled. You will be notified when teams check in.';
                    statusEl.className = 'push-status push-status-active';
                    break;
                case 'unsubscribed':
                    statusEl.textContent = 'Push notifications are disabled. Enable them to get alerts when teams submit check-ins.';
                    statusEl.className = 'push-status push-status-inactive';
                    break;
                case 'denied':
                    statusEl.textContent = 'Notifications are blocked by your browser. Please allow notifications in your browser settings.';
                    statusEl.className = 'push-status push-status-denied';
                    break;
                case 'unsupported':
                    statusEl.textContent = 'Push notifications are not supported in this browser.';
                    statusEl.className = 'push-status push-status-unsupported';
                    break;
                case 'error':
                    statusEl.textContent = 'Something went wrong. Try again.';
                    statusEl.className = 'push-status push-status-error';
                    break;
            }
        }
    }

    /**
     * Toggle subscription on/off.
     */
    function toggle() {
        if (state.isSubscribed) {
            return unsubscribe();
        } else {
            return subscribe();
        }
    }

    /**
     * Get current state (for external checks).
     */
    function getState() {
        return {
            isSupported: state.isSupported,
            isSubscribed: state.isSubscribed,
            permission: 'Notification' in window ? Notification.permission : 'unsupported',
        };
    }

    return {
        init: init,
        subscribe: subscribe,
        unsubscribe: unsubscribe,
        toggle: toggle,
        getState: getState,
        isSupported: isSupported,
    };
})();
