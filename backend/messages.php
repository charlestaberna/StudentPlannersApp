<?php
require_once __DIR__ . '/core/auth.php';

// self-heal: make sure the table exists even if the SQL file wasn't re-imported
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `messages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `body`        TEXT NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");

// self-heal: add file-attachment columns for older installs
foreach ([
    'file_path' => "VARCHAR(255) NULL",
    'file_name' => "VARCHAR(255) NULL",
    'file_type' => "VARCHAR(120) NULL",
] as $col => $def) {
    if (!columnExists($conn, 'messages', $col)) {
        mysqli_query($conn, "ALTER TABLE messages ADD COLUMN `$col` $def");
    }
}
// body can now be empty if a message is just an attachment (only alter once)
$bodyCol = mysqli_fetch_assoc(mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'body'"));
if ($bodyCol && strtoupper($bodyCol['Null']) === 'NO') {
    mysqli_query($conn, "ALTER TABLE messages MODIFY COLUMN `body` TEXT NULL");
}

// self-heal: per-user "delete for me" table — a row here just hides that
// message from that one user; it does NOT touch the real message row, so
// everyone else in the chat still sees it normally
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `message_hides` (
  `message_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`message_id`,`user_id`),
  FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");

// self-heal: private group chats that any account can create, plus who
// belongs to each one. `owner` is whoever created the group (or whoever an
// admin/owner later promotes); owners can invite/remove members, change the
// group logo, and delete the group. Everyone else is just a `member`.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `chat_groups` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `logo_path`   VARCHAR(255) NULL,
  `created_by`  INT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `chat_group_members` (
  `group_id`  INT UNSIGNED NOT NULL,
  `user_id`   INT UNSIGNED NOT NULL,
  `role`      ENUM('owner','member') NOT NULL DEFAULT 'member',
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`,`user_id`),
  FOREIGN KEY (`group_id`) REFERENCES `chat_groups`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `login_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB");

// self-heal: tag each message with the private group it belongs to. NULL
// keeps meaning "the one shared chat for everyone" (the original behavior),
// so nothing about the existing global chat changes for older installs.
if (!columnExists($conn, 'messages', 'group_id')) {
    mysqli_query($conn, "ALTER TABLE messages ADD COLUMN `group_id` INT UNSIGNED NULL DEFAULT NULL AFTER `user_id`");
}

// self-heal: single-row table holding the shared background image for the
// group chat — admin-only to change, but everyone who opens messages.php sees it
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `chat_settings` (
  `id`  TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  `background_path` VARCHAR(255) NULL,
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NULL
) ENGINE=InnoDB");
mysqli_query($conn, "INSERT IGNORE INTO chat_settings (id) VALUES (1)");

$chatBgDir = __DIR__ . '/uploads/chat_bg/';
$bgImageExt = ['jpg','jpeg','png','gif','webp'];
$maxBgBytes = 8 * 1024 * 1024; // 8MB

$chatUploadDir = __DIR__ . '/uploads/messages_files/';
$imageExt = ['jpg','jpeg','png','gif','webp'];
$fileExt  = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip','rar'];
$maxChatBytes = 8 * 1024 * 1024; // 8MB

$groupLogoDir = __DIR__ . '/uploads/group_logos/';
$logoExt = ['jpg','jpeg','png','gif','webp'];
$maxLogoBytes = 5 * 1024 * 1024; // 5MB

// Which chats this account can see: "General" (the one shared chat, id 0)
// plus every private group they belong to. Admins get every group too, so
// there's always someone who can step in for moderation.
function myGroups($conn, $userId, $isAdmin){
    if ($isAdmin) {
        // admins see every private group (for moderation), but the "owner"
        // star only shows on ones they actually created/own themselves
        $stmt = mysqli_prepare($conn, "SELECT g.*, gm.role AS my_role FROM chat_groups g
            LEFT JOIN chat_group_members gm ON gm.group_id = g.id AND gm.user_id = ?
            ORDER BY g.name ASC");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT g.*, gm.role AS my_role FROM chat_groups g
            JOIN chat_group_members gm ON gm.group_id = g.id AND gm.user_id = ?
            ORDER BY g.name ASC");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    }
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) { $out[] = $r; }
    return $out;
}

$groupId = isset($_GET['group']) ? intval($_GET['group']) : (isset($_POST['group_id']) ? intval($_POST['group_id']) : 0);

$currentGroup = null;
if ($groupId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM chat_groups WHERE id=?");
    mysqli_stmt_bind_param($stmt, "i", $groupId);
    mysqli_stmt_execute($stmt);
    $currentGroup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $stmt = mysqli_prepare($conn, "SELECT role FROM chat_group_members WHERE group_id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, "ii", $groupId, $userId);
    mysqli_stmt_execute($stmt);
    $myMembership = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $isGroupMember = $myMembership !== null || $isAdmin;
    $isGroupOwner  = ($myMembership && $myMembership['role'] === 'owner') || $isAdmin;

    if (!$currentGroup || !$isGroupMember) {
        header("Location: messages.php?err=" . urlencode("You don't have access to that group chat."));
        exit;
    }
} else {
    $isGroupMember = true;   // "General" is open to every account, as before
    $isGroupOwner  = false;
}

function redirBase($groupId){
    return 'messages.php' . ($groupId > 0 ? ('?group=' . $groupId) : '');
}
function redirSep($groupId){
    return $groupId > 0 ? '&' : '?';
}

$message = ""; $msgType = "ok";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // anyone who is logged in can post to the shared chat
    if ($action === 'send_message') {
        $body = trim($_POST['body'] ?? '');
        $filePathDb = null; $fileNameDb = null; $fileTypeDb = null;
        $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                $message = "The attachment failed to upload."; $msgType = "err";
            } elseif ($_FILES['attachment']['size'] > $maxChatBytes) {
                $message = "Attachment is too big. Max size is 8MB."; $msgType = "err";
            } else {
                $origName = $_FILES['attachment']['name'];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $isImage = in_array($ext, $imageExt, true) && @getimagesize($_FILES['attachment']['tmp_name']);
                if (!$isImage && !in_array($ext, $fileExt, true)) {
                    $message = "That file type isn't allowed."; $msgType = "err";
                } else {
                    if (!is_dir($chatUploadDir)) mkdir($chatUploadDir, 0775, true);
                    $newName = 'msg_' . $userId . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $chatUploadDir . $newName)) {
                        $filePathDb = 'uploads/messages_files/' . $newName;
                        $fileNameDb = mb_substr(basename($origName), 0, 200);
                        $fileTypeDb = $isImage ? 'image' : 'file';
                    } else {
                        $message = "Could not save the attachment."; $msgType = "err";
                    }
                }
            }
        }

        if ($message === '') {
            if ($body === '' && !$filePathDb) {
                $message = "Message can't be empty."; $msgType = "err";
            } elseif (mb_strlen($body) > 1000) {
                $message = "Message is too long."; $msgType = "err";
            } else {
                $gidParam = $groupId > 0 ? $groupId : null;
                $stmt = mysqli_prepare($conn, "INSERT INTO messages (user_id, group_id, body, file_path, file_name, file_type) VALUES (?,?,?,?,?,?)");
                mysqli_stmt_bind_param($stmt, "iissss", $userId, $gidParam, $body, $filePathDb, $fileNameDb, $fileTypeDb);
                mysqli_stmt_execute($stmt);
            }
        }
    }

    // "Unsend" — removes the message for EVERYONE. Only the sender or an
    // admin can do this, and it's permanent (file gets deleted too).
    if ($action === 'unsend_message') {
        $id = intval($_POST['message_id']);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT user_id, file_path FROM messages WHERE id=$id"));
        if ($row && ($isAdmin || (int)$row['user_id'] === $userId)) {
            if (!empty($row['file_path']) && file_exists(__DIR__ . '/' . $row['file_path'])) {
                @unlink(__DIR__ . '/' . $row['file_path']);
            }
            mysqli_query($conn, "DELETE FROM messages WHERE id=$id");
        }
    }

    // "Delete" — only hides the message from the account that clicked it.
    // The message itself is untouched, so everybody else still sees it.
    if ($action === 'hide_message') {
        $id = intval($_POST['message_id']);
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO message_hides (message_id, user_id) VALUES (?,?)");
        mysqli_stmt_bind_param($stmt, "ii", $id, $userId);
        mysqli_stmt_execute($stmt);
    }

    // any logged-in account can start a private group chat and, in the same
    // step, invite whoever they want into it — they become the owner
    if ($action === 'create_group') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $message = "Give the group chat a name."; $msgType = "err";
        } elseif (mb_strlen($name) > 100) {
            $message = "That name is too long."; $msgType = "err";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO chat_groups (name, created_by) VALUES (?,?)");
            mysqli_stmt_bind_param($stmt, "si", $name, $userId);
            mysqli_stmt_execute($stmt);
            $newGroupId = mysqli_insert_id($conn);

            $stmt = mysqli_prepare($conn, "INSERT INTO chat_group_members (group_id, user_id, role) VALUES (?,?,'owner')");
            mysqli_stmt_bind_param($stmt, "ii", $newGroupId, $userId);
            mysqli_stmt_execute($stmt);

            // optional logo picked at creation time
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $logoExt, true) && $_FILES['logo']['size'] <= $maxLogoBytes && @getimagesize($_FILES['logo']['tmp_name'])) {
                    if (!is_dir($groupLogoDir)) mkdir($groupLogoDir, 0775, true);
                    $newName = 'grp_' . $newGroupId . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $groupLogoDir . $newName)) {
                        $logoPathDb = 'uploads/group_logos/' . $newName;
                        $stmt = mysqli_prepare($conn, "UPDATE chat_groups SET logo_path=? WHERE id=?");
                        mysqli_stmt_bind_param($stmt, "si", $logoPathDb, $newGroupId);
                        mysqli_stmt_execute($stmt);
                    }
                }
            }

            // invite whoever was ticked in the "invite members" list
            $invite = $_POST['invite'] ?? [];
            if (is_array($invite)) {
                foreach ($invite as $uid) {
                    $uid = intval($uid);
                    if ($uid > 0 && $uid !== $userId) {
                        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO chat_group_members (group_id, user_id, role) VALUES (?,?,'member')");
                        mysqli_stmt_bind_param($stmt, "ii", $newGroupId, $uid);
                        mysqli_stmt_execute($stmt);
                    }
                }
            }

            if (!empty($_GET['ajax']) || !empty($_POST['ajax'])) { header("Location: messages.php?group=$newGroupId&ajax=1"); exit; }
            header("Location: messages.php?group=$newGroupId");
            exit;
        }
    }

    // owner (or an admin) invites an existing account into their private group
    if ($action === 'invite_member') {
        if (!$isGroupOwner || $groupId <= 0) {
            $message = "Only the group owner can invite people."; $msgType = "err";
        } else {
            $inviteId = intval($_POST['invite_user_id'] ?? 0);
            $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM login_accounts WHERE id=$inviteId AND is_approved=1"));
            if (!$inviteId || !$exists) {
                $message = "Pick someone to invite."; $msgType = "err";
            } else {
                $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO chat_group_members (group_id, user_id, role) VALUES (?,?,'member')");
                mysqli_stmt_bind_param($stmt, "ii", $groupId, $inviteId);
                mysqli_stmt_execute($stmt);
            }
        }
    }

    // owner (or an admin) removes someone from their private group
    if ($action === 'remove_member') {
        $removeId = intval($_POST['remove_user_id'] ?? 0);
        if (!$isGroupOwner || $groupId <= 0) {
            $message = "Only the group owner can remove members."; $msgType = "err";
        } elseif ($removeId === $userId) {
            $message = "Use \"Leave group\" to remove yourself."; $msgType = "err";
        } else {
            mysqli_query($conn, "DELETE FROM chat_group_members WHERE group_id=$groupId AND user_id=$removeId AND role != 'owner'");
        }
    }

    // any member (besides the owner) can leave a private group whenever they want
    if ($action === 'leave_group') {
        if ($groupId <= 0) {
            $message = "You can't leave the general chat."; $msgType = "err";
        } elseif ($isGroupOwner && !$isAdmin) {
            $message = "As the owner, delete the group instead of leaving it."; $msgType = "err";
        } else {
            mysqli_query($conn, "DELETE FROM chat_group_members WHERE group_id=$groupId AND user_id=$userId");
            header("Location: messages.php");
            exit;
        }
    }

    // owner (or an admin) permanently deletes the whole private group
    if ($action === 'delete_group') {
        if (!$isGroupOwner || $groupId <= 0) {
            $message = "Only the group owner can delete this group."; $msgType = "err";
        } else {
            $files = mysqli_query($conn, "SELECT file_path FROM messages WHERE group_id=$groupId AND file_path IS NOT NULL");
            while ($f = mysqli_fetch_assoc($files)) {
                if (file_exists(__DIR__ . '/' . $f['file_path'])) @unlink(__DIR__ . '/' . $f['file_path']);
            }
            if (!empty($currentGroup['logo_path']) && file_exists(__DIR__ . '/' . $currentGroup['logo_path'])) {
                @unlink(__DIR__ . '/' . $currentGroup['logo_path']);
            }
            mysqli_query($conn, "DELETE FROM messages WHERE group_id=$groupId");
            mysqli_query($conn, "DELETE FROM chat_groups WHERE id=$groupId");
            header("Location: messages.php");
            exit;
        }
    }

    // owner (or an admin) sets/replaces this group's logo — every private
    // group chat can have its own picture, just like the shared background
    if ($action === 'set_group_logo') {
        if (!$isGroupOwner || $groupId <= 0) {
            $message = "Only the group owner can change the logo."; $msgType = "err";
        } elseif (!isset($_FILES['logo']) || $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE) {
            $message = "Pick an image first."; $msgType = "err";
        } elseif ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $message = "The image failed to upload."; $msgType = "err";
        } elseif ($_FILES['logo']['size'] > $maxLogoBytes) {
            $message = "Image is too big. Max size is 5MB."; $msgType = "err";
        } else {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $isImage = in_array($ext, $logoExt, true) && @getimagesize($_FILES['logo']['tmp_name']);
            if (!$isImage) {
                $message = "Please pick a valid image file (jpg, png, gif, or webp)."; $msgType = "err";
            } else {
                if (!is_dir($groupLogoDir)) mkdir($groupLogoDir, 0775, true);
                $newName = 'grp_' . $groupId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $groupLogoDir . $newName)) {
                    if (!empty($currentGroup['logo_path']) && file_exists(__DIR__ . '/' . $currentGroup['logo_path'])) {
                        @unlink(__DIR__ . '/' . $currentGroup['logo_path']);
                    }
                    $logoPathDb = 'uploads/group_logos/' . $newName;
                    $stmt = mysqli_prepare($conn, "UPDATE chat_groups SET logo_path=? WHERE id=?");
                    mysqli_stmt_bind_param($stmt, "si", $logoPathDb, $groupId);
                    mysqli_stmt_execute($stmt);
                } else {
                    $message = "Could not save the image."; $msgType = "err";
                }
            }
        }
    }

    // admin-only: set (or replace) the shared background image for
    // everyone's group chat
    if ($action === 'set_chat_background') {
        if ($groupId > 0) {
            $message = "The shared background only applies to the general chat."; $msgType = "err";
        } elseif (!$isAdmin) {
            $message = "Only an admin can change the chat background."; $msgType = "err";
        } elseif (!isset($_FILES['background']) || $_FILES['background']['error'] === UPLOAD_ERR_NO_FILE) {
            $message = "Pick an image first."; $msgType = "err";
        } elseif ($_FILES['background']['error'] !== UPLOAD_ERR_OK) {
            $message = "The image failed to upload."; $msgType = "err";
        } elseif ($_FILES['background']['size'] > $maxBgBytes) {
            $message = "Image is too big. Max size is 8MB."; $msgType = "err";
        } else {
            $origName = $_FILES['background']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $isImage = in_array($ext, $bgImageExt, true) && @getimagesize($_FILES['background']['tmp_name']);
            if (!$isImage) {
                $message = "Please pick a valid image file (jpg, png, gif, or webp)."; $msgType = "err";
            } else {
                if (!is_dir($chatBgDir)) mkdir($chatBgDir, 0775, true);
                $newName = 'bg_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($_FILES['background']['tmp_name'], $chatBgDir . $newName)) {
                    // remove the old background file, if any, so uploads don't pile up
                    $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT background_path FROM chat_settings WHERE id=1"));
                    if ($old && !empty($old['background_path']) && file_exists(__DIR__ . '/' . $old['background_path'])) {
                        @unlink(__DIR__ . '/' . $old['background_path']);
                    }
                    $newPathDb = 'uploads/chat_bg/' . $newName;
                    $stmt = mysqli_prepare($conn, "UPDATE chat_settings SET background_path=?, updated_by=?, updated_at=NOW() WHERE id=1");
                    mysqli_stmt_bind_param($stmt, "si", $newPathDb, $userId);
                    mysqli_stmt_execute($stmt);
                } else {
                    $message = "Could not save the image."; $msgType = "err";
                }
            }
        }
    }

    // admin-only: reset the group chat back to the default background
    if ($action === 'remove_chat_background') {
        if ($groupId > 0) {
            $message = "The shared background only applies to the general chat."; $msgType = "err";
        } elseif (!$isAdmin) {
            $message = "Only an admin can change the chat background."; $msgType = "err";
        } else {
            $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT background_path FROM chat_settings WHERE id=1"));
            if ($old && !empty($old['background_path']) && file_exists(__DIR__ . '/' . $old['background_path'])) {
                @unlink(__DIR__ . '/' . $old['background_path']);
            }
            $stmt = mysqli_prepare($conn, "UPDATE chat_settings SET background_path=NULL, updated_by=?, updated_at=NOW() WHERE id=1");
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
        }
    }

    $base = redirBase($groupId);
    $sep  = redirSep($groupId);
    if (!empty($_GET['ajax']) || !empty($_POST['ajax'])) { header("Location: {$base}{$sep}ajax=1"); exit; }
    header("Location: " . $base . ($message ? ($sep . 'err=' . urlencode($message)) : ''));
    exit;
}

