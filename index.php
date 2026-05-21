<?php
require_once __DIR__ . '/auth.php';
if (is_logged_in()) {
    redirect('dashboard.php');
}
$team = parent_access_team();
if ($team) {
    redirect('team.php?token=' . $team['parent_token']);
}
redirect('403.php');
