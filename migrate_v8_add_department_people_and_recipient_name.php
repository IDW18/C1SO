<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function tableExists8($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}
function columnExists8($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

if (!tableExists8($conn, 'department_people')) {
    $conn->query("
        CREATE TABLE department_people (
          id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          department_id  INT UNSIGNED NOT NULL,
          name           VARCHAR(150) NOT NULL,
          created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_deptperson_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
          UNIQUE KEY uniq_dept_person (department_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages_log[] = "Created department_people table (faculty/staff list per department, for the new Deployment wizard).";
} else {
    $messages_log[] = "department_people table already exists.";
}

if (!columnExists8($conn, 'deployments', 'recipient_name')) {
    $conn->query("ALTER TABLE deployments ADD COLUMN recipient_name VARCHAR(150) DEFAULT NULL AFTER department_id");
    $messages_log[] = "Added recipient_name column to deployments (which faculty/staff member received the item).";
} else {
    $messages_log[] = "deployments.recipient_name already exists.";
}

$messages_log[] = "Deployments page redesigned into a step-by-step wizard: Select Department -> Select/Add Faculty or Staff -> Choose PO or PN -> Pick Category & Items (with a manual Add option) -> enter Property Number per item -> add multiple items -> Ready for Deployment (code-level change, no further schema update needed).";
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v8 — C1SO TECH</title>
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
    <h1>✓ Migration v8 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="deployments.php">Go to Deployments →</a></p>
  </div>
</body>
</html>
