<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnExists5($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

if (!columnExists5($conn, 'inventory_batches', 'po_number')) {
    $conn->query("ALTER TABLE inventory_batches ADD COLUMN po_number VARCHAR(100) DEFAULT NULL AFTER notes");
    $messages_log[] = "Added po_number column to inventory_batches (PO / Receipt Number per batch).";
} else {
    $messages_log[] = "inventory_batches.po_number already exists.";
}

if (!columnExists5($conn, 'deployments', 'po_number')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN po_number VARCHAR(100) DEFAULT NULL AFTER property_number");
    $messages_log[] = "Added po_number column to deployments (PO / Receipt Number reference).";
} else {
    $messages_log[] = "deployments.po_number already exists.";
}

$messages_log[] = "Item labels changed from \"Item Name\" to \"Item Model\" in the UI (code-level change, no schema update needed).";
$messages_log[] = "Inventory Items list rows are now clickable, opening a popup with per-batch PO / Receipt Number and Remarks history (code-level change, no schema update needed).";
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v5 — C1SO TECH</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#15171D;color:#E8E6E0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
  .box{background:#1C1F26;border:1px solid #333844;border-radius:8px;padding:30px 34px;max-width:560px;}
  h1{font-size:18px;margin:0 0 12px;color:#7EA3C4;}
  a{color:#7EA3C4;}
  p{line-height:1.6;font-size:14px;}
  ul{padding-left:18px; line-height:1.7;}
</style>
</head>
<body>
  <div class="box">
    <h1>✓ Migration v5 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="index.php">Go to Dashboard →</a></p>
  </div>
</body>
</html>
