<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

redirect(microsoft_authorize_url());