<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/spec_schema.php';
require_login();

$pageTitle = 'Deployments';
$activePage = 'deployments';
$successMsg = '';
$errorMsg = '';

function deploy_one_item($conn, $userId, $itemId, $deptId, $recipientName, $poNumber, $pnNumber, $qty, $date, $time, $remarks, $deploymentType = 'new', $replacesDeploymentId = null, $itemDetails = '', $mrNumber = '') {
    $itemRow = $conn->prepare(
        "SELECT item_name, quantity_total,
                COALESCE((SELECT SUM(d.quantity_deployed) FROM deployments d WHERE d.item_id = i.id AND d.returned_date IS NULL), 0) AS deployed_qty
         FROM inventory_items i WHERE i.id = ?"
    );
    $itemRow->bind_param('i', $itemId);
    $itemRow->execute();
    $item = $itemRow->get_result()->fetch_assoc();

    if (!$item || $deptId === 0) {
        return ['ok' => false, 'error' => 'Please select a valid item and department.', 'item_name' => null, 'deployment_id' => null];
    }
    if ($poNumber === '') {
        return ['ok' => false, 'error' => 'PO Number is required for every item.', 'item_name' => $item['item_name'], 'deployment_id' => null];
    }
    if ($pnNumber === '') {
        return ['ok' => false, 'error' => 'PN Number is required.', 'item_name' => $item['item_name'], 'deployment_id' => null];
    }

    $available = $item['quantity_total'] - $item['deployed_qty'];
    if ($qty > $available) {
        return ['ok' => false, 'error' => "Only $available unit(s) of \"{$item['item_name']}\" left to deploy.", 'item_name' => $item['item_name'], 'deployment_id' => null];
    }

    $stmt = $conn->prepare(
        "INSERT INTO deployments (item_id, department_id, recipient_name, po_number, pn_number, mr_number, quantity_deployed, deployment_type, replaces_deployment_id, deployed_date, deployed_time, remarks, item_details, deployed_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    $stmt->bind_param('iissssisissssi', $itemId, $deptId, $recipientName, $poNumber, $pnNumber, $mrNumber, $qty, $deploymentType, $replacesDeploymentId, $date, $time, $remarks, $itemDetails, $userId);
    $stmt->execute();
    $deploymentId = $stmt->insert_id;

    if ($deploymentType === 'replacement' && $replacesDeploymentId) {
        $ret = $conn->prepare("UPDATE deployments SET returned_date = ?, returned_time = ? WHERE id = ? AND returned_date IS NULL");
        $ret->bind_param('ssi', $date, $time, $replacesDeploymentId);
        $ret->execute();
    }

    $batchesRes = $conn->prepare(
        "SELECT b.id, b.quantity,
                b.quantity - COALESCE((
                  SELECT SUM(c.quantity) FROM deployment_batch_consumption c
                  JOIN deployments dd ON dd.id = c.deployment_id
                  WHERE c.batch_id = b.id AND dd.returned_date IS NULL
                ), 0) AS batch_left
         FROM inventory_batches b
         WHERE b.item_id = ?
         ORDER BY b.batch_date ASC, b.id ASC"
    );
    $batchesRes->bind_param('i', $itemId);
    $batchesRes->execute();
    $batches = $batchesRes->get_result()->fetch_all(MYSQLI_ASSOC);

    $remaining = $qty;
    foreach ($batches as $batch) {
        if ($remaining <= 0) break;
        $batchLeft = (int)$batch['batch_left'];
        if ($batchLeft <= 0) continue;
        $take = min($batchLeft, $remaining);
        $cstmt = $conn->prepare("INSERT INTO deployment_batch_consumption (deployment_id, batch_id, quantity) VALUES (?,?,?)");
        $cstmt->bind_param('iii', $deploymentId, $batch['id'], $take);
        $cstmt->execute();
        $remaining -= $take;
    }

    $deptNameRes = $conn->prepare("SELECT name FROM departments WHERE id=?");
    $deptNameRes->bind_param('i', $deptId);
    $deptNameRes->execute();
    $deptName = $deptNameRes->get_result()->fetch_assoc()['name'] ?? '';

    log_activity(
        $conn, 'Deployed item',
        "{$item['item_name']} x$qty to $deptName" . ($recipientName ? " (for $recipientName)" : '') . " (PO #$poNumber, PN #$pnNumber" . ($mrNumber !== '' ? ", MR #$mrNumber" : '') . ")",
        'deployment'
    );

    return ['ok' => true, 'error' => null, 'item_name' => $item['item_name'], 'deployment_id' => $deploymentId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deploy_item' && can_use_deployments()) {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $deptId = (int)($_POST['department_id'] ?? 0);
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $poNumber = trim($_POST['po_number'] ?? '');
    $pnNumber = trim($_POST['pn_number'] ?? '');
    $qty    = max(1, (int)($_POST['quantity_deployed'] ?? 1));
    $date   = $_POST['deployed_date'] ?? date('Y-m-d');
    $time   = $_POST['deployed_time'] ?? date('H:i:s');
    $remarks = trim($_POST['remarks'] ?? '');
    $userId = current_user()['id'];

    $result = deploy_one_item($conn, $userId, $itemId, $deptId, $recipientName, $poNumber, $pnNumber, $qty, $date, $time, $remarks);
    if (!$result['ok']) {
        $errorMsg = $result['error'];
    } else {
        $successMsg = 'Item deployed successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_deployment_cart' && can_use_deployments()) {
    header('Content-Type: application/json');
    $userId = current_user()['id'];
    $deptId = (int)($_POST['department_id'] ?? 0);
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $pnNumber = trim($_POST['ref_number'] ?? '');
    $date = $_POST['deployed_date'] ?? date('Y-m-d');
    $time = $_POST['deployed_time'] ?? date('H:i:s');
    $mrNumber = trim($_POST['mr_number'] ?? '');

    $specKind = trim($_POST['spec_kind'] ?? '');
    $specValuesRaw = json_decode($_POST['spec_values'] ?? '{}', true);
    if (!is_array($specValuesRaw)) $specValuesRaw = [];

    $specFields = [];
    if ($specKind !== '' && isset(SPEC_SCHEMA[$specKind])) {
        foreach (SPEC_SCHEMA[$specKind] as $key => $meta) {
            $specFields[$key] = isset($specValuesRaw[$key]) ? trim((string)$specValuesRaw[$key]) : '';
        }
    }
    $hasSpecs = count(array_filter($specFields, function($v){ return $v !== ''; })) > 0;
    $deploymentType = $_POST['deployment_type'] ?? 'new';
    if (!in_array($deploymentType, ['new', 'replacement'], true)) $deploymentType = 'new';
    $replacesDeploymentId = (int)($_POST['replaces_deployment_id'] ?? 0);
    $cartJson = $_POST['cart'] ?? '[]';
    $cart = json_decode($cartJson, true);

    if ($deptId === 0) {
        echo json_encode(['ok' => false, 'error' => 'Please select a department.']);
        exit;
    }

    $deptCheck = $conn->prepare("SELECT id FROM departments WHERE id = ?");
    $deptCheck->bind_param('i', $deptId);
    $deptCheck->execute();
    if (!$deptCheck->get_result()->fetch_assoc()) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid department.']);
        exit;
    }
    if ($recipientName === '') {
        echo json_encode(['ok' => false, 'error' => 'Please select a faculty/staff name.']);
        exit;
    }

    $personCheck = $conn->prepare("SELECT 1 FROM department_people WHERE department_id = ? AND name = ? LIMIT 1");
    $personCheck->bind_param('is', $deptId, $recipientName);
    $personCheck->execute();
    if (!$personCheck->get_result()->fetch_assoc()) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid faculty/staff name for this department.']);
        exit;
    }
    if ($pnNumber === '') {
        echo json_encode(['ok' => false, 'error' => 'Please select a PN number.']);
        exit;
    }
    if ($mrNumber === '') {
        echo json_encode(['ok' => false, 'error' => 'Please enter an MR (Material Request) Number.']);
        exit;
    }

    $pnCheck = $conn->prepare("SELECT 1 FROM inventory_batches WHERE pn_number = ? LIMIT 1");
    $pnCheck->bind_param('s', $pnNumber);
    $pnCheck->execute();
    if (!$pnCheck->get_result()->fetch_assoc()) {
        echo json_encode(['ok' => false, 'error' => 'That PN number was not found on file.']);
        exit;
    }

    if ($deploymentType === 'replacement') {
        if ($replacesDeploymentId === 0) {
            echo json_encode(['ok' => false, 'error' => 'Please select which deployed item is being replaced.']);
            exit;
        }
        $oldCheck = $conn->prepare(
            "SELECT id FROM deployments WHERE id = ? AND department_id = ? AND recipient_name = ? AND returned_date IS NULL"
        );
        $oldCheck->bind_param('iis', $replacesDeploymentId, $deptId, $recipientName);
        $oldCheck->execute();
        if (!$oldCheck->get_result()->fetch_assoc()) {
            echo json_encode(['ok' => false, 'error' => 'The item selected for replacement was not found or is no longer active.']);
            exit;
        }
    } else {
        $replacesDeploymentId = null;
    }
    if (!is_array($cart) || empty($cart)) {
        echo json_encode(['ok' => false, 'error' => 'Add at least one item before deploying.']);
        exit;
    }

    $deployedCount = 0;
    $errors = [];
    $firstDeploymentId = null;
    foreach ($cart as $line) {
        $itemId = (int)($line['item_id'] ?? 0);

        $poNum = trim($line['po_number'] ?? '');
        $itemDetails = trim($line['item_details'] ?? '');
        if ($poNum === '') {
            $errors[] = 'Please select a PO Number for every item in the cart.';
            continue;
        }

        $poCheck = $conn->prepare("SELECT 1 FROM inventory_batches WHERE item_id = ? AND po_number = ? LIMIT 1");
        $poCheck->bind_param('is', $itemId, $poNum);
        $poCheck->execute();
        if (!$poCheck->get_result()->fetch_assoc()) {
            $errors[] = 'That PO Number was not found on file for the selected item.';
            continue;
        }
        $result = deploy_one_item($conn, $userId, $itemId, $deptId, $recipientName, $poNum, $pnNumber, 1, $date, $time, '', $deploymentType, $replacesDeploymentId, $itemDetails, $mrNumber);
        if ($result['ok']) {
            $deployedCount++;
            if ($firstDeploymentId === null) $firstDeploymentId = $result['deployment_id'];
        } else {
            $errors[] = $result['error'];
        }
    }

    if ($hasSpecs && $firstDeploymentId !== null) {
        save_spec_values($conn, 'deployment_spec_values', 'deployment_id', $firstDeploymentId, $specFields);
    }

    if ($deployedCount > 0) {
        echo json_encode(['ok' => true, 'deployed' => $deployedCount, 'errors' => $errors]);
    } else {
        echo json_encode(['ok' => false, 'error' => $errors[0] ?? 'Could not deploy the selected items.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'items_by_category' && can_use_deployments()) {
    header('Content-Type: application/json');
    $typeId = (int)($_GET['type_id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT i.id, i.item_name,
                (i.quantity_total - COALESCE((SELECT SUM(dd.quantity_deployed) FROM deployments dd WHERE dd.item_id=i.id AND dd.returned_date IS NULL),0)) AS available
         FROM inventory_items i
         WHERE i.item_type_id = ?
         HAVING available > 0
         ORDER BY i.item_name"
    );
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = ['id' => (int)$row['id'], 'name' => $row['item_name'], 'available' => (int)$row['available']];
    }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'po_numbers_for_item' && can_use_deployments()) {
    header('Content-Type: application/json');
    $itemId = (int)($_GET['item_id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT DISTINCT po_number FROM inventory_batches
         WHERE item_id = ? AND po_number IS NOT NULL AND po_number <> ''
         ORDER BY po_number"
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $res = $stmt->get_result();
    $numbers = [];
    while ($row = $res->fetch_assoc()) {
        $numbers[] = $row['po_number'];
    }
    echo json_encode(['ok' => true, 'po_numbers' => $numbers]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'preset_specs_for_item' && can_use_deployments()) {
    header('Content-Type: application/json');
    $itemId = (int)($_GET['item_id'] ?? 0);
    $specs = load_spec_values($conn, 'inventory_item_spec_values', 'item_id', $itemId);
    echo json_encode(['ok' => true, 'specs' => $specs]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'current_ph_time' && can_use_deployments()) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'date' => date('Y-m-d'), 'time' => date('H:i')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'pn_numbers' && can_use_deployments()) {
    header('Content-Type: application/json');
    $res = $conn->query(
        "SELECT DISTINCT pn_number FROM inventory_batches
         WHERE pn_number IS NOT NULL AND pn_number <> ''
         ORDER BY pn_number"
    );
    $numbers = [];
    while ($row = $res->fetch_assoc()) {
        $numbers[] = $row['pn_number'];
    }
    echo json_encode(['ok' => true, 'pn_numbers' => $numbers]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'active_deployments_for_person' && can_use_deployments()) {
    header('Content-Type: application/json');
    $deptId = (int)($_GET['department_id'] ?? 0);
    $recipientName = trim($_GET['recipient_name'] ?? '');
    if ($deptId === 0 || $recipientName === '') {
        echo json_encode(['ok' => true, 'items' => []]);
        exit;
    }
    $stmt = $conn->prepare(
        "SELECT d.id, i.item_name, d.po_number, d.pn_number, d.deployed_date, d.deployed_time
         FROM deployments d
         JOIN inventory_items i ON i.id = d.item_id
         WHERE d.department_id = ? AND d.recipient_name = ? AND d.returned_date IS NULL
         ORDER BY d.deployed_date DESC, d.id DESC"
    );
    $stmt->bind_param('is', $deptId, $recipientName);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id'              => (int)$row['id'],
            'item_name'       => $row['item_name'],
            'po_number'       => $row['po_number'] ?: '',
            'pn_number'       => $row['pn_number'] ?: '',
            'deployed_date'   => $row['deployed_date'] ? date('M j, Y', strtotime($row['deployed_date'])) : '',
            'deployed_time'   => $row['deployed_time'] ? date('g:i A', strtotime($row['deployed_time'])) : '',
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_returned' && can_use_deployments()) {
    $deployId = (int)($_POST['deployment_id'] ?? 0);
    $returnDate = $_POST['returned_date'] ?? date('Y-m-d');
    $returnTime = $_POST['returned_time'] ?? date('H:i:s');
    $confirmUsername = trim($_POST['confirm_username'] ?? '');
    $me = current_user();

    if ($confirmUsername === '' || strcasecmp($confirmUsername, $me['username']) !== 0) {
        $errorMsg = 'Return not confirmed — the username typed did not match your account. Please try again.';
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
            $_SESSION['return_success'] = "\"{$info['item_name']}\" from {$info['dept_name']} was returned successfully — " . date('M j, Y \a\t g:i:s A', strtotime("$returnDate $returnTime")) . '.';
        }
        header('Location: deployments.php');
        exit;
    }
}

if (!empty($_SESSION['return_success'])) {
    $successMsg = $_SESSION['return_success'];
    unset($_SESSION['return_success']);
}

$filterStatus = $_GET['status'] ?? 'active';
$where = '';
if ($filterStatus === 'active') $where = 'WHERE d.returned_date IS NULL';
elseif ($filterStatus === 'returned') $where = 'WHERE d.returned_date IS NOT NULL';

$deployRes = $conn->query(
    "SELECT d.*, i.item_name, dep.name AS dept_name, u.full_name AS deployed_by_name, u.role AS deployed_by_role
     FROM deployments d
     JOIN inventory_items i ON i.id = d.item_id
     JOIN departments dep ON dep.id = d.department_id
     JOIN users u ON u.id = d.deployed_by
     $where
     ORDER BY u.full_name, d.deployed_date DESC, d.id DESC"
);

$groupedDeploysByType = ['new' => [], 'replacement' => []];
$allDeployIds = [];
while ($d = $deployRes->fetch_assoc()) {
    $type = $d['deployment_type'] ?? 'new';
    if (!isset($groupedDeploysByType[$type])) $type = 'new';
    $key = $d['deployed_by_name'] ?: 'Unknown';
    $groupedDeploysByType[$type][$key][] = $d;
    $allDeployIds[] = (int)$d['id'];
}

$consumptionByDeploy = [];
if (!empty($allDeployIds)) {
    $idList = implode(',', array_map('intval', $allDeployIds));
    $cres = $conn->query(
        "SELECT c.deployment_id, c.quantity, b.batch_date, b.pn_number, b.po_number
         FROM deployment_batch_consumption c
         JOIN inventory_batches b ON b.id = c.batch_id
         WHERE c.deployment_id IN ($idList)
         ORDER BY b.batch_date ASC, b.id ASC"
    );
    while ($c = $cres->fetch_assoc()) {
        $consumptionByDeploy[$c['deployment_id']][] = $c;
    }
}

$availableItems = $conn->query(
    "SELECT i.id, i.item_name,
            (i.quantity_total - COALESCE((SELECT SUM(dd.quantity_deployed) FROM deployments dd WHERE dd.item_id=i.id AND dd.returned_date IS NULL),0)) AS available
     FROM inventory_items i
     HAVING available > 0
     ORDER BY i.item_name"
);
$departments = $conn->query("SELECT * FROM departments ORDER BY name");
$itemTypes = $conn->query("SELECT * FROM item_types ORDER BY name");

$deptListPayload = [];
$deptRows = $conn->query("SELECT * FROM departments ORDER BY name");
while ($dRow = $deptRows->fetch_assoc()) {
    $deptListPayload[] = ['id' => (int)$dRow['id'], 'name' => $dRow['name']];
}
$peopleByDept = [];
$peopleRows = $conn->query("SELECT * FROM department_people ORDER BY name");
while ($pRow = $peopleRows->fetch_assoc()) {
    $peopleByDept[$pRow['department_id']][] = ['id' => (int)$pRow['id'], 'name' => $pRow['name']];
}
$categoryListPayload = [];
$itemTypes->data_seek(0);
while ($tRow = $itemTypes->fetch_assoc()) {
    $categoryListPayload[] = ['id' => (int)$tRow['id'], 'name' => $tRow['name']];
}

$peripheralTypeIds = ['keyboard' => null, 'mouse' => null, 'monitor' => null];
$periphRes = $conn->query("SELECT id, name FROM item_types WHERE LOWER(name) IN ('keyboard','mouse','monitor')");
while ($pr = $periphRes->fetch_assoc()) {
    $peripheralTypeIds[strtolower($pr['name'])] = (int)$pr['id'];
}

$quickPickTypeIds = [];
foreach (SPEC_KIND_ITEM_TYPE_NAMES as $specKey => $typeName) {
    $quickPickTypeIds[$specKey] = null;
}
$qpRes = $conn->query("SELECT id, name FROM item_types");
while ($qr = $qpRes->fetch_assoc()) {
    $specKey = spec_schema_key_for_type_name($qr['name']);
    if ($specKey !== null) {
        $quickPickTypeIds[$specKey] = (int)$qr['id'];
    }
}

$pnListPayload = [];
$pnRows = $conn->query(
    "SELECT DISTINCT pn_number FROM inventory_batches
     WHERE pn_number IS NOT NULL AND pn_number <> ''
     ORDER BY pn_number"
);
while ($pnRow = $pnRows->fetch_assoc()) {
    $pnListPayload[] = $pnRow['pn_number'];
}

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

<?php if (can_use_deployments()): ?>
<div class="panel">
  <div class="panel-header"><h2>Deploy Items</h2></div>
  <div class="panel-body">
    <p class="field-hint" style="margin-bottom:12px;">Open the deployment wizard to deploy items to a department. If you leave for Inventory partway through, your progress is kept — just reopen this to continue where you left off.</p>
    <button type="button" class="btn-primary" onclick="openDeployWizard()">+ Start / Continue Deployment</button>
  </div>
</div>

<div id="deployWizardBackdrop" class="login-modal-backdrop item-details-backdrop">
  <div class="login-modal-card item-details-card wiz-modal-card" onclick="event.stopPropagation();">
    <button type="button" class="login-modal-close" onclick="closeDeployWizard()">&times;</button>
    <h3 class="idt-title" style="margin-bottom:14px;">Deploy Items</h3>

    <div class="wiz-steps" id="wizSteps">
      <div class="wiz-step active" data-step="0"><span class="wiz-step-num">1</span> Type</div>
      <div class="wiz-step" data-step="1"><span class="wiz-step-num">2</span> Department</div>
      <div class="wiz-step" data-step="2"><span class="wiz-step-num">3</span> Faculty/Staff</div>
      <div class="wiz-step" data-step="0b"><span class="wiz-step-num">4</span> Kind of Item</div>
      <div class="wiz-step wiz-step-replace" data-step="2b" style="display:none;"><span class="wiz-step-num">5</span> Item to Replace</div>
      <div class="wiz-step" data-step="3"><span class="wiz-step-num">6</span> PN Number</div>
      <div class="wiz-step" data-step="4"><span class="wiz-step-num">7</span> Items</div>
      <div class="wiz-step" data-step="5"><span class="wiz-step-num">8</span> Ready</div>
    </div>

    <div class="wiz-panel active" id="wizStep0">
      <p class="field-hint" style="margin-bottom:14px;">What kind of deployment is this?</p>
      <div class="wiz-choice-grid">
        <div class="wiz-choice-card" id="wizChoiceNew" onclick="wizSelectDeploymentType('new')">New Deployment</div>
        <div class="wiz-choice-card" id="wizChoiceReplacement" onclick="wizSelectDeploymentType('replacement')">Deployment for Replacement</div>
      </div>
    </div>

    <div class="wiz-panel" id="wizStep1">
      <p class="field-hint" style="margin-bottom:12px;">Select a department.</p>
      <input type="text" class="wiz-search-box" id="wizDeptSearch" placeholder="Search department..." oninput="wizFilterList('wizDeptList', this.value)">
      <div class="wiz-select-list" id="wizDeptList">
        <?php foreach ($deptListPayload as $dept): ?>
          <div class="wiz-select-item" data-id="<?php echo $dept['id']; ?>" data-name="<?php echo h($dept['name']); ?>" data-search="<?php echo strtolower(h($dept['name'])); ?>" onclick="wizSelectDepartment(<?php echo $dept['id']; ?>, this.getAttribute('data-name'))"><?php echo h($dept['name']); ?></div>
        <?php endforeach; ?>
        <?php if (empty($deptListPayload)): ?><div class="wiz-select-empty">No departments on file yet. Ask an admin to add one on the Settings page.</div><?php endif; ?>
      </div>
      <div class="wiz-nav"><button type="button" class="btn-secondary btn-sm" onclick="wizGoStep(0)">← Back</button></div>
    </div>

    <div class="wiz-panel" id="wizStep2">
      <p class="field-hint" style="margin-bottom:12px;">Who is this item for? Select a name for <b id="wizStep2DeptName"></b>.</p>
      <input type="text" class="wiz-search-box" id="wizPersonSearch" placeholder="Search faculty/staff..." oninput="wizFilterList('wizPersonList', this.value)">
      <div class="wiz-select-list" id="wizPersonList"></div>
      <div class="wiz-nav"><button type="button" class="btn-secondary btn-sm" onclick="wizGoStep(1)">← Back</button></div>
    </div>

    <div class="wiz-panel" id="wizStep0b">
      <p class="field-hint" style="margin-bottom:14px;">What kind of item is this?</p>
      <div class="wiz-choice-grid">
        <div class="wiz-choice-card" id="wizChoicePc" onclick="wizSelectQuickPickKind('pc')" <?php echo $quickPickTypeIds['pc'] === null ? 'style="opacity:.5; cursor:not-allowed;" title="No \'PC/System Unit\' item type in Settings"' : ''; ?>>PC</div>
        <div class="wiz-choice-card" id="wizChoiceLaptop" onclick="wizSelectQuickPickKind('laptop')" <?php echo $quickPickTypeIds['laptop'] === null ? 'style="opacity:.5; cursor:not-allowed;" title="No \'Laptop\' item type in Settings"' : ''; ?>>Laptop</div>
        <div class="wiz-choice-card" id="wizChoiceProjector" onclick="wizSelectQuickPickKind('projector')" <?php echo $quickPickTypeIds['projector'] === null ? 'style="opacity:.5; cursor:not-allowed;" title="No \'Projector\' item type in Settings"' : ''; ?>>Projector</div>
      </div>
      <div class="wiz-nav"><button type="button" class="btn-secondary btn-sm" onclick="wizGoStep(2)">← Back</button></div>
    </div>

    <div class="wiz-panel" id="wizStep2b">
      <p class="field-hint" style="margin-bottom:12px;">Select the currently deployed item that is being replaced for <b id="wizStep2bPersonName"></b>. It will be automatically marked as Returned.</p>
      <div class="wiz-select-list" id="wizReplaceList"></div>
      <div class="wiz-nav"><button type="button" class="btn-secondary btn-sm" onclick="wizGoStep('0b')">← Back</button></div>
    </div>

    <div class="wiz-panel" id="wizStep3">
      <p class="field-hint" style="margin-bottom:12px;">Select the PN number this deployment is covered by, and enter the MR (Material Request) Number for this deployment. <b>Both required.</b></p>
      <input type="text" class="wiz-search-box" id="wizPnSearch" placeholder="Search PN number..." oninput="wizFilterList('wizPoList', this.value)">
      <div class="wiz-select-list" id="wizPoList">
        <?php foreach ($pnListPayload as $pn): ?>
          <div class="wiz-select-item" data-val="<?php echo h($pn); ?>" data-search="<?php echo strtolower(h($pn)); ?>" onclick="wizSelectPo(this.getAttribute('data-val'))"><?php echo h($pn); ?></div>
        <?php endforeach; ?>
        <?php if (empty($pnListPayload)): ?><div class="wiz-select-empty">No PN numbers on file yet.</div><?php endif; ?>
      </div>
      <input type="hidden" id="wizRefNumber">
      <div class="field" style="margin-top:14px;">
        <label>MR Number</label>
        <input type="text" id="wizMrNumber" placeholder="e.g. MR-2026-0032" oninput="wizOnMrNumberInput()" style="width:100%; box-sizing:border-box; background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:9px 11px; color:var(--text); font-size:13px;">
      </div>
      <div class="wiz-nav">
        <button type="button" class="btn-secondary btn-sm" onclick="wizGoStep(2)">← Back</button>
        <button type="button" class="btn-primary btn-sm" id="wizStep3NextBtn" onclick="wizGoStep(4)" disabled>Next →</button>
      </div>
    </div>

    <div class="wiz-panel" id="wizStep4">
      <p class="field-hint" style="margin-bottom:12px;">Pick a category, then an item, then this unit's PO Number — add as many as you need. <b>PO Number is required.</b></p>
      <div class="field-row">
        <div class="field">
          <label>Category</label>
          <select id="wizCategorySelect" onchange="wizLoadItemsForCategory()">
            <option value="">— Select category —</option>
            <?php foreach ($categoryListPayload as $cat): ?>
              <option value="<?php echo $cat['id']; ?>"><?php echo h($cat['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="field-hint">Don't see the category you need? <a href="settings.php" target="_blank">Add a new item type</a>.</div>
        </div>
        <div class="field">
          <label>Item Model (only items with stock left are shown)</label>
          <select id="wizItemSelect" onchange="wizLoadPoForItem(); wizLoadPresetSpecsForItem();">
            <option value="">— Select category first —</option>
          </select>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>PO Number for this unit (required)</label>
          <select id="wizPoNumberSelect">
            <option value="">— Select item first —</option>
          </select>
        </div>
      </div>
      <div class="field-row">
        <div class="field" style="flex:1 1 100%;">
          <label>Details (optional)</label>
          <textarea id="wizItemDetails" rows="3" placeholder="e.g. unit condition, serial/asset tag, notes for this specific unit..." style="width:100%; box-sizing:border-box; background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:9px 11px; color:var(--text); font-size:13px; font-family:inherit; resize:vertical;"></textarea>
        </div>
      </div>
      <div class="field-row">
        <div class="field" style="display:flex; align-items:flex-end;">
          <button type="button" class="btn-primary" style="width:100%;" onclick="wizAddCartLine()">+ Add This Item</button>
        </div>
      </div>

      <div class="idt-section-title" style="margin-top:20px;">Items Added So Far</div>
      <div id="wizCartList" class="idt-batches"></div>
      <div id="wizCartEmpty" class="idt-empty">No items added yet.</div>

      <div class="wiz-nav">
        <button type="button" class="btn-secondary btn-sm" onclick="wizGoStep(3)">← Back</button>
        <button type="button" class="btn-primary btn-sm" onclick="wizGoStep(5)">Done Adding Items →</button>
      </div>
    </div>

    <div class="wiz-panel" id="wizStep5">
      <p class="field-hint" style="margin-bottom:12px;">Review the deployment below, then confirm.</p>
      <div class="idt-stats" style="grid-template-columns:repeat(auto-fit, minmax(140px,1fr));">
        <div class="idt-stat"><div class="idt-stat-label">Type</div><div class="idt-stat-value" id="wizReviewType" style="font-size:14px;"></div></div>
        <div class="idt-stat"><div class="idt-stat-label">Department</div><div class="idt-stat-value" id="wizReviewDept" style="font-size:14px;"></div></div>
        <div class="idt-stat"><div class="idt-stat-label">Faculty/Staff</div><div class="idt-stat-value" id="wizReviewPerson" style="font-size:14px;"></div></div>
        <div class="idt-stat"><div class="idt-stat-label" id="wizReviewRefLabel">PN Number</div><div class="idt-stat-value" id="wizReviewRefNumber" style="font-size:14px;"></div></div>
        <div class="idt-stat"><div class="idt-stat-label">MR Number</div><div class="idt-stat-value" id="wizReviewMrNumber" style="font-size:14px;"></div></div>
        <div class="idt-stat"><div class="idt-stat-label">Total Items</div><div class="idt-stat-value" id="wizReviewCount"></div></div>
      </div>
      <div class="idt-section-title">Items</div>
      <div id="wizReviewCartList" class="idt-batches"></div>

      <div class="idt-section-title" style="margin-top:18px;" id="wizSpecSectionTitle">Unit Specifications (optional)</div>
      <p class="field-hint" style="margin-bottom:10px;" id="wizSpecSectionHint">If this deployment is a built PC/system unit, fill in its specs here — this becomes the spec sheet shown when this deployment is clicked in the Deployed Items list, the same way it's tracked in the old spec sheet.</p>
      <div id="wizSpecFieldsContainer"></div>

      <div id="wizPeriphSection">
      <div class="idt-section-title" style="margin-top:18px;">Keyboard, Mouse &amp; Monitor (optional)</div>
      <p class="field-hint" style="margin-bottom:10px;">If this PC set includes a Keyboard, Mouse, and/or Monitor from stock, select them here — each becomes its own deployment record and is deducted from that item's stock, the same as any other deployed item. Leave blank if not applicable.</p>
      <?php foreach (['keyboard' => 'Keyboard', 'mouse' => 'Mouse', 'monitor' => 'Monitor'] as $periphKey => $periphLabel): ?>
        <div class="field-row">
          <div class="field">
            <label><?php echo h($periphLabel); ?></label>
            <select id="wizPeriph_<?php echo $periphKey; ?>_item" onchange="wizLoadPoForPeripheral('<?php echo $periphKey; ?>')" <?php echo $peripheralTypeIds[$periphKey] === null ? 'disabled' : ''; ?>>
              <?php if ($peripheralTypeIds[$periphKey] === null): ?>
                <option value="">No "<?php echo h($periphLabel); ?>" item type in Settings yet</option>
              <?php else: ?>
                <option value="">— None —</option>
              <?php endif; ?>
            </select>
          </div>
          <div class="field">
            <label>PO Number</label>
            <select id="wizPeriph_<?php echo $periphKey; ?>_po" <?php echo $peripheralTypeIds[$periphKey] === null ? 'disabled' : ''; ?>>
              <option value="">— Select <?php echo h($periphLabel); ?> first —</option>
            </select>
          </div>
        </div>
      <?php endforeach; ?>
      </div>

      <div class="field-row" style="margin-top:14px;">
        <div class="field" style="max-width:220px;">
          <label>Date Deployed</label>
          <input type="date" id="wizDeployDate" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="field" style="max-width:220px;">
          <label>Time Deployed</label>
          <input type="time" id="wizDeployTime" value="<?php echo date('H:i'); ?>">
        </div>
      </div>
      <div class="wiz-nav">
        <button type="button" class="btn-secondary btn-sm" onclick="wizGoStep(4)">← Back, Add More Items</button>
        <button type="button" class="btn-primary" id="wizConfirmBtn" onclick="wizConfirmDeployment()">✓ Ready for Deployment</button>
      </div>
    </div>

    <div class="wiz-panel" id="wizStepDone">
      <div style="text-align:center; padding:30px 10px;">
        <div style="font-size:40px; margin-bottom:10px;">✓</div>
        <h3 style="margin:0 0 6px;">Deployed!</h3>
        <p class="field-hint" id="wizDoneSummary" style="margin-bottom:20px;"></p>
        <button type="button" class="btn-primary" onclick="wizStartNew()">Start Another Deployment</button>
      </div>
    </div>

  </div>
</div>
<?php endif; ?>

<?php

function render_deploy_records_panel($title, $groupedDeploys, $consumptionByDeploy, $anchorId) {
    ?>
    <div class="panel" id="<?php echo h($anchorId); ?>" style="scroll-margin-top:20px;">
      <div class="panel-header">
        <h2><?php echo h($title); ?></h2>
      </div>
      <div class="panel-body">
        <?php if (empty($groupedDeploys)): ?>
          <div class="dash-search-empty">No records found.</div>
        <?php else: ?>
          <div class="user-card-grid">
            <?php foreach ($groupedDeploys as $userName => $entries): ?>
              <div class="user-card">
                <div class="user-card-header">
                  <span class="account-avatar"><?php echo strtoupper(substr($userName,0,1)); ?></span>
                  <span class="user-card-name"><?php echo h($userName); ?></span>
                  <?php if (!empty($entries[0]['deployed_by_role'])): ?>
                    <span class="account-role-badge role-<?php echo $entries[0]['deployed_by_role']; ?>"><?php echo h($entries[0]['deployed_by_role']); ?></span>
                  <?php endif; ?>
                  <span class="user-card-count"><?php echo count($entries); ?> <?php echo count($entries)===1?'record':'records'; ?></span>
                </div>
                <div class="user-card-body">
                  <?php foreach ($entries as $d): ?>
                    <div class="deploy-entry">
                      <div class="deploy-entry-top">
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
                      <div class="deploy-entry-meta">To <?php echo h($d['dept_name']); ?><?php echo !empty($d['recipient_name']) ? ' — ' . h($d['recipient_name']) : ''; ?><?php echo $d['remarks'] ? ' — ' . h($d['remarks']) : ''; ?></div>
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
                      <?php if (!$d['returned_date']): ?>
                        <form method="POST" class="return-confirm-form" style="display:flex; gap:6px; align-items:center; margin-top:8px; flex-wrap:wrap;">
                          <input type="hidden" name="action" value="mark_returned">
                          <input type="hidden" name="deployment_id" value="<?php echo $d['id']; ?>">
                          <input type="hidden" name="confirm_username" class="return-confirm-username-field">
                          <input type="date" name="returned_date" value="<?php echo date('Y-m-d'); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:5px 7px; color:var(--text); font-size:12px;">
                          <input type="time" name="returned_time" value="<?php echo date('H:i'); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:5px 7px; color:var(--text); font-size:12px;">
                          <button type="submit" class="btn-secondary btn-sm">Return</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px; flex-wrap:wrap; gap:10px;">
  <div class="report-jumpnav">
    <a href="#dep-new">New Deployment</a>
    <a href="#dep-replacement">Replacement</a>
  </div>
  <div style="display:flex; gap:8px;">
    <a href="deployments.php?status=active" class="btn-secondary btn-sm">Active</a>
    <a href="deployments.php?status=returned" class="btn-secondary btn-sm">Returned</a>
    <a href="deployments.php?status=all" class="btn-secondary btn-sm">All</a>
  </div>
</div>

<?php render_deploy_records_panel('New Deployment Records', $groupedDeploysByType['new'], $consumptionByDeploy, 'dep-new'); ?>
<?php render_deploy_records_panel('Deployment for Replacement Records', $groupedDeploysByType['replacement'], $consumptionByDeploy, 'dep-replacement'); ?>

<style>
  .wiz-steps{ display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; }
  .wiz-step{ display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-faint); padding:6px 10px; border-radius:20px; border:1px solid var(--border-soft); }
  .wiz-step.active{ color:var(--accent-bright); border-color:var(--accent-bright); }
  .wiz-step.done{ color:var(--green); border-color:var(--green); }
  .wiz-step-num{ display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; background:var(--panel-2); font-size:10.5px; font-weight:700; }
  .wiz-step.active .wiz-step-num{ background:var(--accent-bright); color:var(--bg); }
  .wiz-step.done .wiz-step-num{ background:var(--green); color:var(--bg); }
  .wiz-panel{ display:none; }
  .wiz-panel.active{ display:block; }
  .wiz-choice-grid{ display:flex; flex-wrap:wrap; gap:10px; }
  .wiz-choice-card{
    padding:12px 18px; border:1px solid var(--border-soft); border-radius:8px; cursor:pointer;
    font-size:13px; font-weight:600; color:var(--text); text-align:center; min-width:90px;
    background:var(--panel-2); transition:border-color .12s;
  }
  .wiz-choice-card:hover{ border-color:var(--accent-bright); }
  .wiz-choice-card.selected{ border-color:var(--accent-bright); background:var(--accent-bright); color:var(--bg); }
  .wiz-nav{ display:flex; justify-content:space-between; margin-top:20px; gap:10px; }

  .wiz-select-list{
    display:flex; flex-direction:column; gap:6px; max-height:280px; overflow-y:auto;
    border:1px solid var(--border-soft); border-radius:8px; padding:6px; max-width:420px;
  }
  .wiz-select-item{
    padding:10px 12px; font-size:13px; cursor:pointer; border-radius:6px;
    background:var(--panel-2); border:1px solid transparent; transition:border-color .12s, background .12s;
  }
  .wiz-select-item:hover{ border-color:var(--accent-bright); }
  .wiz-select-item.selected{ background:var(--accent-bright); color:var(--bg); border-color:var(--accent-bright); }
  .wiz-select-empty{ padding:12px; font-size:12.5px; color:var(--text-faint); text-align:center; }
  .wiz-select-item-sub{ display:block; font-size:11px; color:var(--text-faint); margin-top:2px; }
  .wiz-select-item.selected .wiz-select-item-sub{ color:var(--bg); opacity:.8; }
  .wiz-selected-chip{
    display:inline-flex; align-items:center; gap:8px; margin-top:10px; padding:7px 12px;
    border:1px solid var(--accent-bright); border-radius:20px; font-size:12.5px; color:var(--accent-bright);
  }
  .wiz-cart-line{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding:9px 12px; font-size:12.5px; }
  .wiz-cart-remove{ margin-left:auto; background:none; border:none; color:var(--red); cursor:pointer; font-size:12px; }
  .wiz-search-box{
    width:100%; max-width:420px; box-sizing:border-box; margin-bottom:8px;
    background:var(--bg); border:1px solid var(--border); border-radius:6px;
    padding:8px 11px; color:var(--text); font-size:13px;
  }
  .wiz-modal-card{ max-width:700px; }
  .report-jumpnav a{ margin-right:10px; }
</style>

<script>

  var wizPeopleByDept = <?php echo json_encode($peopleByDept, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

  var wizState = {
    currentStep: '0',
    deploymentType: 'new',
    departmentId: null,
    departmentName: '',
    recipientName: '',
    replacesDeploymentId: null,
    replacesLabel: '',
    refNumber: '',
    mrNumber: '',
    specKind: '',
    presetSpecs: {},
    cart: []
  };

  function wizEsc(s){
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }

  function wizFilterList(listId, query){
    var q = (query || '').toLowerCase().trim();
    var list = document.getElementById(listId);
    if (!list) return;
    var items = list.querySelectorAll('.wiz-select-item');
    var visibleCount = 0;
    items.forEach(function(el){
      var hay = el.getAttribute('data-search') || el.textContent.toLowerCase();
      var match = hay.indexOf(q) !== -1;
      el.style.display = match ? '' : 'none';
      if (match) visibleCount++;
    });
    var emptyEl = list.querySelector('.wiz-filter-empty');
    if (visibleCount === 0 && items.length > 0) {
      if (!emptyEl) {
        emptyEl = document.createElement('div');
        emptyEl.className = 'wiz-select-empty wiz-filter-empty';
        emptyEl.textContent = 'No matches.';
        list.appendChild(emptyEl);
      }
    } else if (emptyEl) {
      emptyEl.remove();
    }
  }

  var wizStepOrder = ['0','1','2','0b','2b','3','4','5'];

  function wizGoStep(n){
    n = String(n);
    wizState.currentStep = n;
    document.querySelectorAll('.wiz-panel').forEach(function(p){ p.classList.remove('active'); });
    document.getElementById('wizStep' + n).classList.add('active');
    var nIdx = wizStepOrder.indexOf(n);
    document.querySelectorAll('.wiz-step').forEach(function(s){
      var step = s.getAttribute('data-step');
      var idx = wizStepOrder.indexOf(step);
      s.classList.remove('active','done');
      if (step === n) s.classList.add('active');
      else if (idx !== -1 && idx < nIdx) s.classList.add('done');
    });
    if (n === '5') wizRenderReview();
  }

  function openDeployWizard(){
    var backdrop = document.getElementById('deployWizardBackdrop');
    wizResetToStep0();
    backdrop.classList.add('open');
    requestAnimationFrame(function(){ backdrop.classList.add('show'); });
    wizGoStep('0');
  }

  function closeDeployWizard(){
    var backdrop = document.getElementById('deployWizardBackdrop');
    backdrop.classList.remove('show');
    setTimeout(function(){ backdrop.classList.remove('open'); }, 180);
  }

  function wizSelectDeploymentType(type){
    wizState.deploymentType = type;
    document.getElementById('wizChoiceNew').classList.toggle('selected', type === 'new');
    document.getElementById('wizChoiceReplacement').classList.toggle('selected', type === 'replacement');
    document.querySelectorAll('.wiz-step-replace').forEach(function(el){
      el.style.display = (type === 'replacement') ? 'flex' : 'none';
    });
    wizGoStep(1);
  }

  var wizQuickPickTypeIds = <?php echo json_encode($quickPickTypeIds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  function wizSelectQuickPickKind(specKey){
    wizState.specKind = specKey;
    document.getElementById('wizChoicePc').classList.toggle('selected', specKey === 'pc');
    document.getElementById('wizChoiceLaptop').classList.toggle('selected', specKey === 'laptop');
    document.getElementById('wizChoiceProjector').classList.toggle('selected', specKey === 'projector');

    var catSelect = document.getElementById('wizCategorySelect');
    var typeId = wizQuickPickTypeIds[specKey];
    if (typeId) {
      catSelect.value = String(typeId);
      wizLoadItemsForCategory();
      catSelect.disabled = true;
    } else {
      catSelect.disabled = false;
    }

    if (wizState.deploymentType === 'replacement') {
      document.getElementById('wizStep2bPersonName').textContent = wizState.recipientName;
      wizLoadReplaceCandidates();
      wizGoStep('2b');
    } else {
      wizGoStep(3);
    }
  }

  function wizSelectDepartment(id, name){
    wizState.departmentId = id;
    wizState.departmentName = name;
    wizState.recipientName = '';
    wizState.replacesDeploymentId = null;
    wizState.replacesLabel = '';
    document.querySelectorAll('#wizDeptList .wiz-select-item').forEach(function(el){
      el.classList.toggle('selected', el.getAttribute('data-id') == id);
    });
    document.getElementById('wizStep2DeptName').textContent = name;
    var searchBox = document.getElementById('wizPersonSearch');
    if (searchBox) searchBox.value = '';
    wizRenderPersonList();
    wizGoStep(2);
  }

  function wizRenderPersonList(){
    var listEl = document.getElementById('wizPersonList');
    var people = wizPeopleByDept[wizState.departmentId] || [];
    if (people.length === 0) {
      listEl.innerHTML = '<div class="wiz-select-empty">No faculty/staff on file for this department yet. Ask an admin to add names on the Settings page.</div>';
      return;
    }
    listEl.innerHTML = people.map(function(p){
      return '<div class="wiz-select-item" data-name="' + wizEsc(p.name) + '" data-search="' + wizEsc(p.name.toLowerCase()) + '">' + wizEsc(p.name) + '</div>';
    }).join('');
    listEl.querySelectorAll('.wiz-select-item').forEach(function(el){
      el.addEventListener('click', function(){ wizSelectPerson(el.getAttribute('data-name')); });
    });
  }

  function wizSelectPerson(name){
    wizState.recipientName = name;
    wizState.replacesDeploymentId = null;
    wizState.replacesLabel = '';
    document.querySelectorAll('#wizPersonList .wiz-select-item').forEach(function(el){
      el.classList.toggle('selected', el.getAttribute('data-name') === name);
    });
    wizGoStep('0b');
  }

  function wizLoadReplaceCandidates(){
    var listEl = document.getElementById('wizReplaceList');
    listEl.innerHTML = '<div class="wiz-select-empty">Loading...</div>';
    var url = 'deployments.php?ajax=active_deployments_for_person&department_id=' + encodeURIComponent(wizState.departmentId) + '&recipient_name=' + encodeURIComponent(wizState.recipientName);
    fetch(url).then(function(r){ return r.json(); }).then(function(data){
      if (!data.ok || !data.items.length) {
        listEl.innerHTML = '<div class="wiz-select-empty">No currently deployed items found for this person.</div>';
        return;
      }
      listEl.innerHTML = data.items.map(function(it){
        var sub = (it.po_number ? 'PO# ' + it.po_number + ' — ' : '') + (it.pn_number ? 'PN# ' + it.pn_number + ' — ' : '') + it.deployed_date + (it.deployed_time ? ' ' + it.deployed_time : '');
        return '<div class="wiz-select-item" data-id="' + it.id + '" data-item-name="' + wizEsc(it.item_name) + '" data-search="' + wizEsc(it.item_name.toLowerCase()) + '">'
          + wizEsc(it.item_name)
          + '<span class="wiz-select-item-sub">' + wizEsc(sub) + '</span>'
          + '</div>';
      }).join('');
      listEl.querySelectorAll('.wiz-select-item').forEach(function(el){
        el.addEventListener('click', function(){
          wizSelectReplaceItem(el.getAttribute('data-id'), el.getAttribute('data-item-name'));
        });
      });
    }).catch(function(){
      listEl.innerHTML = '<div class="wiz-select-empty">Could not load deployed items. Please try again.</div>';
    });
  }

  function wizSelectReplaceItem(deploymentId, itemName){
    wizState.replacesDeploymentId = deploymentId;
    wizState.replacesLabel = itemName;
    document.querySelectorAll('#wizReplaceList .wiz-select-item').forEach(function(el){
      el.classList.toggle('selected', el.getAttribute('data-id') == deploymentId);
    });
    wizGoStep(3);
  }

  function wizSelectPo(pn){
    wizState.refNumber = pn;
    document.querySelectorAll('#wizPoList .wiz-select-item').forEach(function(el){
      el.classList.toggle('selected', el.getAttribute('data-val') === pn);
    });
    document.getElementById('wizRefNumber').value = pn;
    wizUpdateStep3NextBtn();
  }

  function wizOnMrNumberInput(){
    wizState.mrNumber = document.getElementById('wizMrNumber').value.trim();
    wizUpdateStep3NextBtn();
  }

  function wizUpdateStep3NextBtn(){
    var hasPn = !!(wizState.refNumber);
    var hasMr = !!(document.getElementById('wizMrNumber').value.trim());
    document.getElementById('wizStep3NextBtn').disabled = !(hasPn && hasMr);
  }

  function wizLoadItemsForCategory(){
    var typeId = document.getElementById('wizCategorySelect').value;
    var itemSelect = document.getElementById('wizItemSelect');
    if (!typeId) {
      itemSelect.innerHTML = '<option value="">— Select category first —</option>';
      return;
    }
    itemSelect.innerHTML = '<option value="">Loading...</option>';
    fetch('deployments.php?ajax=items_by_category&type_id=' + encodeURIComponent(typeId))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.ok || !data.items.length) {
          itemSelect.innerHTML = '<option value="">No items with stock in this category</option>';
          return;
        }
        itemSelect.innerHTML = '<option value="">— Select item —</option>' + data.items.map(function(it){
          return '<option value="' + it.id + '" data-name="' + wizEsc(it.name) + '">' + wizEsc(it.name) + ' — ' + it.available + ' left</option>';
        }).join('');
        wizLoadPoForItem();
      });
  }

  function wizLoadPresetSpecsForItem(){
    var itemSelect = document.getElementById('wizItemSelect');
    var itemId = itemSelect.value;
    wizState.presetSpecs = {};
    if (!itemId) return;
    fetch('deployments.php?ajax=preset_specs_for_item&item_id=' + encodeURIComponent(itemId))
      .then(function(r){ return r.json(); })
      .then(function(data){
        wizState.presetSpecs = (data.ok && data.specs) ? data.specs : {};
      });
  }

  function wizLoadPoForItem(){
    var itemSelect = document.getElementById('wizItemSelect');
    var poSelect = document.getElementById('wizPoNumberSelect');
    var itemId = itemSelect.value;
    if (!itemId) {
      poSelect.innerHTML = '<option value="">— Select item first —</option>';
      return;
    }
    poSelect.innerHTML = '<option value="">Loading...</option>';
    fetch('deployments.php?ajax=po_numbers_for_item&item_id=' + encodeURIComponent(itemId))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.ok || !data.po_numbers.length) {
          poSelect.innerHTML = '<option value="">No PO numbers on file for this item</option>';
          return;
        }
        poSelect.innerHTML = '<option value="">— Select PO Number —</option>' + data.po_numbers.map(function(po){
          return '<option value="' + wizEsc(po) + '">' + wizEsc(po) + '</option>';
        }).join('');
      });
  }

  var wizPeripheralTypeIds = <?php echo json_encode($peripheralTypeIds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  function wizLoadPeripheralOptions(key){
    var typeId = wizPeripheralTypeIds[key];
    var itemSelect = document.getElementById('wizPeriph_' + key + '_item');
    if (!itemSelect || !typeId) return;
    var poSelect = document.getElementById('wizPeriph_' + key + '_po');
    var previouslySelected = itemSelect.value;
    itemSelect.innerHTML = '<option value="">Loading...</option>';
    fetch('deployments.php?ajax=items_by_category&type_id=' + encodeURIComponent(typeId))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.ok || !data.items.length) {
          itemSelect.innerHTML = '<option value="">No stock available</option>';
          poSelect.innerHTML = '<option value="">— None —</option>';
          return;
        }
        itemSelect.innerHTML = '<option value="">— None —</option>' + data.items.map(function(it){
          return '<option value="' + it.id + '" data-name="' + wizEsc(it.name) + '"' + (String(it.id) === previouslySelected ? ' selected' : '') + '>' + wizEsc(it.name) + ' — ' + it.available + ' left</option>';
        }).join('');
        wizLoadPoForPeripheral(key);
      });
  }

  function wizLoadPoForPeripheral(key){
    var itemSelect = document.getElementById('wizPeriph_' + key + '_item');
    var poSelect = document.getElementById('wizPeriph_' + key + '_po');
    var itemId = itemSelect.value;
    if (!itemId) {
      poSelect.innerHTML = '<option value="">— Select ' + key.charAt(0).toUpperCase() + key.slice(1) + ' first —</option>';
      return;
    }
    poSelect.innerHTML = '<option value="">Loading...</option>';
    fetch('deployments.php?ajax=po_numbers_for_item&item_id=' + encodeURIComponent(itemId))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.ok || !data.po_numbers.length) {
          poSelect.innerHTML = '<option value="">No PO numbers on file for this item</option>';
          return;
        }
        poSelect.innerHTML = '<option value="">— Select PO Number —</option>' + data.po_numbers.map(function(po){
          return '<option value="' + wizEsc(po) + '">' + wizEsc(po) + '</option>';
        }).join('');
      });
  }

  function wizAddCartLine(){
    var itemSelect = document.getElementById('wizItemSelect');
    var itemId = itemSelect.value;
    if (!itemId) { alert('Please select an item first.'); return; }
    var itemName = itemSelect.options[itemSelect.selectedIndex].getAttribute('data-name') || itemSelect.options[itemSelect.selectedIndex].text;
    var poSelect = document.getElementById('wizPoNumberSelect');
    var poNum = poSelect.value;
    if (!poNum) { alert('Please select a PO Number for this item — it is required.'); return; }
    var catSelect = document.getElementById('wizCategorySelect');
    var catName = catSelect.options[catSelect.selectedIndex] ? catSelect.options[catSelect.selectedIndex].text : '';
    var detailsBox = document.getElementById('wizItemDetails');
    var itemDetails = detailsBox ? detailsBox.value.trim() : '';

    wizState.cart.push({ item_id: itemId, item_name: itemName, category_name: catName, po_number: poNum, item_details: itemDetails });
    poSelect.value = '';
    if (detailsBox) detailsBox.value = '';
    wizRenderCart();
  }

  function wizRemoveCartLine(idx){
    wizState.cart.splice(idx, 1);
    wizRenderCart();
  }

  function wizRenderCart(){
    var list = document.getElementById('wizCartList');
    var empty = document.getElementById('wizCartEmpty');
    if (wizState.cart.length === 0) {
      list.innerHTML = '';
      empty.style.display = 'block';
      return;
    }
    empty.style.display = 'none';
    list.innerHTML = wizState.cart.map(function(line, idx){
      return '<div class="wiz-cart-line">'
        + '<span><b>' + wizEsc(line.item_name) + '</b></span>'
        + '<span style="color:var(--text-faint);">' + wizEsc(line.category_name) + '</span>'
        + '<span class="idt-batch-po">PO# ' + wizEsc(line.po_number) + '</span>'
        + (line.item_details ? '<span class="idt-batch-notes">' + wizEsc(line.item_details) + '</span>' : '')
        + '<button type="button" class="wiz-cart-remove" onclick="wizRemoveCartLine(' + idx + ')">Remove</button>'
        + '</div>';
    }).join('');
  }

  var wizSpecSchema = <?php echo json_encode(SPEC_SCHEMA, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var wizSpecKindLabels = { pc: 'PC/System Unit', laptop: 'Laptop', projector: 'Projector', '': 'Item' };

  function wizRenderSpecFields(presetValues){
    presetValues = presetValues || {};
    var container = document.getElementById('wizSpecFieldsContainer');
    var periphSection = document.getElementById('wizPeriphSection');
    var schema = wizSpecSchema[wizState.specKind];
    document.getElementById('wizSpecSectionTitle').textContent = 'Unit Specifications (optional)';
    if (!schema) {

      container.innerHTML = '<div class="field-hint">No preset Specifications fields for this Kind of Item.</div>';
      document.getElementById('wizSpecSectionHint').textContent = 'This Kind of Item has no fixed Specifications fields.';
      periphSection.style.display = 'none';
      return;
    }
    var kindLabel = wizSpecKindLabels[wizState.specKind] || 'Item';
    document.getElementById('wizSpecSectionHint').textContent = 'Fill in this ' + kindLabel + '\'s specs — this becomes the spec sheet shown when this deployment is clicked in the Deployed Items list.';
    var keys = Object.keys(schema);
    var html = '';
    for (var i = 0; i < keys.length; i += 2) {
      html += '<div class="field-row">';
      for (var j = i; j < Math.min(i + 2, keys.length); j++) {
        var key = keys[j];
        var meta = schema[key];
        var val = presetValues[key] ? ' value="' + wizEsc(presetValues[key]).replace(/"/g, '&quot;') + '"' : '';
        html += '<div class="field"><label>' + wizEsc(meta[0]) + '</label>'
          + '<input type="text" class="wiz-spec-input" data-spec-key="' + key + '" placeholder="' + wizEsc(meta[1]) + '"' + val + '></div>';
      }
      html += '</div>';
    }
    container.innerHTML = html;

    periphSection.style.display = (wizState.specKind === 'pc') ? '' : 'none';
  }

  function wizCollectSpecValues(){
    var out = {};
    document.querySelectorAll('#wizSpecFieldsContainer .wiz-spec-input').forEach(function(el){
      var key = el.getAttribute('data-spec-key');
      var val = el.value.trim();
      if (val !== '') out[key] = val;
    });
    return out;
  }

  function wizRenderReview(){
    var wizTypeLabels = { new: 'New Deployment', replacement: 'Deployment for Replacement' };
    document.getElementById('wizReviewType').textContent = wizTypeLabels[wizState.deploymentType] || 'New Deployment';
    document.getElementById('wizReviewDept').textContent = wizState.departmentName || '—';
    var personLabel = wizState.recipientName || '—';
    if (wizState.deploymentType === 'replacement' && wizState.replacesLabel) {
      personLabel += ' (replacing: ' + wizState.replacesLabel + ')';
    }
    document.getElementById('wizReviewPerson').textContent = personLabel;
    document.getElementById('wizReviewRefLabel').textContent = 'PN Number';
    var refNum = document.getElementById('wizRefNumber').value.trim();
    wizState.refNumber = refNum;
    document.getElementById('wizReviewRefNumber').textContent = refNum || '—';
    var mrNum = document.getElementById('wizMrNumber').value.trim();
    wizState.mrNumber = mrNum;
    var mrReviewEl = document.getElementById('wizReviewMrNumber');
    if (mrReviewEl) mrReviewEl.textContent = mrNum || '—';
    document.getElementById('wizReviewCount').textContent = wizState.cart.length;

    ['keyboard', 'mouse', 'monitor'].forEach(function(key){ wizLoadPeripheralOptions(key); });

    wizRenderSpecFields(wizState.presetSpecs);

    var list = document.getElementById('wizReviewCartList');
    if (wizState.cart.length === 0) {
      list.innerHTML = '<div class="idt-empty">No items added.</div>';
    } else {
      list.innerHTML = wizState.cart.map(function(line){
        return '<div class="wiz-cart-line">'
          + '<span><b>' + wizEsc(line.item_name) + '</b></span>'
          + '<span style="color:var(--text-faint);">' + wizEsc(line.category_name) + '</span>'
          + '<span class="idt-batch-po">PO# ' + wizEsc(line.po_number) + '</span>'
          + (line.item_details ? '<span class="idt-batch-notes">' + wizEsc(line.item_details) + '</span>' : '')
          + '</div>';
      }).join('');
    }
  }

  function wizConfirmDeployment(){
    if (wizState.cart.length === 0) { alert('Add at least one item before deploying.'); return; }
    if (wizState.deploymentType === 'replacement' && !wizState.replacesDeploymentId) { alert('Please select which deployed item is being replaced.'); return; }

    var peripheralLines = [];
    for (var i = 0; i < ['keyboard','mouse','monitor'].length; i++) {
      var key = ['keyboard','mouse','monitor'][i];
      var itemSelect = document.getElementById('wizPeriph_' + key + '_item');
      var poSelect = document.getElementById('wizPeriph_' + key + '_po');
      if (itemSelect && itemSelect.value) {
        if (!poSelect.value) {
          alert('Please select a PO Number for the selected ' + key.charAt(0).toUpperCase() + key.slice(1) + ' — it is required.');
          return;
        }
        var itemName = itemSelect.options[itemSelect.selectedIndex].getAttribute('data-name') || itemSelect.options[itemSelect.selectedIndex].text;
        peripheralLines.push({ item_id: itemSelect.value, item_name: itemName, category_name: key.charAt(0).toUpperCase() + key.slice(1), po_number: poSelect.value, item_details: '' });
      }
    }

    var btn = document.getElementById('wizConfirmBtn');
    btn.disabled = true;
    btn.textContent = 'Deploying...';

    var fd = new FormData();
    fd.append('action', 'submit_deployment_cart');
    fd.append('department_id', wizState.departmentId);
    fd.append('recipient_name', wizState.recipientName);
    fd.append('ref_number', wizState.refNumber);
    fd.append('mr_number', wizState.mrNumber);
    fd.append('deployment_type', wizState.deploymentType);
    if (wizState.deploymentType === 'replacement') {
      fd.append('replaces_deployment_id', wizState.replacesDeploymentId);
    }
    fd.append('deployed_date', document.getElementById('wizDeployDate').value);
    fd.append('deployed_time', document.getElementById('wizDeployTime').value);
    fd.append('cart', JSON.stringify(wizState.cart.concat(peripheralLines)));

    fd.append('spec_kind', wizState.specKind || '');
    fd.append('spec_values', JSON.stringify(wizCollectSpecValues()));

    fetch('deployments.php', { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        btn.disabled = false;
        btn.textContent = '✓ Ready for Deployment';
        if (data.ok) {
          document.getElementById('wizDoneSummary').textContent =
            data.deployed + ' item(s) deployed to ' + wizState.departmentName + (wizState.recipientName ? ' — ' + wizState.recipientName : '') + '.';
          document.querySelectorAll('.wiz-panel').forEach(function(p){ p.classList.remove('active'); });
          document.getElementById('wizStepDone').classList.add('active');
        } else {
          alert(data.error || 'Could not complete this deployment.');
        }
      })
      .catch(function(){
        btn.disabled = false;
        btn.textContent = '✓ Ready for Deployment';
        alert('Something went wrong. Please try again.');
      });
  }

  function wizStartNew(){
    wizResetToStep0();
  }

  function wizResetToStep0(){
    wizState = {
      currentStep: '0', deploymentType: 'new', departmentId: null, departmentName: '', recipientName: '',
      replacesDeploymentId: null, replacesLabel: '', refNumber: '', mrNumber: '', specKind: '', presetSpecs: {}, cart: []
    };
    document.getElementById('wizChoiceNew').classList.remove('selected');
    document.getElementById('wizChoiceReplacement').classList.remove('selected');
    document.getElementById('wizChoicePc').classList.remove('selected');
    document.getElementById('wizChoiceLaptop').classList.remove('selected');
    document.getElementById('wizChoiceProjector').classList.remove('selected');
    document.getElementById('wizCategorySelect').disabled = false;
    document.querySelectorAll('.wiz-step-replace').forEach(function(el){ el.style.display = 'none'; });
    document.getElementById('wizRefNumber').value = '';
    document.getElementById('wizMrNumber').value = '';
    ['wizSpecCasing','wizSpecProcessor','wizSpecMotherboard','wizSpecRam','wizSpecSsd','wizSpecPsu','wizSpecNote'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) el.value = '';
    });
    ['keyboard','mouse','monitor'].forEach(function(key){
      var itemEl = document.getElementById('wizPeriph_' + key + '_item');
      var poEl = document.getElementById('wizPeriph_' + key + '_po');
      if (itemEl) itemEl.value = '';
      if (poEl) poEl.value = '';
    });
    var deptSearch = document.getElementById('wizDeptSearch');
    if (deptSearch) { deptSearch.value = ''; wizFilterList('wizDeptList', ''); }
    var pnSearch = document.getElementById('wizPnSearch');
    if (pnSearch) { pnSearch.value = ''; wizFilterList('wizPoList', ''); }
    document.querySelectorAll('#wizDeptList .wiz-select-item, #wizPoList .wiz-select-item').forEach(function(el){ el.classList.remove('selected'); });
    document.getElementById('wizPersonList').innerHTML = '';
    document.getElementById('wizReplaceList').innerHTML = '';
    document.getElementById('wizStep3NextBtn').disabled = true;
    document.getElementById('wizCategorySelect').value = '';
    document.getElementById('wizItemSelect').innerHTML = '<option value="">— Select category first —</option>';
    document.getElementById('wizPoNumberSelect').innerHTML = '<option value="">— Select item first —</option>';
    wizRenderCart();

    fetch('deployments.php?ajax=current_ph_time')
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          document.getElementById('wizDeployDate').value = data.date;
          document.getElementById('wizDeployTime').value = data.time;
        }
      });
    wizGoStep(0);
  }

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

  document.addEventListener('DOMContentLoaded', function(){
    var backdrop = document.getElementById('deployWizardBackdrop');
    if (backdrop) {
      backdrop.addEventListener('click', closeDeployWizard);
    }
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      var backdrop = document.getElementById('deployWizardBackdrop');
      if (backdrop && backdrop.classList.contains('open')) closeDeployWizard();
      var detailsBackdrop = document.getElementById('deployDetailsBackdrop');
      if (detailsBackdrop && detailsBackdrop.classList.contains('open')) closeDeployDetails();
    }
  });
</script>

<?php include 'includes/footer.php'; ?>
