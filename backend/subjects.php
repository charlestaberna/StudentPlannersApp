<?php
require_once __DIR__ . '/core/auth.php';

$message = ""; $msgType = "ok";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$canManage) {
        $message = "Only admins and faculty can manage subjects."; $msgType = "err";
    } elseif ($action === 'add_subject' || $action === 'edit_subject') {
        $name  = trim($_POST['subject_name'] ?? '');
        $code  = trim($_POST['subject_code'] ?? '');
        $instr = trim($_POST['instructor'] ?? '');
        $units = ($_POST['units'] ?? '') !== '' ? floatval($_POST['units']) : null;
        $color = $_POST['color'] ?? '#1a6cf5';

        if ($name === '') {
            $message = "Subject name is required."; $msgType = "err";
        } elseif ($action === 'add_subject') {
            $stmt = mysqli_prepare($conn, "INSERT INTO subjects (user_id,subject_name,subject_code,instructor,units,color) VALUES (?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, "issdss", $userId, $name, $code, $instr, $units, $color);
            mysqli_stmt_execute($stmt);
            $message = "Subject added."; $msgType = "ok";
        } else {
            // shared list now — any admin/faculty can edit any subject,
            // not just the one that originally added it
            $id = intval($_POST['subject_id']);
            $stmt = mysqli_prepare($conn, "UPDATE subjects SET subject_name=?, subject_code=?, instructor=?, units=?, color=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssdsi", $name, $code, $instr, $units, $color, $id);
            mysqli_stmt_execute($stmt);
            $message = "Subject updated."; $msgType = "ok";
        }
    } elseif ($action === 'delete_subject') {
        $id = intval($_POST['subject_id']);
        mysqli_query($conn, "DELETE FROM subjects WHERE id=$id");
        $message = "Subject deleted (linked tasks/notes were kept, unlinked)."; $msgType = "ok";
    }
}

// Subjects are a shared, school-wide list now — everyone sees the same
// subjects; only admins/faculty can add, edit, or delete them.
$subjects = mysqli_query($conn, "
  SELECT s.*,
    (SELECT COUNT(*) FROM tasks t WHERE t.subject_id=s.id AND t.status!='completed') AS open_tasks,
    (SELECT COUNT(*) FROM schedule sc WHERE sc.subject_id=s.id) AS class_count
  FROM subjects s ORDER BY s.subject_name ASC");

$pageTitle = "Subjects";
$pageSub   = $canManage ? "Manage the shared list of subjects — visible to everyone." : "Subjects set up by your admin/faculty. View only.";
$activeNav = "subjects";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="section-head">
  <div>
    <div class="section-eyebrow">Courses</div>
    <div class="section-title">Your Subjects</div>
    <div class="section-sub"><?php echo mysqli_num_rows($subjects); ?> subject(s)</div>
  </div>
  <?php if ($canManage): ?>
  <button class="btn btn-primary" onclick="openModal('addSubjectModal')">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Subject
  </button>
  <?php endif; ?>
</div>

<?php if (mysqli_num_rows($subjects) === 0): ?>
  <div class="card">
    <div class="empty-state">
      <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      <div class="empty-title">No subjects yet</div>
      <div class="empty-sub">Add your first subject to start scheduling classes and tasks.</div>
    </div>
  </div>
<?php else: ?>
<div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
  <?php mysqli_data_seek($subjects,0); while ($s = mysqli_fetch_assoc($subjects)):
    $sEsc = htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8');
  ?>
  <div class="card card-pad" style="--c:<?php echo h($s['color']); ?>;border-top:3px solid <?php echo h($s['color']); ?>">
    <div class="flex items-center gap-10" style="margin-bottom:10px">
      <span class="chip" style="width:12px;height:12px;background:<?php echo h($s['color']); ?>"></span>
      <div style="font-size:16px;font-weight:700;flex:1"><?php echo h($s['subject_name']); ?></div>
    </div>
    <?php if ($s['subject_code']): ?><div style="font-size:11.5px;color:var(--muted);margin-bottom:4px">Code: <?php echo h($s['subject_code']); ?></div><?php endif; ?>
    <?php if ($s['instructor']): ?><div style="font-size:11.5px;color:var(--muted);margin-bottom:4px">Instructor: <?php echo h($s['instructor']); ?></div><?php endif; ?>
    <?php if ($s['units']): ?><div style="font-size:11.5px;color:var(--muted);margin-bottom:10px">Units: <?php echo h($s['units']); ?></div><?php endif; ?>
    <div class="flex gap-8" style="margin:12px 0 14px">
      <span class="pill"><?php echo $s['open_tasks']; ?> open task<?php echo $s['open_tasks']==1?'':'s'; ?></span>
      <span class="pill gold"><?php echo $s['class_count']; ?> class slot<?php echo $s['class_count']==1?'':'s'; ?></span>
    </div>
    <?php if ($canManage): ?>
    <div class="flex gap-8">
      <button class="btn btn-outline btn-sm" style="flex:1" onclick='editSubject(<?php echo $sEsc; ?>)'>Edit</button>
      <form method="POST" style="flex:1" onsubmit="return confirm('Delete this subject? Linked tasks/notes will be unlinked, not deleted.');">
        <input type="hidden" name="action" value="delete_subject">
        <input type="hidden" name="subject_id" value="<?php echo $s['id']; ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Delete</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endwhile; ?>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Add Subject Modal -->
<div class="modal-bg" id="addSubjectModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">Add Subject</div><button class="modal-close" onclick="closeModal('addSubjectModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_subject">
      <div class="form-row"><div><label>Subject Name</label><input type="text" name="subject_name" required placeholder="e.g. Database Management"></div></div>
      <div class="form-row cols-2">
        <div><label>Subject Code</label><input type="text" name="subject_code" placeholder="e.g. IT302"></div>
        <div><label>Units</label><input type="number" step="0.5" name="units" placeholder="3"></div>
      </div>
      <div class="form-row cols-2">
        <div><label>Instructor</label><input type="text" name="instructor" placeholder="Prof. Name"></div>
        <div><label>Color Tag</label><input type="color" name="color" value="#1a6cf5" style="height:44px;padding:4px"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addSubjectModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Subject</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal-bg" id="editSubjectModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">Edit Subject</div><button class="modal-close" onclick="closeModal('editSubjectModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_subject">
      <input type="hidden" name="subject_id" id="es_id">
      <div class="form-row"><div><label>Subject Name</label><input type="text" name="subject_name" id="es_name" required></div></div>
      <div class="form-row cols-2">
        <div><label>Subject Code</label><input type="text" name="subject_code" id="es_code"></div>
        <div><label>Units</label><input type="number" step="0.5" name="units" id="es_units"></div>
      </div>
      <div class="form-row cols-2">
        <div><label>Instructor</label><input type="text" name="instructor" id="es_instructor"></div>
        <div><label>Color Tag</label><input type="color" name="color" id="es_color" style="height:44px;padding:4px"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editSubjectModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function editSubject(s){
  document.getElementById('es_id').value = s.id;
  document.getElementById('es_name').value = s.subject_name;
  document.getElementById('es_code').value = s.subject_code || '';
  document.getElementById('es_units').value = s.units || '';
  document.getElementById('es_instructor').value = s.instructor || '';
  document.getElementById('es_color').value = s.color || '#1a6cf5';
  openModal('editSubjectModal');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
