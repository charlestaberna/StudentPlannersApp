<?php
require_once __DIR__ . '/core/auth.php';

if (!$isAdmin) {
    header("Location: index.php");
    exit;
}

// self-heal: make sure older installs also accept the 'faculty' role
mysqli_query($conn, "ALTER TABLE login_accounts MODIFY COLUMN role ENUM('admin','faculty','student') NOT NULL DEFAULT 'student'");

$message = ""; $msgType = "ok";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $id = intval($_POST['account_id']);
        mysqli_query($conn, "UPDATE login_accounts SET is_approved=1 WHERE id=$id");
        $message = "Account approved."; $msgType = "ok";
    }
    if ($action === 'revoke') {
        $id = intval($_POST['account_id']);
        if ($id !== $userId) {
            mysqli_query($conn, "UPDATE login_accounts SET is_approved=0 WHERE id=$id");
            $message = "Access revoked."; $msgType = "ok";
        }
    }
    if ($action === 'change_role') {
        $id = intval($_POST['account_id']);
        $role = in_array($_POST['role'] ?? '', ['admin','faculty','student'], true) ? $_POST['role'] : 'student';
        if ($id !== $userId) {
            mysqli_query($conn, "UPDATE login_accounts SET role='$role' WHERE id=$id");
            $message = "Role updated."; $msgType = "ok";
        }
    }
    if ($action === 'delete_account') {
        $id = intval($_POST['account_id']);
        if ($id !== $userId) {
            mysqli_query($conn, "DELETE FROM login_accounts WHERE id=$id");
            $message = "Account deleted."; $msgType = "ok";
        } else {
            $message = "You cannot delete your own account."; $msgType = "err";
        }
    }
}

$pending  = mysqli_query($conn, "SELECT * FROM login_accounts WHERE is_approved=0 ORDER BY created_at DESC");
$accounts = mysqli_query($conn, "SELECT * FROM login_accounts WHERE is_approved=1 ORDER BY name ASC");

$pageTitle = "Manage Accounts";
$pageSub   = "Approve new users and manage access.";
$activeNav = "accounts";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<?php if (mysqli_num_rows($pending) > 0): ?>
<div class="section-head"><div><div class="section-eyebrow">Awaiting Approval</div><div class="section-title">Pending Requests</div></div></div>
<div class="stat-grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr));margin-bottom:30px">
  <?php while ($p = mysqli_fetch_assoc($pending)): ?>
  <div class="card card-pad" style="border-top:3px solid var(--orange)">
    <div style="font-weight:700;font-size:15px"><?php echo h($p['name']); ?></div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:10px">@<?php echo h($p['username']); ?></div>
    <span class="badge role-<?php echo $p['role']; ?>"><?php echo h($p['role']); ?></span>
    <div class="flex gap-8" style="margin-top:14px">
      <form method="POST" style="flex:1"><input type="hidden" name="action" value="approve"><input type="hidden" name="account_id" value="<?php echo $p['id']; ?>">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Approve</button></form>
      <form method="POST" style="flex:1" onsubmit="return confirm('Reject and delete this request?');"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="account_id" value="<?php echo $p['id']; ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Reject</button></form>
    </div>
  </div>
  <?php endwhile; ?>
</div>
<?php endif; ?>

<div class="section-head"><div><div class="section-eyebrow">All Users</div><div class="section-title">Active Accounts</div></div></div>
<div class="card">
  <div class="table-wrap">
  <table>
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last Seen</th><th></th></tr></thead>
    <tbody>
    <?php while ($a = mysqli_fetch_assoc($accounts)): ?>
      <tr>
        <td style="font-weight:600"><?php echo h($a['name']); ?></td>
        <td style="color:var(--muted)">@<?php echo h($a['username']); ?></td>
        <td><span class="badge role-<?php echo $a['role']; ?>"><?php echo h($a['role']); ?></span></td>
        <td><span class="badge <?php echo $a['is_online']?'online':'offline'; ?>"><?php echo $a['is_online']?'Online':'Offline'; ?></span></td>
        <td style="font-size:12px;color:var(--muted)"><?php echo $a['last_seen'] ? date('M j, g:i A', strtotime($a['last_seen'])) : '—'; ?></td>
        <td>
          <div class="flex gap-8">
            <?php if ($a['id'] != $userId): ?>
              <form method="POST" class="flex gap-8" style="align-items:center">
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="account_id" value="<?php echo $a['id']; ?>">
                <select name="role" onchange="this.form.submit()" style="width:auto;padding:6px 28px 6px 10px;font-size:11.5px">
                  <option value="student" <?php echo $a['role']==='student'?'selected':''; ?>>Student</option>
                  <option value="faculty" <?php echo $a['role']==='faculty'?'selected':''; ?>>Faculty</option>
                  <option value="admin"   <?php echo $a['role']==='admin'?'selected':''; ?>>Admin</option>
                </select>
              </form>
              <form method="POST"><input type="hidden" name="action" value="revoke"><input type="hidden" name="account_id" value="<?php echo $a['id']; ?>">
                <button type="submit" class="btn btn-outline btn-sm">Revoke</button>
              </form>
              <form method="POST" onsubmit="return confirm('Delete this account permanently?');"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="account_id" value="<?php echo $a['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            <?php else: ?>
              <span class="pill">This is you</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  </div>
</div>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
