<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnHasSuperadmin9($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (!$res) return false;
    $row = $res->fetch_assoc();
    return $row && strpos($row['Type'], 'superadmin') !== false;
}

if (!columnHasSuperadmin9($conn, 'users', 'role')) {
    $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','staff') NOT NULL DEFAULT 'staff'");
    $messages_log[] = "Added \"superadmin\" to the users.role list of allowed roles.";
} else {
    $messages_log[] = "users.role already allows \"superadmin\".";
}

if (!columnHasSuperadmin9($conn, 'activity_log', 'actor_role')) {
    $conn->query("ALTER TABLE activity_log MODIFY COLUMN actor_role ENUM('superadmin','admin','staff') NOT NULL");
    $messages_log[] = "Added \"superadmin\" to the activity_log.actor_role list of allowed roles.";
} else {
    $messages_log[] = "activity_log.actor_role already allows \"superadmin\".";
}

$superUsername = 'superadmin';
$superPassword = 'superadmin123';
$superHash = password_hash($superPassword, PASSWORD_DEFAULT);

$sStmt = $conn->prepare("SELECT id, password_hash FROM users WHERE username = ?");
$sStmt->bind_param('s', $superUsername);
$sStmt->execute();
$sRes = $sStmt->get_result();

if ($sRow = $sRes->fetch_assoc()) {
    if (empty($sRow['password_hash']) || !password_get_info($sRow['password_hash'])['algo']) {
        $supd = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $supd->bind_param('si', $superHash, $sRow['id']);
        $supd->execute();
        $messages_log[] = "Default superadmin password has been set/reset.";
    } else {
        $messages_log[] = "Superadmin account already exists with a password set. Nothing changed.";
    }
} else {
    $sIns = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role, status) VALUES (?, ?, ?, 'superadmin', 'active')");
    $sFull = 'Super Administrator';
    $sIns->bind_param('sss', $sFull, $superUsername, $superHash);
    $sIns->execute();
    $messages_log[] = "Default superadmin account created (username: superadmin / password: superadmin123 — change this after logging in).";
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v9 — C1SO TECH</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#15171D;color:#E8E6E0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
  .box{background:#1C1F26;border:1px solid #333844;border-radius:8px;padding:30px 34px;max-width:560px;}
  h1{font-size:18px;margin:0 0 12px;color:#7EA3C4;}
  a{color:#7EA3C4;}
  code{background:#242832;padding:2px 6px;border-radius:4px;color:#D9A441;}
  p{line-height:1.6;font-size:14px;}
  ul{padding-left:18px; line-height:1.7;}
</style>
</head>
<body>
  <div class="box">
    <h1>✓ Migration v9 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p>Super Admin login:<br>
      Username: <code>superadmin</code><br>
      Password: <code>superadmin123</code>
    </p>
    <p style="color:#D9A441;">This account is not created anywhere in the site's own UI — only by running setup.php or this migration. Change this password after logging in.</p>
    <p><a href="login.php">Go to Login →</a></p>
  </div>
</body>
</html>
