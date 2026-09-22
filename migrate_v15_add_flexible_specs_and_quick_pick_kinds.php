<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function tableExists15($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists15($conn, 'deployment_spec_values')) {
    $conn->query(
        "CREATE TABLE deployment_spec_values (
          id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          deployment_id INT UNSIGNED NOT NULL,
          spec_key      VARCHAR(60) NOT NULL,
          spec_value    VARCHAR(255) DEFAULT NULL,
          created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_deploy_spec (deployment_id, spec_key),
          CONSTRAINT fk_dspecv_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $messages_log[] = "Created deployment_spec_values (flexible per-Kind-of-Item Specifications, one row per field per deployment).";
} else {
    $messages_log[] = "deployment_spec_values already present.";
}

if (!tableExists15($conn, 'inventory_item_spec_values')) {
    $conn->query(
        "CREATE TABLE inventory_item_spec_values (
          id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          item_id    INT UNSIGNED NOT NULL,
          spec_key   VARCHAR(60) NOT NULL,
          spec_value VARCHAR(255) DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_item_spec (item_id, spec_key),
          CONSTRAINT fk_ispecv_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $messages_log[] = "Created inventory_item_spec_values (preset/default Specifications per Inventory item/model, auto-filled into a deployment's specs when that model is picked).";
} else {
    $messages_log[] = "inventory_item_spec_values already present.";
}

$requiredKinds = ['PC/System Unit', 'Laptop', 'Projector'];
foreach ($requiredKinds as $kindName) {
    $check = $conn->prepare("SELECT id FROM item_types WHERE LOWER(name) = LOWER(?)");
    $check->bind_param('s', $kindName);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    if (!$existing) {
        $ins = $conn->prepare("INSERT INTO item_types (name) VALUES (?)");
        $ins->bind_param('s', $kindName);
        $ins->execute();
        $messages_log[] = "Added \"$kindName\" to Item Types (Settings) — required for the wizard's quick-pick buttons.";
    } else {
        $messages_log[] = "\"$kindName\" already exists in Item Types.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v15 — C1SO TECH</title>
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
    <h1>✓ Migration v15 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="inventory.php">Go to Inventory →</a> · <a href="deployments.php">Go to Deployments →</a></p>
  </div>
</body>
</html>
