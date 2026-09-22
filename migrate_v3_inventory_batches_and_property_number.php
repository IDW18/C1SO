<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages = [];

function columnExists($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}
function tableExists($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists($conn, 'inventory_batches')) {
    $conn->query("
        CREATE TABLE inventory_batches (
          id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          item_id      INT UNSIGNED NOT NULL,
          quantity     INT UNSIGNED NOT NULL,
          batch_date   DATE NOT NULL,
          notes        VARCHAR(255) DEFAULT NULL,
          added_by     INT UNSIGNED NOT NULL,
          created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_batch_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
          CONSTRAINT fk_batch_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "Created inventory_batches table.";
} else {
    $messages[] = "inventory_batches table already exists.";
}

if (!columnExists($conn, 'inventory_items', 'date_added')) {
    $conn->query("ALTER TABLE inventory_items ADD COLUMN date_added DATE NULL AFTER condition_notes");
    $conn->query("UPDATE inventory_items SET date_added = DATE(created_at) WHERE date_added IS NULL");
    $conn->query("ALTER TABLE inventory_items MODIFY date_added DATE NOT NULL");
    $messages[] = "Added date_added column and backfilled it from created_at.";
} else {
    $messages[] = "date_added column already exists.";
}

if (!columnExists($conn, 'inventory_items', 'low_stock_threshold')) {
    $conn->query("ALTER TABLE inventory_items ADD COLUMN low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5 AFTER quantity_total");
    $messages[] = "Added low_stock_threshold column (default 5).";
} else {
    $messages[] = "low_stock_threshold column already exists.";
}

if (!columnExists($conn, 'deployments', 'property_number')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN property_number VARCHAR(100) DEFAULT NULL AFTER department_id");

    if (columnExists($conn, 'inventory_items', 'property_number')) {
        $conn->query("
            UPDATE deployments d
            JOIN inventory_items i ON i.id = d.item_id
            SET d.property_number = i.property_number
            WHERE d.property_number IS NULL
        ");
        $messages[] = "Added property_number column to deployments and copied over each item's old property number into its deployment records.";
    } else {
        $messages[] = "Added property_number column to deployments.";
    }
} else {
    $messages[] = "deployments.property_number column already exists.";
}

if (tableExists($conn, 'inventory_batches')) {
    $noBatch = $conn->query("
        SELECT i.id, i.quantity_total, i.date_added, i.created_by
        FROM inventory_items i
        LEFT JOIN inventory_batches b ON b.item_id = i.id
        WHERE b.id IS NULL AND i.quantity_total > 0
    ");
    $count = 0;
    while ($row = $noBatch->fetch_assoc()) {
        $stmt = $conn->prepare("INSERT INTO inventory_batches (item_id, quantity, batch_date, notes, added_by) VALUES (?,?,?,?,?)");
        $notes = 'Initial stock (backfilled by migration)';
        $stmt->bind_param('iissi', $row['id'], $row['quantity_total'], $row['date_added'], $notes, $row['created_by']);
        $stmt->execute();
        $count++;
    }
    if ($count > 0) $messages[] = "Backfilled a starting batch record for $count existing item(s).";
}

if (columnExists($conn, 'inventory_items', 'status')) {
    $conn->query("ALTER TABLE inventory_items DROP COLUMN status");
    $messages[] = "Removed old 'status' column from inventory_items (status is now computed live as Good/Low Stock).";
}
if (columnExists($conn, 'inventory_items', 'property_number')) {

    $conn->query("ALTER TABLE inventory_items DROP INDEX property_number");
    $conn->query("ALTER TABLE inventory_items DROP COLUMN property_number");
    $messages[] = "Removed old 'property_number' column from inventory_items (now entered manually per deployment instead).";
}

if (!columnExists($conn, 'activity_log', 'module')) {
    $conn->query("ALTER TABLE activity_log ADD COLUMN module VARCHAR(30) NOT NULL DEFAULT 'other' AFTER details");
    $messages[] = "Added 'module' column to activity_log (from the previous update).";
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v3 — C1SO TECH</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#15171D;color:#E8E6E0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
  .box{background:#1C1F26;border:1px solid #333844;border-radius:8px;padding:30px 34px;max-width:520px;}
  h1{font-size:18px;margin:0 0 12px;color:#7EA3C4;}
  a{color:#7EA3C4;}
  p{line-height:1.6;font-size:14px;}
  ul{padding-left:18px; line-height:1.7;}
</style>
</head>
<body>
  <div class="box">
    <h1>✓ Migration v3 complete</h1>
    <ul>
      <?php foreach ($messages as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="inventory.php">Go to Inventory →</a></p>
  </div>
</body>
</html>
