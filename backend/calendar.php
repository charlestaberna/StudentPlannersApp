<?php
require_once __DIR__ . '/core/auth.php';

$message = ""; $msgType = "ok";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$canManage) {
        $message = "Only admins and faculty can manage calendar events."; $msgType = "err";
    } elseif ($action === 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $date  = $_POST['event_date'] ?? '';
        $type  = in_array($_POST['event_type'] ?? '', ['exam','deadline','holiday','activity','other']) ? $_POST['event_type'] : 'other';
        $desc  = trim($_POST['description'] ?? '');
        if (!$title || !$date) {
            $message = "Title and date are required."; $msgType = "err";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO events (user_id,title,event_date,event_type,description) VALUES (?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, "issss", $userId, $title, $date, $type, $desc);
            mysqli_stmt_execute($stmt);
            $message = "Event added."; $msgType = "ok";
        }
    } elseif ($action === 'delete_event') {
        $id = intval($_POST['event_id']);
        mysqli_query($conn, "DELETE FROM events WHERE id=$id");
        $message = "Event removed."; $msgType = "ok";
    }
}

$month = isset($_GET['m']) ? max(1,min(12,intval($_GET['m']))) : intval(date('n'));
$year  = isset($_GET['y']) ? intval($_GET['y']) : intval(date('Y'));
$first = mktime(0,0,0,$month,1,$year);
$daysInMonth = date('t', $first);
$startWeekday = date('N', $first); // 1 = Mon .. 7 = Sun
$monthLabel = date('F Y', $first);

$prevM = $month - 1; $prevY = $year; if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year; if ($nextM > 12) { $nextM = 1; $nextY++; }

$monthStart = date('Y-m-01', $first);
$monthEnd   = date('Y-m-t', $first);

// Tasks and events are shared, school-wide content now — everyone sees the
// same calendar; only admins/faculty can add or remove events.
$byDay = [];
$tasksRes = mysqli_query($conn, "SELECT id, title, due_date AS d, 'task' AS kind, priority AS meta FROM tasks WHERE due_date BETWEEN '$monthStart' AND '$monthEnd' AND status != 'completed'");
while ($r = mysqli_fetch_assoc($tasksRes)) { $byDay[$r['d']][] = $r; }
$eventsRes = mysqli_query($conn, "SELECT id, title, event_date AS d, 'event' AS kind, event_type AS meta FROM events WHERE event_date BETWEEN '$monthStart' AND '$monthEnd'");
while ($r = mysqli_fetch_assoc($eventsRes)) { $byDay[$r['d']][] = $r; }

$allEventsList = mysqli_query($conn, "SELECT * FROM events ORDER BY event_date DESC LIMIT 30");

$pageTitle = "Calendar";
$pageSub   = $canManage ? "Month view of the shared tasks and events." : "Month view set by your admin/faculty. View only.";
$activeNav = "calendar";
include __DIR__ . '/core/layout_head.php';
?>

<?php if ($message): ?><div class="alert alert-<?php echo $msgType==='ok'?'ok':'err'; ?>"><?php echo h($message); ?></div><?php endif; ?>

<div class="section-head">
  <div>
    <div class="section-eyebrow">Month View</div>
    <div class="section-title"><?php echo $monthLabel; ?></div>
  </div>
  <div class="flex gap-8">
    <a class="btn btn-ghost btn-sm" href="?m=<?php echo $prevM; ?>&y=<?php echo $prevY; ?>">← Prev</a>
    <a class="btn btn-ghost btn-sm" href="?m=<?php echo date('n'); ?>&y=<?php echo date('Y'); ?>">Today</a>
    <a class="btn btn-ghost btn-sm" href="?m=<?php echo $nextM; ?>&y=<?php echo $nextY; ?>">Next →</a>
    <?php if ($canManage): ?>
    <button class="btn btn-primary btn-sm" onclick="openModal('addEventModal')">+ Add Event</button>
    <?php endif; ?>
  </div>
</div>

<div class="card card-pad" style="margin-bottom:22px">
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:8px">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
      <div style="text-align:center;font-size:10.5px;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase;padding-bottom:6px"><?php echo $d; ?></div>
    <?php endforeach; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px">
    <?php for ($i=1; $i<$startWeekday; $i++): ?>
      <div></div>
    <?php endfor; ?>
    <?php for ($day=1; $day<=$daysInMonth; $day++):
      $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
      $isToday = $dateStr === date('Y-m-d');
      $items = $byDay[$dateStr] ?? [];
    ?>
      <div style="min-height:88px;border-radius:10px;padding:8px;background:<?php echo $isToday?'rgba(26,108,245,.1)':'rgba(255,255,255,.02)'; ?>;border:1px solid <?php echo $isToday?'rgba(60,130,255,.4)':'rgba(90,120,190,.08)'; ?>">
        <div style="font-size:11.5px;font-weight:<?php echo $isToday?'800':'600'; ?>;color:<?php echo $isToday?'var(--glow2)':'var(--muted)'; ?>;margin-bottom:5px"><?php echo $day; ?></div>
        <?php foreach (array_slice($items,0,3) as $it):
          $cls = $it['kind']==='task' ? 'badge pri-'.$it['meta'] : 'badge type-'.$it['meta'];
        ?>
          <div class="<?php echo $cls; ?>" style="display:block;margin-bottom:3px;font-size:8.5px;padding:2px 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo h($it['title']); ?>"><?php echo h($it['title']); ?></div>
        <?php endforeach; ?>
        <?php if (count($items) > 3): ?><div style="font-size:9px;color:var(--muted2)">+<?php echo count($items)-3; ?> more</div><?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
  <table>
    <thead><tr><th>Event</th><th>Date</th><th>Type</th><th>Description</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (mysqli_num_rows($allEventsList) === 0): ?>
      <tr><td colspan="<?php echo $canManage ? 5 : 4; ?>"><div class="empty-state"><div class="empty-title">No events yet</div><div class="empty-sub">Add exams, deadlines, or important dates.</div></div></td></tr>
    <?php else: while ($e = mysqli_fetch_assoc($allEventsList)): ?>
      <tr>
        <td style="font-weight:600"><?php echo h($e['title']); ?></td>
        <td><?php echo date('M j, Y', strtotime($e['event_date'])); ?></td>
        <td><span class="badge type-<?php echo $e['event_type']; ?>"><?php echo h($e['event_type']); ?></span></td>
        <td style="color:var(--muted);font-size:12.5px"><?php echo h($e['description']); ?></td>
        <?php if ($canManage): ?>
        <td>
          <form method="POST" onsubmit="return confirm('Delete this event?');">
            <input type="hidden" name="action" value="delete_event">
            <input type="hidden" name="event_id" value="<?php echo $e['id']; ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endwhile; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="modal-bg" id="addEventModal">
  <div class="modal">
    <div class="modal-head"><div class="modal-title">Add Calendar Event</div><button class="modal-close" onclick="closeModal('addEventModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add_event">
      <div class="form-row"><div><label>Title</label><input type="text" name="title" required placeholder="e.g. Final Exams"></div></div>
      <div class="form-row cols-2">
        <div><label>Date</label><input type="date" name="event_date" required></div>
        <div><label>Type</label>
          <select name="event_type">
            <option value="exam">Exam</option>
            <option value="deadline">Deadline</option>
            <option value="holiday">Holiday</option>
            <option value="activity">Activity</option>
            <option value="other" selected>Other</option>
          </select>
        </div>
      </div>
      <div class="form-row"><div><label>Description</label><textarea name="description" placeholder="Optional details…"></textarea></div></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addEventModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Event</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
