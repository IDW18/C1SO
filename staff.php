<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_admin();

$pageTitle = 'Manage Staff';
$activePage = 'staff';
$successMsg = '';
$errorMsg = '';
$currentUserId = current_user()['id'];

function is_target_superadmin($conn, $id) {
    if ($id <= 0) return false;
    $r = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $r->bind_param('i', $id);
    $r->execute();
    $row = $r->get_result()->fetch_assoc();
    return $row && $row['role'] === 'superadmin';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['admin','staff']) ? $_POST['role'] : 'staff';

        if ($fullName === '' || $username === '' || $password === '') {
            $errorMsg = 'Full name, username, and password are all required.';
        } elseif (strlen($password) < 6) {
            $errorMsg = 'Password must be at least 6 characters.';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param('s', $username);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $errorMsg = 'That username is already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?,?,?,?)");
                $stmt->bind_param('ssss', $fullName, $username, $hash, $role);
                $stmt->execute();
                log_activity($conn, 'Added ' . $role . ' account', "$fullName (@$username)", 'staff');
                $successMsg = "Account for \"$fullName\" created as $role.";
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== $currentUserId && !is_target_superadmin($conn, $id)) {
            $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','disabled','active') WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $nameRes = $conn->prepare("SELECT full_name, status FROM users WHERE id=?");
            $nameRes->bind_param('i', $id);
            $nameRes->execute();
            $row = $nameRes->get_result()->fetch_assoc();
            log_activity($conn, 'Changed account status', ($row['full_name'] ?? '') . ' → ' . ($row['status'] ?? ''), 'staff');
            $successMsg = 'Account status updated.';
        } elseif ($id === $currentUserId) {
            $errorMsg = "You can't disable your own account.";
        } else {
            $errorMsg = "This account can't be modified from here.";
        }
    } elseif ($action === 'change_role') {
        $id = (int)($_POST['id'] ?? 0);
        $newRole = in_array($_POST['role'] ?? '', ['admin','staff']) ? $_POST['role'] : 'staff';
        if ($id !== $currentUserId && !is_target_superadmin($conn, $id)) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param('si', $newRole, $id);
            $stmt->execute();
            log_activity($conn, 'Changed account role', "User #$id → $newRole", 'staff');
            $successMsg = 'Role updated.';
        } elseif ($id === $currentUserId) {
            $errorMsg = "You can't change your own role here.";
        } else {
            $errorMsg = "This account can't be modified from here.";
        }
    } elseif ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== $currentUserId && !is_target_superadmin($conn, $id)) {
            $nameRes = $conn->prepare("SELECT full_name FROM users WHERE id=?");
            $nameRes->bind_param('i', $id);
            $nameRes->execute();
            $name = $nameRes->get_result()->fetch_assoc()['full_name'] ?? '';
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            log_activity($conn, 'Deleted account', $name, 'staff');
            $successMsg = 'Account deleted.';
        } elseif ($id === $currentUserId) {
            $errorMsg = "You can't delete your own account.";
        } else {
            $errorMsg = "This account can't be modified from here.";
        }
    }
}

$users = $conn->query("SELECT * FROM users ORDER BY role DESC, full_name");

include 'includes/header.php';
?>

<?php if ($successMsg): ?><div class="alert alert-success"><?php echo h($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-header"><h2>Add Account (Admin or Staff)</h2></div>
  <div class="panel-body">
    <form method="POST">
      <input type="hidden" name="action" value="add_user">
      <div class="field-row">
        <div class="field">
          <label>Full Name</label>
          <input type="text" name="full_name" required>
        </div>
        <div class="field">
          <label>Username (used to log in)</label>
          <input type="text" name="username" required>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" minlength="6" required>
        </div>
        <div class="field">
          <label>Role</label>
          <select name="role">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn-primary" data-loading-text="Creating...">Create Account</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-header"><h2>All Accounts</h2></div>
  <div class="panel-body" style="padding:0;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Status</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php while ($u = $users->fetch_assoc()): ?>
          <tr>
            <td><?php echo h($u['full_name']); ?><?php echo $u['id']==$currentUserId ? ' <span class="badge b-accent">You</span>' : ''; ?></td>
            <td><span class="badge">@<?php echo h($u['username']); ?></span></td>
            <td>
              <?php if ($u['role'] === 'superadmin'): ?>
                <span class="badge b-amber">Super Admin</span>
              <?php elseif ($u['id'] != $currentUserId): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <select name="role" onchange="this.form.submit()" style="background:var(--bg); border:1px solid var(--border); border-radius:4px; padding:4px 6px; color:var(--text); font-size:12px;">
                    <option value="staff" <?php echo $u['role']==='staff'?'selected':''; ?>>Staff</option>
                    <option value="admin" <?php echo $u['role']==='admin'?'selected':''; ?>>Admin</option>
                  </select>
                </form>
              <?php else: ?>
                <span class="badge b-amber"><?php echo h($u['role']); ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?php echo $u['status']==='active'?'b-green':'b-red'; ?>"><?php echo h($u['status']); ?></span>
            </td>
            <td><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
            <td style="display:flex; gap:6px;">
              <?php if ($u['role'] !== 'superadmin' && $u['id'] != $currentUserId): ?>
                <form method="POST">
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="btn-secondary btn-sm"><?php echo $u['status']==='active'?'Disable':'Enable'; ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Permanently delete this account?');">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                  <button type="submit" class="btn-danger btn-sm">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
