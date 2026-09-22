<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_admin();

$pageTitle = 'Full Activity Log';
$activePage = 'activity';

$validTabs = [
    'auth' => 'Login / Logout',
    'worklog' => 'Added Work Logs',
    'inventory' => 'Inventory Activity',
    'deployment' => 'Deployment Activity',
    'settings' => 'Settings Changes',
    'staff' => 'Staff Management',
    'account' => 'Account Changes',
];
$tab = $_GET['tab'] ?? 'auth';
if (!isset($validTabs[$tab])) $tab = 'auth';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$totalRow = $conn->prepare("SELECT COUNT(*) c FROM activity_log WHERE module = ?");
$totalRow->bind_param('s', $tab);
$totalRow->execute();
$total = (int)$totalRow->get_result()->fetch_assoc()['c'];
$totalPages = max(1, ceil($total / $perPage));

$stmt = $conn->prepare("SELECT * FROM activity_log WHERE module = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param('sii', $tab, $perPage, $offset);
$stmt->execute();
$logs = $stmt->get_result();

$tabCounts = [];
foreach (array_keys($validTabs) as $m) {
    $r = $conn->prepare("SELECT COUNT(*) c FROM activity_log WHERE module = ?");
    $r->bind_param('s', $m);
    $r->execute();
    $tabCounts[$m] = (int)$r->get_result()->fetch_assoc()['c'];
}

include 'includes/header.php';
?>

<div class="activity-tabs">
  <?php foreach ($validTabs as $key => $label): ?>
    <a href="activity.php?tab=<?php echo $key; ?>" class="activity-tab <?php echo $tab===$key?'active':''; ?>">
      <?php echo h($label); ?> <span class="activity-tab-count"><?php echo $tabCounts[$key]; ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel-header">
    <h2><?php echo h($validTabs[$tab]); ?></h2>
    <div class="field-hint">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $total; ?> total entries)</div>
  </div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead>
        <tr><th>Date/Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php if ($logs->num_rows > 0): while ($l = $logs->fetch_assoc()): ?>
          <tr>
            <td><?php echo date('M j, Y g:i a', strtotime($l['created_at'])); ?></td>
            <td><?php echo h($l['actor_name']); ?></td>
            <td><span class="badge <?php echo in_array($l['actor_role'], ['admin','superadmin'], true) ? 'b-amber' : 'b-accent'; ?>"><?php echo h($l['actor_role']); ?></span></td>
            <td><?php echo h($l['action']); ?></td>
            <td><?php echo h($l['details']); ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="5">No activity recorded in this category yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div style="display:flex; gap:8px; justify-content:center; margin-top:16px;">
  <?php if ($page > 1): ?><a href="activity.php?tab=<?php echo $tab; ?>&page=<?php echo $page-1; ?>" class="btn-secondary btn-sm">← Prev</a><?php endif; ?>
  <?php if ($page < $totalPages): ?><a href="activity.php?tab=<?php echo $tab; ?>&page=<?php echo $page+1; ?>" class="btn-secondary btn-sm">Next →</a><?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
