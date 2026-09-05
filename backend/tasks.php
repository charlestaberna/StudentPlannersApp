<?php
require_once __DIR__ . '/core/auth.php';

$message = ""; $msgType = "ok";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$canManage) {
        $message = "Only admins and faculty can manage tasks."; $msgType = "err";
    } elseif ($action === 'add_task' || $action === 'edit_task') {
        $title   = trim($_POST['title'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $due     = $_POST['due_date'] ?? '';
        $time    = $_POST['due_time'] ?? '';
        $subject = $_POST['subject_id'] ?? '';
        $priority= in_array($_POST['priority'] ?? '', ['low','medium','high']) ? $_POST['priority'] : 'medium';
        $status  = in_array($_POST['status'] ?? '', ['pending','in_progress','completed']) ? $_POST['status'] : 'pending';

        $due2  = $due ?: null;
        $time2 = $time ?: null;
        $sub2  = $subject !== '' ? intval($subject) : null;

        if ($title === '') {
            $message = "Task title is required."; $msgType = "err";
        } elseif ($action === 'add_task') {
            $stmt = mysqli_prepare($conn, "INSERT INTO tasks (user_id,subject_id,title,description,due_date,due_time,priority,status) VALUES (?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, "iissssss", $userId, $sub2, $title, $desc, $due2, $time2, $priority, $status);
            mysqli_stmt_execute($stmt);
            $message = "Task added."; $msgType = "ok";
        } else {
            // shared task board — any admin/faculty can edit any task
            $id = intval($_POST['task_id']);
            $completedAt = $status === 'completed' ? ', completed_at = NOW()' : ', completed_at = NULL';
            $stmt = mysqli_prepare($conn, "UPDATE tasks SET subject_id=?, title=?, description=?, due_date=?, due_time=?, priority=?, status=? $completedAt WHERE id=?");
            mysqli_stmt_bind_param($stmt, "issssssi", $sub2, $title, $desc, $due2, $time2, $priority, $status, $id);
            mysqli_stmt_execute($stmt);
            $message = "Task updated."; $msgType = "ok";
        }
    } elseif ($action === 'delete_task') {
        $id = intval($_POST['task_id']);
        mysqli_query($conn, "DELETE FROM tasks WHERE id=$id");
        $message = "Task deleted."; $msgType = "ok";
    } elseif ($action === 'toggle_task') {
        $id = intval($_POST['task_id']);
        $newStatus = $_POST['new_status'] === 'completed' ? 'completed' : 'pending';
        $completedAt = $newStatus === 'completed' ? 'NOW()' : 'NULL';
        mysqli_query($conn, "UPDATE tasks SET status='$newStatus', completed_at=$completedAt WHERE id=$id");
    }
}

// filters
$filterStatus  = $_GET['status'] ?? 'all';
$filterSubject = $_GET['subject'] ?? 'all';
$search        = trim($_GET['q'] ?? '');

// Tasks are a shared, school-wide to-do board now — everyone sees the same
// list; only admins/faculty can add, edit, delete, or toggle them.
$where = [];
if ($filterStatus !== 'all') $where[] = "t.status='" . mysqli_real_escape_string($conn,$filterStatus) . "'";
if ($filterSubject !== 'all') $where[] = "t.subject_id=" . intval($filterSubject);
if ($search !== '') $where[] = "t.title LIKE '%" . mysqli_real_escape_string($conn,$search) . "%'";
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$tasks = mysqli_query($conn, "
  SELECT t.*, sub.subject_name, sub.color FROM tasks t
  LEFT JOIN subjects sub ON sub.id = t.subject_id
  WHERE $whereSql
  ORDER BY (t.status='completed'), (t.due_date IS NULL), t.due_date ASC, FIELD(t.priority,'high','medium','low')");

$subjects = mysqli_query($conn, "SELECT * FROM subjects ORDER BY subject_name");
$subjectsForFilter = mysqli_query($conn, "SELECT * FROM subjects ORDER BY subject_name");

$pageTitle = "Welcome to Tasks";
$pageSub   = $canManage ? "Manage the shared task board — visible to everyone." : "Tasks and assignments set by your admin/faculty. View only.";
$activeNav = "tasks";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="section-head">
  <div>
    <div class="section-eyebrow">To-Do List</div>
    <div class="section-title">Your Tasks</div>
    <div class="section-sub"><?php echo mysqli_num_rows($tasks); ?> task(s) matching your filters</div>
  </div>
  <?php if ($canManage): ?>
  <button class="btn btn-primary" onclick="openModal('addTaskModal')">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Task
  </button>
  <?php endif; ?>
</div>

<div class="card card-pad" style="margin-bottom:18px">
  <form method="GET" class="form-row cols-3" style="margin-bottom:0">
    <div><input type="text" name="q" placeholder="Search tasks…" value="<?php echo h($search); ?>"></div>
    <div>
      <select name="status" onchange="this.form.submit()">
        <option value="all" <?php echo $filterStatus==='all'?'selected':''; ?>>All Statuses</option>
        <option value="pending" <?php echo $filterStatus==='pending'?'selected':''; ?>>Pending</option>
        <option value="in_progress" <?php echo $filterStatus==='in_progress'?'selected':''; ?>>In Progress</option>
        <option value="completed" <?php echo $filterStatus==='completed'?'selected':''; ?>>Completed</option>
      </select>
    </div>
    <div>
      <select name="subject" onchange="this.form.submit()">
        <option value="all">All Subjects</option>
        <?php mysqli_data_seek($subjectsForFilter,0); while($s = mysqli_fetch_assoc($subjectsForFilter)): ?>
          <option value="<?php echo $s['id']; ?>" <?php echo $filterSubject==$s['id']?'selected':''; ?>><?php echo h($s['subject_name']); ?></option>
        <?php endwhile; ?>
      </select>
    </div>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
  <table>
    <thead><tr><th></th><th>Task</th><th>Subject</th><th>Date</th><th>Priority</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (mysqli_num_rows($tasks) === 0): ?>
      <tr><td colspan="7">
        <div class="empty-state">
          <svg viewBox="0 0 24 24" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          <div class="empty-title">No tasks found</div>
          <div class="empty-sub">Try adjusting your filters, or add a new task.</div>
        </div>
      </td></tr>
    <?php else: while ($t = mysqli_fetch_assoc($tasks)):
        $overdue = $t['due_date'] && $t['status'] !== 'completed' && strtotime($t['due_date']) < strtotime(date('Y-m-d'));
        $tEsc = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
    ?>
      <tr>
        <td>
          <?php if ($canManage): ?>
          <form method="POST">
            <input type="hidden" name="action" value="toggle_task">
            <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
            <input type="hidden" name="new_status" value="<?php echo $t['status']==='completed'?'pending':'completed'; ?>">
            <button type="submit" class="btn-icon btn-outline" style="width:26px;height:26px;border-radius:50%;<?php echo $t['status']==='completed'?'background:rgba(46,207,122,.2);border-color:var(--green);color:var(--green)':''; ?>" title="Toggle complete">
              <?php echo $t['status']==='completed' ? '✓' : ''; ?>
            </button>
          </form>
          <?php else: ?>
          <div class="btn-icon" style="width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:1px solid rgba(90,120,190,.2);<?php echo $t['status']==='completed'?'background:rgba(46,207,122,.2);border-color:var(--green);color:var(--green)':''; ?>">
            <?php echo $t['status']==='completed' ? '✓' : ''; ?>
          </div>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight:600;<?php echo $t['status']==='completed'?'text-decoration:line-through;color:var(--muted)':''; ?>"><?php echo h($t['title']); ?></div>
          <?php if ($t['description']): ?><div style="font-size:11.5px;color:var(--muted);margin-top:2px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo h($t['description']); ?></div><?php endif; ?>
        </td>
        <td><?php if ($t['subject_name']): ?><span class="flex items-center gap-8"><span class="chip" style="background:<?php echo h($t['color']); ?>"></span><?php echo h($t['subject_name']); ?></span><?php else: ?><span style="color:var(--muted2)">—</span><?php endif; ?></td>
        <td>
          <?php if ($t['due_date']): ?>
            <span style="<?php echo $overdue ? 'color:#ff8f8f;font-weight:700' : ''; ?>"><?php echo date('M j, Y', strtotime($t['due_date'])); ?></span>
            <?php if ($overdue): ?><div style="font-size:10px;color:#ff8f8f">OVERDUE</div><?php endif; ?>
          <?php else: ?><span style="color:var(--muted2)">No date</span><?php endif; ?>
        </td>
        <td><span class="badge pri-<?php echo $t['priority']; ?>"><?php echo $t['priority']; ?></span></td>
        <td><span class="badge st-<?php echo $t['status']; ?>"><?php echo str_replace('_',' ',$t['status']); ?></span></td>
        <td>
          <?php if ($canManage): ?>
          <div class="flex gap-8">
            <button class="btn btn-outline btn-sm" onclick='editTask(<?php echo $tEsc; ?>)'>Edit</button>
            <form method="POST" onsubmit="return confirm('Delete this task?');">
              <input type="hidden" name="action" value="delete_task">
              <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($canManage): ?>
<!-- Add Task Modal -->
<div class="modal-bg" id="addTaskModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Add Task</div>
      <button class="modal-close" onclick="closeModal('addTaskModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_task">
      <div class="form-row"><div><label>Title</label><input type="text" name="title" required placeholder="e.g. Finish lab report"></div></div>
      <div class="form-row"><div><label>Description</label><textarea name="description" placeholder="Optional details…"></textarea></div></div>
      <div class="form-row cols-2">
        <div><label>Due Date</label><input type="date" name="due_date"></div>
        <div><label>Due Time</label><input type="time" name="due_time"></div>
      </div>
      <div class="form-row cols-3">
        <div><label>Subject</label>
          <select name="subject_id"><option value="">— None —</option>
            <?php mysqli_data_seek($subjects,0); while($s = mysqli_fetch_assoc($subjects)): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo h($s['subject_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div><label>Priority</label>
          <select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select>
        </div>
        <div><label>Status</label>
          <select name="status"><option value="pending" selected>Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addTaskModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Task</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Task Modal -->
<div class="modal-bg" id="editTaskModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Edit Task</div>
      <button class="modal-close" onclick="closeModal('editTaskModal')">✕</button>
    </div>
    <form method="POST" id="editTaskForm">
      <input type="hidden" name="action" value="edit_task">
      <input type="hidden" name="task_id" id="et_id">
      <div class="form-row"><div><label>Title</label><input type="text" name="title" id="et_title" required></div></div>
      <div class="form-row"><div><label>Description</label><textarea name="description" id="et_description"></textarea></div></div>
      <div class="form-row cols-2">
        <div><label>Due Date</label><input type="date" name="due_date" id="et_due_date"></div>
        <div><label>Due Time</label><input type="time" name="due_time" id="et_due_time"></div>
      </div>
      <div class="form-row cols-3">
        <div><label>Subject</label>
          <select name="subject_id" id="et_subject_id"><option value="">— None —</option>
            <?php mysqli_data_seek($subjects,0); while($s = mysqli_fetch_assoc($subjects)): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo h($s['subject_name']); ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div><label>Priority</label>
          <select name="priority" id="et_priority"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
        </div>
        <div><label>Status</label>
          <select name="status" id="et_status"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editTaskModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function editTask(t){
  document.getElementById('et_id').value = t.id;
  document.getElementById('et_title').value = t.title;
  document.getElementById('et_description').value = t.description || '';
  document.getElementById('et_due_date').value = t.due_date || '';
  document.getElementById('et_due_time').value = t.due_time ? t.due_time.slice(0,5) : '';
  document.getElementById('et_subject_id').value = t.subject_id || '';
  document.getElementById('et_priority').value = t.priority;
  document.getElementById('et_status').value = t.status;
  openModal('editTaskModal');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
