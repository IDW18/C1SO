<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_admin();

$pageTitle = 'Settings';
$activePage = 'settings';

$successMsg = '';
$errorMsg = '';
$userId = current_user()['id'];

$reopenModal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item_type') {
        $name = trim($_POST['name'] ?? '');
        $reopenModal = 'itemTypes';
        if ($name === '') {
            $errorMsg = 'Please enter a name for the item type.';
        } else {

            $dupCheck = $conn->prepare("SELECT id, name FROM item_types WHERE LOWER(name) = LOWER(?)");
            $dupCheck->bind_param('s', $name);
            $dupCheck->execute();
            $dup = $dupCheck->get_result()->fetch_assoc();
            if ($dup) {
                $errorMsg = "Item type \"{$dup['name']}\" already exists — pick a different name, or use the existing one.";
            } else {
                $stmt = $conn->prepare("INSERT INTO item_types (name, created_by) VALUES (?, ?)");
                $stmt->bind_param('si', $name, $userId);
                if ($stmt->execute()) {
                    log_activity($conn, 'Added item type', $name, 'settings');
                    $successMsg = "Item type \"$name\" added.";
                    $reopenModal = '';
                } else {
                    $errorMsg = 'Could not add item type: ' . h($conn->error);
                }
            }
        }
    } elseif ($action === 'delete_item_type') {
        $id = (int)($_POST['id'] ?? 0);

        $check = $conn->prepare("SELECT COUNT(*) c FROM inventory_items WHERE item_type_id = ?");
        $check->bind_param('i', $id);
        $check->execute();
        $count = $check->get_result()->fetch_assoc()['c'];
        if ($count > 0) {
            $errorMsg = 'Cannot delete: there are inventory items using this type.';
        } else {
            $nameRes = $conn->prepare("SELECT name FROM item_types WHERE id=?");
            $nameRes->bind_param('i', $id);
            $nameRes->execute();
            $name = $nameRes->get_result()->fetch_assoc()['name'] ?? '';
            $stmt = $conn->prepare("DELETE FROM item_types WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            log_activity($conn, 'Deleted item type', $name, 'settings');
            $successMsg = 'Item type deleted.';
        }
    } elseif ($action === 'add_department') {
        $name = trim($_POST['name'] ?? '');
        $reopenModal = 'departments';
        if ($name === '') {
            $errorMsg = 'Please enter a department name.';
        } else {
            $dupCheck = $conn->prepare("SELECT id, name FROM departments WHERE LOWER(name) = LOWER(?)");
            $dupCheck->bind_param('s', $name);
            $dupCheck->execute();
            $dup = $dupCheck->get_result()->fetch_assoc();
            if ($dup) {
                $errorMsg = "Department \"{$dup['name']}\" already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO departments (name) VALUES (?)");
                $stmt->bind_param('s', $name);
                if ($stmt->execute()) {
                    log_activity($conn, 'Added department', $name, 'settings');
                    $successMsg = "Department \"$name\" added.";
                    $reopenModal = '';
                } else {
                    $errorMsg = 'Could not add department: ' . h($conn->error);
                }
            }
        }
    } elseif ($action === 'delete_department') {
        $id = (int)($_POST['id'] ?? 0);
        $check = $conn->prepare("SELECT COUNT(*) c FROM deployments WHERE department_id = ?");
        $check->bind_param('i', $id);
        $check->execute();
        $count = $check->get_result()->fetch_assoc()['c'];
        if ($count > 0) {
            $errorMsg = 'Cannot delete: there are deployment records for this department.';
        } else {
            $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            log_activity($conn, 'Deleted department', '', 'settings');
            $successMsg = 'Department deleted.';
        }
    } elseif ($action === 'add_department_person') {
        $deptId = (int)($_POST['department_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $reopenModal = 'deptPeople';
        if ($deptId <= 0 || $name === '') {
            $errorMsg = 'Select a department and enter a name.';
        } else {
            $dupCheck = $conn->prepare("SELECT id, name FROM department_people WHERE department_id = ? AND LOWER(name) = LOWER(?)");
            $dupCheck->bind_param('is', $deptId, $name);
            $dupCheck->execute();
            $dup = $dupCheck->get_result()->fetch_assoc();
            if ($dup) {
                $errorMsg = "\"{$dup['name']}\" is already on file for this department.";
            } else {
                $stmt = $conn->prepare("INSERT INTO department_people (department_id, name) VALUES (?, ?)");
                $stmt->bind_param('is', $deptId, $name);
                if ($stmt->execute()) {
                    log_activity($conn, 'Added faculty/staff', $name, 'settings');
                    $successMsg = "\"$name\" added.";
                    $reopenModal = '';
                } else {
                    $errorMsg = 'Could not add faculty/staff: ' . h($conn->error);
                }
            }
        }
    } elseif ($action === 'delete_department_person') {
        $id = (int)($_POST['id'] ?? 0);
        $nameRes = $conn->prepare("SELECT name FROM department_people WHERE id=?");
        $nameRes->bind_param('i', $id);
        $nameRes->execute();
        $name = $nameRes->get_result()->fetch_assoc()['name'] ?? '';
        $stmt = $conn->prepare("DELETE FROM department_people WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity($conn, 'Deleted faculty/staff', $name, 'settings');
        $successMsg = 'Faculty/staff removed.';
    } elseif ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $reopenModal = 'categories';
        if ($name === '') {
            $errorMsg = 'Please enter a category name.';
        } else {
            $dupCheck = $conn->prepare("SELECT id, name FROM categories WHERE LOWER(name) = LOWER(?)");
            $dupCheck->bind_param('s', $name);
            $dupCheck->execute();
            $dup = $dupCheck->get_result()->fetch_assoc();
            if ($dup) {
                $errorMsg = "Category \"{$dup['name']}\" already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->bind_param('s', $name);
                if ($stmt->execute()) {
                    log_activity($conn, 'Added work log category', $name, 'settings');
                    $successMsg = "Category \"$name\" added.";
                    $reopenModal = '';
                } else {
                    $errorMsg = 'Could not add category: ' . h($conn->error);
                }
            }
        }
    } elseif ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        log_activity($conn, 'Deleted work log category', '', 'settings');
        $successMsg = 'Category deleted.';
    }
}

