<?php
require_once __DIR__ . '/core/auth.php';

// ── quick add task from dashboard (admins/faculty only) ──
$message = ""; $msgType = "ok";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_add_task') {
    if (!$canManage) {
        $message = "Only admins and faculty can add tasks."; $msgType = "err";
    } else {
        $title = trim($_POST['title'] ?? '');
        $due   = $_POST['due_date'] ?? '';
        if ($title === '') {
            $message = "Task title is required."; $msgType = "err";
        } else {
            $due2 = $due ?: null;
            $stmt = mysqli_prepare($conn, "INSERT INTO tasks (user_id, title, due_date, priority, status) VALUES (?,?,?,'medium','pending')");
            mysqli_stmt_bind_param($stmt, "iss", $userId, $title, $due2);
            mysqli_stmt_execute($stmt);
            $message = "Task added."; $msgType = "ok";
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_task' && $canManage) {
    $id = intval($_POST['task_id']);
    $newStatus = $_POST['new_status'] === 'completed' ? 'completed' : 'pending';
    $completedAt = $newStatus === 'completed' ? 'NOW()' : 'NULL';
    mysqli_query($conn, "UPDATE tasks SET status='$newStatus', completed_at=$completedAt WHERE id=$id");
    header("Location: index.php");
    exit;
}

// ── stats — shared, school-wide numbers, same for every role ──
$totalTasks     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks"))['c'];
$completedTasks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status='completed'"))['c'];
$pendingTasks   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status!='completed'"))['c'];
$overdueTasks   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status!='completed' AND due_date IS NOT NULL AND due_date < CURDATE()"))['c'];
$subjectCount   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM subjects"))['c'];
$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

// today's classes (shared timetable)
$dow = date('D'); $dow = substr($dow,0,3);
$todayClasses = mysqli_query($conn, "
  SELECT s.*, sub.subject_name, sub.color FROM schedule s
  JOIN subjects sub ON sub.id = s.subject_id
  WHERE s.day_of_week='$dow' ORDER BY s.start_time ASC");

// upcoming tasks (next 7 days, not completed)
$upcoming = mysqli_query($conn, "
  SELECT t.*, sub.subject_name, sub.color FROM tasks t
  LEFT JOIN subjects sub ON sub.id = t.subject_id
  WHERE t.status != 'completed'
  ORDER BY (t.due_date IS NULL), t.due_date ASC, FIELD(t.priority,'high','medium','low') LIMIT 6");

// upcoming events
$events = mysqli_query($conn, "SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 4");

$pageTitle = "Dashboard";
$pageSub   = $canManage
    ? "Welcome back, " . $user['name'] . " — here's the school-wide overview."
    : "Welcome back, " . $user['name'] . " — here's what your admin/faculty set up.";
$activeNav = "dashboard";
include __DIR__ . '/core/layout_head.php';
?>

<?php if (isset($_GET['welcome'])): ?><div class="alert alert-ok">Welcome to Student Planner created by taberna, <?php echo h($user['name']); ?>! Your account is ready to go.</div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="stat-grid">
  <div class="stat-card" style="--c:var(--glow2)">
    <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
    <div class="stat-val"><?php echo $pendingTasks; ?></div>
    <div class="stat-label">Pending Tasks</div>
  </div>
  <div class="stat-card" style="--c:var(--green)">
    <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    <div class="stat-val"><?php echo $completedTasks; ?></div>
    <div class="stat-label">Completed</div>
  </div>
  <div class="stat-card" style="--c:var(--red)">
    <div class="stat-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    <div class="stat-val"><?php echo $overdueTasks; ?></div>
    <div class="stat-label">Overdue</div>
  </div>
  <div class="stat-card" style="--c:var(--gold)">
    <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
    <div class="stat-val"><?php echo $subjectCount; ?></div>
    <div class="stat-label">Subjects</div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:22px">
  <!-- Quick add + upcoming tasks -->
  <div class="card card-pad">
    <div class="section-head">
      <div>
        <div class="section-eyebrow">Task Progress</div>
        <div class="section-title">Add Tasks</div>
      </div>
      <span class="pill"><?php echo $completionRate; ?>% done</span>
    </div>
    <div class="progress-track" style="margin-bottom:20px">
      <div class="progress-fill" style="width:<?php echo $completionRate; ?>%"></div>
    </div>

    <?php if ($canManage): ?>
    <form method="POST" class="flex gap-8" style="margin-bottom:18px">
      <input type="hidden" name="action" value="quick_add_task">
      <input type="text" name="title" placeholder="Quick add a task…" required style="flex:1">
      <input type="date" name="due_date" style="width:150px">
      <button class="btn btn-primary btn-sm" type="submit">Add</button>
    </form>
    <?php endif; ?>

    <?php if (mysqli_num_rows($upcoming) === 0): ?>
      <div class="empty-state" style="padding:30px 10px">
        <div class="empty-title">No pending tasks 🎉</div>
        <div class="empty-sub">You're all caught up.</div>
      </div>
    <?php else: while ($t = mysqli_fetch_assoc($upcoming)): ?>
      <div class="flex items-center gap-10" style="padding:11px 0;border-bottom:1px solid rgba(90,120,190,.08)">
        <?php if ($canManage): ?>
        <form method="POST">
          <input type="hidden" name="action" value="toggle_task">
          <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>">
          <input type="hidden" name="new_status" value="completed">
          <button type="submit" class="btn-icon btn-outline" style="width:26px;height:26px;border-radius:50%" title="Mark complete"></button>
        </form>
        <?php else: ?>
        <div class="btn-icon" style="width:26px;height:26px;border-radius:50%;border:1px solid rgba(90,120,190,.2)"></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div style="font-size:13.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo h($t['title']); ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">
            <?php echo $t['subject_name'] ? h($t['subject_name']).' · ' : ''; ?>
            <?php echo $t['due_date'] ? 'Due '.date('M j', strtotime($t['due_date'])) : 'No due date'; ?>
          </div>
        </div>
        <span class="badge pri-<?php echo $t['priority']; ?>"><?php echo $t['priority']; ?></span>
      </div>
    <?php endwhile; endif; ?>
    <div style="margin-top:14px;text-align:right"><a href="tasks.php" class="btn btn-ghost btn-sm">View all tasks →</a></div>
  </div>

  <!-- Today's schedule + events -->
  <div class="card card-pad">
    <div class="section-head">
      <div>
        <div class="section-eyebrow">Today · <?php echo date('l'); ?></div>
        <div class="section-title">Class Schedule</div>
      </div>
    </div>
    <?php if (mysqli_num_rows($todayClasses) === 0): ?>
      <div class="empty-state" style="padding:30px 10px">
        <div class="empty-title">No classes today</div>
        <div class="empty-sub">Enjoy the free time!</div>
      </div>
    <?php else: while ($c = mysqli_fetch_assoc($todayClasses)): ?>
      <div class="flex items-center gap-10" style="padding:11px 0;border-bottom:1px solid rgba(90,120,190,.08)">
        <span class="chip" style="background:<?php echo h($c['color']); ?>"></span>
        <div style="flex:1;min-width:0">
          <div style="font-size:13.5px;font-weight:600"><?php echo h($c['subject_name']); ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px"><?php echo h($c['room'] ?: 'No room set'); ?></div>
        </div>
        <div style="font-size:11.5px;color:var(--glow2);font-weight:600">
          <?php echo date('g:i A', strtotime($c['start_time'])); ?> – <?php echo date('g:i A', strtotime($c['end_time'])); ?>
        </div>
      </div>
    <?php endwhile; endif; ?>

    <div class="section-eyebrow" style="margin-top:22px">Coming Up</div>
    <?php if (mysqli_num_rows($events) === 0): ?>
      <div style="font-size:12.5px;color:var(--muted2);padding:8px 0">No upcoming events.</div>
    <?php else: while ($e = mysqli_fetch_assoc($events)): ?>
      <div class="flex items-center gap-10" style="padding:9px 0">
        <span class="badge type-<?php echo $e['event_type']; ?>"><?php echo h($e['event_type']); ?></span>
        <div style="flex:1;font-size:13px"><?php echo h($e['title']); ?></div>
        <div style="font-size:11px;color:var(--muted)"><?php echo date('M j', strtotime($e['event_date'])); ?></div>
      </div>
    <?php endwhile; endif; ?>
    <div style="margin-top:14px;text-align:right"><a href="schedule.php" class="btn btn-ghost btn-sm">View schedule →</a></div>
  </div>
</div>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
