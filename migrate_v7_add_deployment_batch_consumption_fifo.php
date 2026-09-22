<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function tableExists7($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists7($conn, 'deployment_batch_consumption')) {
    $conn->query("
        CREATE TABLE deployment_batch_consumption (
          id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          deployment_id  INT UNSIGNED NOT NULL,
          batch_id       INT UNSIGNED NOT NULL,
          quantity       INT UNSIGNED NOT NULL,
          created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_dbc_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE,
          CONSTRAINT fk_dbc_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages_log[] = "Created deployment_batch_consumption table (tracks which batch/PO each deployment drew its units from).";
} else {
    $messages_log[] = "deployment_batch_consumption table already exists.";
}

$messages_log[] = "Deploying an item now automatically consumes the OLDEST batch(es) first (FIFO), and Deployments/Inventory show which batch/PO each deployment came from (code-level change, no further schema update needed).";
$messages_log[] = "Inventory Items list is now a flat table with a 'Kind of Item' column, instead of grouped section headers (code-level change).";
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v7 — C1SO TECH</title>
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
    <h1>✓ Migration v7 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="index.php">Go to Dashboard →</a></p>
  </div>
</body>
</html>
