/**
 * Offline Form Queue
 *
 * Stores form submissions in IndexedDB when the device is offline,
 * then retries them automatically when connectivity returns.
 *
 * Usage:
 *   OfflineQueue.init();
 *   OfflineQueue.interceptForm(formElement, '/explorer_checkin.php');
 */
var OfflineQueue = (function () {
    'use strict';

    var DB_NAME = 'explorer_offline_queue';
    var DB_VERSION = 1;
    var STORE_NAME = 'pending_submissions';
    var db = null;

    /**
     * Open (or create) the IndexedDB database.
     */
    function openDB() {
        return new Promise(function (resolve, reject) {
            if (db) {
                resolve(db);
                return;
            }

            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function (event) {
                var database = event.target.result;

                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    var store = database.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                    store.createIndex('createdAt', 'createdAt', { unique: false });
                }
            };

            request.onsuccess = function (event) {
                db = event.target.result;
                resolve(db);
            };

            request.onerror = function () {
                reject(new Error('Failed to open offline queue database'));
            };
        });
    }

    /**
     * Save a form submission to the queue.
     */
    function saveSubmission(url, formData, metadata) {
        // Convert FormData to a plain object for storage
        var data = {};
        formData.forEach(function (value, key) {
            // Handle multiple values for same key (e.g. checkboxes)
            if (data[key] !== undefined) {
                if (!Array.isArray(data[key])) {
                    data[key] = [data[key]];
                }
                data[key].push(value);
            } else {
                data[key] = value;
            }
        });

        var submission = {
            url: url,
            data: data,
            metadata: metadata || {},
            createdAt: new Date().toISOString(),
            retryCount: 0,
            status: 'pending'
        };

        return openDB().then(function (database) {
            return new Promise(function (resolve, reject) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var request = store.add(submission);

                request.onsuccess = function () {
                    resolve(request.result);
                };

                request.onerror = function () {
                    reject(new Error('Failed to save submission to offline queue'));
                };
            });
        });
    }

    /**
     * Get all pending submissions.
     */
    function getPending() {
        return openDB().then(function (database) {
            return new Promise(function (resolve, reject) {
                var tx = database.transaction(STORE_NAME, 'readonly');
                var store = tx.objectStore(STORE_NAME);
                var request = store.getAll();

                request.onsuccess = function () {
                    var results = request.result.filter(function (item) {
                        return item.status === 'pending';
                    });
                    resolve(results);
                };

                request.onerror = function () {
                    reject(new Error('Failed to read offline queue'));
                };
            });
        });
    }

    /**
     * Remove a submission from the queue after successful send.
     */
    function removeSubmission(id) {
        return openDB().then(function (database) {
            return new Promise(function (resolve, reject) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var request = store.delete(id);

                request.onsuccess = function () {
                    resolve();
                };

                request.onerror = function () {
                    reject(new Error('Failed to remove submission from queue'));
                };
            });
        });
    }

    /**
     * Mark a submission as failed (increment retry count).
     */
    function markRetry(id) {
        return openDB().then(function (database) {
            return new Promise(function (resolve, reject) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                var store = tx.objectStore(STORE_NAME);
                var getReq = store.get(id);

                getReq.onsuccess = function () {
                    var item = getReq.result;

                    if (!item) {
                        resolve();
                        return;
                    }

                    item.retryCount++;
                    item.lastRetryAt = new Date().toISOString();

                    // Give up after 10 retries
                    if (item.retryCount >= 10) {
                        item.status = 'failed';
                    }

                    var putReq = store.put(item);
                    putReq.onsuccess = function () { resolve(); };
                    putReq.onerror = function () { reject(); };
                };

                getReq.onerror = function () {
                    reject();
                };
            });
        });
    }

    /**
     * Attempt to send a queued submission.
     */
    function sendSubmission(submission) {
        var formBody = new URLSearchParams();

        Object.keys(submission.data).forEach(function (key) {
            var value = submission.data[key];

            if (Array.isArray(value)) {
                value.forEach(function (v) {
                    formBody.append(key, v);
                });
            } else {
                formBody.append(key, value);
            }
        });

        return fetch(submission.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formBody.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.ok || response.status === 302 || response.status === 303) {
                return removeSubmission(submission.id);
            }

            // Server error - mark for retry
            return markRetry(submission.id);
        });
    }

    /**
     * Process all pending submissions (called when back online).
     */
    function processQueue() {
        return getPending().then(function (submissions) {
            if (submissions.length === 0) {
                return Promise.resolve(0);
            }

            var promises = submissions.map(function (submission) {
                return sendSubmission(submission).catch(function () {
                    return markRetry(submission.id);
                });
            });

            return Promise.all(promises).then(function () {
                return submissions.length;
            });
        });
    }

    /**
     * Check if we are currently offline.
     */
    function isOffline() {
        return !navigator.onLine;
    }

    /**
     * Show a status banner to the user.
     */
    function showBanner(message, type) {
        // Remove any existing banner
        var existing = document.getElementById('offline-queue-banner');
        if (existing) {
            existing.remove();
        }

        var banner = document.createElement('div');
        banner.id = 'offline-queue-banner';
        banner.setAttribute('role', 'alert');
        banner.setAttribute('aria-live', 'polite');

        var bgColor = type === 'success' ? '#00703c' : type === 'warning' ? '#f47738' : '#1d70b8';

        banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;padding:0.75rem 1rem;'
            + 'background:' + bgColor + ';color:#fff;font-weight:700;font-size:0.95rem;text-align:center;'
            + 'box-shadow:0 2px 8px rgba(0,0,0,0.15);';

        banner.textContent = message;

        document.body.insertBefore(banner, document.body.firstChild);

        // Auto-dismiss success banners after 6 seconds
        if (type === 'success') {
            setTimeout(function () {
                if (banner.parentNode) {
                    banner.remove();
                }
            }, 6000);
        }
    }

    /**
     * Dismiss the banner.
     */
    function dismissBanner() {
        var existing = document.getElementById('offline-queue-banner');
        if (existing) {
            existing.remove();
        }
    }

    /**
     * Intercept a form's submit event to support offline queuing.
     *
     * @param {HTMLFormElement} form - The form element to intercept
     * @param {string} actionUrl - The URL to submit to
     * @param {object} options - Optional config: { onQueued, onSent, successRedirect }
     */
    function interceptForm(form, actionUrl, options) {
        options = options || {};

        form.addEventListener('submit', function (event) {
            // Only intercept if offline
            if (!isOffline()) {
                return; // Let the normal form submit proceed
            }

            event.preventDefault();

            var formData = new FormData(form);
            var metadata = {
                page: window.location.pathname,
                timestamp: new Date().toISOString()
            };

            saveSubmission(actionUrl, formData, metadata).then(function () {
                showBanner(
                    'You are offline. Your check-in has been saved and will be submitted automatically when you reconnect.',
                    'info'
                );

                if (options.onQueued) {
                    options.onQueued();
                }
            }).catch(function () {
                showBanner(
                    'Could not save your check-in offline. Please try again.',
                    'warning'
                );
            });
        });
    }

    /**
     * Initialise the offline queue: listen for connectivity changes and process queue.
     */
    function init() {
        // Open the database early
        openDB();

        // When coming back online, process the queue
        window.addEventListener('online', function () {
            dismissBanner();

            processQueue().then(function (count) {
                if (count > 0) {
                    showBanner(
                        'Back online! ' + count + ' queued check-in' + (count > 1 ? 's' : '') + ' submitted successfully.',
                        'success'
                    );
                }
            });
        });

        // Show offline indicator
        window.addEventListener('offline', function () {
            showBanner(
                'You are offline. Don\'t worry — any check-ins you submit will be saved and sent when you reconnect.',
                'info'
            );
        });

        // If we're online at load, try to process any leftover queue items
        if (!isOffline()) {
            processQueue().then(function (count) {
                if (count > 0) {
                    showBanner(
                        count + ' queued check-in' + (count > 1 ? 's' : '') + ' submitted successfully.',
                        'success'
                    );
                }
            });
        } else {
            showBanner(
                'You are offline. Check-ins will be saved locally and submitted when you reconnect.',
                'info'
            );
        }
    }

    // Public API
    return {
        init: init,
        interceptForm: interceptForm,
        processQueue: processQueue,
        getPending: getPending,
        isOffline: isOffline,
        showBanner: showBanner,
        dismissBanner: dismissBanner
    };
})();
