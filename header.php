<?php

require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$pageTitle = $pageTitle ?? 'Dashboard';
$activePage = $activePage ?? '';

$activityRes = $conn->query(
    "SELECT actor_name, actor_role, action, details, created_at
     FROM activity_log
     WHERE module = 'worklog'
     ORDER BY created_at DESC LIMIT 25"
);

$unreadMsgCount = unread_message_count($conn);

$showWelcomeToast = !empty($_SESSION['just_logged_in']);
if ($showWelcomeToast) unset($_SESSION['just_logged_in']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($pageTitle); ?> — C1SO TECH</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">C1SO TECH</div>
      <div class="brand-title">Work Log &amp; Inventory</div>
      <div class="brand-sub"><?php echo h($user['full_name']); ?></div>
    </div>

    <div class="nav-section">MAIN</div>
    <div class="nav-list">
      <a class="nav-item <?php echo $activePage==='dashboard'?'active':''; ?>" href="index.php">▤ Dashboard</a>
      <a class="nav-item <?php echo $activePage==='worklog'?'active':''; ?>" href="worklog.php">▤ Work Log</a>
      <a class="nav-item <?php echo $activePage==='inventory'?'active':''; ?>" href="inventory.php">▤ Inventory</a>
      <a class="nav-item <?php echo $activePage==='deployments'?'active':''; ?>" href="deployments.php">▤ Deployments</a>
      <a class="nav-item <?php echo $activePage==='departments'?'active':''; ?>" href="departments.php">▤ Departments</a>
      <a class="nav-item <?php echo $activePage==='reports'?'active':''; ?>" href="reports.php">▤ Reports</a>
      <a class="nav-item <?php echo $activePage==='messages'?'active':''; ?>" href="messages.php">▤ Messages<?php if ($unreadMsgCount > 0): ?> <span class="nav-badge"><?php echo $unreadMsgCount; ?></span><?php endif; ?></a>
    </div>

    <div class="nav-section">PUBLIC</div>
    <div class="nav-list">
      <a class="nav-item" href="public_worklog.php" target="_blank">▤ Public Work Log Page ↗</a>
    </div>

    <?php if (is_admin()): ?>
    <div class="nav-section">ADMIN</div>
    <div class="nav-list">
      <a class="nav-item <?php echo $activePage==='staff'?'active':''; ?>" href="staff.php">▤ Manage Staff</a>
      <a class="nav-item <?php echo $activePage==='settings'?'active':''; ?>" href="settings.php">▤ Item Types / Departments / Categories</a>
      <a class="nav-item <?php echo $activePage==='activity'?'active':''; ?>" href="activity.php">▤ Full Activity Log</a>
    </div>
    <?php endif; ?>

    <div class="activity-widget">
      <div class="activity-widget-title">Log Days — Work Log Activity</div>
      <?php if ($activityRes && $activityRes->num_rows > 0): ?>
        <?php while ($a = $activityRes->fetch_assoc()): ?>
          <div class="activity-item">
            <span class="who <?php echo in_array($a['actor_role'], ['admin','superadmin'], true) ? 'admin' : ''; ?>"><?php echo h($a['actor_name']); ?></span>
            <span class="what"> — <?php echo h($a['action']); ?><?php echo $a['details'] ? ': ' . h($a['details']) : ''; ?></span><br>
            <span class="when"><?php echo date('M j, Y g:i a', strtotime($a['created_at'])); ?></span>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="activity-item"><span class="what">No activity yet.</span></div>
      <?php endif; ?>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div style="display:flex; gap:12px; align-items:center;">
        <button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
        <div class="topbar-title">
          <?php echo h($pageTitle); ?>
          <span class="full-date"><?php echo date('l, F j, Y'); ?></span>
        </div>
      </div>

      <div style="display:flex; align-items:center; gap:10px;">
      <a href="messages.php" class="icon-btn" title="Messages" aria-label="Messages">
        ✉
        <?php if ($unreadMsgCount > 0): ?><span class="icon-badge"><?php echo $unreadMsgCount > 9 ? '9+' : $unreadMsgCount; ?></span><?php endif; ?>
      </a>
      <div class="account-menu">
        <button class="account-btn" onclick="document.getElementById('accountDropdown').classList.toggle('open')">
          <span class="account-avatar"><?php echo strtoupper(substr($user['full_name'],0,1)); ?></span>
          <span><?php echo h($user['full_name']); ?></span>
          <span class="account-role-badge role-<?php echo h($user['role']); ?>"><?php echo $user['role'] === 'superadmin' ? 'Super Admin' : h($user['role']); ?></span>
        </button>
        <div class="account-dropdown" id="accountDropdown">
          <div class="account-dropdown-header">
            <div class="name"><?php echo h($user['full_name']); ?></div>
            <div class="username">@<?php echo h($user['username']); ?></div>
          </div>
          <a href="account.php">Change Login Name / Password</a>
          <?php if (is_admin()): ?>
            <a href="staff.php">Manage Staff Accounts</a>
          <?php endif; ?>
          <a href="logout.php" class="danger">Logout</a>
        </div>
      </div>
      </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>
    <?php if ($showWelcomeToast): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        showToast('Welcome back, <?php echo addslashes($user['full_name']); ?>!');
      });
    </script>
    <?php endif; ?>

    <div class="content">
