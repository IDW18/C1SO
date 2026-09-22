<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (is_logged_in()) {
    log_activity($conn, 'Logged out', '', 'auth');
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
