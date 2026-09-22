<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$totalItemsRow = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(quantity_total),0) units FROM inventory_items")->fetch_assoc();

$deployedRow = $conn->query("SELECT COALESCE(SUM(quantity_deployed),0) units FROM deployments WHERE returned_date IS NULL")->fetch_assoc();

$totalUnits = (int)$totalItemsRow['units'];
$deployedUnits = (int)$deployedRow['units'];
$remainingUnits = max(0, $totalUnits - $deployedUnits);

$lowStockRow = $conn->query(
    "SELECT COUNT(*) c FROM inventory_items i
     WHERE (i.quantity_total - COALESCE((SELECT SUM(d.quantity_deployed) FROM deployments d WHERE d.item_id=i.id AND d.returned_date IS NULL),0)) <= i.low_stock_threshold"
)->fetch_assoc();

$todayLogsRow = $conn->query("SELECT COUNT(*) c FROM work_logs WHERE log_date = CURDATE()")->fetch_assoc();
$pendingLogsRow = $conn->query("SELECT COUNT(*) c FROM work_logs WHERE status = 'pending'")->fetch_assoc();

$staffCountRow = $conn->query("SELECT COUNT(*) c FROM users WHERE role='staff' AND status='active'")->fetch_assoc();

$recentDeploy = $conn->query(
    "SELECT d.id, i.item_name, d.po_number, d.pn_number, d.mr_number, dep.name AS dept_name, d.quantity_deployed,
            d.deployed_date, d.returned_date, d.deployment_type, u.full_name AS deployed_by_name
     FROM deployments d
     JOIN inventory_items i ON i.id = d.item_id
     JOIN departments dep ON dep.id = d.department_id
     JOIN users u ON u.id = d.deployed_by
     ORDER BY d.deployed_date DESC, d.id DESC
     LIMIT 8"
);

$allUsers = $conn->query("SELECT id, full_name, role FROM users ORDER BY role DESC, full_name");

$dashSearch = trim($_GET['dash_q'] ?? '');
$searchWorklogs = null;
$searchItems = null;
$searchDeploys = null;
if ($dashSearch !== '') {
    $like = '%' . $dashSearch . '%';

    $swl = $conn->prepare(
        "SELECT l.id, l.log_date, l.item_name, l.description, l.status, u.full_name AS created_by_name
         FROM work_logs l LEFT JOIN users u ON u.id = l.created_by
         WHERE l.description LIKE ? OR l.item_name LIKE ? OR l.requester LIKE ?
         ORDER BY l.log_date DESC LIMIT 8"
    );
    $swl->bind_param('sss', $like, $like, $like);
    $swl->execute();
    $searchWorklogs = $swl->get_result();

    $sit = $conn->prepare(
        "SELECT i.id, i.item_name, t.name AS type_name, i.quantity_total
         FROM inventory_items i JOIN item_types t ON t.id = i.item_type_id
         WHERE i.item_name LIKE ? ORDER BY i.item_name LIMIT 8"
    );
    $sit->bind_param('s', $like);
    $sit->execute();
    $searchItems = $sit->get_result();

    $sdp = $conn->prepare(
        "SELECT d.id, i.item_name, dep.name AS dept_name, d.po_number, d.pn_number, d.mr_number, d.deployed_date, d.returned_date
         FROM deployments d JOIN inventory_items i ON i.id=d.item_id JOIN departments dep ON dep.id=d.department_id
         WHERE i.item_name LIKE ? OR dep.name LIKE ? OR d.po_number LIKE ? OR d.pn_number LIKE ? OR d.mr_number LIKE ?
         ORDER BY d.deployed_date DESC LIMIT 8"
    );
    $sdp->bind_param('sssss', $like, $like, $like, $like, $like);
    $sdp->execute();
    $searchDeploys = $sdp->get_result();
}

$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$userWorkLogs = null;
$selectedUserName = '';

if ($selectedUserId > 0) {
    $uRes = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $uRes->bind_param('i', $selectedUserId);
    $uRes->execute();
    $uRow = $uRes->get_result()->fetch_assoc();
    $selectedUserName = $uRow['full_name'] ?? '';

    $stmt = $conn->prepare(
        "SELECT l.*, c.name AS category_name
         FROM work_logs l
         LEFT JOIN categories c ON c.id = l.category_id
         WHERE l.created_by = ?
         ORDER BY l.log_date DESC, l.id DESC
         LIMIT 100"
    );
    $stmt->bind_param('i', $selectedUserId);
    $stmt->execute();
    $userWorkLogs = $stmt->get_result();
}

