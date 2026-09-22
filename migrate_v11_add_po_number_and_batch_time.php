<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnExists11($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

if (!columnExists11($conn, 'inventory_batches', 'po_number')) {
    $conn->query("ALTER TABLE inventory_batches ADD COLUMN po_number VARCHAR(100) DEFAULT NULL AFTER pn_number");
    $messages_log[] = "Added inventory_batches.po_number (sits next to PN Number).";
} else {
    $messages_log[] = "inventory_batches.po_number already present.";
}

if (!columnExists11($conn, 'inventory_batches', 'batch_time')) {
    $conn->query("ALTER TABLE inventory_batches ADD COLUMN batch_time TIME DEFAULT NULL AFTER batch_date");
    $messages_log[] = "Added inventory_batches.batch_time.";
} else {
    $messages_log[] = "inventory_batches.batch_time already present.";
}

if (!columnExists11($conn, 'deployments', 'po_number')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN po_number VARCHAR(100) DEFAULT NULL AFTER property_number");

    $conn->query("UPDATE deployments SET po_number = property_number WHERE po_number IS NULL AND property_number IS NOT NULL AND property_number <> ''");
    $messages_log[] = "Added deployments.po_number (replaces the old free-typed Property Number going forward; existing values copied over).";
} else {
    $messages_log[] = "deployments.po_number already present.";
}

$conn->query("UPDATE inventory_batches SET po_number = CONCAT('PO-LEGACY-', id) WHERE po_number IS NULL OR po_number = ''");
$messages_log[] = "Backfilled blank PO Numbers on existing batches with a placeholder (PO-LEGACY-#) — edit these later if you have the real PO.";
$conn->query("UPDATE inventory_batches SET pn_number = CONCAT('PN-LEGACY-', id) WHERE pn_number IS NULL OR pn_number = ''");
$messages_log[] = "Backfilled blank PN Numbers on existing batches with a placeholder (PN-LEGACY-#) — edit these later if you have the real PN.";

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v11 — C1SO TECH</title>
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
    <h1>✓ Migration v11 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="login.php">Go to Login →</a></p>
  </div>
</body>
</html>
