<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function tableExists14($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists14($conn, 'deployment_specs')) {
    $conn->query(
        "CREATE TABLE deployment_specs (
          id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          deployment_id INT UNSIGNED NOT NULL,
          casing        VARCHAR(255) DEFAULT NULL,
          processor     VARCHAR(255) DEFAULT NULL,
          motherboard   VARCHAR(255) DEFAULT NULL,
          ram           VARCHAR(255) DEFAULT NULL,
          ssd           VARCHAR(255) DEFAULT NULL,
          psu           VARCHAR(255) DEFAULT NULL,
          keyboard_mouse VARCHAR(255) DEFAULT NULL,
          monitor       VARCHAR(255) DEFAULT NULL,
          spec_note     TEXT DEFAULT NULL,
          created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_deployment (deployment_id),
          CONSTRAINT fk_specs_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $messages_log[] = "Created deployment_specs (fixed-field unit specs — Casing, Processor, Motherboard, RAM, SSD, PSU, Keyboard & Mouse, Monitor, Note — one optional row per deployment).";
} else {
    $messages_log[] = "deployment_specs already present.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v14 — C1SO TECH</title>
<style>
  body{font-family:'Segoe UI',sans-serif;background:#15171D;color:#E8E6E0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
  .box{background:#1C1F26;border:1px solid #333844;border-radius:8px;padding:30px 34px;max-width:600px;}
  h1{font-size:18px;margin:0 0 12px;color:#7EA3C4;}
  a{color:#7EA3C4;}
  ul{padding-left:18px; line-height:1.7;}
</style>
</head>
<body>
  <div class="box">
    <h1>✓ Migration v14 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="deployments.php">Go to Deployments →</a> · <a href="inventory.php">Go to Inventory →</a></p>
  </div>
</body>
</html>
