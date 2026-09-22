<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

$pageTitle = 'Work Log';
$activePage = 'worklog';
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $name = trim($_POST['category_name'] ?? '');
    if ($name !== '') {
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        log_activity($conn, 'Added work log category', $name, 'worklog');
    }
    header('Location: worklog.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_entry') {
    $logDate    = $_POST['log_date'] ?? date('Y-m-d');
    $logTime    = trim($_POST['log_time'] ?? '');
    $itemName   = trim($_POST['item_name'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $status     = in_array($_POST['status'] ?? '', ['done','ongoing','pending']) ? $_POST['status'] : 'done';
    $remarks    = in_array($_POST['remarks'] ?? '', ['ok','replace']) ? $_POST['remarks'] : 'ok';
    $requester  = trim($_POST['requester'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    $noteColor  = in_array($_POST['note_color'] ?? '', ['none','red','green','orange']) ? $_POST['note_color'] : 'none';
    $userId     = current_user()['id'];

    if ($desc === '' || $logTime === '') {
        $errorMsg = 'Please fill in at least the time and task description.';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO work_logs (log_date, log_time, item_name, description, category_id, status, remarks, requester, notes, note_color, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssssisssssi',
            $logDate, $logTime, $itemName, $desc, $categoryId, $status, $remarks, $requester, $notes, $noteColor, $userId
        );
        $stmt->execute();
        $logId = $stmt->insert_id;
        $stmt->close();

        if (!empty($_FILES['photos']['name'][0])) {
            $uploadDir = 'uploads/worklog/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                        $filename = uniqid('log_' . $logId . '_') . '.' . $ext;
                        $destPath = $uploadDir . $filename;
                        if (move_uploaded_file($tmpName, $destPath)) {
                            $pstmt = $conn->prepare("INSERT INTO work_log_photos (log_id, file_path) VALUES (?, ?)");
                            $pstmt->bind_param('is', $logId, $destPath);
                            $pstmt->execute();
                            $pstmt->close();
                        }
                    }
                }
            }
        }

        log_activity($conn, 'Added work log entry', $itemName ?: $desc, 'worklog');
        $successMsg = 'Task entry saved.';
    }
}

$filterDate     = $_GET['date'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterStatus   = $_GET['status'] ?? '';
$search         = trim($_GET['q'] ?? '');

$where = [];
$params = [];
$types = '';

if ($filterDate !== '') { $where[] = 'l.log_date = ?'; $params[] = $filterDate; $types .= 's'; }
if ($filterCategory !== '') { $where[] = 'l.category_id = ?'; $params[] = (int)$filterCategory; $types .= 'i'; }
if ($filterStatus !== '') { $where[] = 'l.status = ?'; $params[] = $filterStatus; $types .= 's'; }
if ($search !== '') {
    $where[] = '(l.description LIKE ? OR l.item_name LIKE ? OR l.requester LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT l.*, c.name AS category_name, u.full_name AS created_by_name, u.id AS created_by_id, u.role AS created_by_role
        FROM work_logs l
        LEFT JOIN categories c ON c.id = l.category_id
        LEFT JOIN users u ON u.id = l.created_by
        $whereSql
        ORDER BY u.full_name, l.log_date DESC, l.id DESC
        LIMIT 400";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$logsRes = $stmt->get_result();

$groupedLogs = [];
while ($l = $logsRes->fetch_assoc()) {
    $key = $l['created_by_name'] ?: 'Unknown';
    $groupedLogs[$key][] = $l;
}

$categories = $conn->query("SELECT * FROM categories ORDER BY name");

include 'includes/header.php';
?>

<?php if ($successMsg): ?><div class="alert alert-success"><?php echo h($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-header">
    <h2>New Task Entry</h2>
  </div>
  <div class="panel-body">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_entry">
      <div class="field-row">
        <div class="field">
          <label>Date</label>
          <input type="date" name="log_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="field">
          <label>Time (e.g. 7:00 am)</label>
          <input type="text" name="log_time" placeholder="e.g. 7:00 am" required>
        </div>
      </div>
      <div class="field">
        <label>Item Name (optional)</label>
        <input type="text" name="item_name" placeholder="e.g. LAN Cable, Power Supply Unit, Router">
      </div>
      <div class="field">
        <label>Task Description</label>
        <textarea name="description" placeholder="What did you do?" required></textarea>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Category</label>
          <select name="category_id">
            <option value="">— None —</option>
            <?php $categories->data_seek(0); while ($c = $categories->fetch_assoc()): ?>
              <option value="<?php echo $c['id']; ?>"><?php echo h($c['name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="done">Done</option>
            <option value="ongoing">Ongoing</option>
            <option value="pending">Pending</option>
          </select>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Item Remarks</label>
          <select name="remarks">
            <option value="ok">Okay</option>
            <option value="replace">Needs Replacement</option>
          </select>
        </div>
        <div class="field">
          <label>Note Color Indicator</label>
          <select name="note_color">
            <option value="none">No Indicator</option>
            <option value="red">🔴 Red</option>
            <option value="green">🟢 Green</option>
            <option value="orange">🟠 Orange</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label>Requested By / Location</label>
        <input type="text" name="requester" placeholder="e.g. Registrar's Office, Ms. Santos, Room 204">
      </div>
      <div class="field">
        <label>Notes</label>
        <textarea name="notes" placeholder="Additional remarks, parts used, follow-up needed..."></textarea>
      </div>
      <div class="field">
        <label>Documentation Photos</label>
        <input type="file" name="photos[]" accept="image/*" multiple>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn-primary" data-loading-text="Saving entry...">Save Entry</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <h2>Add New Category</h2>
  </div>
  <div class="panel-body">
    <form method="POST" style="display:flex; gap:10px;">
      <input type="hidden" name="action" value="add_category">
      <input type="text" name="category_name" placeholder="New category name" style="flex:1; background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:9px 11px; color:var(--text);" required>
      <button type="submit" class="btn-secondary">+ Add</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <h2>Task Entries</h2>
    <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap;">
      <input type="text" name="q" placeholder="Search..." value="<?php echo h($search); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text); font-size:12.5px;">
      <input type="date" name="date" value="<?php echo h($filterDate); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text); font-size:12.5px;">
      <select name="status" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text-dim); font-size:12.5px;">
        <option value="">All Status</option>
        <option value="done" <?php echo $filterStatus==='done'?'selected':''; ?>>Done</option>
        <option value="ongoing" <?php echo $filterStatus==='ongoing'?'selected':''; ?>>Ongoing</option>
        <option value="pending" <?php echo $filterStatus==='pending'?'selected':''; ?>>Pending</option>
      </select>
      <button type="submit" class="btn-secondary btn-sm">Filter</button>
      <a href="worklog.php" class="btn-secondary btn-sm">Reset</a>
    </form>
  </div>
  <div class="panel-body">
    <?php if (empty($groupedLogs)): ?>
      <div class="dash-search-empty">No task entries found.</div>
    <?php else: ?>
      <div class="user-card-grid">
        <?php foreach ($groupedLogs as $userName => $entries): ?>
          <div class="user-card">
            <div class="user-card-header">
              <span class="account-avatar"><?php echo strtoupper(substr($userName,0,1)); ?></span>
              <span class="user-card-name"><?php echo h($userName); ?></span>
              <?php if (!empty($entries[0]['created_by_role'])): ?>
                <span class="account-role-badge role-<?php echo $entries[0]['created_by_role']; ?>"><?php echo h($entries[0]['created_by_role']); ?></span>
              <?php endif; ?>
              <span class="user-card-count"><?php echo count($entries); ?> <?php echo count($entries)===1?'entry':'entries'; ?></span>
            </div>
            <div class="user-card-body">
              <?php foreach ($entries as $l): ?>
                <div class="worklog-entry">
                  <div class="worklog-entry-top">
                    <span class="worklog-entry-date"><?php echo date('M j, Y', strtotime($l['log_date'])); ?> · <?php echo h($l['log_time']); ?></span>
                    <?php
                      $statusClass = ['done'=>'b-green','ongoing'=>'b-accent','pending'=>'b-amber'][$l['status']] ?? 'b-gray';
                    ?>
                    <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($l['status']); ?></span>
                    <span class="badge <?php echo $l['remarks']==='replace' ? 'b-red' : 'b-green'; ?>">
                      <?php echo $l['remarks']==='replace' ? 'Needs Replacement' : 'Okay'; ?>
                    </span>
                  </div>
                  <div class="worklog-entry-item"><?php echo h($l['item_name'] ?: '—'); ?><?php echo $l['category_name'] ? ' <span class="badge">'.h($l['category_name']).'</span>' : ''; ?></div>
                  <div class="worklog-entry-desc"><?php echo h($l['description']); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
