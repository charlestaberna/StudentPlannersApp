<?php
require_once __DIR__ . '/core/auth.php';

$message = ""; $msgType = "ok";
$days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

// The class schedule is now a single school-wide timetable rather than a
// per-account list: admins and faculty ("staff") can add/edit/remove class
// slots, and everyone (including students) can view the whole thing.
// Students only get read access — no add/edit/delete controls are rendered
// for them, and the actions below are blocked server-side too so a student
// can't just POST directly to schedule.php to bypass the UI.
$canManageSchedule = $isAdmin || ($user['role'] === 'faculty');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$canManageSchedule) {
        $message = "Only admins and faculty can edit the class schedule."; $msgType = "err";
    } elseif ($action === 'add_slot') {
        $subject = intval($_POST['subject_id'] ?? 0);
        $day     = in_array($_POST['day_of_week'] ?? '', $days) ? $_POST['day_of_week'] : 'Mon';
        $start   = $_POST['start_time'] ?? '';
        $end     = $_POST['end_time'] ?? '';
        $room    = trim($_POST['room'] ?? '');

        if (!$subject || !$start || !$end) {
            $message = "Subject, start time and end time are required."; $msgType = "err";
        } elseif ($end <= $start) {
            $message = "End time must be after start time."; $msgType = "err";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO schedule (user_id,subject_id,day_of_week,start_time,end_time,room) VALUES (?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, "iissss", $userId, $subject, $day, $start, $end, $room);
            mysqli_stmt_execute($stmt);
            $message = "Class added to schedule."; $msgType = "ok";
        }
    } elseif ($action === 'delete_slot') {
        // any admin/faculty can remove any slot now — it's a shared
        // timetable, not tied to whoever happened to add it
        $id = intval($_POST['slot_id']);
        mysqli_query($conn, "DELETE FROM schedule WHERE id=$id");
        $message = "Class removed."; $msgType = "ok";
    }
}

// Subjects for the "Add Class" dropdown: only admins/faculty manage the
// schedule, so this stays scoped to the current admin/faculty account's own
// subject list — students never see this dropdown at all.
$subjects = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id=$userId ORDER BY subject_name");
$hasSubjects = mysqli_num_rows($subjects) > 0;

// The timetable itself is shared: every class slot from every admin/faculty
// account shows up here for everyone, students included.
$slotsRes = mysqli_query($conn, "
  SELECT sc.*, s.subject_name, s.color FROM schedule sc
  JOIN subjects s ON s.id = sc.subject_id
  ORDER BY FIELD(sc.day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), sc.start_time");
$slots = [];
foreach ($days as $d) $slots[$d] = [];
while ($row = mysqli_fetch_assoc($slotsRes)) { $slots[$row['day_of_week']][] = $row; }

$pageTitle = "Class Schedule";
$pageSub   = "Your weekly timetable.";
$activeNav = "schedule";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="section-head">
  <div>
    <div class="section-eyebrow">Weekly Timetable</div>
    <div class="section-title">Class Schedule</div>
    <div class="section-sub"><?php echo $canManageSchedule ? 'Shared school-wide timetable — visible to everyone, editable by admins and faculty.' : 'Shared school-wide timetable set by your admin/faculty. View only.'; ?></div>
  </div>
  <?php if ($canManageSchedule && $hasSubjects): ?>
  <button class="btn btn-primary" onclick="openModal('addSlotModal')">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Class
  </button>
  <?php endif; ?>
</div>

<?php if ($canManageSchedule && !$hasSubjects): ?>
  <div class="card"><div class="empty-state">
    <div class="empty-title">Add a subject first</div>
    <div class="empty-sub">You need at least one subject of your own before you can add classes to the schedule. <a href="subjects.php" style="color:var(--glow2)">Go to Subjects →</a></div>
  </div></div>
<?php endif; ?>

<?php if (!$canManageSchedule || $hasSubjects || !empty(array_filter($slots))): ?>
<div class="table-wrap">
<div style="display:grid;grid-template-columns:repeat(7,minmax(150px,1fr));gap:12px;min-width:1000px">
  <?php foreach ($days as $d): $full = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday','Sun'=>'Sunday']; ?>
  <div class="card card-pad" style="min-height:220px">
    <div style="font-weight:700;font-size:13px;letter-spacing:.5px;margin-bottom:12px;color:var(--glow2)"><?php echo $full[$d]; ?></div>
    <?php if (empty($slots[$d])): ?>
      <div style="font-size:11.5px;color:var(--muted2);padding:8px 0">No classes</div>
    <?php else: foreach ($slots[$d] as $sl): ?>
      <div style="background:rgba(255,255,255,.03);border-left:3px solid <?php echo h($sl['color']); ?>;border-radius:8px;padding:9px 10px;margin-bottom:8px;position:relative">
        <div style="font-size:12.5px;font-weight:700;line-height:1.3"><?php echo h($sl['subject_name']); ?></div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:3px"><?php echo date('g:i A', strtotime($sl['start_time'])); ?>–<?php echo date('g:i A', strtotime($sl['end_time'])); ?></div>
        <?php if ($sl['room']): ?><div style="font-size:10.5px;color:var(--muted2);margin-top:1px"><?php echo h($sl['room']); ?></div><?php endif; ?>
        <?php if ($canManageSchedule): ?>
        <form method="POST" onsubmit="return confirm('Remove this class slot?');" style="position:absolute;top:6px;right:6px">
          <input type="hidden" name="action" value="delete_slot">
          <input type="hidden" name="slot_id" value="<?php echo $sl['id']; ?>">
          <button type="submit" title="Remove" style="background:none;border:none;color:var(--muted2);cursor:pointer;font-size:12px">✕</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<?php if ($canManageSchedule): ?>
<!-- Add Slot Modal -->
<div class="modal-bg" id="addSlotModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">Add Class to Schedule</div><button class="modal-close" onclick="closeModal('addSlotModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_slot">
      <div class="form-row"><div><label>Subject</label>
        <select name="subject_id" required>
          <?php mysqli_data_seek($subjects,0); while($s = mysqli_fetch_assoc($subjects)): ?>
            <option value="<?php echo $s['id']; ?>"><?php echo h($s['subject_name']); ?></option>
          <?php endwhile; ?>
        </select>
      </div></div>
      <div class="form-row"><div><label>Day</label>
        <select name="day_of_week"><?php foreach($days as $d): ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php endforeach; ?></select>
      </div></div>
      <div class="form-row cols-2">
        <div><label>Start Time</label><input type="time" name="start_time" required></div>
        <div><label>End Time</label><input type="time" name="end_time" required></div>
      </div>
      <div class="form-row"><div><label>Room (optional)</label><input type="text" name="room" placeholder="e.g. Rm 204"></div></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addSlotModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add to Schedule</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
