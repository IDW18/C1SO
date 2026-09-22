<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

$pageTitle = 'Departments';
$activePage = 'departments';
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_returned' && can_use_deployments()) {
    $deployId = (int)($_POST['deployment_id'] ?? 0);
    $returnDate = $_POST['returned_date'] ?? date('Y-m-d');
    $returnTime = $_POST['returned_time'] ?? date('H:i:s');
    $confirmUsername = trim($_POST['confirm_username'] ?? '');
    $me = current_user();
    $backDeptId = (int)($_POST['back_department_id'] ?? 0);
    $backStaff = $_POST['back_staff'] ?? '';

    if ($confirmUsername === '' || strcasecmp($confirmUsername, $me['username']) !== 0) {
        $_SESSION['dept_error'] = 'Return not confirmed — the username typed did not match your account. Please try again.';
    } else {
        $infoRes = $conn->prepare(
            "SELECT d.item_id, i.item_name, dep.name AS dept_name
             FROM deployments d JOIN inventory_items i ON i.id=d.item_id JOIN departments dep ON dep.id=d.department_id
             WHERE d.id=?"
        );
        $infoRes->bind_param('i', $deployId);
        $infoRes->execute();
        $info = $infoRes->get_result()->fetch_assoc();

        $stmt = $conn->prepare("UPDATE deployments SET returned_date = ?, returned_time = ? WHERE id = ?");
        $stmt->bind_param('ssi', $returnDate, $returnTime, $deployId);
        $stmt->execute();

        if ($info) {
            log_activity($conn, 'Marked item returned', "{$info['item_name']} from {$info['dept_name']} (confirmed by @{$confirmUsername})", 'deployment');
            $_SESSION['dept_success'] = "\"{$info['item_name']}\" from {$info['dept_name']} was returned successfully — " . date('M j, Y \a\t g:i:s A', strtotime("$returnDate $returnTime")) . '.';
        }
    }
    $backUrl = 'departments.php';
    if ($backDeptId > 0) {
        $backUrl .= '?department_id=' . $backDeptId;
        if ($backStaff !== '') $backUrl .= '&staff=' . urlencode($backStaff);
    }
    header('Location: ' . $backUrl);
    exit;
}

if (!empty($_SESSION['dept_success'])) { $successMsg = $_SESSION['dept_success']; unset($_SESSION['dept_success']); }
if (!empty($_SESSION['dept_error']))   { $errorMsg   = $_SESSION['dept_error'];   unset($_SESSION['dept_error']); }

$selectedDeptId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$selectedStaff  = trim($_GET['staff'] ?? '');
$staffSearch    = trim($_GET['staff_q'] ?? '');
$deptSearch     = trim($_GET['dept_q'] ?? '');

$deptListRes = $conn->query(
    "SELECT dep.id, dep.name,
            (SELECT COUNT(DISTINCT dp.name) FROM department_people dp WHERE dp.department_id = dep.id) AS staff_count,
            (SELECT COUNT(*) FROM deployments d WHERE d.department_id = dep.id AND d.returned_date IS NULL) AS active_deploy_count
     FROM departments dep
     ORDER BY dep.name"
);
$departments = [];
while ($row = $deptListRes->fetch_assoc()) { $departments[] = $row; }

$selectedDeptName = '';
$staffList = [];
if ($selectedDeptId > 0) {
    $dRes = $conn->prepare("SELECT name FROM departments WHERE id = ?");
    $dRes->bind_param('i', $selectedDeptId);
    $dRes->execute();
    $dRow = $dRes->get_result()->fetch_assoc();
    if ($dRow) {
        $selectedDeptName = $dRow['name'];

        $sRes = $conn->prepare(
            "SELECT dp.name,
                    (SELECT COUNT(*) FROM deployments d WHERE d.department_id = ? AND d.recipient_name = dp.name AND d.returned_date IS NULL) AS active_count,
                    (SELECT COUNT(*) FROM deployments d WHERE d.department_id = ? AND d.recipient_name = dp.name) AS total_count
             FROM department_people dp
             WHERE dp.department_id = ?
             ORDER BY dp.name"
        );
        $sRes->bind_param('iii', $selectedDeptId, $selectedDeptId, $selectedDeptId);
        $sRes->execute();
        $sResult = $sRes->get_result();
        while ($row = $sResult->fetch_assoc()) { $staffList[] = $row; }
    } else {
        $selectedDeptId = 0;
    }
}

