<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/spec_schema.php';
require_login();

$pageTitle = 'Inventory';
$activePage = 'inventory';
$successMsg = '';
$errorMsg = '';
$userId = current_user()['id'];

function recalc_item_quantity($conn, $itemId) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM inventory_batches WHERE item_id = ?");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $upd = $conn->prepare("UPDATE inventory_items SET quantity_total = ? WHERE id = ?");
    $upd->bind_param('ii', $total, $itemId);
    $upd->execute();
    return $total;
}

function add_or_restock_item_row($conn, $userId, $itemName, $itemTypeId, $qty, $details, $pnNumber, $poNumber, $threshold, $dateAdded, $timeAdded, $serials = [], $specValues = []) {
    $check = $conn->prepare("SELECT id FROM inventory_items WHERE item_name = ? AND item_type_id = ?");
    $check->bind_param('si', $itemName, $itemTypeId);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();

    $isRestock = (bool)$existing;
    if ($existing) {
        $itemId = $existing['id'];
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO inventory_items (item_name, item_type_id, quantity_total, low_stock_threshold, date_added, created_by)
             VALUES (?,?,?,?,?,?)"
        );
        $zero = 0;
        $stmt->bind_param('siiisi', $itemName, $itemTypeId, $zero, $threshold, $dateAdded, $userId);
        $stmt->execute();
        $itemId = $stmt->insert_id;
    }

    $bstmt = $conn->prepare("INSERT INTO inventory_batches (item_id, quantity, batch_date, batch_time, notes, pn_number, po_number, added_by) VALUES (?,?,?,?,?,?,?,?)");
    $bstmt->bind_param('iisssssi', $itemId, $qty, $dateAdded, $timeAdded, $details, $pnNumber, $poNumber, $userId);
    $bstmt->execute();
    $batchId = $bstmt->insert_id;
    recalc_item_quantity($conn, $itemId);

    if (!empty($serials)) {
        $sstmt = $conn->prepare("INSERT INTO inventory_batch_serials (batch_id, serial_number) VALUES (?, ?)");
        for ($u = 0; $u < $qty; $u++) {
            $serial = trim($serials[$u] ?? '');
            $serialOrNull = $serial === '' ? null : $serial;
            $sstmt->bind_param('is', $batchId, $serialOrNull);
            $sstmt->execute();
        }
    }

    if (!empty($specValues)) {
        $specKey = spec_schema_key_for_type_name(item_type_name_for_id($conn, $itemTypeId));
        if ($specKey !== null && isset(SPEC_SCHEMA[$specKey])) {
            $validSpecValues = [];
            foreach (SPEC_SCHEMA[$specKey] as $fieldKey => $meta) {
                if (isset($specValues[$fieldKey]) && trim((string)$specValues[$fieldKey]) !== '') {
                    $validSpecValues[$fieldKey] = trim((string)$specValues[$fieldKey]);
                }
            }
            if (!empty($validSpecValues)) {
                save_spec_values($conn, 'inventory_item_spec_values', 'item_id', $itemId, $validSpecValues);
            }
        }
    }

    $action = $isRestock ? 'Restocked inventory item' : 'Added inventory item';
    log_activity($conn, $action, "$itemName +$qty on $dateAdded (PN #$pnNumber, PO #$poNumber)", 'inventory');

    return ['item_name' => $itemName, 'qty' => $qty, 'is_restock' => $isRestock];
}

