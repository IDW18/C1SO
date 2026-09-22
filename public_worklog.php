<?php

require_once 'includes/db.php';
require_once 'includes/auth.php';

$search = trim($_GET['q'] ?? '');
$filterDate = $_GET['date'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$where = [];
$params = [];
$types = '';

if ($filterDate !== '') { $where[] = 'l.log_date = ?'; $params[] = $filterDate; $types .= 's'; }
if ($filterStatus !== '') { $where[] = 'l.status = ?'; $params[] = $filterStatus; $types .= 's'; }
if ($search !== '') {
    $where[] = '(l.description LIKE ? OR l.item_name LIKE ? OR l.requester LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT l.log_date, l.log_time, l.item_name, l.description, l.status, l.remarks, l.requester,
               c.name AS category_name, u.full_name AS logged_by
        FROM work_logs l
        LEFT JOIN categories c ON c.id = l.category_id
        LEFT JOIN users u ON u.id = l.created_by
        $whereSql
        ORDER BY l.log_date DESC, l.id DESC
        LIMIT 300";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result();

$totalRow = $conn->query("SELECT COUNT(*) c FROM work_logs")->fetch_assoc();

$deployRes = $conn->query(
    "SELECT d.po_number, d.quantity_deployed, d.deployed_date, d.remarks,
            i.item_name, dep.name AS dept_name, u.full_name AS deployed_by_name
     FROM deployments d
     JOIN inventory_items i ON i.id = d.item_id
     JOIN departments dep ON dep.id = d.department_id
     JOIN users u ON u.id = d.deployed_by
     WHERE d.returned_date IS NULL
     ORDER BY d.deployed_date DESC, d.id DESC
     LIMIT 300"
);
$totalDeployedRow = $conn->query("SELECT COUNT(*) c FROM deployments WHERE returned_date IS NULL")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Public Work Log — C1SO TECH</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  .public-wrap{ max-width:1100px; margin:0 auto; padding:34px 24px 60px; }
  .public-header{ text-align:center; margin-bottom:26px; }
  .public-header .brand-mark{ font-family:var(--font-mono); font-size:12px; letter-spacing:.1em; color:var(--accent-bright); margin-bottom:8px; }
  .public-header h1{ font-size:24px; font-weight:700; }
  .public-header p{ color:var(--text-dim); font-size:13px; margin-top:6px; }
  .public-filter-bar{ display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-bottom:22px; }
  .public-filter-bar input, .public-filter-bar select{
    background:var(--panel); border:1px solid var(--border); border-radius:5px;
    padding:9px 12px; color:var(--text); font-family:var(--font-ui); font-size:13px;
  }
</style>
</head>
<body>
<div class="public-wrap">
  <div class="public-header">
    <div class="brand-mark">C1SO TECH</div>
    <h1>Public Work Log</h1>
    <p><?php echo (int)$totalRow['c']; ?> total entries logged — updated live, view only</p>
  </div>

  <form method="GET" class="public-filter-bar">
    <input type="text" name="q" placeholder="Search description, item, requester..." value="<?php echo h($search); ?>">
    <input type="date" name="date" value="<?php echo h($filterDate); ?>">
    <select name="status">
      <option value="">All Status</option>
      <option value="done" <?php echo $filterStatus==='done'?'selected':''; ?>>Done</option>
      <option value="ongoing" <?php echo $filterStatus==='ongoing'?'selected':''; ?>>Ongoing</option>
      <option value="pending" <?php echo $filterStatus==='pending'?'selected':''; ?>>Pending</option>
    </select>
    <button type="submit" class="btn-secondary">Filter</button>
    <a href="public_worklog.php" class="btn-secondary">Reset</a>
  </form>

  <div class="panel">
    <div class="panel-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Item</th>
            <th>Description</th>
            <th>Category</th>
            <th>Status</th>
            <th>Remarks</th>
            <th>Requested By</th>
            <th>Logged By</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($logs && $logs->num_rows > 0): ?>
            <?php while ($l = $logs->fetch_assoc()):
              $statusClass = ['done'=>'b-green','ongoing'=>'b-accent','pending'=>'b-amber'][$l['status']] ?? 'b-gray'; ?>
              <tr>
                <td><?php echo date('M j, Y', strtotime($l['log_date'])); ?></td>
                <td><?php echo h($l['log_time']); ?></td>
                <td><?php echo h($l['item_name'] ?: '—'); ?></td>
                <td style="max-width:320px; white-space:pre-wrap;"><?php echo h($l['description']); ?></td>
                <td><?php echo h($l['category_name'] ?: '—'); ?></td>
                <td><span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                <td><span class="badge <?php echo $l['remarks']==='replace'?'b-red':'b-green'; ?>"><?php echo $l['remarks']==='replace'?'Needs Replacement':'Okay'; ?></span></td>
                <td><?php echo h($l['requester'] ?: '—'); ?></td>
                <td><?php echo h($l['logged_by'] ?: '—'); ?></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr class="empty-row"><td colspan="9">No work log entries found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="public-header" style="margin-top:40px;">
    <h1>Deployments (Currently Deployed)</h1>
    <p><?php echo (int)$totalDeployedRow['c']; ?> item(s) currently deployed — updated live, view only</p>
  </div>

  <div class="panel">
    <div class="panel-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>PO #</th>
            <th>Department</th>
            <th>Qty</th>
            <th>Date Deployed</th>
            <th>Remarks</th>
            <th>Deployed By</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($deployRes && $deployRes->num_rows > 0): ?>
            <?php while ($d = $deployRes->fetch_assoc()): ?>
              <tr>
                <td><?php echo h($d['item_name']); ?></td>
                <td><span class="badge"><?php echo h($d['po_number'] ?: '—'); ?></span></td>
                <td><?php echo h($d['dept_name']); ?></td>
                <td><?php echo (int)$d['quantity_deployed']; ?></td>
                <td><?php echo date('M j, Y', strtotime($d['deployed_date'])); ?></td>
                <td><?php echo h($d['remarks'] ?: '—'); ?></td>
                <td><?php echo h($d['deployed_by_name']); ?></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr class="empty-row"><td colspan="7">No items currently deployed.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p style="text-align:center; color:var(--text-faint); font-size:11.5px; margin-top:20px;">
    This is a public read-only view.
  </p>
</div>
</body>
</html>
