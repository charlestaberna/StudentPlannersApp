<?php
require_once __DIR__ . '/core/auth.php';

$message = ""; $msgType = "ok";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$canManage) {
        $message = "Only admins and faculty can manage notes."; $msgType = "err";
    } elseif ($action === 'add_note' || $action === 'edit_note') {
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $subject = $_POST['subject_id'] ?? '';
        $pinned  = isset($_POST['is_pinned']) ? 1 : 0;
        $sub2    = $subject !== '' ? intval($subject) : null;

        if ($title === '') {
            $message = "Note title is required."; $msgType = "err";
        } elseif ($action === 'add_note') {
            $stmt = mysqli_prepare($conn, "INSERT INTO notes (user_id,subject_id,title,content,is_pinned) VALUES (?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, "iissi", $userId, $sub2, $title, $content, $pinned);
            mysqli_stmt_execute($stmt);
            $message = "Note added."; $msgType = "ok";
        } else {
            // shared notes board — any admin/faculty can edit any note
            $id = intval($_POST['note_id']);
            $stmt = mysqli_prepare($conn, "UPDATE notes SET subject_id=?, title=?, content=?, is_pinned=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "issii", $sub2, $title, $content, $pinned, $id);
            mysqli_stmt_execute($stmt);
            $message = "Note updated."; $msgType = "ok";
        }
    } elseif ($action === 'delete_note') {
        $id = intval($_POST['note_id']);
        mysqli_query($conn, "DELETE FROM notes WHERE id=$id");
        $message = "Note deleted."; $msgType = "ok";
    } elseif ($action === 'toggle_pin') {
        $id = intval($_POST['note_id']);
        mysqli_query($conn, "UPDATE notes SET is_pinned = 1 - is_pinned WHERE id=$id");
    }
}

// Notes are a shared, school-wide board now — everyone sees the same
// notes; only admins/faculty can add, edit, delete, or pin them.
$subjects = mysqli_query($conn, "SELECT * FROM subjects ORDER BY subject_name");
$notes = mysqli_query($conn, "
  SELECT n.*, s.subject_name, s.color FROM notes n
  LEFT JOIN subjects s ON s.id = n.subject_id
  ORDER BY n.is_pinned DESC, n.updated_at DESC");

$pageTitle = "Notes";
$pageSub   = $canManage ? "Manage the shared notes board — visible to everyone." : "Notes and reminders set by your admin/faculty. View only.";
$activeNav = "notes";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="section-head">
  <div>
    <div class="section-eyebrow">Study Notes</div>
    <div class="section-title">Your Notes</div>
    <div class="section-sub"><?php echo mysqli_num_rows($notes); ?> note(s)</div>
  </div>
  <?php if ($canManage): ?>
  <button class="btn btn-primary" onclick="openModal('addNoteModal')">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Note
  </button>
  <?php endif; ?>
</div>

<?php if (mysqli_num_rows($notes) === 0): ?>
  <div class="card"><div class="empty-state">
    <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M4 4h16v12H8l-4 4V4z"/></svg>
    <div class="empty-title">No notes yet</div>
    <div class="empty-sub">Jot down reminders, ideas, or study notes.</div>
  </div></div>
<?php else: ?>
<div class="stat-grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
  <?php while ($n = mysqli_fetch_assoc($notes)):
    $nEsc = htmlspecialchars(json_encode($n), ENT_QUOTES, 'UTF-8');
    $color = $n['color'] ?: '#3b9eff';
  ?>
  <div class="card card-pad" style="border-top:3px solid <?php echo h($color); ?>">
    <div class="flex items-center gap-8" style="margin-bottom:8px">
      <?php if ($n['is_pinned']): ?><span title="Pinned" style="color:var(--gold)">📌</span><?php endif; ?>
      <div style="font-weight:700;font-size:14.5px;flex:1"><?php echo h($n['title']); ?></div>
    </div>
    <?php if ($n['subject_name']): ?><span class="pill" style="margin-bottom:10px"><?php echo h($n['subject_name']); ?></span><?php endif; ?>
    <div style="font-size:12.5px;color:rgba(210,225,255,.75);line-height:1.6;white-space:pre-wrap;margin:8px 0 14px;max-height:130px;overflow:hidden"><?php echo h($n['content']); ?></div>
    <div style="font-size:10.5px;color:var(--muted2);margin-bottom:12px">Updated <?php echo date('M j, Y', strtotime($n['updated_at'])); ?></div>
    <?php if ($canManage): ?>
    <div class="flex gap-8">
      <form method="POST"><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="note_id" value="<?php echo $n['id']; ?>">
        <button type="submit" class="btn btn-outline btn-sm"><?php echo $n['is_pinned'] ? 'Unpin' : 'Pin'; ?></button>
      </form>
      <button class="btn btn-outline btn-sm" onclick='editNote(<?php echo $nEsc; ?>)'>Edit</button>
      <form method="POST" onsubmit="return confirm('Delete this note?');">
        <input type="hidden" name="action" value="delete_note"><input type="hidden" name="note_id" value="<?php echo $n['id']; ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endwhile; ?>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Add Note Modal -->
<div class="modal-bg" id="addNoteModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">New Note</div><button class="modal-close" onclick="closeModal('addNoteModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_note">
      <div class="form-row"><div><label>Title</label><input type="text" name="title" required></div></div>
      <div class="form-row"><div><label>Content</label><textarea name="content" style="min-height:140px"></textarea></div></div>
      <div class="form-row cols-2">
        <div><label>Subject (optional)</label>
          <select name="subject_id"><option value="">— None —</option>
            <?php mysqli_data_seek($subjects,0); while($s = mysqli_fetch_assoc($subjects)): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo h($s['subject_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div style="display:flex;align-items:flex-end"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0"><input type="checkbox" name="is_pinned" style="width:auto"> Pin this note</label></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addNoteModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Note</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Note Modal -->
<div class="modal-bg" id="editNoteModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">Edit Note</div><button class="modal-close" onclick="closeModal('editNoteModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_note">
      <input type="hidden" name="note_id" id="en_id">
      <div class="form-row"><div><label>Title</label><input type="text" name="title" id="en_title" required></div></div>
      <div class="form-row"><div><label>Content</label><textarea name="content" id="en_content" style="min-height:140px"></textarea></div></div>
      <div class="form-row cols-2">
        <div><label>Subject (optional)</label>
          <select name="subject_id" id="en_subject_id"><option value="">— None —</option>
            <?php mysqli_data_seek($subjects,0); while($s = mysqli_fetch_assoc($subjects)): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo h($s['subject_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div style="display:flex;align-items:flex-end"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0"><input type="checkbox" name="is_pinned" id="en_pinned" style="width:auto"> Pin this note</label></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editNoteModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function editNote(n){
  document.getElementById('en_id').value = n.id;
  document.getElementById('en_title').value = n.title;
  document.getElementById('en_content').value = n.content || '';
  document.getElementById('en_subject_id').value = n.subject_id || '';
  document.getElementById('en_pinned').checked = n.is_pinned == 1;
  openModal('editNoteModal');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
