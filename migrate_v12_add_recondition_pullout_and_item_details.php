<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnExists12($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

$colRes = $conn->query("SHOW COLUMNS FROM deployments LIKE 'deployment_type'");
$colRow = $colRes ? $colRes->fetch_assoc() : null;
$currentType = $colRow['Type'] ?? '';
if ($currentType !== '' && strpos($currentType, 'recondition') === false) {
    $conn->query("ALTER TABLE deployments MODIFY COLUMN deployment_type ENUM('new','replacement','recondition','pullout') NOT NULL DEFAULT 'new'");
    $messages_log[] = "Expanded deployments.deployment_type to include 'recondition' and 'pullout' (Recondition / Pullout Unit).";
} else {
    $messages_log[] = "deployments.deployment_type already includes recondition/pullout.";
}

if (!columnExists12($conn, 'deployments', 'item_details')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN item_details TEXT DEFAULT NULL AFTER remarks");
    $messages_log[] = "Added deployments.item_details (per-item Details textbox entered in the Deploy Items wizard).";
} else {
    $messages_log[] = "deployments.item_details already present.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v12 — C1SO TECH</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#15171D;color:#E8E6E0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
  .box{background:#1C1F26;border:1px solid #333844;border-radius:8px;padding:30px 34px;max-width:600px;}
  h1{font-size:18px;margin:0 0 12px;color:#7EA3C4;}
  a{color:#7EA3C4;}
  code{background:#242832;padding:2px 6px;border-radius:4px;color:#D9A441;}
  p{line-height:1.6;font-size:14px;}
  ul{padding-left:18px; line-height:1.7;}
</style>
</head>
<body>
  <div class="box">
    <h1>✓ Migration v12 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="deployments.php">Go to Deployments →</a></p>
  </div>
</body>
</html>