include 'includes/header.php';
?>

<div class="panel">
  <div class="panel-body" style="padding:18px;">
    <form method="GET" class="dash-search-bar">
      <span class="dash-search-icon">🔍</span>
      <input type="text" name="dash_q" placeholder="Search work logs, inventory items, deployments..." value="<?php echo h($dashSearch); ?>" autocomplete="off">
      <button type="submit" class="btn-primary btn-sm">Search</button>
      <?php if ($dashSearch !== ''): ?><a href="index.php" class="btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($dashSearch !== ''): ?>
<div class="panel">
  <div class="panel-header"><h2>Search Results for "<?php echo h($dashSearch); ?>"</h2></div>
  <div class="panel-body">
    <div class="dash-search-group">
      <div class="dash-search-group-title">Work Log Entries (<?php echo $searchWorklogs->num_rows; ?>)</div>
      <?php if ($searchWorklogs->num_rows === 0): ?>
        <div class="dash-search-empty">No matching work log entries.</div>
      <?php else: while ($r = $searchWorklogs->fetch_assoc()): ?>
        <div class="dash-search-row">
          <span><?php echo date('M j, Y', strtotime($r['log_date'])); ?></span>
          <span><?php echo h($r['item_name'] ?: '—'); ?></span>
          <span style="flex:1; color:var(--text-dim);"><?php echo h(mb_strimwidth($r['description'], 0, 70, '…')); ?></span>
          <span class="badge"><?php echo h($r['created_by_name']); ?></span>
        </div>
      <?php endwhile; endif; ?>
    </div>

    <div class="dash-search-group">
      <div class="dash-search-group-title">Inventory Items (<?php echo $searchItems->num_rows; ?>)</div>
      <?php if ($searchItems->num_rows === 0): ?>
        <div class="dash-search-empty">No matching inventory items.</div>
      <?php else: while ($r = $searchItems->fetch_assoc()): ?>
        <div class="dash-search-row">
          <span><?php echo h($r['item_name']); ?></span>
          <span class="badge"><?php echo h($r['type_name']); ?></span>
          <span style="flex:1; color:var(--text-dim);">Qty: <?php echo (int)$r['quantity_total']; ?></span>
          <a href="inventory.php?q=<?php echo urlencode($r['item_name']); ?>" class="btn-secondary btn-sm">View →</a>
        </div>
      <?php endwhile; endif; ?>
    </div>

    <div class="dash-search-group">
      <div class="dash-search-group-title">Deployments (<?php echo $searchDeploys->num_rows; ?>)</div>
      <?php if ($searchDeploys->num_rows === 0): ?>
        <div class="dash-search-empty">No matching deployment records.</div>
      <?php else: while ($r = $searchDeploys->fetch_assoc()): ?>
        <div class="dash-search-row">
          <span><?php echo h($r['item_name']); ?></span>
          <span class="badge"><?php echo h($r['dept_name']); ?></span>
          <?php if (!empty($r['mr_number'])): ?><span class="badge">MR# <?php echo h($r['mr_number']); ?></span><?php endif; ?>
          <span style="flex:1; color:var(--text-dim);"><?php echo date('M j, Y', strtotime($r['deployed_date'])); ?></span>
          <span class="badge <?php echo $r['returned_date']?'b-green':'b-amber'; ?>"><?php echo $r['returned_date']?'Returned':'Deployed'; ?></span>
        </div>
      <?php endwhile; endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header">
    <h2>View Work Log by Person</h2>
  </div>
  <div class="panel-body">
    <form method="GET" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div class="field" style="margin-bottom:0; min-width:240px;">
        <label>Select Admin or Staff</label>
        <select name="user_id" onchange="this.form.submit()">
          <option value="0">— Select a name —</option>
          <?php while ($u = $allUsers->fetch_assoc()): ?>
            <option value="<?php echo $u['id']; ?>" <?php echo $selectedUserId==$u['id']?'selected':''; ?>>
              <?php echo h($u['full_name']); ?> (<?php echo h($u['role']); ?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <noscript><button type="submit" class="btn-secondary">Go</button></noscript>
    </form>

    <?php if ($selectedUserId > 0): ?>
      <div style="margin-top:18px;">
        <table class="data-table">
          <thead>
            <tr><th>Date</th><th>Time</th><th>Item</th><th>Description</th><th>Category</th><th>Status</th><th>Remarks</th></tr>
          </thead>
          <tbody>
            <?php if ($userWorkLogs && $userWorkLogs->num_rows > 0): ?>
              <?php while ($l = $userWorkLogs->fetch_assoc()):
                $statusClass = ['done'=>'b-green','ongoing'=>'b-accent','pending'=>'b-amber'][$l['status']] ?? 'b-gray'; ?>
                <tr>
                  <td><?php echo date('M j, Y', strtotime($l['log_date'])); ?></td>
                  <td><?php echo h($l['log_time']); ?></td>
                  <td><?php echo h($l['item_name'] ?: '—'); ?></td>
                  <td style="max-width:320px; white-space:pre-wrap;"><?php echo h($l['description']); ?></td>
                  <td><?php echo h($l['category_name'] ?: '—'); ?></td>
                  <td><span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                  <td><span class="badge <?php echo $l['remarks']==='replace'?'b-red':'b-green'; ?>"><?php echo $l['remarks']==='replace'?'Needs Replacement':'Okay'; ?></span></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr class="empty-row"><td colspan="7"><?php echo h($selectedUserName); ?> has no work log entries yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card stat-card-clickable" onclick="location.href='inventory.php'">
    <div class="stat-label">TOTAL INVENTORY ITEMS</div>
    <div class="stat-value"><?php echo (int)$totalItemsRow['c']; ?></div>
  </div>
  <div class="stat-card stat-card-clickable" onclick="location.href='inventory.php'">
    <div class="stat-label">TOTAL UNITS ON RECORD</div>
    <div class="stat-value accent"><?php echo $totalUnits; ?></div>
  </div>
  <div class="stat-card stat-card-clickable" onclick="location.href='deployments.php?status=active'">
    <div class="stat-label">UNITS DEPLOYED</div>
    <div class="stat-value amber"><?php echo $deployedUnits; ?></div>
  </div>
  <div class="stat-card stat-card-clickable" onclick="location.href='inventory.php'">
    <div class="stat-label">UNITS LEFT (IN STOCK)</div>
    <div class="stat-value green"><?php echo $remainingUnits; ?></div>
  </div>
  <div class="stat-card stat-card-clickable" onclick="location.href='worklog.php'">
    <div class="stat-label">WORK LOG ENTRIES TODAY</div>
    <div class="stat-value"><?php echo (int)$todayLogsRow['c']; ?></div>
  </div>
  <div class="stat-card stat-card-clickable" onclick="location.href='worklog.php?status=pending'">
    <div class="stat-label">PENDING TASKS</div>
    <div class="stat-value amber"><?php echo (int)$pendingLogsRow['c']; ?></div>
  </div>
  <div class="stat-card stat-card-clickable" onclick="location.href='inventory.php'">
    <div class="stat-label">LOW STOCK ITEMS</div>
    <div class="stat-value red"><?php echo (int)$lowStockRow['c']; ?></div>
  </div>
  <?php if (is_admin()): ?>
  <div class="stat-card stat-card-clickable" onclick="location.href='staff.php'">
    <div class="stat-label">ACTIVE STAFF ACCOUNTS</div>
    <div class="stat-value"><?php echo (int)$staffCountRow['c']; ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-header">
    <h2>Recent Deployments</h2>
    <a href="deployments.php" class="btn-secondary btn-sm">View All →</a>
  </div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Item</th>
          <th>PO #</th>
          <th>MR #</th>
          <th>Department</th>
          <th>Qty</th>
          <th>Date Deployed</th>
          <th>Status</th>
          <th>Deployed By</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recentDeploy && $recentDeploy->num_rows > 0): ?>
          <?php while ($d = $recentDeploy->fetch_assoc()): ?>
            <tr class="row-clickable" onclick="location.href='deployments.php?status=all#dep-<?php echo h($d['deployment_type'] ?: 'new'); ?>'" title="View in Deployments">
              <td><?php echo h($d['item_name']); ?></td>
              <td><span class="badge"><?php echo h($d['po_number'] ?: '—'); ?></span></td>
              <td><span class="badge"><?php echo h($d['mr_number'] ?: '—'); ?></span></td>
              <td><?php echo h($d['dept_name']); ?></td>
              <td><?php echo (int)$d['quantity_deployed']; ?></td>
              <td><?php echo date('M j, Y', strtotime($d['deployed_date'])); ?></td>
              <td>
                <?php if ($d['returned_date']): ?>
                  <span class="badge b-green">Returned <?php echo date('M j', strtotime($d['returned_date'])); ?></span>
                <?php else: ?>
                  <span class="badge b-amber">Deployed</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($d['deployed_by_name']); ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr class="empty-row"><td colspan="8">No deployments recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
