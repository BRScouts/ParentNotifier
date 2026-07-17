<footer class="site-footer enhanced-footer">
    <div class="container">

        <div class="footer-main">
            <div class="footer-brand-block">
               

                <div>
                    <h2 class="footer-title">
                        <?= e(APP_NAME) ?>
                    </h2>

                    <p class="footer-subtitle">
                        Explorer Belt 2026 parent updates portal
                    </p>
                </div>
            </div>

            <div class="footer-message">
                <strong>Our Privacy Notice</strong>
                <span>
                    How we handle your personal information and data during the event.
                    <a href="<?= e(url('privacy.php')) ?>">Read more</a>
                </span>
            </div>
        </div>

        <div class="footer-lower">
            <div class="footer-meta">
                <span>
                    &copy; <?= e(date('Y')) ?> Explorer Belt 2026
                </span>

                <span class="footer-dot" aria-hidden="true">•</span>

            </div>

            <div class="footer-credit">
                <span>Developed & Provided by</span>
                <a href="https://ckenterprises.co.uk" target="_blank" rel="noopener">
                    CK Enterprises UK
                </a>
            </div>
        </div>

    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {});
        });
    }
</script>
</body>
</html>