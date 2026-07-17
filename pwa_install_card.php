<?php
/**
 * PWA Install Card
 *
 * A reusable component that shows users how to add the app to their home screen.
 * Detects platform (iOS vs Android/Chrome) and shows appropriate instructions.
 * On supported browsers, also shows a native "Install" button via the beforeinstallprompt event.
 *
 * Include this file where you want the card to appear:
 *   include __DIR__ . '/pwa_install_card.php';
 *
 * The card auto-hides if the app is already running in standalone mode (installed).
 */
?>
<div id="pwaInstallCard" style="display:none;">
    <div style="border:2px solid #7413dc;background:#faf5ff;padding:1.25rem;margin-bottom:1.5rem;border-radius:0;">
        <div style="display:flex;align-items:flex-start;gap:0.75rem;">
            <div style="font-size:1.75rem;line-height:1;" aria-hidden="true">📱</div>
            <div style="flex:1;">
                <h3 style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:900;">Add to your home screen</h3>
                <p style="margin:0 0 0.75rem;color:#505a5f;font-size:0.95rem;">
                    Install this app for faster access, even when signal is weak. It works like a normal app on your phone.
                </p>

                <!-- Native install button (Chrome/Edge/Android) -->
                <button
                    id="pwaInstallBtn"
                    type="button"
                    class="btn btn-primary"
                    style="display:none;margin-bottom:0.75rem;"
                >
                    Install app
                </button>

                <!-- iOS instructions -->
                <div id="pwaIosInstructions" style="display:none;">
                    <p style="margin:0 0 0.5rem;font-weight:700;">On iPhone or iPad:</p>
                    <ol style="margin:0;padding-left:1.25rem;font-size:0.95rem;line-height:1.7;">
                        <li>Tap the <strong>Share</strong> button <span aria-hidden="true" style="font-size:1.1rem;">&#xFEFF;⎙</span> (the square with an arrow) at the bottom of Safari</li>
                        <li>Scroll down and tap <strong>"Add to Home Screen"</strong></li>
                        <li>Tap <strong>"Add"</strong> in the top right</li>
                    </ol>
                </div>

                <!-- Android/other instructions (shown when native prompt isn't available) -->
                <div id="pwaAndroidInstructions" style="display:none;">
                    <p style="margin:0 0 0.5rem;font-weight:700;">On Android:</p>
                    <ol style="margin:0;padding-left:1.25rem;font-size:0.95rem;line-height:1.7;">
                        <li>Tap the <strong>menu</strong> (three dots) in the top right of Chrome</li>
                        <li>Tap <strong>"Add to Home screen"</strong> or <strong>"Install app"</strong></li>
                        <li>Tap <strong>"Add"</strong> to confirm</li>
                    </ol>
                </div>

                <button
                    id="pwaInstallDismiss"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    style="margin-top:0.75rem;"
                >
                    Got it, don't show again
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var card = document.getElementById('pwaInstallCard');
    var installBtn = document.getElementById('pwaInstallBtn');
    var iosInstructions = document.getElementById('pwaIosInstructions');
    var androidInstructions = document.getElementById('pwaAndroidInstructions');
    var dismissBtn = document.getElementById('pwaInstallDismiss');

    if (!card) return;

    // Don't show if already installed (standalone mode)
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        return;
    }

    // Don't show if previously dismissed
    try {
        if (localStorage.getItem('pwa_install_dismissed') === '1') {
            return;
        }
    } catch (e) {}

    // Detect platform
    var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var isAndroid = /android/i.test(navigator.userAgent);
    var deferredPrompt = null;

    // Listen for the native install prompt (Chrome, Edge, Samsung Internet)
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;

        // Show native install button, hide manual instructions
        installBtn.style.display = 'inline-block';
        if (androidInstructions) androidInstructions.style.display = 'none';
    });

    // Show appropriate instructions
    if (isIos) {
        iosInstructions.style.display = 'block';
        card.style.display = 'block';
    } else if (isAndroid) {
        androidInstructions.style.display = 'block';
        card.style.display = 'block';
    } else {
        // Desktop or unknown - still show if install prompt fires
        androidInstructions.style.display = 'block';
        card.style.display = 'block';
    }

    // Handle native install button click
    if (installBtn) {
        installBtn.addEventListener('click', function () {
            if (!deferredPrompt) return;

            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choiceResult) {
                if (choiceResult.outcome === 'accepted') {
                    card.style.display = 'none';
                    try { localStorage.setItem('pwa_install_dismissed', '1'); } catch (e) {}
                }
                deferredPrompt = null;
            });
        });
    }

    // Handle dismiss
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function () {
            card.style.display = 'none';
            try { localStorage.setItem('pwa_install_dismissed', '1'); } catch (e) {}
        });
    }

    // Hide card if app gets installed
    window.addEventListener('appinstalled', function () {
        card.style.display = 'none';
        try { localStorage.setItem('pwa_install_dismissed', '1'); } catch (e) {}
    });
})();
</script>
