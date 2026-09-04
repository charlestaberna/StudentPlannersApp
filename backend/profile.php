<?php
require_once __DIR__ . '/core/auth.php';

$message = ""; $msgType = "ok";
$uploadDir = __DIR__ . '/uploads/avatars/';
$allowedExt = ['jpg','jpeg','png','gif','webp'];
$maxBytes = 3 * 1024 * 1024; // 3MB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_avatar') {
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] === UPLOAD_ERR_NO_FILE) {
            $message = "Please choose an image first."; $msgType = "err";
        } elseif ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $message = "Upload failed. Please try again."; $msgType = "err";
        } else {
            $tmp  = $_FILES['avatar']['tmp_name'];
            $size = $_FILES['avatar']['size'];
            $origName = $_FILES['avatar']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            $imgInfo = @getimagesize($tmp);
            if (!$imgInfo || !in_array($ext, $allowedExt, true)) {
                $message = "Only JPG, PNG, GIF or WEBP images are allowed."; $msgType = "err";
            } elseif ($size > $maxBytes) {
                $message = "Image is too big. Max size is 3MB."; $msgType = "err";
            } else {
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

                // remove the old picture (if any) so we don't pile up orphan files
                if (!empty($user['avatar_path']) && file_exists(__DIR__ . '/' . $user['avatar_path'])) {
                    @unlink(__DIR__ . '/' . $user['avatar_path']);
                }

                $newName = 'user_' . $userId . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                $destFs  = $uploadDir . $newName;
                $destRel = 'uploads/avatars/' . $newName;

                if (move_uploaded_file($tmp, $destFs)) {
                    $stmt = mysqli_prepare($conn, "UPDATE login_accounts SET avatar_path=? WHERE id=?");
                    mysqli_stmt_bind_param($stmt, "si", $destRel, $userId);
                    mysqli_stmt_execute($stmt);
                    $_SESSION['sp_user']['avatar_path'] = $destRel;
                    $user['avatar_path'] = $destRel;
                    $message = "Profile picture updated."; $msgType = "ok";
                } else {
                    $message = "Could not save the file on the server."; $msgType = "err";
                }
            }
        }
    }

    if ($action === 'remove_avatar') {
        if (!empty($user['avatar_path']) && file_exists(__DIR__ . '/' . $user['avatar_path'])) {
            @unlink(__DIR__ . '/' . $user['avatar_path']);
        }
        mysqli_query($conn, "UPDATE login_accounts SET avatar_path=NULL WHERE id=$userId");
        $_SESSION['sp_user']['avatar_path'] = null;
        $user['avatar_path'] = null;
        $message = "Profile picture removed."; $msgType = "ok";
    }
}

$pageTitle = "My Profile";
$pageSub   = "This picture follows your account everywhere — sidebar, chat, and more.";
$activeNav = "profile";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="grid-2">
  <div class="card card-pad" style="text-align:center">
    <div class="section-eyebrow" style="justify-content:center">Profile Picture</div>
    <div style="display:flex;justify-content:center;margin:14px 0 16px">
      <?php
        $avatarSrc = null;
        if (!empty($user['avatar_path']) && file_exists(__DIR__ . '/' . $user['avatar_path'])) {
            $avatarSrc = h($user['avatar_path']) . '?v=' . filemtime(__DIR__ . '/' . $user['avatar_path']);
        }
      ?>
      <div class="profile-av-wrap<?php echo $avatarSrc ? ' clickable' : ''; ?>"
           <?php if ($avatarSrc): ?>onclick="openAvatarZoom('<?php echo $avatarSrc; ?>')" title="Click to view full size"<?php endif; ?>>
        <?php echo renderAvatar($user['name'], $user['avatar_path'] ?? null, 'profile-av'); ?>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="avatarForm">
      <input type="hidden" name="action" value="upload_avatar">
      <label for="avatarInput" class="btn btn-outline btn-sm" style="cursor:pointer;display:inline-flex">
        Choose Image
      </label>
      <input type="file" id="avatarInput" name="avatar" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" style="display:none" onchange="document.getElementById('avatarFileName').textContent=this.files[0]?this.files[0].name:''; document.getElementById('avatarSaveBtn').style.display=this.files.length?'inline-flex':'none';">
      <div id="avatarFileName" style="font-size:12px;color:var(--muted);margin-top:10px"></div>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:center">
        <button type="submit" id="avatarSaveBtn" class="btn btn-primary btn-sm" style="display:none">Save Picture</button>
      </div>
    </form>

    <?php if (!empty($user['avatar_path'])): ?>
    <form method="POST" onsubmit="return confirm('Remove your profile picture?');" style="margin-top:10px">
      <input type="hidden" name="action" value="remove_avatar">
      <button type="submit" class="btn btn-danger btn-sm">Remove Picture</button>
    </form>
    <?php endif; ?>

    <div style="font-size:11.5px;color:var(--muted2);margin-top:16px">ADD YOUR IMAGE FOR PROFILE</div>
  </div>

  <div class="card card-pad">
    <div class="section-eyebrow">Account Details</div>
    <div class="section-title" style="font-size:16px;margin-bottom:16px"><?php echo h($user['name']); ?></div>

    <div class="form-row">
      <div>
        <label style="font-size:11.5px;color:var(--muted);display:block;margin-bottom:6px">Username</label>
        <input type="text" value="@<?php echo h($user['username']); ?>" disabled>
      </div>
    </div>
    <div class="form-row">
      <div>
        <label style="font-size:11.5px;color:var(--muted);display:block;margin-bottom:6px">Role</label>
        <input type="text" value="<?php echo h(ucfirst($user['role'])); ?>" disabled>
      </div>
    </div>
    <div style="font-size:12px;color:var(--muted2);margin-top:6px">
      Your picture and details show up on the sidebar and in the Messages chat, and stay attached to this account even after you log out or refresh the page.
    </div>
  </div>
</div>

<div class="avatar-zoom-bg" id="avatarZoomBg" onclick="closeAvatarZoom()">
  <img id="avatarZoomImg" src="" alt="Profile picture">
</div>

<script>
function openAvatarZoom(src){
  document.getElementById('avatarZoomImg').src = src;
  document.getElementById('avatarZoomBg').classList.add('show');
}
function closeAvatarZoom(){
  document.getElementById('avatarZoomBg').classList.remove('show');
}
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeAvatarZoom();
});
</script>

<style>
.profile-av{width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,var(--glow),var(--gold));
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:28px;color:#04102b;overflow:hidden;
  box-shadow:0 0 0 3px rgba(60,130,255,.15), 0 12px 30px rgba(0,0,10,.4)}
img.profile-av.avatar-img{width:84px!important;height:84px!important;object-fit:cover}
.profile-av-wrap{display:inline-flex;border-radius:50%;transition:transform .18s}
.profile-av-wrap.clickable{cursor:zoom-in}
.profile-av-wrap.clickable:hover{transform:scale(1.04)}
.avatar-zoom-bg{position:fixed;inset:0;background:rgba(2,5,15,.85);backdrop-filter:blur(6px);z-index:300;
  display:none;align-items:center;justify-content:center;padding:30px}
.avatar-zoom-bg.show{display:flex}
.avatar-zoom-bg img{max-width:90vw;max-height:85vh;border-radius:20px;box-shadow:0 30px 80px rgba(0,0,15,.6);cursor:zoom-out}
</style>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
