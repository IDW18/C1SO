<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

$pageTitle = 'Reports';
$activePage = 'reports';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_disposal_status' && is_admin()) {
    $flagId = (int)($_POST['flag_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $userId = current_user()['id'];
    if (in_array($newStatus, ['reviewed', 'disposed'], true)) {
        $stmt = $conn->prepare("UPDATE inventory_disposal_flags SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
        $stmt->bind_param('sii', $newStatus, $userId, $flagId);
        $stmt->execute();

        $infoRes = $conn->prepare(
            "SELECT i.item_name FROM inventory_disposal_flags f JOIN inventory_items i ON i.id=f.item_id WHERE f.id=?"
        );
        $infoRes->bind_param('i', $flagId);
        $infoRes->execute();
        $info = $infoRes->get_result()->fetch_assoc();
        if ($info) {
            log_activity($conn, 'Updated disposal review status', "{$info['item_name']} marked $newStatus", 'inventory');
        }
    }
    header('Location: reports.php#rep-disposal');
    exit;
}

$totalItemsRow = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(quantity_total),0) units FROM inventory_items")->fetch_assoc();
$deployedRow = $conn->query("SELECT COALESCE(SUM(quantity_deployed),0) units FROM deployments WHERE returned_date IS NULL")->fetch_assoc();
$totalUnits = (int)$totalItemsRow['units'];
$deployedUnits = (int)$deployedRow['units'];
$remainingUnits = max(0, $totalUnits - $deployedUnits);
$lowStockRow = $conn->query(
    "SELECT COUNT(*) c FROM inventory_items i
     WHERE (i.quantity_total - COALESCE((SELECT SUM(d.quantity_deployed) FROM deployments d WHERE d.item_id=i.id AND d.returned_date IS NULL),0)) <= i.low_stock_threshold"
)->fetch_assoc();
$totalWorklogRow = $conn->query("SELECT COUNT(*) c FROM work_logs")->fetch_assoc();
$pendingWorklogRow = $conn->query("SELECT COUNT(*) c FROM work_logs WHERE status = 'pending'")->fetch_assoc();

$itemReport = $conn->query(
    "SELECT i.item_name, t.name AS type_name, i.quantity_total, i.low_stock_threshold, i.date_added,
            COALESCE((SELECT SUM(d.quantity_deployed) FROM deployments d WHERE d.item_id=i.id AND d.returned_date IS NULL),0) AS deployed_qty
     FROM inventory_items i
     JOIN item_types t ON t.id = i.item_type_id
     ORDER BY t.name, i.item_name"
);

$deptReportNew = $conn->query(
    "SELECT dep.name AS dept_name, i.item_name, d.po_number, d.pn_number, d.mr_number, d.quantity_deployed, d.deployed_date
     FROM deployments d
     JOIN inventory_items i ON i.id = d.item_id
     JOIN departments dep ON dep.id = d.department_id
     WHERE d.returned_date IS NULL AND (d.deployment_type IS NULL OR d.deployment_type = 'new')
     ORDER BY dep.name, d.deployed_date DESC"
);
$deptReportReplacement = $conn->query(
    "SELECT dep.name AS dept_name, i.item_name, d.po_number, d.pn_number, d.mr_number, d.quantity_deployed, d.deployed_date
     FROM deployments d
     JOIN inventory_items i ON i.id = d.item_id
     JOIN departments dep ON dep.id = d.department_id
     WHERE d.returned_date IS NULL AND d.deployment_type = 'replacement'
     ORDER BY dep.name, d.deployed_date DESC"
);

$disposalHistory = $conn->query(
    "SELECT f.*, i.item_name, t.name AS type_name, u.full_name AS reviewed_by_name
     FROM inventory_disposal_flags f
     JOIN inventory_items i ON i.id = f.item_id
     JOIN item_types t ON t.id = i.item_type_id
     LEFT JOIN users u ON u.id = f.reviewed_by
     ORDER BY f.flagged_date DESC, f.id DESC"
);
$disposalPendingRow = $conn->query("SELECT COUNT(*) c FROM inventory_disposal_flags WHERE status = 'pending'")->fetch_assoc();

$typeSummary = $conn->query(
    "SELECT t.name AS type_name, COUNT(i.id) AS item_count, COALESCE(SUM(i.quantity_total),0) AS total_units,
            COALESCE((SELECT SUM(d.quantity_deployed) FROM deployments d JOIN inventory_items ii ON ii.id=d.item_id WHERE ii.item_type_id=t.id AND d.returned_date IS NULL),0) AS deployed_units
     FROM item_types t
     LEFT JOIN inventory_items i ON i.item_type_id = t.id
     GROUP BY t.id, t.name
     ORDER BY t.name"
);

$worklogStatusSummary = $conn->query(
    "SELECT status, COUNT(*) c FROM work_logs GROUP BY status"
);
$worklogByStatus = ['done'=>0,'ongoing'=>0,'pending'=>0];
while ($row = $worklogStatusSummary->fetch_assoc()) {
    $worklogByStatus[$row['status']] = (int)$row['c'];
}

include 'includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
  <div class="report-jumpnav">
    <a href="#rep-summary">Summary</a>
    <a href="#rep-worklog">Work Log</a>
    <a href="#rep-items">Items</a>
    <a href="#rep-departments">Departments</a>
    <a href="#rep-disposal">Disposal History</a>
  </div>
  <button type="button" class="btn-primary" onclick="window.print()">🖨 Print Report</button>
</div>

<div id="printable-report">

<div class="stat-grid" id="rep-summary" style="scroll-margin-top:20px;">
  <div class="stat-card">
    <div class="stat-label">TOTAL INVENTORY ITEMS</div>
    <div class="stat-value"><?php echo (int)$totalItemsRow['c']; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">UNITS DEPLOYED</div>
    <div class="stat-value amber"><?php echo $deployedUnits; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">UNITS LEFT (IN STOCK)</div>
    <div class="stat-value green"><?php echo $remainingUnits; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">LOW STOCK ITEMS</div>
    <div class="stat-value red"><?php echo (int)$lowStockRow['c']; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">TOTAL WORK LOG ENTRIES</div>
    <div class="stat-value"><?php echo (int)$totalWorklogRow['c']; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">PENDING TASKS</div>
    <div class="stat-value amber"><?php echo (int)$pendingWorklogRow['c']; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">DUE FOR DISPOSAL REVIEW</div>
    <div class="stat-value red"><?php echo (int)$disposalPendingRow['c']; ?></div>
  </div>
</div>

<div class="panel" id="rep-worklog" style="scroll-margin-top:20px;">
  <div class="panel-header"><h2>Work Log Summary (by Status)</h2></div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead><tr><th>Status</th><th>Count</th></tr></thead>
      <tbody>
        <tr><td><span class="badge b-green">Done</span></td><td><?php echo $worklogByStatus['done']; ?></td></tr>
        <tr><td><span class="badge b-accent">Ongoing</span></td><td><?php echo $worklogByStatus['ongoing']; ?></td></tr>
        <tr><td><span class="badge b-amber">Pending</span></td><td><?php echo $worklogByStatus['pending']; ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" id="rep-items" style="scroll-margin-top:20px;">
  <div class="panel-header"><h2>Summary by Kind of Item</h2></div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead><tr><th>Kind</th><th># Distinct Items</th><th>Total Units</th><th>Deployed</th><th>Left</th></tr></thead>
      <tbody>
        <?php if ($typeSummary->num_rows > 0): while ($t = $typeSummary->fetch_assoc()):
          $left = max(0, $t['total_units'] - $t['deployed_units']); ?>
          <tr>
            <td><?php echo h($t['type_name']); ?></td>
            <td><?php echo (int)$t['item_count']; ?></td>
            <td><?php echo (int)$t['total_units']; ?></td>
            <td><?php echo (int)$t['deployed_units']; ?></td>
            <td><b><?php echo $left; ?></b></td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="5">No item types yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2>Items Left Report (per item)</h2></div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead><tr><th>Item</th><th>Kind</th><th>Total</th><th>Deployed</th><th>Left</th><th>Status</th><th>Date Added</th></tr></thead>
      <tbody>
        <?php if ($itemReport->num_rows > 0): while ($i = $itemReport->fetch_assoc()):
          $left = max(0, $i['quantity_total'] - $i['deployed_qty']);
          $isLow = $left <= $i['low_stock_threshold'];
        ?>
          <tr>
            <td><?php echo h($i['item_name']); ?></td>
            <td><?php echo h($i['type_name']); ?></td>
            <td><?php echo (int)$i['quantity_total']; ?></td>
            <td><?php echo (int)$i['deployed_qty']; ?></td>
            <td><b><?php echo $left; ?></b></td>
            <td><span class="badge <?php echo $isLow?'b-red':'b-green'; ?>"><?php echo $isLow?'Low Stock':'Good Stock'; ?></span></td>
            <td><?php echo date('M j, Y', strtotime($i['date_added'])); ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="7">No inventory items yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" id="rep-departments" style="scroll-margin-top:20px;">
  <div class="panel-header"><h2>New Deployments — by Department</h2></div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead><tr><th>Department</th><th>Item</th><th>PO #</th><th>PN #</th><th>MR #</th><th>Qty</th><th>Date Deployed</th></tr></thead>
      <tbody>
        <?php if ($deptReportNew->num_rows > 0): while ($d = $deptReportNew->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($d['dept_name']); ?></td>
            <td><?php echo h($d['item_name']); ?></td>
            <td><span class="badge"><?php echo h($d['po_number'] ?: '—'); ?></span></td>
            <td><span class="badge"><?php echo h($d['pn_number'] ?: '—'); ?></span></td>
            <td><span class="badge"><?php echo h($d['mr_number'] ?: '—'); ?></span></td>
            <td><?php echo (int)$d['quantity_deployed']; ?></td>
            <td><?php echo date('M j, Y', strtotime($d['deployed_date'])); ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="7">No new deployments currently active.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2>Deployment for Replacement — by Department</h2></div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead><tr><th>Department</th><th>Item</th><th>PO #</th><th>PN #</th><th>MR #</th><th>Qty</th><th>Date Deployed</th></tr></thead>
      <tbody>
        <?php if ($deptReportReplacement->num_rows > 0): while ($d = $deptReportReplacement->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($d['dept_name']); ?></td>
            <td><?php echo h($d['item_name']); ?></td>
            <td><span class="badge"><?php echo h($d['po_number'] ?: '—'); ?></span></td>
            <td><span class="badge"><?php echo h($d['pn_number'] ?: '—'); ?></span></td>
            <td><span class="badge"><?php echo h($d['mr_number'] ?: '—'); ?></span></td>
            <td><?php echo (int)$d['quantity_deployed']; ?></td>
            <td><?php echo date('M j, Y', strtotime($d['deployed_date'])); ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="7">No replacement deployments currently active.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" id="rep-disposal" style="scroll-margin-top:20px;">
  <div class="panel-header">
    <h2>Inventory Disposal Review History</h2>
  </div>
  <div class="panel-body" style="padding:0;">
    <p class="field-hint" style="padding:12px 18px 0;">
      Items with no batch, deployment, or return activity for 30+ days are flagged here automatically (checked whenever the Inventory page is opened), building a running history for disposal review.
    </p>
    <table class="data-table">
      <thead><tr><th>Item</th><th>Kind</th><th>Last Activity</th><th>Days Inactive</th><th>Flagged On</th><th>Status</th><?php if (is_admin()): ?><th>Action</th><?php endif; ?></tr></thead>
      <tbody>
        <?php if ($disposalHistory->num_rows > 0): while ($f = $disposalHistory->fetch_assoc()):
          $statusClass = $f['status'] === 'pending' ? 'b-red' : ($f['status'] === 'reviewed' ? 'b-amber' : 'b-gray');
          $statusLabel = ucfirst($f['status']);
        ?>
          <tr>
            <td><?php echo h($f['item_name']); ?></td>
            <td><?php echo h($f['type_name']); ?></td>
            <td><?php echo $f['last_activity_date'] ? date('M j, Y', strtotime($f['last_activity_date'])) : '—'; ?></td>
            <td><?php echo (int)$f['days_inactive']; ?> days</td>
            <td><?php echo date('M j, Y', strtotime($f['flagged_date'])); ?></td>
            <td>
              <span class="badge <?php echo $statusClass; ?>"><?php echo h($statusLabel); ?></span>
              <?php if ($f['status'] !== 'pending' && $f['reviewed_by_name']): ?>
                <br><span style="color:var(--text-faint); font-size:11px;">by <?php echo h($f['reviewed_by_name']); ?></span>
              <?php endif; ?>
            </td>
            <?php if (is_admin()): ?>
            <td style="white-space:nowrap;">
              <?php if ($f['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="update_disposal_status">
                  <input type="hidden" name="flag_id" value="<?php echo $f['id']; ?>">
                  <input type="hidden" name="new_status" value="reviewed">
                  <button type="submit" class="btn-secondary btn-sm">Mark Reviewed</button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this item as disposed?');">
                  <input type="hidden" name="action" value="update_disposal_status">
                  <input type="hidden" name="flag_id" value="<?php echo $f['id']; ?>">
                  <input type="hidden" name="new_status" value="disposed">
                  <button type="submit" class="btn-danger btn-sm">Mark Disposed</button>
                </form>
              <?php else: ?>
                <span style="color:var(--text-faint); font-size:12px;">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="<?php echo is_admin() ? 7 : 6; ?>">No items have been flagged for disposal review yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>

<style>
  @media print {
    .sidebar, .topbar, .mobile-menu-btn, .account-menu, .report-jumpnav, .toast-container, button, .btn-primary, .btn-secondary, .btn-danger { display:none !important; }
    .content { padding:0 !important; }
    body { background:#fff !important; color:#000 !important; }
    .panel { border:1px solid #999 !important; background:#fff !important; }
    .stat-card { border:1px solid #999 !important; background:#fff !important; }
    .stat-card .stat-value { color:#000 !important; }
    table.data-table th, table.data-table td { color:#000 !important; border-color:#ccc !important; }
    .badge { border-color:#999 !important; color:#000 !important; }
  }
</style>

<?php include 'includes/footer.php'; ?>
