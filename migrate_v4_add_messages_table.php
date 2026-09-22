<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$messages_log = [];

function columnExists4($conn, $table, $column) {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}
function tableExists4($conn, $table) {
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

if (!tableExists4($conn, 'messages')) {
    $conn->query("
        CREATE TABLE messages (
          id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          sender_id   INT UNSIGNED NOT NULL,
          recipient_id INT UNSIGNED NOT NULL,
          body        TEXT NOT NULL,
          is_read     TINYINT(1) NOT NULL DEFAULT 0,
          created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_msg_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
          INDEX idx_msg_recipient_read (recipient_id, is_read),
          INDEX idx_msg_conversation (sender_id, recipient_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages_log[] = "Created messages table (for the new user-to-user inbox).";
} else {
    $messages_log[] = "messages table already exists.";
}

$messages_log[] = "Staff now have full access to the Deployment area (code-level change, no schema update needed).";
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration v4 — C1SO TECH</title>
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
    <h1>✓ Migration v4 complete</h1>
    <ul>
      <?php foreach ($messages_log as $m) echo '<li>' . h($m) . '</li>'; ?>
    </ul>
    <p><a href="index.php">Go to Dashboard →</a></p>
  </div>
</body>
</html>
