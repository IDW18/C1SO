<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

$pageTitle = 'My Account';
$activePage = '';
$successMsg = '';
$errorMsg = '';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $errorMsg = 'Current password is incorrect.';
    } elseif ($fullName === '' || $username === '') {
        $errorMsg = 'Name and username cannot be empty.';
    } else {

        $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->bind_param('si', $username, $user['id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errorMsg = 'That username is already taken.';
        } else {
            if ($newPassword !== '') {
                if (strlen($newPassword) < 6) {
                    $errorMsg = 'New password must be at least 6 characters.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, password_hash=? WHERE id=?");
                    $stmt->bind_param('sssi', $fullName, $username, $hash, $user['id']);
                    $stmt->execute();
                }
            }
            if (!$errorMsg) {
                if ($newPassword === '') {
                    $stmt = $conn->prepare("UPDATE users SET full_name=?, username=? WHERE id=?");
                    $stmt->bind_param('ssi', $fullName, $username, $user['id']);
                    $stmt->execute();
                }
                $_SESSION['full_name'] = $fullName;
                $_SESSION['username'] = $username;
                log_activity($conn, 'Updated own account', 'profile info' . ($newPassword !== '' ? ' + password' : ''), 'account');
                $successMsg = 'Account updated successfully.';
                $user = current_user();
            }
        }
    }
}

include 'includes/header.php';
?>

<?php if ($successMsg): ?><div class="alert alert-success"><?php echo h($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<div class="panel" style="max-width:480px;">
  <div class="panel-header"><h2>Change Login Name / Password</h2></div>
  <div class="panel-body">
    <form method="POST">
      <div class="field">
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?php echo h($user['full_name']); ?>" required>
      </div>
      <div class="field">
        <label>Username (login name)</label>
        <input type="text" name="username" value="<?php echo h($user['username']); ?>" required>
      </div>
      <div class="field">
        <label>New Password (leave blank to keep current)</label>
        <input type="password" name="new_password" minlength="6" placeholder="••••••••">
      </div>
      <div class="field">
        <label>Current Password (required to save changes)</label>
        <input type="password" name="current_password" required>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn-primary" data-loading-text="Saving...">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
