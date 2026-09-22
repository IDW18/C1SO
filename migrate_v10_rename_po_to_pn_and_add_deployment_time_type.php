<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnExists10($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

if (columnExists10($conn, 'inventory_batches', 'po_number') && !columnExists10($conn, 'inventory_batches', 'pn_number')) {
    $conn->query("ALTER TABLE inventory_batches CHANGE COLUMN po_number pn_number VARCHAR(100) DEFAULT NULL");
    $messages_log[] = "Renamed inventory_batches.po_number to pn_number.";
} else {
    $messages_log[] = "inventory_batches.pn_number already present.";
}

if (columnExists10($conn, 'deployments', 'po_number') && !columnExists10($conn, 'deployments', 'pn_number')) {
    $conn->query("ALTER TABLE deployments CHANGE COLUMN po_number pn_number VARCHAR(100) DEFAULT NULL");
    $messages_log[] = "Renamed deployments.po_number to pn_number.";
} else {
    $messages_log[] = "deployments.pn_number already present.";
}

$conn->query("ALTER TABLE inventory_batches MODIFY COLUMN notes VARCHAR(1000) DEFAULT NULL");
$messages_log[] = "Widened inventory_batches.notes (Details) to 1000 characters.";

if (columnExists10($conn, 'inventory_items', 'condition_notes')) {
    $conn->query("ALTER TABLE inventory_items DROP COLUMN condition_notes");
    $messages_log[] = "Dropped inventory_items.condition_notes — Condition is no longer tracked.";
} else {
    $messages_log[] = "inventory_items.condition_notes already removed.";
}

if (!columnExists10($conn, 'deployments', 'deployed_time')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN deployed_time TIME DEFAULT NULL AFTER deployed_date");
    $messages_log[] = "Added deployments.deployed_time.";
} else {
    $messages_log[] = "deployments.deployed_time already present.";
}
if (!columnExists10($conn, 'deployments', 'returned_time')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN returned_time TIME DEFAULT NULL AFTER returned_date");
    $messages_log[] = "Added deployments.returned_time.";
} else {
    $messages_log[] = "deployments.returned_time already present.";
}

if (!columnExists10($conn, 'deployments', 'deployment_type')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN deployment_type ENUM('new','replacement') NOT NULL DEFAULT 'new' AFTER quantity_deployed");
    $messages_log[] = "Added deployments.deployment_type (new / replacement).";
} else {
    $messages_log[] = "deployments.deployment_type already present.";
}

if (!columnExists10($conn, 'deployments', 'replaces_deployment_id')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN replaces_deployment_id INT UNSIGNED DEFAULT NULL AFTER deployment_type");
    $conn->query("ALTER TABLE deployments ADD CONSTRAINT fk_deploy_replaces FOREIGN KEY (replaces_deployment_id) REFERENCES deployments(id) ON DELETE SET NULL");
    $messages_log[] = "Added deployments.replaces_deployment_id (links a replacement deployment to the old one it replaced).";
} else {
    $messages_log[] = "deployments.replaces_deployment_id already present.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v10 — C1SO TECH</title>
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
    <h1>✓ Migration v10 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="login.php">Go to Login →</a></p>
  </div>
</body>
</html>
