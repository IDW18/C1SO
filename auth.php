<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user() {
    if (!isset($_SESSION['user_id'])) return null;
    return [
        'id'        => (int)$_SESSION['user_id'],
        'full_name' => $_SESSION['full_name'],
        'username'  => $_SESSION['username'],
        'role'      => $_SESSION['role'],
    ];
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_superadmin() {
    return is_logged_in() && $_SESSION['role'] === 'superadmin';
}

function is_admin() {
    return is_logged_in() && in_array($_SESSION['role'], ['admin', 'superadmin'], true);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        header('Location: index.php?err=forbidden');
        exit;
    }
}

function require_superadmin() {
    require_login();
    if (!is_superadmin()) {
        header('Location: index.php?err=forbidden');
        exit;
    }
}

function require_admin_for_write() {
    if (!is_admin()) {
        header('Location: index.php?err=forbidden');
        exit;
    }
}

function can_use_deployments() {
    return is_logged_in();
}

function log_activity($conn, $action, $details = '', $module = 'other') {
    $user = current_user();
    $userId   = $user ? $user['id'] : null;
    $actor    = $user ? $user['full_name'] : 'Unknown';
    $role     = $user ? $user['role'] : 'staff';

    $stmt = $conn->prepare(
        "INSERT INTO activity_log (user_id, actor_name, actor_role, action, details, module) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssss', $userId, $actor, $role, $action, $details, $module);
    $stmt->execute();
    $stmt->close();
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function unread_message_count($conn) {
    $user = current_user();
    if (!$user) return 0;
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE recipient_id = ? AND is_read = 0");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['c'] ?? 0);
}