$staffDeployments = [];
$consumptionByDeploy = [];
if ($selectedDeptId > 0 && $selectedStaff !== '') {
    $stmt = $conn->prepare(
        "SELECT d.*, i.item_name, dep.name AS dept_name, u.full_name AS deployed_by_name, u.role AS deployed_by_role
         FROM deployments d
         JOIN inventory_items i ON i.id = d.item_id
         JOIN departments dep ON dep.id = d.department_id
         JOIN users u ON u.id = d.deployed_by
         WHERE d.department_id = ? AND d.recipient_name = ?
         ORDER BY d.deployed_date DESC, d.id DESC"
    );
    $stmt->bind_param('is', $selectedDeptId, $selectedStaff);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) { $staffDeployments[] = $row; $ids[] = (int)$row['id']; }

    if (!empty($ids)) {
        $idList = implode(',', array_map('intval', $ids));
        $cres = $conn->query(
            "SELECT c.deployment_id, c.quantity, b.batch_date, b.pn_number, b.po_number
             FROM deployment_batch_consumption c
             JOIN inventory_batches b ON b.id = c.batch_id
             WHERE c.deployment_id IN ($idList)
             ORDER BY b.batch_date ASC, b.id ASC"
        );
        while ($c = $cres->fetch_assoc()) { $consumptionByDeploy[$c['deployment_id']][] = $c; }
    }
}

$deployTypeLabels = ['new' => 'New', 'replacement' => 'Replacement', 'recondition' => 'Recondition', 'pullout' => 'Pullout Unit'];
$deployTypeBadgeClass = ['new' => 'badge', 'replacement' => 'badge b-accent', 'recondition' => 'badge b-amber', 'pullout' => 'badge b-red'];

include 'includes/header.php';
?>