// shared row renderer so the initial page load and the polling endpoint
// always produce identical markup
function renderMsgRow($m, $userId, $isAdmin, $anyoneElseOnline){
    $mine = ($m['uid'] == $userId);
    $canUnsend = $isAdmin || $mine; // removes it for everyone
    $senderOnline = !empty($m['is_online']);
    ob_start();
    ?>
    <div class="msg-row <?php echo $mine ? 'me' : 'them'; ?>" data-id="<?php echo (int)$m['id']; ?>" data-name="<?php echo h(mb_strtolower($m['name'])); ?>" data-role="<?php echo h($m['role']); ?>">
        <?php if (!$mine): ?>
        <div class="msg-avatar-wrap">
            <?php echo renderAvatar($m['name'], $m['avatar_path'] ?? null, 'msg-avatar'); ?>
            <span class="status-dot <?php echo $senderOnline ? 'online' : 'offline'; ?>" title="<?php echo $senderOnline ? 'Online' : 'Offline'; ?>"></span>
        </div>
        <?php endif; ?>
        <div class="msg-col">
            <?php if (!$mine): ?>
            <div class="msg-name">
                <?php echo h($m['name']); ?>
                <?php if ($m['role'] === 'admin'): ?><span class="badge role-admin msg-badge">admin</span><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (trim((string)$m['body']) !== ''): ?>
            <div class="msg-bubble"><?php echo nl2br(h($m['body'])); ?></div>
            <?php endif; ?>
            <?php if (!empty($m['file_path'])): ?>
                <?php if ($m['file_type'] === 'image'): ?>
                    <a href="<?php echo h($m['file_path']); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo h($m['file_path']); ?>" class="msg-image" alt="<?php echo h($m['file_name']); ?>">
                    </a>
                <?php else: ?>
                    <a href="<?php echo h($m['file_path']); ?>" class="msg-file" download target="_blank" rel="noopener">
                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span class="fname"><?php echo h($m['file_name']); ?></span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <div class="msg-time">
                <?php echo date('g:i A · M j', strtotime($m['created_at'])); ?>
                <?php if ($mine): ?>
                    <span class="msg-status <?php echo $anyoneElseOnline ? 'delivered' : 'sent'; ?>"><?php echo $anyoneElseOnline ? 'Delivered' : 'Sent'; ?></span>
                <?php endif; ?>
                <?php if ($canUnsend): ?>
                    <button type="button" class="msg-del" title="Unsend for everyone" onclick="unsendMsg(<?php echo (int)$m['id']; ?>)">
                        <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        Unsend
                    </button>
                <?php endif; ?>
                <button type="button" class="msg-hide" title="Delete for you only" onclick="hideMsg(<?php echo (int)$m['id']; ?>)">
                    <svg viewBox="0 0 24 24"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-10-7-10-7a18.5 18.5 0 0 1 4.22-5.94M9.9 4.24A10.9 10.9 0 0 1 12 5c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    Delete
                </button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

$groupFilter = $groupId > 0 ? "m.group_id = $groupId" : "m.group_id IS NULL";
$msgSelect = "
    SELECT m.id, m.body, m.created_at, m.file_path, m.file_name, m.file_type,
           u.name, u.role, u.id AS uid, u.avatar_path, u.is_online
    FROM messages m
    JOIN login_accounts u ON u.id = m.user_id
    LEFT JOIN message_hides mh ON mh.message_id = m.id AND mh.user_id = $userId
    WHERE mh.message_id IS NULL AND $groupFilter
    ORDER BY m.id ASC";

// "delivered" just means someone else besides me is currently online to
// receive it — this is a shared group chat, not a 1:1, so there's no single
// recipient to check.
$onlineRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM login_accounts WHERE is_online = 1 AND id != $userId"));
$anyoneElseOnline = $onlineRow && (int)$onlineRow['c'] > 0;

// lightweight polling endpoint: returns just the message list markup
if (isset($_GET['ajax'])) {
    $rows = mysqli_query($conn, $msgSelect);
    while ($m = mysqli_fetch_assoc($rows)) {
        echo renderMsgRow($m, $userId, $isAdmin, $anyoneElseOnline);
    }
    exit;
}

$rows = mysqli_query($conn, $msgSelect);
$allMessages = [];
while ($m = mysqli_fetch_assoc($rows)) { $allMessages[] = $m; }

$bgRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT background_path FROM chat_settings WHERE id=1"));
$chatBackground = ($groupId === 0 && $bgRow && !empty($bgRow['background_path']) && file_exists(__DIR__ . '/' . $bgRow['background_path']))
    ? $bgRow['background_path'] : null;

$allMyGroups = myGroups($conn, $userId, $isAdmin);

$groupMembers = [];
if ($groupId > 0) {
    $res = mysqli_query($conn, "SELECT u.id, u.name, u.role, u.avatar_path, gm.role AS group_role
        FROM chat_group_members gm JOIN login_accounts u ON u.id = gm.user_id
        WHERE gm.group_id = $groupId ORDER BY (gm.role='owner') DESC, u.name ASC");
    while ($r = mysqli_fetch_assoc($res)) { $groupMembers[] = $r; }
}
$memberIds = array_map('intval', array_column($groupMembers, 'id'));
$inviteCandidates = [];
$res = mysqli_query($conn, "SELECT id, name, username, role FROM login_accounts WHERE is_approved=1 ORDER BY name ASC");
while ($r = mysqli_fetch_assoc($res)) { $inviteCandidates[] = $r; }

if ($groupId > 0 && $currentGroup) {
    $pageTitle = $currentGroup['name'];
    $pageSub   = "Private group chat · " . count($groupMembers) . " member" . (count($groupMembers) === 1 ? '' : 's');
} else {
    $pageTitle = "Messages";
    $pageSub   = "One shared chat for everyone signed in to the planner.";
}
$activeNav = "chat";
include __DIR__ . '/core/layout_head.php';
?>

<?php if (isset($_GET['err'])): ?><div class="alert alert-err"><?php echo h($_GET['err']); ?></div><?php endif; ?>

<div class="chat-tabs">
  <a href="messages.php" class="chat-tab<?php echo $groupId === 0 ? ' active' : ''; ?>">
    <span class="chat-tab-av chat-tab-av-general">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </span>
    General
  </a>
  <?php foreach ($allMyGroups as $g): ?>
  <a href="messages.php?group=<?php echo (int)$g['id']; ?>" class="chat-tab<?php echo $groupId === (int)$g['id'] ? ' active' : ''; ?>">
    <span class="chat-tab-av"><?php echo renderAvatar($g['name'], $g['logo_path'], 'chat-tab-av-img'); ?></span>
    <?php echo h($g['name']); ?>
    <?php if ($g['my_role'] === 'owner'): ?><span class="chat-tab-owner" title="You own this group">★</span><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <button type="button" class="chat-tab chat-tab-new" onclick="openModal('newGroupModal')">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New group
  </button>
</div>

<div class="card chat-wrap">
  <div class="chat-head">
    <div class="flex items-center gap-10">
      <?php if ($groupId > 0 && $currentGroup): ?>
        <?php echo renderAvatar($currentGroup['name'], $currentGroup['logo_path'], 'chat-head-av'); ?>
      <?php endif; ?>
      <div>
        <div class="section-title" style="font-size:16px"><?php echo $groupId > 0 ? h(mb_strtoupper($currentGroup['name'])) : 'GROUP CHAT FOR EVERYONE'; ?></div>
        <div class="section-sub">
          <?php if ($groupId > 0): ?>
            Private group chat · <?php echo count($groupMembers); ?> member<?php echo count($groupMembers) === 1 ? '' : 's'; ?> · Unsend removes it for everyone, Delete only removes it from your view
          <?php else: ?>
            Group chat · visible to every account · Unsend removes it for everyone, Delete only removes it from your view
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="chat-head-actions">
      <?php if ($groupId > 0): ?>
      <div class="chat-bg-btn" id="groupSettingsBtn" title="Group settings" onclick="openModal('groupSettingsModal')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </div>
      <?php else: ?>
        <?php if ($isAdmin): ?>
        <div class="chat-bg-btn" id="chatBgBtn" title="Change group chat background" onclick="toggleBgMenu(event)">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        </div>
        <div class="chat-bg-menu" id="chatBgMenu">
          <div class="chat-bg-menu-title">Group chat background</div>
          <div class="chat-bg-menu-sub">Only admins can change this — it's the same background for everyone in the chat.</div>
          <form method="POST" enctype="multipart/form-data" id="chatBgForm">
            <input type="hidden" name="action" value="set_chat_background">
            <label class="btn btn-ghost btn-sm chat-bg-upload-label">
              Upload image
              <input type="file" name="background" id="chatBgInput" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" style="display:none">
            </label>
          </form>
          <?php if ($chatBackground): ?>
          <form method="POST" id="chatBgRemoveForm">
            <input type="hidden" name="action" value="remove_chat_background">
            <button type="submit" class="btn btn-danger btn-sm chat-bg-remove-btn">Remove background</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <span class="badge online"><span class="badge-dot"></span> <?php echo count($allMessages); ?> messages</span>
    </div>
  </div>

  <div class="chat-search-row">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" id="chatSearch" placeholder="Search by user or admin name…" autocomplete="off">
    <button type="button" id="chatSearchClear" title="Clear search" style="display:none">&times;</button>
  </div>
  <div class="chat-search-empty" id="chatSearchEmpty" style="display:none">No messages from that user/admin.</div>

  <div class="chat-messages<?php echo $chatBackground ? ' has-bg' : ''; ?>" id="chatMessages"
    <?php if ($chatBackground): ?>style="background-image:url('<?php echo h($chatBackground); ?>')"<?php endif; ?>>
    <?php if (empty($allMessages)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <div class="empty-title">No messages yet</div>
        <div class="empty-sub">Be the first to say hello 👋</div>
      </div>
    <?php else: foreach ($allMessages as $m): echo renderMsgRow($m, $userId, $isAdmin, $anyoneElseOnline); endforeach; endif; ?>
  </div>

  <div class="chat-attach-preview" id="attachPreview">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <span class="name" id="attachName"></span>
    <button type="button" onclick="clearAttachment()" title="Remove">&times;</button>
  </div>

  <div class="emoji-pop" id="emojiPop">
    <div class="emoji-tabs" id="emojiTabs"></div>
    <div class="emoji-grid" id="emojiGrid"></div>
  </div>

  <form method="POST" enctype="multipart/form-data" class="chat-input-row" id="chatForm">
    <input type="hidden" name="action" value="send_message">
    <input type="hidden" name="ajax" value="1">
    <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
    <input type="file" name="attachment" id="chatAttachment" style="display:none"
      accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,image/*">
    <div class="chat-attach-btn" title="Attach a photo or file" onclick="document.getElementById('chatAttachment').click()">
      <svg viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
    </div>
    <div class="chat-attach-btn" id="emojiBtn" title="Emoji" onclick="toggleEmojiPop(event)">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
    </div>
    <input type="text" name="body" id="chatBody" placeholder="<?php echo $groupId > 0 ? 'Message ' . h($currentGroup['name']) . '…' : 'Write a message to everyone…'; ?>" autocomplete="off" maxlength="1000">
    <button type="submit" class="btn btn-primary btn-icon" title="Send">
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
    </button>
  </form>

  <form method="POST" id="unsendForm" style="display:none">
    <input type="hidden" name="action" value="unsend_message">
    <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
    <input type="hidden" name="message_id" id="unsendMsgId">
  </form>
  <form method="POST" id="hideForm" style="display:none">
    <input type="hidden" name="action" value="hide_message">
    <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
    <input type="hidden" name="message_id" id="hideMsgId">
  </form>
</div>

<!-- ================= New Group modal ================= -->
<div class="modal-bg" id="newGroupModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">New private group chat</div><button class="modal-close" onclick="closeModal('newGroupModal')">✕</button></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create_group">
      <label class="form-label">Group name</label>
      <input type="text" name="name" maxlength="100" placeholder="e.g. BSIT 3-A Study Group" required>

      <label class="form-label" style="margin-top:14px">Group logo (optional)</label>
      <input type="file" name="logo" accept=".jpg,.jpeg,.png,.gif,.webp,image/*">

      <label class="form-label" style="margin-top:14px">Invite members (optional — you can invite more later)</label>
      <div class="member-pick-list">
        <?php foreach ($inviteCandidates as $c): if ((int)$c['id'] === $userId) continue; ?>
        <label class="member-pick-row">
          <input type="checkbox" name="invite[]" value="<?php echo (int)$c['id']; ?>">
          <?php echo h($c['name']); ?> <span class="member-pick-role">@<?php echo h($c['username']); ?> · <?php echo h($c['role']); ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (count($inviteCandidates) <= 1): ?>
          <div class="section-sub" style="padding:6px 2px">No other accounts to invite yet.</div>
        <?php endif; ?>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('newGroupModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create group</button>
      </div>
    </form>
  </div>
</div>

<?php if ($groupId > 0 && $currentGroup): ?>
<!-- ================= Group settings modal ================= -->
<div class="modal-bg" id="groupSettingsModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title"><?php echo h($currentGroup['name']); ?> · Settings</div><button class="modal-close" onclick="closeModal('groupSettingsModal')">✕</button></div>

    <?php if ($isGroupOwner): ?>
    <label class="form-label">Group logo</label>
    <div class="flex items-center gap-10" style="margin-bottom:14px">
      <?php echo renderAvatar($currentGroup['name'], $currentGroup['logo_path'], 'chat-head-av'); ?>
      <form method="POST" enctype="multipart/form-data" id="groupLogoForm" style="flex:1">
        <input type="hidden" name="action" value="set_group_logo">
        <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
        <label class="btn btn-ghost btn-sm chat-bg-upload-label" style="width:100%">
          Change logo
          <input type="file" name="logo" id="groupLogoInput" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" style="display:none">
        </label>
      </form>
    </div>
    <?php endif; ?>

    <label class="form-label">Members (<?php echo count($groupMembers); ?>)</label>
    <div class="member-pick-list" style="margin-bottom:14px">
      <?php foreach ($groupMembers as $m): ?>
      <div class="member-pick-row" style="justify-content:space-between">
        <span class="flex items-center gap-10">
          <?php echo renderAvatar($m['name'], $m['avatar_path'], 'msg-avatar'); ?>
          <?php echo h($m['name']); ?>
          <?php if ($m['group_role'] === 'owner'): ?><span class="badge role-admin msg-badge">owner</span>
          <?php elseif ($m['role'] === 'admin'): ?><span class="badge role-admin msg-badge">admin</span><?php endif; ?>
        </span>
        <?php if ($isGroupOwner && $m['group_role'] !== 'owner'): ?>
        <button type="button" class="msg-del" title="Remove from group" onclick="removeMember(<?php echo (int)$m['id']; ?>)">Remove</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($isGroupOwner):
        $nonMemberIds = array_diff(array_map('intval', array_column($inviteCandidates, 'id')), $memberIds); ?>
    <label class="form-label">Invite someone</label>
    <form method="POST" class="flex gap-10" style="margin-bottom:18px">
      <input type="hidden" name="action" value="invite_member">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <select name="invite_user_id" style="flex:1">
        <?php if (empty($nonMemberIds)): ?>
          <option value="">Everyone is already in this group</option>
        <?php else: foreach ($inviteCandidates as $c): if (!in_array((int)$c['id'], $nonMemberIds, true)) continue; ?>
          <option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?> (@<?php echo h($c['username']); ?>)</option>
        <?php endforeach; endif; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm" <?php echo empty($nonMemberIds) ? 'disabled' : ''; ?>>Invite</button>
    </form>
    <?php endif; ?>

    <div class="modal-actions" style="justify-content:space-between">
      <?php if ($isGroupOwner): ?>
      <form method="POST" onsubmit="return confirm('Delete this group chat for everyone? This cannot be undone.');">
        <input type="hidden" name="action" value="delete_group">
        <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
        <button type="submit" class="btn btn-danger">Delete group</button>
      </form>
      <?php else: ?>
      <form method="POST" onsubmit="return confirm('Leave this group chat?');">
        <input type="hidden" name="action" value="leave_group">
        <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
        <button type="submit" class="btn btn-danger">Leave group</button>
      </form>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" onclick="closeModal('groupSettingsModal')">Close</button>
    </div>
  </div>
</div>
<form method="POST" id="removeMemberForm" style="display:none">
  <input type="hidden" name="action" value="remove_member">
  <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
  <input type="hidden" name="remove_user_id" id="removeMemberId">
</form>
<?php endif; ?>



<script>
const chatBox = document.getElementById('chatMessages');
chatBox.scrollTop = chatBox.scrollHeight;
const CURRENT_GROUP_ID = <?php echo (int)$groupId; ?>;

// ---------- Search by user/admin name ----------
const chatSearch      = document.getElementById('chatSearch');
const chatSearchClear = document.getElementById('chatSearchClear');
const chatSearchEmpty = document.getElementById('chatSearchEmpty');

function filterChat(){
  const q = chatSearch.value.trim().toLowerCase();
  chatSearchClear.style.display = q ? 'inline-flex' : 'none';

  const rows = chatBox.querySelectorAll('.msg-row');
  let visible = 0;
  rows.forEach(row => {
    const name = row.dataset.name || '';
    const role = row.dataset.role || '';
    const match = !q || name.includes(q) || role.includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });

  const emptyState = chatBox.querySelector('.empty-state');
  chatSearchEmpty.style.display = (q && visible === 0 && !emptyState) ? 'block' : 'none';
}

chatSearch.addEventListener('input', filterChat);
chatSearchClear.addEventListener('click', function(){
  chatSearch.value = '';
  filterChat();
  chatSearch.focus();
});

function unsendMsg(id){
  if(!confirm('Unsend this message? It will be removed for everyone in the chat and cannot be undone.')) return;
  document.getElementById('unsendMsgId').value = id;
  const fd = new FormData(document.getElementById('unsendForm'));
  fd.set('ajax','1');
  fetch('messages.php', { method:'POST', body: fd }).then(refresh);
}

function hideMsg(id){
  if(!confirm('Delete this message for you? It will still be visible to everyone else.')) return;
  // remove it instantly from this device without waiting for the next poll
  const row = chatBox.querySelector('.msg-row[data-id="' + id + '"]');
  if (row) row.remove();
  document.getElementById('hideMsgId').value = id;
  const fd = new FormData(document.getElementById('hideForm'));
  fd.set('ajax','1');
  fetch('messages.php', { method:'POST', body: fd });
}

const attachInput   = document.getElementById('chatAttachment');
const attachPreview = document.getElementById('attachPreview');
const attachName    = document.getElementById('attachName');
attachInput.addEventListener('change', function(){
  if (this.files && this.files.length){
    attachName.textContent = this.files[0].name;
    attachPreview.classList.add('show');
  } else {
    clearAttachment();
  }
});
function clearAttachment(){
  attachInput.value = '';
  attachPreview.classList.remove('show');
  attachName.textContent = '';
}

// send without a full page reload
document.getElementById('chatForm').addEventListener('submit', function(e){
  e.preventDefault();
  const input = document.getElementById('chatBody');
  const val = input.value.trim();
  if(!val && !attachInput.files.length) return;
  const fd = new FormData(this);
  fetch('messages.php', { method:'POST', body: fd }).then(refresh);
  input.value = '';
  clearAttachment();
});

// light polling so new messages from others show up without a manual reload
function refresh(){
  fetch('messages.php?ajax=1' + (CURRENT_GROUP_ID ? '&group=' + CURRENT_GROUP_ID : ''))
    .then(r => r.text())
    .then(html => {
      const wasAtBottom = chatBox.scrollTop + chatBox.clientHeight >= chatBox.scrollHeight - 40;
      chatBox.innerHTML = html || chatBox.innerHTML;
      filterChat();
      if (wasAtBottom) chatBox.scrollTop = chatBox.scrollHeight;
    })
    .catch(()=>{});
}
setInterval(refresh, 4000);

// ---------- Emoji picker ----------
const EMOJI_CATEGORIES = {
  "Smileys": ["😀","😁","😂","🤣","😃","😄","😅","😆","😉","😊","😋","😎","😍","😘","🥰","😗","😙","😚","🙂","🤗","🤩","🤔","🤨","😐","😑","😶","🙄","😏","😣","😥","😮","🤐","😯","😪","😫","🥱","😴","😌","😛","😜","😝","🤤","😒","😓","😔","😕","🙃","🤑","😲","☹️","🙁","😖","😞","😟","😤","😢","😭","😦","😧","😨","😩","🤯","😬","😰","😱","🥵","🥶","😳","🤪","😵","😡","😠","🤬","😷","🤒","🤕","🤢","🤮","🥴","😇","🥳","🥺","🤠","🤡","🤥","🤫","🤭","🧐","🤓"],
  "People": ["👋","🤚","🖐️","✋","🖖","👌","🤏","✌️","🤞","🤟","🤘","🤙","👈","👉","👆","🖕","👇","☝️","👍","👎","✊","👊","🤛","🤜","👏","🙌","👐","🤲","🙏","🤝","💪","🦵","🦶","👂","👃","🧠","🦷","👀","👁️","👅","👄","💋","💯","👶","🧒","👦","👧","🧑","👨","👩","🧓","👴","👵","🙋","🙆","🙅","💁","🙇","🤦","🤷","👮","🕵️","💂","👷","🤴","👸","👳","👲","🧕","🤵","👰","🤰","🤱","👼","🎅","🤶","🦸","🦹","🧙","🧚","🧛","🧜","🧝","🧞","🧟"],
  "Animals": ["🐶","🐱","🐭","🐹","🐰","🦊","🐻","🐼","🐨","🐯","🦁","🐮","🐷","🐽","🐸","🐵","🙈","🙉","🙊","🐒","🐔","🐧","🐦","🐤","🐣","🐥","🦆","🦅","🦉","🦇","🐺","🐗","🐴","🦄","🐝","🐛","🦋","🐌","🐞","🐜","🦟","🦗","🕷️","🕸️","🦂","🐢","🐍","🦎","🦖","🦕","🐙","🦑","🦐","🦞","🦀","🐡","🐠","🐟","🐬","🐳","🐋","🦈","🐊","🐅","🐆","🦓","🦍","🦧","🐘","🦛","🦏","🐪","🐫","🦒","🦘","🐃","🐂","🐄","🐎","🐖","🐏","🐑","🦙","🐐","🦌","🐕","🐩","🦮","🐈","🐓","🦃","🦤","🦚","🦜","🦢","🦩","🕊️","🐇","🦝","🦨","🦡","🦫","🦦","🦥","🐁","🐀","🐿️","🦔"],
  "Food": ["🍏","🍎","🍐","🍊","🍋","🍌","🍉","🍇","🍓","🫐","🍈","🍒","🍑","🥭","🍍","🥥","🥝","🍅","🍆","🥑","🥦","🥬","🥒","🌶️","🫑","🌽","🥕","🫒","🧄","🧅","🥔","🍠","🥐","🥯","🍞","🥖","🥨","🧀","🥚","🍳","🧈","🥞","🧇","🥓","🥩","🍗","🍖","🌭","🍔","🍟","🍕","🫓","🥪","🥙","🧆","🌮","🌯","🫔","🥗","🥘","🫕","🥫","🍝","🍜","🍲","🍛","🍣","🍱","🥟","🦪","🍤","🍙","🍚","🍘","🍥","🥠","🥮","🍢","🍡","🍧","🍨","🍦","🥧","🧁","🍰","🎂","🍮","🍭","🍬","🍫","🍿","🧂","🍩","🍪","🌰","🥜","🍯","🥛","🍼","☕","🍵","🧃","🥤","🧋","🍶","🍺","🍻","🥂","🍷","🥃","🍸","🍹","🧉","🍾"],
  "Activities": ["⚽","🏀","🏈","⚾","🥎","🎾","🏐","🏉","🥏","🎱","🪀","🏓","🏸","🏒","🏑","🥍","🏏","🥅","⛳","🪁","🏹","🎣","🤿","🥊","🥋","🎽","🛹","🛼","🛷","⛸️","🥌","🎿","⛷️","🏂","🪂","🏋️","🤼","🤸","⛹️","🤺","🤾","🏌️","🏇","🧘","🏄","🏊","🤽","🚣","🧗","🚵","🚴","🏆","🥇","🥈","🥉","🏅","🎖️","🏵️","🎗️","🎫","🎟️","🎪","🤹","🎭","🩰","🎨","🎬","🎤","🎧","🎼","🎹","🥁","🪘","🎷","🎺","🎸","🪕","🎻","🎲","♟️","🎯","🎳","🎮","🎰","🧩"],
  "Travel": ["🚗","🚕","🚙","🚌","🚎","🏎️","🚓","🚑","🚒","🚐","🛻","🚚","🚛","🚜","🦯","🦽","🦼","🛴","🚲","🛵","🏍️","🛺","🚨","🚔","🚍","🚘","🚖","🚡","🚠","🚟","🚃","🚋","🚞","🚝","🚄","🚅","🚈","🚂","🚆","🚇","🚊","🚉","✈️","🛫","🛬","🛩️","💺","🛰️","🚀","🛸","🚁","🛶","⛵","🚤","🛥️","🛳️","⛴️","🚢","⚓","🪝","⛽","🚧","🚦","🚥","🗺️","🗿","🗽","🗼","🏰","🏯","🏟️","🎡","🎢","🎠","⛲","⛱️","🏖️","🏝️","🏜️","🌋","⛰️","🏔️","🗻","🏕️","⛺","🏠","🏡","🏘️","🏚️","🏗️","🏭","🏢","🏬","🏣","🏤","🏥","🏦","🏨","🏪","🏫","🏩","💒","🏛️","⛪","🕌","🕍","🛕","🕋"],
  "Objects": ["⌚","📱","💻","⌨️","🖥️","🖨️","🖱️","🖲️","💽","💾","💿","📀","📷","📸","📹","🎥","📽️","🎞️","📞","☎️","📟","📠","📺","📻","🎙️","🎚️","🎛️","🧭","⏱️","⏲️","⏰","🕰️","⌛","⏳","📡","🔋","🔌","💡","🔦","🕯️","🪔","🧯","🛢️","💸","💵","💴","💶","💷","🪙","💰","💳","💎","⚖️","🪜","🧰","🔧","🔨","⚒️","🛠️","⛏️","🪓","🪚","🔩","⚙️","🪤","🧱","⛓️","🔫","💣","🧨","🪃","🔪","🗡️","⚔️","🛡️","🚬","⚰️","🪦","⚱️","🏺","🔮","📿","🧿","💈","🔭","🔬","🕳️","💊","💉","🩸","🩹","🩺","🚪","🛏️","🛋️","🪑","🚽","🚿","🛁","🪒","🧴","🧷","🧹","🧺","🧻","🪣","🧼","🪥","🧽","🧯","🛒"],
  "Symbols": ["❤️","🧡","💛","💚","💙","💜","🖤","🤍","🤎","💔","❣️","💕","💞","💓","💗","💖","💘","💝","💟","☮️","✝️","☪️","🕉️","☸️","✡️","🔯","🕎","☯️","☦️","🛐","⛎","♈","♉","♊","♋","♌","♍","♎","♏","♐","♑","♒","♓","🆔","⚛️","🉑","☢️","☣️","📴","📳","🈶","🈚","🈸","🈺","🈷️","✴️","🆚","💮","🉐","㊙️","㊗️","🈴","🈵","🈹","🈲","🅰️","🅱️","🆎","🆑","🅾️","🆘","❌","⭕","🛑","⛔","📛","🚫","💯","💢","♨️","🚷","🚯","🚳","🚱","🔞","📵","🚭","❗","❕","❓","❔","‼️","⁉️","🔅","🔆","〽️","⚠️","🚸","🔱","⚜️","🔰","♻️","✅","🈯","💹","❇️","✳️","❎","🌐","💠","Ⓜ️","🌀","💤","🏧","🚾","♿","🅿️","🈳","🈂️","🛂","🛃","🛄","🛅"],
  "Flags": ["🏁","🚩","🎌","🏴","🏳️","🏳️‍🌈","🏴‍☠️","🇵🇭","🇺🇸","🇯🇵","🇰🇷","🇨🇳","🇬🇧","🇨🇦","🇦🇺","🇩🇪","🇫🇷","🇮🇹","🇪🇸","🇧🇷","🇮🇳","🇷🇺","🇸🇬","🇹🇭","🇻🇳","🇲🇾","🇮🇩","🇸🇦","🇦🇪","🇲🇽"]
};

let emojiOpen = false;

function buildEmojiPicker(){
  const tabsEl = document.getElementById('emojiTabs');
  const gridEl = document.getElementById('emojiGrid');
  const cats = Object.keys(EMOJI_CATEGORIES);
  tabsEl.innerHTML = cats.map((c,i) => `<button type="button" class="emoji-tab${i===0?' active':''}" data-cat="${c}">${EMOJI_CATEGORIES[c][0]}</button>`).join('');
  function showCat(cat){
    gridEl.innerHTML = EMOJI_CATEGORIES[cat].map(e => `<button type="button" class="emoji-item">${e}</button>`).join('');
  }
  tabsEl.querySelectorAll('.emoji-tab').forEach(btn => {
    btn.addEventListener('click', function(){
      tabsEl.querySelectorAll('.emoji-tab').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      showCat(this.dataset.cat);
    });
  });
  gridEl.addEventListener('click', function(e){
    const btn = e.target.closest('.emoji-item');
    if (!btn) return;
    insertEmoji(btn.textContent);
  });
  showCat(cats[0]);
}

function insertEmoji(emoji){
  const input = document.getElementById('chatBody');
  const start = input.selectionStart ?? input.value.length;
  const end   = input.selectionEnd ?? input.value.length;
  input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
  const pos = start + emoji.length;
  input.focus();
  input.setSelectionRange(pos, pos);
}

function toggleEmojiPop(e){
  e.stopPropagation();
  const pop = document.getElementById('emojiPop');
  const btn = document.getElementById('emojiBtn');
  // .emoji-pop is position:fixed, but it starts out nested inside .chat-wrap,
  // which inherits backdrop-filter from .card. Any ancestor with a filter /
  // backdrop-filter creates a new containing block for fixed-position
  // descendants, so the popup ends up positioned (and clipped by
  // .chat-wrap's overflow:hidden) relative to that card instead of the
  // viewport — meaning it never visibly shows up. Moving it to be a direct
  // child of <body> once keeps it truly viewport-fixed.
  if (pop.parentElement !== document.body) {
    document.body.appendChild(pop);
  }
  emojiOpen = !emojiOpen;
  if (emojiOpen) {
    const r = btn.getBoundingClientRect();
    const popWidth = Math.min(300, window.innerWidth - 20);
    let left = r.left - popWidth + r.width;
    left = Math.max(10, Math.min(left, window.innerWidth - popWidth - 10));
    pop.style.left = left + 'px';
    pop.style.bottom = (window.innerHeight - r.top + 10) + 'px';
    pop.style.top = 'auto';
  }
  pop.classList.toggle('show', emojiOpen);
}

document.addEventListener('click', function(e){
  const pop = document.getElementById('emojiPop');
  const btn = document.getElementById('emojiBtn');
  if (emojiOpen && !pop.contains(e.target) && !btn.contains(e.target)) {
    pop.classList.remove('show');
    emojiOpen = false;
  }
});

buildEmojiPicker();

// ---------- Group chat background (admin only) ----------
const chatBgBtn = document.getElementById('chatBgBtn');
if (chatBgBtn) {
  const chatBgMenu  = document.getElementById('chatBgMenu');
  const chatBgInput = document.getElementById('chatBgInput');
  const chatBgForm  = document.getElementById('chatBgForm');

  window.toggleBgMenu = function(e){
    e.stopPropagation();
    chatBgMenu.classList.toggle('show');
  };

  document.addEventListener('click', function(e){
    if (chatBgMenu.classList.contains('show') && !chatBgMenu.contains(e.target) && !chatBgBtn.contains(e.target)) {
      chatBgMenu.classList.remove('show');
    }
  });

  // submit as soon as an image is picked — no extra "confirm" step needed
  chatBgInput.addEventListener('change', function(){
    if (this.files && this.files.length) chatBgForm.submit();
  });
}

// ---------- Group logo (group owner only) ----------
const groupLogoInput = document.getElementById('groupLogoInput');
if (groupLogoInput) {
  const groupLogoForm = document.getElementById('groupLogoForm');
  groupLogoInput.addEventListener('change', function(){
    if (this.files && this.files.length) groupLogoForm.submit();
  });
}

// ---------- Remove member from group (owner only) ----------
function removeMember(userId){
  if(!confirm('Remove this person from the group?')) return;
  document.getElementById('removeMemberId').value = userId;
  document.getElementById('removeMemberForm').submit();
}
</script>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
