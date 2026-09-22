<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

$pageTitle = 'Messages';
$activePage = 'messages';
$me = current_user();
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');

    if ($recipientId === $me['id']) {
        $errorMsg = "You can't send a message to yourself.";
    } elseif ($recipientId === 0 || $body === '') {
        $errorMsg = 'Please choose a recipient and enter a message.';
    } else {

        $chk = $conn->prepare("SELECT id, full_name FROM users WHERE id = ?");
        $chk->bind_param('i', $recipientId);
        $chk->execute();
        $recipient = $chk->get_result()->fetch_assoc();

        if (!$recipient) {
            $errorMsg = 'Recipient not found.';
        } else {
            $stmt = $conn->prepare("INSERT INTO messages (sender_id, recipient_id, body) VALUES (?,?,?)");
            $stmt->bind_param('iis', $me['id'], $recipientId, $body);
            $stmt->execute();
            header('Location: messages.php?with=' . $recipientId);
            exit;
        }
    }
}

$withId = (int)($_GET['with'] ?? 0);
if ($withId > 0) {
    $mark = $conn->prepare("UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0");
    $mark->bind_param('ii', $me['id'], $withId);
    $mark->execute();
}

$convRes = $conn->prepare(
    "SELECT u.id, u.full_name, u.role,
       (SELECT body FROM messages m WHERE (m.sender_id=u.id AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=u.id) ORDER BY m.created_at DESC LIMIT 1) AS last_body,
       (SELECT created_at FROM messages m WHERE (m.sender_id=u.id AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=u.id) ORDER BY m.created_at DESC LIMIT 1) AS last_at,
       (SELECT COUNT(*) FROM messages m WHERE m.sender_id=u.id AND m.recipient_id=? AND m.is_read=0) AS unread_count
     FROM users u
     WHERE u.id != ? AND u.status = 'active'
     ORDER BY last_at IS NULL, last_at DESC, u.full_name"
);
$convRes->bind_param('iiiiii', $me['id'], $me['id'], $me['id'], $me['id'], $me['id'], $me['id']);
$convRes->execute();
$conversations = $convRes->get_result();

$thread = null;
$otherUser = null;
if ($withId > 0) {
    $ou = $conn->prepare("SELECT id, full_name, role FROM users WHERE id = ?");
    $ou->bind_param('i', $withId);
    $ou->execute();
    $otherUser = $ou->get_result()->fetch_assoc();

    if ($otherUser) {
        $t = $conn->prepare(
            "SELECT m.*, u.full_name AS sender_name
             FROM messages m JOIN users u ON u.id = m.sender_id
             WHERE (m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)
             ORDER BY m.created_at ASC"
        );
        $t->bind_param('iiii', $me['id'], $withId, $withId, $me['id']);
        $t->execute();
        $thread = $t->get_result();
    }
}

include 'includes/header.php';
?>

<?php if ($errorMsg): ?><div class="alert alert-error"><?php echo h($errorMsg); ?></div><?php endif; ?>

