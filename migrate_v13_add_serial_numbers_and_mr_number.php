<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnExists13($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function tableExists13($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists13($conn, 'inventory_batch_serials')) {
    $conn->query(
        "CREATE TABLE inventory_batch_serials (
          id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          batch_id     INT UNSIGNED NOT NULL,
          serial_number VARCHAR(150) DEFAULT NULL,
          created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_batchserial_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $messages_log[] = "Created inventory_batch_serials (one row per physical unit's Serial Number in a restock batch).";
} else {
    $messages_log[] = "inventory_batch_serials already present.";
}

if (!columnExists13($conn, 'deployments', 'mr_number')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN mr_number VARCHAR(100) DEFAULT NULL AFTER pn_number");
    $messages_log[] = "Added deployments.mr_number (one Material Request Number per whole deployment submission).";
} else {
    $messages_log[] = "deployments.mr_number already present.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v13 — C1SO TECH</title>
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
    <h1>✓ Migration v13 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="inventory.php">Go to Inventory →</a> · <a href="deployments.php">Go to Deployments →</a></p>
  </div>
</body>
</html>
