<?php require_once __DIR__ . '/auth.php'; include __DIR__ . '/header.php'; ?>
<main id="main-content" class="container my-5">
    <div class="panel panel-grey">
        <h1>403 - Access not available</h1>
        <p>This portal is only available to leaders who are signed in or parents using a valid team link.</p>
        <p><a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Leader login</a></p>
    </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