<?php if ($successMsg): ?><div class="alert alert-success"><?php echo h($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<div id="deployDetailsBackdrop" class="login-modal-backdrop item-details-backdrop" onclick="closeDeployDetails()">
  <div class="login-modal-card item-details-card" onclick="event.stopPropagation();">
    <button type="button" class="login-modal-close" onclick="closeDeployDetails()">&times;</button>
    <h3 class="idt-title" style="margin-bottom:14px;">Item Details</h3>
    <div id="deployDetailsBody" style="white-space:pre-wrap; font-size:13.5px; line-height:1.6; color:var(--text-dim);"></div>
  </div>
</div>

<div class="dept-breadcrumb">
  <a href="departments.php">Departments</a>
  <?php if ($selectedDeptId > 0): ?>
    <span> / </span><a href="departments.php?department_id=<?php echo $selectedDeptId; ?>"><?php echo h($selectedDeptName); ?></a>
  <?php endif; ?>
  <?php if ($selectedStaff !== ''): ?>
    <span> / </span><span><?php echo h($selectedStaff); ?></span>
  <?php endif; ?>
</div>

<?php if ($selectedDeptId === 0): ?>

  <div class="panel">
    <div class="panel-header">
      <h2>Departments</h2>
      <form method="GET" style="display:flex; gap:8px;">
        <input type="text" name="dept_q" placeholder="Search departments..." value="<?php echo h($deptSearch); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text); font-size:12.5px;">
        <button type="submit" class="btn-secondary btn-sm">Search</button>
        <?php if ($deptSearch !== ''): ?><a href="departments.php" class="btn-secondary btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="panel-body" style="padding:0;">
      <?php
      $filteredDepts = $departments;
      if ($deptSearch !== '') {
          $needle = mb_strtolower($deptSearch);
          $filteredDepts = array_values(array_filter($departments, function($d) use ($needle) {
              return mb_strpos(mb_strtolower($d['name']), $needle) !== false;
          }));
      }
      ?>
      <?php if (empty($filteredDepts)): ?>
        <div class="dash-search-empty" style="padding:20px;">No departments found.</div>
      <?php else: ?>
        <div class="dept-card-grid">
          <?php foreach ($filteredDepts as $d): ?>
            <div class="dept-card" onclick="location.href='departments.php?department_id=<?php echo $d['id']; ?>'">
              <div class="dept-card-name"><?php echo h($d['name']); ?></div>
              <div class="dept-card-meta">
                <span><?php echo (int)$d['staff_count']; ?> staff on file</span>
                <span class="badge b-amber"><?php echo (int)$d['active_deploy_count']; ?> active deployment<?php echo $d['active_deploy_count']==1?'':'s'; ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($selectedStaff === ''): ?>

  <div class="panel">
    <div class="panel-header">
      <h2><?php echo h($selectedDeptName); ?> — Staff</h2>
      <form method="GET" style="display:flex; gap:8px;">
        <input type="hidden" name="department_id" value="<?php echo $selectedDeptId; ?>">
        <input type="text" name="staff_q" placeholder="Search staff..." value="<?php echo h($staffSearch); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text); font-size:12.5px;">
        <button type="submit" class="btn-secondary btn-sm">Search</button>
        <?php if ($staffSearch !== ''): ?><a href="departments.php?department_id=<?php echo $selectedDeptId; ?>" class="btn-secondary btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="panel-body" style="padding:0;">
      <?php
      $filteredStaff = $staffList;
      if ($staffSearch !== '') {
          $needle = mb_strtolower($staffSearch);
          $filteredStaff = array_values(array_filter($staffList, function($s) use ($needle) {
              return mb_strpos(mb_strtolower($s['name']), $needle) !== false;
          }));
      }
      ?>
      <?php if (empty($filteredStaff)): ?>
        <div class="dash-search-empty" style="padding:20px;">
          No faculty/staff on file for this department yet.
          <?php if (is_admin()): ?><a href="settings.php">Add names on the Settings page</a>.<?php endif; ?>
        </div>
      <?php else: ?>
        <div class="dept-card-grid">
          <?php foreach ($filteredStaff as $s): ?>
            <div class="dept-card" onclick="location.href='departments.php?department_id=<?php echo $selectedDeptId; ?>&amp;staff=<?php echo urlencode($s['name']); ?>'">
              <div class="dept-card-name"><?php echo h($s['name']); ?></div>
              <div class="dept-card-meta">
                <span><?php echo (int)$s['total_count']; ?> deployment<?php echo $s['total_count']==1?'':'s'; ?> total</span>
                <?php if ($s['active_count'] > 0): ?>
                  <span class="badge b-amber"><?php echo (int)$s['active_count']; ?> still deployed</span>
                <?php else: ?>
                  <span class="badge b-green">All returned</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <div class="panel">
    <div class="panel-header">
      <h2><?php echo h($selectedStaff); ?> — Deployment History</h2>
      <span class="badge"><?php echo h($selectedDeptName); ?></span>
    </div>
    <div class="panel-body">
      <?php if (empty($staffDeployments)): ?>
        <div class="dash-search-empty">No deployments on file for this person yet.</div>
      <?php else: ?>
        <div class="user-card-grid" style="grid-template-columns:1fr;">
          <div class="user-card">
            <div class="user-card-body">
              <?php foreach ($staffDeployments as $d):
                $type = $d['deployment_type'] ?: 'new';
                $typeLabel = $deployTypeLabels[$type] ?? ucfirst($type);
                $typeBadgeClass = $deployTypeBadgeClass[$type] ?? 'badge';
              ?>
                <div class="deploy-entry">
                  <div class="deploy-entry-top">
                    <span class="<?php echo $typeBadgeClass; ?>"><?php echo h($typeLabel); ?></span>
                    <span class="deploy-entry-date"><?php echo date('M j, Y', strtotime($d['deployed_date'])); ?><?php echo !empty($d['deployed_time']) ? ' · ' . date('g:i A', strtotime($d['deployed_time'])) : ''; ?></span>
                    <?php if ($d['returned_date']): ?>
                      <span class="badge b-green">Returned <?php echo date('M j, Y', strtotime($d['returned_date'])); ?><?php echo !empty($d['returned_time']) ? ' · ' . date('g:i A', strtotime($d['returned_time'])) : ''; ?></span>
                    <?php else: ?>
                      <span class="badge b-amber">Still deployed</span>
                    <?php endif; ?>
                    <?php if (!empty($d['po_number'])): ?><span class="badge">PO# <?php echo h($d['po_number']); ?></span><?php endif; ?>
                    <?php if (!empty($d['pn_number'])): ?><span class="badge">PN# <?php echo h($d['pn_number']); ?></span><?php endif; ?>
                    <?php if (!empty($d['mr_number'])): ?><span class="badge">MR# <?php echo h($d['mr_number']); ?></span><?php endif; ?>
                  </div>
                  <div class="deploy-entry-item"><?php echo h($d['item_name']); ?> <span style="color:var(--text-faint); font-weight:400;">×<?php echo (int)$d['quantity_deployed']; ?></span></div>
                  <div class="deploy-entry-meta">Deployed by <?php echo h($d['deployed_by_name']); ?><?php echo $d['remarks'] ? ' — ' . h($d['remarks']) : ''; ?></div>
                  <?php $consumed = $consumptionByDeploy[$d['id']] ?? []; if ($consumed): ?>
                    <div class="deploy-entry-meta" style="font-size:11px; color:var(--text-faint); margin-top:2px;">
                      Drawn from stock (oldest first):
                      <?php echo implode('; ', array_map(function($c) {
                        $label = date('M j, Y', strtotime($c['batch_date']));
                        if (!empty($c['pn_number'])) $label .= ' · PN #' . h($c['pn_number']);
                        if (!empty($c['po_number'])) $label .= ' · PO #' . h($c['po_number']);
                        return (int)$c['quantity'] . ' from batch dated ' . $label;
                      }, $consumed)); ?>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($d['item_details'])): ?>
                    <div class="deploy-entry-meta deploy-details-link" onclick="showDeployDetails(<?php echo (int)$d['id']; ?>)" style="cursor:pointer; text-decoration:underline; margin-top:4px;">View Details</div>
                    <div class="deploy-details-hidden" id="deployDetails<?php echo (int)$d['id']; ?>" style="display:none;"><?php echo h($d['item_details']); ?></div>
                  <?php endif; ?>
                  <?php if (!$d['returned_date'] && can_use_deployments()): ?>
                    <form method="POST" class="return-confirm-form" style="display:flex; gap:6px; align-items:center; margin-top:8px; flex-wrap:wrap;">
                      <input type="hidden" name="action" value="mark_returned">
                      <input type="hidden" name="deployment_id" value="<?php echo $d['id']; ?>">
                      <input type="hidden" name="confirm_username" class="return-confirm-username-field">
                      <input type="hidden" name="back_department_id" value="<?php echo $selectedDeptId; ?>">
                      <input type="hidden" name="back_staff" value="<?php echo h($selectedStaff); ?>">
                      <input type="date" name="returned_date" value="<?php echo date('Y-m-d'); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:5px 7px; color:var(--text); font-size:12px;">
                      <input type="time" name="returned_time" value="<?php echo date('H:i'); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:5px 7px; color:var(--text); font-size:12px;">
                      <button type="submit" class="btn-secondary btn-sm">Return</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<style>
  .dept-breadcrumb{ font-size:12.5px; color:var(--text-faint); margin-bottom:14px; }
  .dept-breadcrumb a{ color:var(--accent-bright); text-decoration:none; }
  .dept-breadcrumb a:hover{ text-decoration:underline; }
  .dept-card-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(230px,1fr)); gap:12px; padding:16px; }
  .dept-card{
    background:var(--panel-2); border:1px solid var(--border-soft); border-radius:8px;
    padding:14px 16px; cursor:pointer; transition:border-color .12s, transform .12s;
  }
  .dept-card:hover{ border-color:var(--accent-bright); transform:translateY(-1px); }
  .dept-card-name{ font-size:14px; font-weight:600; margin-bottom:8px; }
  .dept-card-meta{ display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:12px; color:var(--text-faint); }
