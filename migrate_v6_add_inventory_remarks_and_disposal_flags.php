<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function tableExists6($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists6($conn, 'inventory_remarks')) {
    $conn->query("
        CREATE TABLE inventory_remarks (
          id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          item_id     INT UNSIGNED NOT NULL,
          remark      VARCHAR(400) NOT NULL,
          remark_date DATE NOT NULL,
          added_by    INT UNSIGNED NOT NULL,
          created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_remark_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
          CONSTRAINT fk_remark_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages_log[] = "Created inventory_remarks table (quick remarks logged per item).";
} else {
    $messages_log[] = "inventory_remarks table already exists.";
}

if (!tableExists6($conn, 'inventory_disposal_flags')) {
    $conn->query("
        CREATE TABLE inventory_disposal_flags (
          id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          item_id            INT UNSIGNED NOT NULL,
          flagged_date       DATE NOT NULL,
          last_activity_date DATE DEFAULT NULL,
          days_inactive       INT UNSIGNED NOT NULL,
          status             ENUM('pending','reviewed','disposed') NOT NULL DEFAULT 'pending',
          reviewed_by        INT UNSIGNED DEFAULT NULL,
          reviewed_at        DATETIME DEFAULT NULL,
          created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_disposal_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
          CONSTRAINT fk_disposal_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages_log[] = "Created inventory_disposal_flags table (30-day inactivity disposal review history).";
} else {
    $messages_log[] = "inventory_disposal_flags table already exists.";
}

$messages_log[] = "Inventory Items list: Restock / Edit / Delete action buttons removed, replaced with a single 'Remarks' button (code-level change).";
$messages_log[] = "Items with no batch/deployment/return activity for 30+ days are now auto-flagged for disposal review, visible in Reports (code-level change, runs automatically whenever Inventory or Reports is opened).";
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v6 — C1SO TECH</title>
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
    <h1>✓ Migration v6 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="index.php">Go to Dashboard →</a></p>
  </div>
</body>
</html>
