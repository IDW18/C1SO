<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages = [];

$colCheck = $conn->query("SHOW COLUMNS FROM activity_log LIKE 'module'");
if ($colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE activity_log ADD COLUMN module VARCHAR(30) NOT NULL DEFAULT 'other' AFTER details");
    $messages[] = "Added 'module' column to activity_log.";

    $conn->query("UPDATE activity_log SET module='worklog' WHERE action LIKE '%work log%'");
    $conn->query("UPDATE activity_log SET module='inventory' WHERE action LIKE '%inventory%'");
    $conn->query("UPDATE activity_log SET module='deployment' WHERE action LIKE '%deploy%' OR action LIKE '%returned%'");
    $conn->query("UPDATE activity_log SET module='staff' WHERE action LIKE '%account%' OR action LIKE '%role%' OR action LIKE '%status%'");
    $conn->query("UPDATE activity_log SET module='settings' WHERE action LIKE '%item type%' OR action LIKE '%department%' OR action LIKE '%category%'");
    $conn->query("UPDATE activity_log SET module='auth' WHERE action LIKE '%Logged in%' OR action LIKE '%Logged out%'");
    $messages[] = "Backfilled module values for existing activity log rows.";
} else {
    $messages[] = "'module' column already exists — nothing to do.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration — C1SO TECH</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#15171D;color:#E8E6E0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}
  .box{background:#1C1F26;border:1px solid #333844;border-radius:8px;padding:30px 34px;max-width:460px;}
  h1{font-size:18px;margin:0 0 12px;color:#7EA3C4;}
  a{color:#7EA3C4;}
  p{line-height:1.6;font-size:14px;}
  ul{padding-left:18px;}
</style>
</head>
<body>
  <div class="box">
    <h1>✓ Migration complete</h1>
    <ul>
      <?php foreach ($messages as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="index.php">Go to Dashboard →</a></p>
  </div>
</body>
</html>