</style>

<script>
  var CURRENT_USERNAME = <?php echo json_encode(current_user()['username']); ?>;

  document.querySelectorAll('.return-confirm-form').forEach(function(form){
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var typed = window.prompt('To confirm this return, please type your username (' + CURRENT_USERNAME + '):');
      if (typed === null) return;
      if (typed.trim().toLowerCase() !== CURRENT_USERNAME.toLowerCase()) {
        alert('That username did not match. Return not confirmed.');
        return;
      }
      form.querySelector('.return-confirm-username-field').value = typed.trim();
      form.submit();
    });
  });

  function showDeployDetails(deployId){
    var el = document.getElementById('deployDetails' + deployId);
    if (!el) return;
    var text = el.textContent || el.innerText || 'No details.';
    var backdrop = document.getElementById('deployDetailsBackdrop');
    document.getElementById('deployDetailsBody').textContent = text;
    backdrop.classList.add('open');
    requestAnimationFrame(function(){ backdrop.classList.add('show'); });
  }
  function closeDeployDetails(){
    var backdrop = document.getElementById('deployDetailsBackdrop');
    backdrop.classList.remove('show');
    setTimeout(function(){ backdrop.classList.remove('open'); }, 180);
  }
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      var backdrop = document.getElementById('deployDetailsBackdrop');
      if (backdrop && backdrop.classList.contains('open')) closeDeployDetails();
    }
  });
</script>

<?php include 'includes/footer.php'; ?>