function item_type_name_for_id($conn, $itemTypeId) {
    static $cache = [];
    if (isset($cache[$itemTypeId])) return $cache[$itemTypeId];
    $stmt = $conn->prepare("SELECT name FROM item_types WHERE id = ?");
    $stmt->bind_param('i', $itemTypeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cache[$itemTypeId] = $row['name'] ?? '';
    return $cache[$itemTypeId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item' && is_superadmin()) {

    $pnNumber    = trim($_POST['pn_number'] ?? '');
    $poNumber    = trim($_POST['po_number'] ?? '');
    $dateAdded   = $_POST['date_added'] ?? date('Y-m-d');
    $timeAdded   = $_POST['time_added'] ?? date('H:i:s');
    $threshold   = max(0, (int)($_POST['low_stock_threshold'] ?? 5));
    $details     = trim($_POST['details'] ?? '');

    $itemNames   = $_POST['item_name'] ?? [];
    $itemTypeIds = $_POST['item_type_id'] ?? [];
    $quantities  = $_POST['quantity_total'] ?? [];
    $serialsByRow = $_POST['serial_numbers'] ?? [];
    $specValuesByRow = $_POST['spec_values'] ?? [];

    if ($pnNumber === '') {
        $errorMsg = 'PN Number is required.';
    } elseif ($poNumber === '') {
        $errorMsg = 'PO Number is required.';
    } elseif (empty($itemNames)) {
        $errorMsg = 'Add at least one item.';
    } else {
        $results = [];
        $rowError = '';
        foreach ($itemNames as $idx => $rawName) {
            $itemName   = trim($rawName ?? '');
            $itemTypeId = (int)($itemTypeIds[$idx] ?? 0);
            $qty        = max(1, (int)($quantities[$idx] ?? 1));
            if ($itemName === '' || $itemTypeId === 0) {
                continue;
            }
            $rowSerials = $serialsByRow[$idx] ?? [];
            $rowSpecValues = $specValuesByRow[$idx] ?? [];
            $results[] = add_or_restock_item_row(
                $conn, $userId, $itemName, $itemTypeId, $qty, $details,
                $pnNumber, $poNumber, $threshold, $dateAdded, $timeAdded, $rowSerials, $rowSpecValues
            );
        }

        if (empty($results)) {
            $errorMsg = 'Item model and kind of item are required for at least one row.';
        } else {
            $addedCount = count(array_filter($results, function($r){ return !$r['is_restock']; }));
            $restockedCount = count($results) - $addedCount;
            $parts = [];
            if ($addedCount) $parts[] = "$addedCount new item" . ($addedCount === 1 ? '' : 's') . ' added';
            if ($restockedCount) $parts[] = "$restockedCount item" . ($restockedCount === 1 ? '' : 's') . ' restocked';
            $successMsg = implode(' and ', $parts) . " under PN #$pnNumber / PO #$poNumber.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_remark' && is_admin()) {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $remarkText = trim($_POST['remark_text'] ?? '');
    $remarkDate = $_POST['remark_date'] ?? date('Y-m-d');

    $nameRes = $conn->prepare("SELECT item_name FROM inventory_items WHERE id=?");
    $nameRes->bind_param('i', $itemId);
    $nameRes->execute();
    $row = $nameRes->get_result()->fetch_assoc();

    if (!$row) {
        $errorMsg = 'Item not found.';
    } elseif ($remarkText === '') {
        $errorMsg = 'Please enter a remark.';
    } else {
        $stmt = $conn->prepare("INSERT INTO inventory_remarks (item_id, remark, remark_date, added_by) VALUES (?,?,?,?)");
        $stmt->bind_param('issi', $itemId, $remarkText, $remarkDate, $userId);
        $stmt->execute();
        log_activity($conn, 'Added remark to inventory item', "{$row['item_name']}: $remarkText", 'inventory');
        $successMsg = "Remark added to \"{$row['item_name']}\".";
    }
}

function run_disposal_autoflag($conn) {
    $rows = $conn->query(
        "SELECT i.id, i.item_name,
                GREATEST(
                  COALESCE((SELECT MAX(b.batch_date) FROM inventory_batches b WHERE b.item_id = i.id), i.date_added),
                  COALESCE((SELECT MAX(d.deployed_date) FROM deployments d WHERE d.item_id = i.id), '1970-01-01'),
                  COALESCE((SELECT MAX(d.returned_date) FROM deployments d WHERE d.item_id = i.id), '1970-01-01')
                ) AS last_activity
         FROM inventory_items i"
    );
    if (!$rows) return;
    $today = date('Y-m-d');
    while ($r = $rows->fetch_assoc()) {
        $lastActivity = $r['last_activity'];
        $daysInactive = (int)floor((strtotime($today) - strtotime($lastActivity)) / 86400);
        if ($daysInactive < 30) continue;

        $chk = $conn->prepare(
            "SELECT id FROM inventory_disposal_flags
             WHERE item_id = ? AND last_activity_date = ? AND status = 'pending'"
        );
        $chk->bind_param('is', $r['id'], $lastActivity);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) continue;

        $ins = $conn->prepare(
            "INSERT INTO inventory_disposal_flags (item_id, flagged_date, last_activity_date, days_inactive, status)
             VALUES (?,?,?,?,'pending')"
        );
        $ins->bind_param('issi', $r['id'], $today, $lastActivity, $daysInactive);
        $ins->execute();
        log_activity($conn, 'Flagged for disposal review', "{$r['item_name']} — {$daysInactive} days with no activity", 'inventory');
    }
}
run_disposal_autoflag($conn);

$filterType = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
$types = '';
if ($filterType !== '') { $where[] = 'i.item_type_id = ?'; $params[] = (int)$filterType; $types .= 'i'; }
if ($search !== '') {
    $where[] = 'i.item_name LIKE ?';
    $like = '%' . $search . '%';
    $params[] = $like;
    $types .= 's';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT i.*, t.name AS type_name,
          COALESCE((SELECT SUM(d.quantity_deployed) FROM deployments d WHERE d.item_id = i.id AND d.returned_date IS NULL), 0) AS deployed_qty
        FROM inventory_items i
        JOIN item_types t ON t.id = i.item_type_id
        $whereSql
        ORDER BY t.name, i.item_name";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$itemsRes = $stmt->get_result();

$allItems = [];
$allItemIds = [];
while ($row = $itemsRes->fetch_assoc()) {
    $allItems[] = $row;
    $allItemIds[] = (int)$row['id'];
}

$batchesByItem = [];
if (!empty($allItemIds)) {
    $idList = implode(',', array_map('intval', $allItemIds));
    $bres = $conn->query(
        "SELECT b.*, u.full_name AS added_by_name
         FROM inventory_batches b
         LEFT JOIN users u ON u.id = b.added_by
         WHERE b.item_id IN ($idList)
         ORDER BY b.batch_date DESC, b.id DESC"
    );
    while ($b = $bres->fetch_assoc()) {
        $batchesByItem[$b['item_id']][] = $b;
    }
}

$remarksByItem = [];
if (!empty($allItemIds)) {
    $idList = implode(',', array_map('intval', $allItemIds));
    $rres = $conn->query(
        "SELECT r.*, u.full_name AS added_by_name
         FROM inventory_remarks r
         LEFT JOIN users u ON u.id = r.added_by
         WHERE r.item_id IN ($idList)
         ORDER BY r.remark_date DESC, r.id DESC"
    );
    while ($r = $rres->fetch_assoc()) {
        $remarksByItem[$r['item_id']][] = $r;
    }
}

$activeDeploysByItem = [];
if (!empty($allItemIds)) {
    $idList = implode(',', array_map('intval', $allItemIds));
    $ares = $conn->query(
        "SELECT d.item_id, d.quantity_deployed, d.deployment_type, d.deployed_date, d.deployed_time,
                dep.name AS dept_name, d.recipient_name
         FROM deployments d
         JOIN departments dep ON dep.id = d.department_id
         WHERE d.item_id IN ($idList) AND d.returned_date IS NULL
         ORDER BY d.deployed_date DESC, d.id DESC"
    );
    while ($a = $ares->fetch_assoc()) {
        $activeDeploysByItem[$a['item_id']][] = $a;
    }
}

$drawsByBatch = [];
if (!empty($allItemIds)) {
    $idList = implode(',', array_map('intval', $allItemIds));
    $dres = $conn->query(
        "SELECT c.batch_id, c.quantity, dep.name AS dept_name, d.deployed_date, d.returned_date
         FROM deployment_batch_consumption c
         JOIN inventory_batches b ON b.id = c.batch_id
         JOIN deployments d ON d.id = c.deployment_id
         JOIN departments dep ON dep.id = d.department_id
         WHERE b.item_id IN ($idList)
         ORDER BY d.deployed_date ASC, d.id ASC"
    );
    while ($dr = $dres->fetch_assoc()) {
        $drawsByBatch[$dr['batch_id']][] = $dr;
    }
}

$serialsByBatch = [];
if (!empty($allItemIds)) {
    $idList = implode(',', array_map('intval', $allItemIds));
    $sres = $conn->query(
        "SELECT s.batch_id, s.serial_number
         FROM inventory_batch_serials s
         JOIN inventory_batches b ON b.id = s.batch_id
         WHERE b.item_id IN ($idList)
         ORDER BY s.id ASC"
    );
    while ($sr = $sres->fetch_assoc()) {
        $serialsByBatch[$sr['batch_id']][] = $sr['serial_number'];
    }
}

$itemTypes = $conn->query("SELECT * FROM item_types ORDER BY name");
$itemTypeCount = $itemTypes->num_rows;

$invTab = ($_GET['tab'] ?? 'items') === 'deployed' ? 'deployed' : 'items';
$deployedItemsRows = [];
$specValuesByDeployment = [];
if ($invTab === 'deployed') {
    $deployedRes = $conn->query(
        "SELECT d.id, d.mr_number, d.quantity_deployed, d.deployed_date, d.deployed_time, d.remarks,
                d.department_id, d.recipient_name,
                i.item_name, t.name AS type_name, t.id AS type_id,
                dep.name AS dept_name, u.full_name AS deployed_by_name
         FROM deployments d
         JOIN inventory_items i ON i.id = d.item_id
         JOIN item_types t ON t.id = i.item_type_id
         JOIN departments dep ON dep.id = d.department_id
         JOIN users u ON u.id = d.deployed_by
         WHERE d.returned_date IS NULL
         ORDER BY d.deployed_date DESC, d.id DESC"
    );
    while ($row = $deployedRes->fetch_assoc()) {
        $deployedItemsRows[] = $row;
    }

    if (!empty($deployedItemsRows)) {
        $depIdList = implode(',', array_map(function($r){ return (int)$r['id']; }, $deployedItemsRows));
        $svRes = $conn->query("SELECT deployment_id, spec_key, spec_value FROM deployment_spec_values WHERE deployment_id IN ($depIdList)");
        while ($sv = $svRes->fetch_assoc()) {
            $specValuesByDeployment[$sv['deployment_id']][$sv['spec_key']] = $sv['spec_value'];
        }
    }
}

$deployedKindPills = [];
foreach ($deployedItemsRows as $row) {
    $deployedKindPills[$row['type_id']] = $row['type_name'];
}

$deployedBatchSiblings = [];
foreach ($deployedItemsRows as $row) {
    $batchKey = implode('|', [
        $row['mr_number'], $row['department_id'], $row['recipient_name'], $row['deployed_date'], $row['deployed_time'],
    ]);
    $deployedBatchSiblings[$batchKey][] = $row;
}

include 'includes/header.php';
?>

<?php if ($successMsg): ?><div class="alert alert-success"><?php echo h($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<div class="activity-tabs">
  <a href="inventory.php?tab=items" class="activity-tab <?php echo $invTab==='items'?'active':''; ?>">Inventory Items</a>
  <a href="inventory.php?tab=deployed" class="activity-tab <?php echo $invTab==='deployed'?'active':''; ?>">Deployed Items <span class="activity-tab-count"><?php echo count($deployedItemsRows); ?></span></a>
</div>

<?php if ($invTab === 'deployed'): ?>

<div class="panel">
  <div class="panel-header">
    <h2>Deployed Items</h2>
  </div>
  <div class="panel-body">
    <?php if (!empty($deployedKindPills)): ?>
    <div class="activity-tabs" id="deployedKindPills" style="margin-bottom:14px;">
      <button type="button" class="activity-tab active" data-kind="all" onclick="filterDeployedByKind('all', this)">All Kinds</button>
      <?php foreach ($deployedKindPills as $typeId => $typeName): ?>
        <button type="button" class="activity-tab" data-kind="<?php echo (int)$typeId; ?>" onclick="filterDeployedByKind('<?php echo (int)$typeId; ?>', this)"><?php echo h($typeName); ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($deployedItemsRows)): ?>
      <table class="data-table"><tbody><tr class="empty-row"><td colspan="7">Nothing currently deployed.</td></tr></tbody></table>
    <?php else: ?>
      <table class="data-table" id="deployedItemsTable">
        <thead>
          <tr>
            <th>MR No.</th>
            <th>Item</th>
            <th>Kind of Item</th>
            <th>Location</th>
            <th>Date Deployed</th>
            <th>Remarks</th>
            <th>Deployed By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deployedItemsRows as $d): ?>
            <tr data-kind-id="<?php echo (int)$d['type_id']; ?>" class="row-clickable" onclick="showDeployedSpecs(<?php echo (int)$d['id']; ?>)" title="Click to view unit specifications">
              <td><?php echo h($d['mr_number'] ?: '—'); ?></td>
              <td><?php echo h($d['item_name']); ?> <span style="color:var(--text-faint);">×<?php echo (int)$d['quantity_deployed']; ?></span></td>
              <td><?php echo h($d['type_name']); ?></td>
              <td><?php echo h($d['dept_name']); ?></td>
              <td><?php echo date('M j, Y', strtotime($d['deployed_date'])); ?><?php echo !empty($d['deployed_time']) ? ' · ' . date('g:i A', strtotime($d['deployed_time'])) : ''; ?></td>
              <td><?php echo h($d['remarks'] ?: '—'); ?></td>
              <td><?php echo h($d['deployed_by_name']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<script>

  function filterDeployedByKind(kindId, btn){
    document.querySelectorAll('#deployedKindPills .activity-tab').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('#deployedItemsTable tbody tr').forEach(function(row){
      if (kindId === 'all' || row.getAttribute('data-kind-id') === String(kindId)) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  }

  var deployedSpecsData = <?php
    $specsPayload = [];
    foreach ($deployedItemsRows as $d) {
        $batchKey = implode('|', [
            $d['mr_number'], $d['department_id'], $d['recipient_name'], $d['deployed_date'], $d['deployed_time'],
        ]);
        $peripherals = [];
        foreach ($deployedBatchSiblings[$batchKey] as $sib) {
            if ($sib['id'] === $d['id']) continue;
            if (!in_array(strtolower($sib['type_name']), ['keyboard', 'mouse', 'monitor'], true)) continue;
            $peripherals[] = $sib['type_name'] . ': ' . $sib['item_name'];
        }
        $specKey = spec_schema_key_for_type_name($d['type_name']);
        $fieldRows = [];
        if ($specKey !== null) {
            $savedValues = $specValuesByDeployment[$d['id']] ?? [];
            foreach (SPEC_SCHEMA[$specKey] as $fieldKey => $meta) {
                $fieldRows[] = ['label' => $meta[0], 'value' => $savedValues[$fieldKey] ?? ''];
            }
        }
        $specsPayload[$d['id']] = [
            'item'        => $d['item_name'],
            'kind'        => $d['type_name'],
            'dept'        => $d['dept_name'],
            'mr'          => $d['mr_number'] ?: '',
            'fields'      => $fieldRows,
            'peripherals' => $peripherals,
        ];
    }
    echo json_encode($specsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  ?>;

  function showDeployedSpecs(deploymentId){
    var d = deployedSpecsData[deploymentId];
    if (!d) return;
    var hasAnySpec = d.fields.some(function(f){ return f.value; });
    var bodyHtml;
    if (!d.fields.length) {
      bodyHtml = '<div class="idt-empty">This Kind of Item has no Specifications fields.</div>';
    } else if (!hasAnySpec) {
      bodyHtml = '<div class="idt-empty">No unit specifications were recorded for this deployment.</div>';
    } else {
      bodyHtml = '<div class="idt-batches"><div class="idt-batch-row-wrap"><div style="padding:12px; display:grid; grid-template-columns:1fr 1fr; gap:10px 18px;">'
        + d.fields.map(function(f){
            return '<div><div style="font-size:11px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.03em; margin-bottom:2px;">' + escHtml(f.label) + '</div>'
              + '<div style="font-size:13px;">' + (f.value ? escHtml(f.value) : '<span style="color:var(--text-faint);">—</span>') + '</div></div>';
          }).join('')
        + '</div></div></div>';
    }
    if (d.peripherals && d.peripherals.length) {
      bodyHtml += '<div class="idt-section-title" style="margin-top:14px;">Peripherals (Keyboard / Mouse / Monitor)</div>'
        + '<div class="idt-batches"><div class="idt-batch-row-wrap"><div style="padding:12px;">'
        + d.peripherals.map(function(p){ return '<div style="font-size:13px; margin-bottom:4px;">' + escHtml(p) + '</div>'; }).join('')
        + '</div></div></div>';
    }
    document.getElementById('deployedSpecsTitle').textContent = d.item + (d.mr ? ' — MR #' + d.mr : '');
    document.getElementById('deployedSpecsSubtitle').textContent = d.kind + ' · ' + d.dept;
    document.getElementById('deployedSpecsBody').innerHTML = bodyHtml;
    var backdrop = document.getElementById('deployedSpecsBackdrop');
    backdrop.classList.add('open');
    requestAnimationFrame(function(){ backdrop.classList.add('show'); });
  }

  function closeDeployedSpecs(){
    var backdrop = document.getElementById('deployedSpecsBackdrop');
    backdrop.classList.remove('show');
    setTimeout(function(){ backdrop.classList.remove('open'); }, 180);
  }
</script>

<div id="deployedSpecsBackdrop" class="login-modal-backdrop item-details-backdrop" onclick="if(event.target===this) closeDeployedSpecs();">
  <div class="login-modal-card item-details-card" style="max-width:520px;">
    <button type="button" class="login-modal-close" onclick="closeDeployedSpecs()">&times;</button>
    <h3 class="idt-title" id="deployedSpecsTitle" style="margin-bottom:2px;"></h3>
    <div class="field-hint" id="deployedSpecsSubtitle" style="margin-bottom:14px;"></div>
    <div class="idt-section-title">Unit Specifications</div>
    <div id="deployedSpecsBody"></div>
  </div>
</div>

<?php else: ?>

<?php if ($itemTypeCount === 0): ?>
  <div class="alert alert-error">
    No item types/kinds have been defined yet (e.g. "Projector", "PC Parts").
    <?php if (is_admin()): ?>
      <a href="settings.php" style="color:inherit; text-decoration:underline;">Go to Settings to add one first</a>.
    <?php else: ?>
      Ask your administrator to add item types under Settings before you can add inventory.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (is_superadmin()): ?>
<div class="panel">
  <div class="panel-header">
    <h2>Add / Restock Inventory Item</h2>
  </div>
  <div class="panel-body">
    <p class="field-hint" style="margin-bottom:14px;">
      One delivery = one PN Number + one PO Number, but a delivery often has several different kinds of items inside it (e.g. a projector, a PC set, and laptops all under the same PO). Add one row per item below — click "+ Add another item" for more. If an item row matches an existing model + kind, it restocks that item instead of creating a duplicate.
    </p>
    <form method="POST" id="restockForm">
      <input type="hidden" name="action" value="add_item">
      <div class="field-row">
        <div class="field">
          <label>PN Number</label>
          <input type="text" name="pn_number" placeholder="e.g. PN-2026-0045" required>
        </div>
        <div class="field">
          <label>PO Number</label>
          <input type="text" name="po_number" placeholder="e.g. PO-2026-0045" required>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Date Added</label>
          <input type="date" name="date_added" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="field">
          <label>Time Added</label>
          <input type="time" name="time_added" value="<?php echo date('H:i'); ?>" required>
        </div>
        <div class="field">
          <label>Low Stock Threshold</label>
          <input type="number" name="low_stock_threshold" value="5" min="0" required>
          <div class="field-hint">Applies to any new item created below.</div>
        </div>
      </div>

      <div id="restockItemRows"></div>

      <div class="form-actions" style="justify-content:flex-start; margin-bottom:16px;">
        <button type="button" class="btn-secondary btn-sm" onclick="restockAddRow()">+ Add another item</button>
      </div>

      <div class="field-row">
        <div class="field" style="flex:1 1 100%;">
          <label>Details (optional)</label>
          <textarea name="details" rows="4" placeholder="e.g. Delivered by supplier, batch 1 of 2, general delivery notes..." style="width:100%; box-sizing:border-box; background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:9px 11px; color:var(--text); font-size:13px; font-family:inherit; resize:vertical;"></textarea>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn-primary" data-loading-text="Saving..." <?php echo $itemTypeCount===0?'disabled':''; ?>>Save</button>
      </div>
    </form>
  </div>
</div>

<template id="restockRowTemplate">
  <div class="restock-item-row" style="border:1px solid var(--border); border-radius:6px; padding:12px; margin-bottom:12px; position:relative;">
    <button type="button" class="btn-secondary btn-sm restock-remove-row" style="position:absolute; top:8px; right:8px;" onclick="restockRemoveRow(this)">Remove</button>
    <div class="field-row">
      <div class="field">
        <label>Kind of Item</label>
        <select class="restock-type-select" name="item_type_id[]" required onchange="restockRenderSpecs(this.closest('.restock-item-row'))">
          <option value="">— Select —</option>
          <?php $itemTypes->data_seek(0); while ($t = $itemTypes->fetch_assoc()): ?>
            <option value="<?php echo $t['id']; ?>"><?php echo h($t['name']); ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="field">
        <label>Item Model</label>
        <input type="text" name="item_name[]" placeholder="e.g. Epson EB-X05 Projector" required>
      </div>
      <div class="field">
        <label>Quantity</label>
        <input type="number" class="restock-qty-input" name="quantity_total[]" value="1" min="1" required>
      </div>
    </div>
    <div class="restock-specs-wrap"></div>
    <div class="restock-serials-wrap">
      <label style="display:block; margin-bottom:6px; font-size:12.5px; color:var(--text-dim);">Serial Numbers (optional, one per unit)</label>
      <div class="restock-serials-list"></div>
    </div>
  </div>
</template>
<?php endif; ?>

<div class="panel">
  <div class="panel-header">
    <h2>Inventory Items</h2>
    <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap;">
      <input type="text" name="q" placeholder="Search item name..." value="<?php echo h($search); ?>" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text); font-size:12.5px;">
      <select name="type" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:7px 10px; color:var(--text-dim); font-size:12.5px;">
        <option value="">All Kinds</option>
        <?php $itemTypes->data_seek(0); while ($t = $itemTypes->fetch_assoc()): ?>
          <option value="<?php echo $t['id']; ?>" <?php echo ($filterType == $t['id'])?'selected':''; ?>><?php echo h($t['name']); ?></option>
        <?php endwhile; ?>
      </select>
      <button type="submit" class="btn-secondary btn-sm">Filter</button>
      <a href="inventory.php" class="btn-secondary btn-sm">Reset</a>
    </form>
  </div>
  <div class="panel-body" style="padding:0;">
    <?php if (empty($allItems)): ?>
      <table class="data-table"><tbody><tr class="empty-row"><td colspan="9">No inventory items found.</td></tr></tbody></table>
    <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Kind of Item</th>
              <th>Item Model</th>
              <th>Total Qty</th>
              <th>Deployed</th>
              <th>Left</th>
              <th>Status</th>
              <th>Date Added</th>
              <th>PN # / PO #</th>
              <?php if (is_admin()): ?><th>Remarks</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allItems as $i):
              $left = max(0, $i['quantity_total'] - $i['deployed_qty']);
              $isLow = $left <= $i['low_stock_threshold'];
              $statusLabel = $isLow ? 'Low Stock' : 'Good Stock';
              $statusClass = $isLow ? 'b-red' : 'b-green';
              $itemBatches = $batchesByItem[$i['id']] ?? [];

              $pnNumbers = array_values(array_unique(array_filter(array_map(function($b){ return $b['pn_number']; }, $itemBatches))));
              $poNumbers = array_values(array_unique(array_filter(array_map(function($b){ return $b['po_number'] ?? ''; }, $itemBatches))));
              $batchCount = count($itemBatches);
              if ($batchCount > 1) {
                  $pnCellHtml = '<span class="po-multi-badge">' . $batchCount . ' batches — click to view</span>';
              } elseif ($pnNumbers || $poNumbers) {
                  $pnCellHtml = 'PN ' . h($pnNumbers[0] ?? '—') . '<br>PO ' . h($poNumbers[0] ?? '—');
              } else {
                  $pnCellHtml = '—';
              }
            ?>
              <tr class="clickable-row" onclick="openItemDetails(<?php echo (int)$i['id']; ?>)">
                <td><?php echo h($i['type_name']); ?></td>
                <td><?php echo h($i['item_name']); ?></td>
                <td><?php echo (int)$i['quantity_total']; ?></td>
                <td><?php echo (int)$i['deployed_qty']; ?></td>
                <td><b><?php echo $left; ?></b></td>
                <td><span class="badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                <td><?php echo date('M j, Y', strtotime($i['date_added'])); ?></td>
                <td style="font-family:var(--font-mono); font-size:12px; color:var(--text-dim);"><?php echo $pnCellHtml; ?></td>
                <?php if (is_admin()): ?>
                <td style="white-space:nowrap;" onclick="event.stopPropagation();">
                  <button type="button" class="btn-secondary btn-sm" onclick="document.getElementById('remark-<?php echo $i['id']; ?>').classList.toggle('open-row')">Remarks</button>
                </td>
                <?php endif; ?>
              </tr>
              <?php if (is_admin()): ?>
              <tr id="remark-<?php echo $i['id']; ?>" class="expand-row" onclick="event.stopPropagation();">
                <td colspan="9" style="background:var(--panel-2);">
                  <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:10px 0;">
                    <input type="hidden" name="action" value="add_remark">
                    <input type="hidden" name="item_id" value="<?php echo $i['id']; ?>">
                    <div class="field" style="margin-bottom:0;">
                      <label>Date</label>
                      <input type="date" name="remark_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="field" style="margin-bottom:0; flex:1; min-width:220px;">
                      <label>Remark</label>
                      <input type="text" name="remark_text" placeholder="e.g. Unit checked, still working fine" required>
                    </div>
                    <button type="submit" class="btn-primary btn-sm" data-loading-text="Saving...">Add Remark</button>
                  </form>
                </td>
              </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<div id="itemDetailsBackdrop" class="login-modal-backdrop item-details-backdrop">
  <div class="login-modal-card item-details-card" onclick="event.stopPropagation();">
    <button type="button" class="login-modal-close" onclick="closeItemDetails()">&times;</button>
    <div id="itemDetailsBody">

    </div>
  </div>
</div>

<style>
  .expand-row{ display:none; }
  .expand-row.open-row{ display:table-row; }
  .clickable-row{ cursor:pointer; }
  .clickable-row:hover{ background:var(--panel-2); }
  .po-multi-badge{
    display:inline-block; padding:3px 9px; border-radius:20px;
    border:1px solid var(--accent-bright); color:var(--accent-bright);
    font-size:11px; font-weight:600; white-space:nowrap;
  }
  .clickable-row:hover .po-multi-badge{ background:var(--accent-bright); color:var(--bg); }
  .item-details-card{
    background:var(--panel); border:1px solid var(--border); border-radius:8px;
    padding:24px 26px; max-width:640px; width:100%; max-height:85vh; overflow-y:auto;
    position:relative;
  }
  .idt-title{ font-size:16px; font-weight:600; color:var(--text); margin:0 0 2px; padding-right:20px; }
  .idt-sub{ font-size:12px; color:var(--text-faint); margin:0 0 16px; }
  .idt-stats{ display:grid; grid-template-columns:repeat(auto-fit, minmax(100px,1fr)); gap:10px; margin-bottom:18px; }
  .idt-stat{ background:var(--panel-2); border:1px solid var(--border-soft); border-radius:6px; padding:10px 12px; }
  .idt-stat-label{ font-size:10.5px; letter-spacing:.04em; text-transform:uppercase; color:var(--text-faint); margin-bottom:4px; }
  .idt-stat-value{ font-size:16px; font-weight:600; color:var(--text); }
  .idt-stat-clickable{ cursor:pointer; transition:background .12s; }
  .idt-stat-clickable:hover{ background:var(--panel); border-color:var(--accent-bright); }
  .idt-expand{ margin-bottom:14px; }
  .idt-section-title{ font-family:var(--font-mono); font-size:11.5px; letter-spacing:.05em; color:var(--accent-bright); text-transform:uppercase; margin:18px 0 8px; }
  .idt-batches{ border:1px solid var(--border-soft); border-radius:6px; overflow:hidden; }
  .idt-batch-row-wrap{ border-top:1px solid var(--border-soft); }
  .idt-batch-row-wrap:first-child{ border-top:none; }
  .idt-batch-row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding:9px 12px; font-size:12.5px; }
  .idt-batch-draws{ padding:0 12px 9px; font-size:11px; color:var(--text-faint); }
  .idt-batch-tag{ font-size:10px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; padding:2px 7px; border-radius:10px; }
  .idt-batch-tag-new{ background:var(--green); color:#08130d; }
  .idt-batch-tag-old{ background:var(--text-faint); color:#08130d; }
  .idt-batch-date{ color:var(--text-dim); min-width:82px; }
  .idt-batch-qty{ font-weight:600; color:var(--text); }
  .idt-batch-po{ font-family:var(--font-mono); font-size:11.5px; color:var(--accent-bright); }
  .idt-batch-notes{ color:var(--text-faint); flex:1; min-width:140px; }
  .idt-batch-by{ color:var(--text-faint); font-size:11px; margin-left:auto; }
  .idt-empty{ color:var(--text-faint); font-size:12.5px; padding:10px 0; }
</style>

<script>

  var itemDetailsData = <?php
    $detailsPayload = [];
    foreach ($allItems as $i) {
        $left = max(0, $i['quantity_total'] - $i['deployed_qty']);
        $isLow = $left <= $i['low_stock_threshold'];
        $batches = [];
        foreach (($batchesByItem[$i['id']] ?? []) as $b) {
            $draws = [];
            foreach (($drawsByBatch[$b['id']] ?? []) as $dr) {
                $draws[] = [
                    'qty'      => (int)$dr['quantity'],
                    'dept'     => $dr['dept_name'],
                    'date'     => date('M j, Y', strtotime($dr['deployed_date'])),
                    'returned' => !empty($dr['returned_date']),
                ];
            }
            $batches[] = [
                'date'    => date('M j, Y', strtotime($b['batch_date'])),
                'time'    => !empty($b['batch_time']) ? date('g:i A', strtotime($b['batch_time'])) : '',
                'qty'     => (int)$b['quantity'],
                'pn'      => $b['pn_number'] ?: '',
                'po'      => $b['po_number'] ?? '',
                'notes'   => $b['notes'] ?: '',
                'by'      => $b['added_by_name'] ?: '',
                'draws'   => $draws,
                'serials' => array_values(array_filter($serialsByBatch[$b['id']] ?? [], function($s){ return $s !== null && $s !== ''; })),
            ];
        }
        $remarks = [];
        foreach (($remarksByItem[$i['id']] ?? []) as $r) {
            $remarks[] = [
                'date' => date('M j, Y', strtotime($r['remark_date'])),
                'text' => $r['remark'],
                'by'   => $r['added_by_name'] ?: '',
            ];
        }
        $deployedList = [];
        foreach (($activeDeploysByItem[$i['id']] ?? []) as $a) {
            $deployedList[] = [
                'qty'   => (int)$a['quantity_deployed'],
                'type'  => $a['deployment_type'] ?: 'new',
                'dept'  => $a['dept_name'],
                'to'    => $a['recipient_name'] ?: '',
                'date'  => date('M j, Y', strtotime($a['deployed_date'])),
                'time'  => !empty($a['deployed_time']) ? date('g:i A', strtotime($a['deployed_time'])) : '',
            ];
        }
        $detailsPayload[$i['id']] = [
            'name'       => $i['item_name'],
            'kind'       => $i['type_name'],
            'total'      => (int)$i['quantity_total'],
            'deployed'   => (int)$i['deployed_qty'],
            'left'       => $left,
            'status'     => $isLow ? 'Low Stock' : 'Good Stock',
            'statusGood' => !$isLow,
            'dateAdded'  => date('M j, Y', strtotime($i['date_added'])),
            'batches'    => $batches,
            'remarks'    => $remarks,
            'deployedList' => $deployedList,
        ];
    }
    echo json_encode($detailsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  ?>;

  function escHtml(s){
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
  }

  function openItemDetails(itemId){
    var data = itemDetailsData[itemId];
    if (!data) return;

    var pnList = [];
    var poList = [];
    data.batches.forEach(function(b){
      if (b.pn && pnList.indexOf(b.pn) === -1) pnList.push(b.pn);
      if (b.po && poList.indexOf(b.po) === -1) poList.push(b.po);
    });
    var pnSummary = pnList.length ? pnList.map(escHtml).join(', ') : '—';
    var poSummary = poList.length ? poList.map(escHtml).join(', ') : '—';

    var statusClass = data.statusGood ? 'b-green' : 'b-red';

    var batchesHtml;
    if (data.batches.length === 0) {
      batchesHtml = '<div class="idt-empty">No batch history recorded yet.</div>';
    } else {
      var multi = data.batches.length > 1;
      batchesHtml = '<div class="idt-batches">' + data.batches.map(function(b, idx){
        var drawsLine = '';
        if (b.draws && b.draws.length) {
          drawsLine = '<div class="idt-batch-draws">Deployed from this batch: ' + b.draws.map(function(d){
            return d.qty + ' to ' + escHtml(d.dept) + ' on ' + escHtml(d.date) + (d.returned ? ' (returned)' : '');
          }).join('; ') + '</div>';
        }
        var serialsLine = '';
        if (b.serials && b.serials.length) {
          serialsLine = '<div class="idt-batch-draws">Serial numbers: ' + b.serials.map(function(s){ return escHtml(s); }).join(', ') + '</div>';
        }
        var tag = '';
        if (multi && idx === 0) tag = '<span class="idt-batch-tag idt-batch-tag-new">Newest stock</span>';
        else if (multi && idx === data.batches.length - 1) tag = '<span class="idt-batch-tag idt-batch-tag-old">Oldest stock</span>';
        var whenLabel = escHtml(b.date) + (b.time ? ' · ' + escHtml(b.time) : '');
        return '<div class="idt-batch-row-wrap">'
          + '<div class="idt-batch-row">'
          + tag
          + '<span class="idt-batch-date">' + whenLabel + '</span>'
          + '<span class="idt-batch-qty">+' + b.qty + '</span>'
          + (b.pn ? '<span class="idt-batch-po">PN #' + escHtml(b.pn) + '</span>' : '<span class="idt-batch-po" style="color:var(--text-faint);">No PN #</span>')
          + (b.po ? '<span class="idt-batch-po">PO #' + escHtml(b.po) + '</span>' : '<span class="idt-batch-po" style="color:var(--text-faint);">No PO #</span>')
          + (b.notes ? '<span class="idt-batch-notes">' + escHtml(b.notes) + '</span>' : '')
          + (b.by ? '<span class="idt-batch-by">by ' + escHtml(b.by) + '</span>' : '')
          + '</div>'
          + drawsLine
          + serialsLine
          + '</div>';
      }).join('') + '</div>';
    }

    var deployedHtml;
    if (!data.deployedList || data.deployedList.length === 0) {
      deployedHtml = '<div class="idt-empty">Nothing currently deployed.</div>';
    } else {
      deployedHtml = '<div class="idt-batches">' + data.deployedList.map(function(d){
        var typeBadge = d.type === 'replacement' ? '<span class="badge b-accent">Replacement</span>' : '<span class="badge">New</span>';
        return '<div class="idt-batch-row-wrap"><div class="idt-batch-row">'
          + typeBadge
          + '<span class="idt-batch-date">' + escHtml(d.date) + (d.time ? ' · ' + escHtml(d.time) : '') + '</span>'
          + '<span class="idt-batch-qty">×' + d.qty + '</span>'
          + '<span class="idt-batch-notes">' + escHtml(d.dept) + (d.to ? ' — ' + escHtml(d.to) : '') + '</span>'
          + '</div></div>';
      }).join('') + '</div>';
    }

    var totalBreakdownHtml = '<div class="idt-batches">' + data.batches.map(function(b){
      return '<div class="idt-batch-row-wrap"><div class="idt-batch-row">'
        + '<span class="idt-batch-date">' + escHtml(b.date) + (b.time ? ' · ' + escHtml(b.time) : '') + '</span>'
        + '<span class="idt-batch-qty">+' + b.qty + '</span>'
        + (b.pn ? '<span class="idt-batch-po">PN #' + escHtml(b.pn) + '</span>' : '')
        + (b.po ? '<span class="idt-batch-po">PO #' + escHtml(b.po) + '</span>' : '')
        + '</div></div>';
    }).join('') + '</div>';

    var leftHtml = '<div class="idt-empty">' + data.left + ' unit(s) currently available (Total − Deployed).</div>';

    var remarksHtml;
    if (data.remarks.length === 0) {
      remarksHtml = '<div class="idt-empty">No remarks logged yet.</div>';
    } else {
      remarksHtml = '<div class="idt-batches">' + data.remarks.map(function(r){
        return '<div class="idt-batch-row-wrap"><div class="idt-batch-row">'
          + '<span class="idt-batch-date">' + escHtml(r.date) + '</span>'
          + '<span class="idt-batch-notes">' + escHtml(r.text) + '</span>'
          + (r.by ? '<span class="idt-batch-by">by ' + escHtml(r.by) + '</span>' : '')
          + '</div></div>';
      }).join('') + '</div>';
    }

    var html = ''
      + '<h3 class="idt-title">' + escHtml(data.name) + '</h3>'
      + '<p class="idt-sub">' + escHtml(data.kind) + '</p>'
      + '<div class="idt-stats">'
      +   '<div class="idt-stat idt-stat-clickable" onclick="idtToggle(\'idt-total\')"><div class="idt-stat-label">Total Qty ▾</div><div class="idt-stat-value">' + data.total + '</div></div>'
      +   '<div class="idt-stat idt-stat-clickable" onclick="idtToggle(\'idt-deployed\')"><div class="idt-stat-label">Deployed ▾</div><div class="idt-stat-value">' + data.deployed + '</div></div>'
      +   '<div class="idt-stat idt-stat-clickable" onclick="idtToggle(\'idt-left\')"><div class="idt-stat-label">Left ▾</div><div class="idt-stat-value">' + data.left + '</div></div>'
      +   '<div class="idt-stat"><div class="idt-stat-label">Status</div><div class="idt-stat-value"><span class="badge ' + statusClass + '">' + escHtml(data.status) + '</span></div></div>'
      +   '<div class="idt-stat"><div class="idt-stat-label">Date Added</div><div class="idt-stat-value" style="font-size:13px;">' + escHtml(data.dateAdded) + '</div></div>'
      +   '<div class="idt-stat"><div class="idt-stat-label">PN #</div><div class="idt-stat-value" style="font-size:12.5px; font-family:var(--font-mono);">' + pnSummary + '</div></div>'
      +   '<div class="idt-stat"><div class="idt-stat-label">PO #</div><div class="idt-stat-value" style="font-size:12.5px; font-family:var(--font-mono);">' + poSummary + '</div></div>'
      + '</div>'
      + '<div id="idt-total" class="idt-expand" style="display:none;"><div class="idt-section-title">Total Qty — Batch Breakdown</div>' + totalBreakdownHtml + '</div>'
      + '<div id="idt-deployed" class="idt-expand" style="display:none;"><div class="idt-section-title">Currently Deployed</div>' + deployedHtml + '</div>'
      + '<div id="idt-left" class="idt-expand" style="display:none;"><div class="idt-section-title">Left In Stock</div>' + leftHtml + '</div>'
      + '<div class="idt-section-title">Batch History (PN # / PO #)</div>'
      + batchesHtml
      + '<div class="idt-section-title">Remarks</div>'
      + remarksHtml;

    document.getElementById('itemDetailsBody').innerHTML = html;
    var backdrop = document.getElementById('itemDetailsBackdrop');
    backdrop.classList.add('open');
    requestAnimationFrame(function(){ backdrop.classList.add('show'); });
  }

  function idtToggle(id){
    var el = document.getElementById(id);
    if (!el) return;
    var isOpen = el.style.display !== 'none';
    document.querySelectorAll('.idt-expand').forEach(function(e){ e.style.display = 'none'; });
    el.style.display = isOpen ? 'none' : 'block';
  }

  function closeItemDetails(){
    var backdrop = document.getElementById('itemDetailsBackdrop');
    backdrop.classList.remove('show');
    setTimeout(function(){ backdrop.classList.remove('open'); }, 180);
  }

  document.getElementById('itemDetailsBackdrop').addEventListener('click', closeItemDetails);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeItemDetails();
  });

  var restockRowsWrap = document.getElementById('restockItemRows');
  var restockRowTemplate = document.getElementById('restockRowTemplate');
  var restockRowCount = 0;

  var restockSpecSchema = <?php echo json_encode(SPEC_SCHEMA, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var restockTypeIdToSpecKey = <?php
    $typeIdToSpecKey = [];
    $itemTypes->data_seek(0);
    while ($t = $itemTypes->fetch_assoc()) {
        $sk = spec_schema_key_for_type_name($t['name']);
        if ($sk !== null) $typeIdToSpecKey[(int)$t['id']] = $sk;
    }
    echo json_encode($typeIdToSpecKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  ?>;

  function restockRenderSpecs(rowEl){
    var idx = rowEl.getAttribute('data-row-index');
    var typeId = rowEl.querySelector('.restock-type-select').value;
    var specsWrap = rowEl.querySelector('.restock-specs-wrap');
    var specKey = restockTypeIdToSpecKey[typeId];
    var schema = specKey ? restockSpecSchema[specKey] : null;
    if (!schema) {
      specsWrap.innerHTML = '';
      return;
    }
    var keys = Object.keys(schema);
    var html = '<label style="display:block; margin:8px 0 6px; font-size:12.5px; color:var(--text-dim);">Specifications (optional preset for this model)</label>';
    for (var i = 0; i < keys.length; i += 2) {
      html += '<div class="field-row" style="margin-bottom:6px;">';
      for (var j = i; j < Math.min(i + 2, keys.length); j++) {
        var key = keys[j];
        var meta = schema[key];
        html += '<div class="field"><input type="text" name="spec_values[' + idx + '][' + key + ']" placeholder="' + meta[0] + ' (' + meta[1] + ')" style="width:100%; box-sizing:border-box; background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:6px 9px; color:var(--text); font-size:12.5px;"></div>';
      }
      html += '</div>';
    }
    specsWrap.innerHTML = html;
  }

  function restockAddRow(){
    var frag = restockRowTemplate.content.cloneNode(true);
    var rowEl = frag.querySelector('.restock-item-row');
    rowEl.setAttribute('data-row-index', restockRowCount);
    restockRowsWrap.appendChild(frag);
    var addedRow = restockRowsWrap.querySelector('.restock-item-row[data-row-index="' + restockRowCount + '"]');
    var qtyInput = addedRow.querySelector('.restock-qty-input');
    qtyInput.addEventListener('input', function(){ restockRenderSerials(addedRow); });
    restockRenderSerials(addedRow);
    restockRowCount++;
    restockUpdateRemoveButtons();
  }

  function restockRemoveRow(btn){
    var row = btn.closest('.restock-item-row');
    if (row) row.remove();
    restockUpdateRemoveButtons();
  }

  function restockUpdateRemoveButtons(){
    var rows = restockRowsWrap.querySelectorAll('.restock-item-row');
    rows.forEach(function(r){
      var btn = r.querySelector('.restock-remove-row');
      btn.style.display = rows.length > 1 ? '' : 'none';
    });
  }

  function restockRenderSerials(rowEl){
    var idx = rowEl.getAttribute('data-row-index');
    var qty = Math.max(1, parseInt(rowEl.querySelector('.restock-qty-input').value, 10) || 1);
    var list = rowEl.querySelector('.restock-serials-list');
    var existing = Array.prototype.slice.call(list.querySelectorAll('input')).map(function(i){ return i.value; });
    var html = '';
    for (var u = 0; u < qty; u++) {
      var val = existing[u] ? ' value="' + existing[u].replace(/"/g, '&quot;') + '"' : '';
      html += '<input type="text" name="serial_numbers[' + idx + '][]" placeholder="Unit ' + (u+1) + ' serial number (optional)"'
        + val + ' style="width:100%; box-sizing:border-box; background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:6px 9px; color:var(--text); font-size:12.5px; margin-bottom:6px;">';
    }
    list.innerHTML = html;
  }

  if (restockRowsWrap) {
    restockAddRow();
  }
</script>

<?php include 'includes/footer.php'; ?>
