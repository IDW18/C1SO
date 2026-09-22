<?php

date_default_timezone_set('Asia/Manila');

$DB_HOST = 'localhost';
$DB_NAME = 'c1so_tech';
$DB_USER = 'root';
$DB_PASS = '';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    die(
        '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;' .
        'border:1px solid #C4675A;border-radius:8px;background:#fff5f4;color:#5b2b26;">' .
        '<h2 style="margin-top:0;">Database connection failed</h2>' .
        '<p>Could not connect to MySQL / the <code>c1so_tech</code> database.</p>' .
        '<ol style="line-height:1.7;">' .
        '<li>Make sure <b>Apache</b> and <b>MySQL</b> are both running (green) in the XAMPP Control Panel.</li>' .
        '<li>Open <b>http://localhost/phpmyadmin</b>, click <b>Import</b>, choose <code>database.sql</code>, then click <b>Go</b>. This creates the <code>c1so_tech</code> database.</li>' .
        '<li>If your MySQL root user has a password, or uses a different username, edit <code>includes/db.php</code> and update <code>$DB_USER</code> / <code>$DB_PASS</code>.</li>' .
        '</ol>' .
        '<p style="color:#8a5a54;font-size:13px;">Technical detail: ' . htmlspecialchars($conn->connect_error ?? 'unknown error') . '</p>' .
        '</div>'
    );
}

$conn->set_charset('utf8mb4');