$itemTypes = $conn->query("SELECT t.*, (SELECT COUNT(*) FROM inventory_items i WHERE i.item_type_id = t.id) AS item_count FROM item_types t ORDER BY t.name");
$departments = $conn->query("SELECT * FROM departments ORDER BY name");
$categories = $conn->query("SELECT * FROM categories ORDER BY name");

$deptOptions = $conn->query("SELECT id, name FROM departments ORDER BY name");
$deptPeople = $conn->query("SELECT p.*, d.name AS dept_name FROM department_people p JOIN departments d ON d.id = p.department_id ORDER BY d.name, p.name");

include 'includes/header.php';
?>

<?php if ($successMsg): ?><div class="alert alert-success"><?php echo h($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<p style="font-family:monospace; font-size:11px; color:#7EA3C4; margin-bottom:8px;">build: settings-tabs-v4</p>

<style>
  .settings-tabs{ display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid var(--border); margin-bottom:20px; padding-bottom:0; }
  .settings-tab-btn{
    background:none; border:1px solid transparent; border-bottom:none;
    color:var(--text-faint); font-size:13.5px; font-weight:600;
    padding:10px 16px; border-radius:8px 8px 0 0; cursor:pointer;
    margin-bottom:-1px;
  }
  .settings-tab-btn:hover{ color:var(--text); }
  .settings-tab-btn.active{
    color:var(--accent-bright); border-color:var(--border); background:var(--panel);
    border-bottom:1px solid var(--panel);
  }
  .settings-tab-panel{ display:none; }
  .settings-tab-panel.active{ display:block; }
</style>

<div class="settings-tabs">
  <button type="button" class="settings-tab-btn" data-tab="itemTypes" onclick="showSettingsTab('itemTypes')">Item Types</button>
  <button type="button" class="settings-tab-btn" data-tab="departments" onclick="showSettingsTab('departments')">Departments</button>
  <button type="button" class="settings-tab-btn" data-tab="categories" onclick="showSettingsTab('categories')">Work Log Categories</button>
</div>

<div id="settingsTab-itemTypes" class="settings-tab-panel">
<div class="panel">
  <div class="panel-header"><h2>Inventory Item Types (Kinds of Item)</h2></div>
  <div class="panel-body">
    <p class="field-hint" style="margin-bottom:12px;">Add or remove the kinds of item used across Inventory (e.g. Projector, PC Parts, Laptop, Monitor).</p>
    <button type="button" class="btn-primary" onclick="openSettingsModal('itemTypes')">+ Add Item Type</button>
    <table class="data-table" style="margin-top:16px;">
      <thead><tr><th>Name</th><th># Items</th><th></th></tr></thead>
      <tbody>
        <?php if ($itemTypes->num_rows > 0): while ($t = $itemTypes->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($t['name']); ?></td>
            <td><?php echo (int)$t['item_count']; ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Delete this item type?');">
                <input type="hidden" name="action" value="delete_item_type">
                <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                <button type="submit" class="btn-danger btn-sm" <?php echo $t['item_count']>0?'disabled title="In use"':''; ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="3">No item types yet. Click "+ Add Item Type" above to add your first one (e.g. "Projector").</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<div id="settingsTab-departments" class="settings-tab-panel">
<div class="panel">
  <div class="panel-header"><h2>Departments</h2></div>
  <div class="panel-body">
    <p class="field-hint" style="margin-bottom:12px;">Add or remove departments that items can be deployed to.</p>
    <button type="button" class="btn-primary" onclick="openSettingsModal('departments')">+ Add Department</button>
    <table class="data-table" style="margin-top:16px;">
      <thead><tr><th>Name</th><th></th></tr></thead>
      <tbody>
        <?php if ($departments->num_rows > 0): while ($d = $departments->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($d['name']); ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Delete this department? This also removes its faculty/staff list.');">
                <input type="hidden" name="action" value="delete_department">
                <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                <button type="submit" class="btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="2">No departments yet. Click "+ Add Department" above to add your first one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2>Faculty / Staff (per Department)</h2></div>
  <div class="panel-body">
    <p class="field-hint" style="margin-bottom:12px;">These are the names that show up when deploying an item to a department — add at least one name per department here, or the Deployment wizard will have nothing to select.</p>
    <button type="button" class="btn-primary" onclick="openSettingsModal('deptPeople')">+ Add Faculty/Staff</button>
    <table class="data-table" style="margin-top:16px;">
      <thead><tr><th>Name</th><th>Department</th><th></th></tr></thead>
      <tbody>
        <?php if ($deptPeople->num_rows > 0): while ($p = $deptPeople->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($p['name']); ?></td>
            <td><?php echo h($p['dept_name']); ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Remove this name?');">
                <input type="hidden" name="action" value="delete_department_person">
                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                <button type="submit" class="btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="3">No faculty/staff added yet. Add a department first, then click "+ Add Faculty/Staff" above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<div id="settingsTab-categories" class="settings-tab-panel">
<div class="panel">
  <div class="panel-header"><h2>Work Log Categories</h2></div>
  <div class="panel-body">
    <p class="field-hint" style="margin-bottom:12px;">Manage/delete categories here. New categories are usually added from the Work Log page, but you can also add one here.</p>
    <button type="button" class="btn-primary" onclick="openSettingsModal('categories')">+ Add Category</button>
    <table class="data-table" style="margin-top:16px;">
      <thead><tr><th>Name</th><th></th></tr></thead>
      <tbody>
        <?php if ($categories->num_rows > 0): while ($c = $categories->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($c['name']); ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Delete this category?');">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                <button type="submit" class="btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr class="empty-row"><td colspan="2">No categories yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<script>

  var SETTINGS_TAB_KEY = 'c1sotech_settings_active_tab';

  function showSettingsTab(tab){
    document.querySelectorAll('.settings-tab-panel').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.settings-tab-btn').forEach(function(b){ b.classList.remove('active'); });
    var panel = document.getElementById('settingsTab-' + tab);
    var btn = document.querySelector('.settings-tab-btn[data-tab="' + tab + '"]');
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');
    try { sessionStorage.setItem(SETTINGS_TAB_KEY, tab); } catch (e) {}
  }

  (function initSettingsTabs(){
    var saved = null;
    try { saved = sessionStorage.getItem(SETTINGS_TAB_KEY); } catch (e) {}
    var valid = ['itemTypes','departments','categories'];
    showSettingsTab(valid.indexOf(saved) !== -1 ? saved : 'itemTypes');
  })();
</script>

<div id="settingsModalBackdrop" class="login-modal-backdrop item-details-backdrop" onclick="closeSettingsModal()">
  <div class="login-modal-card item-details-card" onclick="event.stopPropagation();" style="max-width:440px;">
    <button type="button" class="login-modal-close" onclick="closeSettingsModal()">&times;</button>

    <div id="settingsModal-itemTypes" class="settings-modal-pane">
      <h3 class="idt-title" style="margin-bottom:18px;">Add Item Type</h3>
      <form method="POST">
        <input type="hidden" name="action" value="add_item_type">
        <div class="field">
          <label for="stgItemTypeName">Item Type Name</label>
          <input type="text" id="stgItemTypeName" name="name" placeholder="e.g. Projector, PC Parts, Laptop, Monitor" required autofocus>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeSettingsModal()">Cancel</button>
          <button type="submit" class="btn-primary">Add Type</button>
        </div>
      </form>
    </div>

    <div id="settingsModal-departments" class="settings-modal-pane" style="display:none;">
      <h3 class="idt-title" style="margin-bottom:18px;">Add Department</h3>
      <form method="POST">
        <input type="hidden" name="action" value="add_department">
        <div class="field">
          <label for="stgDeptName">Department Name</label>
          <input type="text" id="stgDeptName" name="name" placeholder="e.g. Registrar's Office, MIS Department" required autofocus>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeSettingsModal()">Cancel</button>
          <button type="submit" class="btn-primary">Add Department</button>
        </div>
      </form>
    </div>

    <div id="settingsModal-deptPeople" class="settings-modal-pane" style="display:none;">
      <h3 class="idt-title" style="margin-bottom:18px;">Add Faculty/Staff</h3>
      <form method="POST">
        <input type="hidden" name="action" value="add_department_person">
        <div class="field">
          <label for="stgDeptPersonDept">Department</label>
          <select id="stgDeptPersonDept" name="department_id" required>
            <option value="">Select department…</option>
            <?php $deptOptions->data_seek(0); while ($do = $deptOptions->fetch_assoc()): ?>
              <option value="<?php echo $do['id']; ?>"><?php echo h($do['name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="field">
          <label for="stgDeptPersonName">Faculty/Staff Name</label>
          <input type="text" id="stgDeptPersonName" name="name" placeholder="e.g. Juan Dela Cruz" required>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeSettingsModal()">Cancel</button>
          <button type="submit" class="btn-primary">Add Name</button>
        </div>
      </form>
    </div>

    <div id="settingsModal-categories" class="settings-modal-pane" style="display:none;">
      <h3 class="idt-title" style="margin-bottom:18px;">Add Work Log Category</h3>
      <form method="POST">
        <input type="hidden" name="action" value="add_category">
        <div class="field">
          <label for="stgCategoryName">Category Name</label>
          <input type="text" id="stgCategoryName" name="name" placeholder="e.g. Maintenance, Troubleshooting" required autofocus>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeSettingsModal()">Cancel</button>
          <button type="submit" class="btn-primary">Add Category</button>
        </div>
      </form>
    </div>

  </div>
</div>

<script>

  function openSettingsModal(which){
    document.querySelectorAll('.settings-modal-pane').forEach(function(p){ p.style.display = 'none'; });
    var pane = document.getElementById('settingsModal-' + which);
    if (pane) pane.style.display = 'block';
    var backdrop = document.getElementById('settingsModalBackdrop');
    backdrop.classList.add('open');
    requestAnimationFrame(function(){ backdrop.classList.add('show'); });
    var firstInput = pane ? pane.querySelector('input[type=text], select') : null;
    if (firstInput) setTimeout(function(){ firstInput.focus(); }, 150);
  }

  function closeSettingsModal(){
    var backdrop = document.getElementById('settingsModalBackdrop');
    backdrop.classList.remove('show');
    setTimeout(function(){ backdrop.classList.remove('open'); }, 180);
  }

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeSettingsModal();
  });
</script>

<?php include 'includes/footer.php'; ?>