<div class="msg-layout">
  <div class="panel msg-conv-list">
    <div class="panel-header"><h2>Conversations</h2></div>
    <div class="panel-body" style="padding:0;">
      <?php if ($conversations->num_rows === 0): ?>
        <div style="padding:20px; color:var(--text-faint); font-size:13px;">No other users yet.</div>
      <?php else: while ($c = $conversations->fetch_assoc()): ?>
        <a href="messages.php?with=<?php echo $c['id']; ?>" class="msg-conv-item <?php echo $withId==$c['id']?'active':''; ?>">
          <span class="account-avatar" style="flex-shrink:0;"><?php echo strtoupper(substr($c['full_name'],0,1)); ?></span>
          <span class="msg-conv-info">
            <span class="msg-conv-name">
              <?php echo h($c['full_name']); ?>
              <span class="account-role-badge role-<?php echo $c['role']; ?>" style="margin-left:6px;"><?php echo h($c['role']); ?></span>
            </span>
            <span class="msg-conv-preview"><?php echo $c['last_body'] ? h(mb_strimwidth($c['last_body'], 0, 46, '…')) : 'No messages yet — say hi!'; ?></span>
          </span>
          <?php if ($c['unread_count'] > 0): ?>
            <span class="msg-unread-badge"><?php echo (int)$c['unread_count']; ?></span>
          <?php endif; ?>
        </a>
      <?php endwhile; endif; ?>
    </div>
  </div>

  <div class="panel msg-thread">
    <?php if (!$otherUser): ?>
      <div class="panel-body" style="text-align:center; color:var(--text-faint); padding:60px 20px;">
        Select a conversation on the left to start messaging.
      </div>
    <?php else: ?>
      <div class="panel-header">
        <h2><?php echo h($otherUser['full_name']); ?> <span class="account-role-badge role-<?php echo $otherUser['role']; ?>"><?php echo h($otherUser['role']); ?></span></h2>
      </div>
      <div class="msg-thread-body" id="msgThreadBody">
        <?php if ($thread->num_rows === 0): ?>
          <div style="text-align:center; color:var(--text-faint); padding:30px 0;">No messages yet. Send the first one below.</div>
        <?php else: while ($m = $thread->fetch_assoc()):
          $mine = $m['sender_id'] == $me['id']; ?>
          <div class="msg-bubble-row <?php echo $mine ? 'mine' : ''; ?>">
            <div class="msg-bubble <?php echo $mine ? 'mine' : ''; ?>">
              <div class="msg-bubble-text"><?php echo nl2br(h($m['body'])); ?></div>
              <div class="msg-bubble-time"><?php echo date('M j, g:i a', strtotime($m['created_at'])); ?></div>
            </div>
          </div>
        <?php endwhile; endif; ?>
      </div>
      <form method="POST" class="msg-composer" id="msgComposerForm">
        <input type="hidden" name="action" value="send_message">
        <input type="hidden" name="recipient_id" value="<?php echo $otherUser['id']; ?>">
        <textarea name="body" placeholder="Write a message..." required></textarea>
        <button type="submit" class="btn-primary" data-loading-text="Sending...">Send</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<style>
  .msg-layout{ display:grid; grid-template-columns:300px 1fr; gap:16px; align-items:start; }
  .msg-conv-list .panel-body{ max-height:70vh; overflow-y:auto; }
  .msg-conv-item{
    display:flex; align-items:center; gap:10px; padding:12px 16px;
    border-bottom:1px solid var(--border-soft); color:var(--text);
  }
  .msg-conv-item:hover{ background:var(--panel-2); }
  .msg-conv-item.active{ background:var(--panel-2); border-left:3px solid var(--accent-bright); padding-left:13px; }
  .msg-conv-info{ flex:1; min-width:0; display:flex; flex-direction:column; gap:2px; }
  .msg-conv-name{ font-size:13px; font-weight:600; white-space:nowrap; }
  .msg-conv-preview{ font-size:11.5px; color:var(--text-faint); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .msg-unread-badge{
    background:var(--accent-bright); color:#0F1216; font-size:11px; font-weight:700;
    border-radius:12px; padding:1px 8px; flex-shrink:0;
  }
  .msg-thread{ display:flex; flex-direction:column; min-height:60vh; }
  .msg-thread-body{ flex:1; overflow-y:auto; padding:18px; display:flex; flex-direction:column; gap:10px; max-height:55vh; }
  .msg-bubble-row{ display:flex; }
  .msg-bubble-row.mine{ justify-content:flex-end; }
  .msg-bubble{
    max-width:70%; background:var(--panel-2); border:1px solid var(--border-soft);
    border-radius:10px; padding:9px 13px;
  }
  .msg-bubble.mine{ background:var(--accent); color:#0F1216; border-color:var(--accent); }
  .msg-bubble-text{ font-size:13.5px; white-space:pre-wrap; word-break:break-word; }
  .msg-bubble-time{ font-size:10px; margin-top:4px; opacity:.65; font-family:var(--font-mono); }
  .msg-composer{ display:flex; gap:10px; padding:14px 18px; border-top:1px solid var(--border-soft); }
  .msg-composer textarea{
    flex:1; resize:none; min-height:44px; background:var(--bg); border:1px solid var(--border);
    border-radius:6px; padding:10px 12px; color:var(--text); font-family:var(--font-ui); font-size:13.5px;
  }
  @media (max-width: 900px){
    .msg-layout{ grid-template-columns:1fr; }
  }
</style>

<script>

  (function(){
    var el = document.getElementById('msgThreadBody');
    if (el) el.scrollTop = el.scrollHeight;
  })();
</script>

<?php include 'includes/footer.php'; ?>
