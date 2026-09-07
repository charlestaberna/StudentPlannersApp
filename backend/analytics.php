<?php
require_once __DIR__ . '/core/auth.php';

// Analytics reflect the shared task/subject board now — the same
// school-wide numbers everyone (admins, faculty, students) sees.
$total     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks"))['c'];
$completed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status='completed'"))['c'];
$pending   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status='pending'"))['c'];
$inprog    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status='in_progress'"))['c'];
$overdue   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE status!='completed' AND due_date IS NOT NULL AND due_date < CURDATE()"))['c'];
$rate      = $total > 0 ? round(($completed/$total)*100) : 0;

// tasks per subject (open vs completed)
$bySubject = mysqli_query($conn, "
  SELECT sub.subject_name, sub.color,
    SUM(t.status='completed') AS done,
    SUM(t.status!='completed') AS open_
  FROM subjects sub LEFT JOIN tasks t ON t.subject_id = sub.id
  GROUP BY sub.id ORDER BY sub.subject_name");
$subjLabels=[]; $subjDone=[]; $subjOpen=[]; $subjColors=[];
$bySubjectRows = [];
while ($r = mysqli_fetch_assoc($bySubject)) {
    $bySubjectRows[] = $r;
    $subjLabels[] = $r['subject_name']; $subjDone[] = (int)$r['done']; $subjOpen[] = (int)$r['open_']; $subjColors[] = $r['color'];
}

// priority breakdown (open tasks)
$pri = mysqli_query($conn, "SELECT priority, COUNT(*) c FROM tasks WHERE status != 'completed' GROUP BY priority");
$priData = ['low'=>0,'medium'=>0,'high'=>0];
while ($r = mysqli_fetch_assoc($pri)) { $priData[$r['priority']] = (int)$r['c']; }

// last 7 days completion trend
$trendLabels = []; $trendData = [];
for ($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM tasks WHERE DATE(completed_at)='$d'"))['c'];
    $trendLabels[] = date('D', strtotime($d));
    $trendData[] = (int)$c;
}

$pageTitle = "Analytics";
$pageSub   = "School-wide study & productivity overview.";
$activeNav = "analytics";
include __DIR__ . '/core/layout_head.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>

<div class="stat-grid">
  <div class="stat-card" style="--c:var(--glow2)"><div class="stat-val"><?php echo $total; ?></div><div class="stat-label">Total Tasks</div></div>
  <div class="stat-card" style="--c:var(--green)"><div class="stat-val"><?php echo $rate; ?>%</div><div class="stat-label">Completion Rate</div></div>
  <div class="stat-card" style="--c:var(--gold)"><div class="stat-val"><?php echo $inprog; ?></div><div class="stat-label">In Progress</div></div>
  <div class="stat-card" style="--c:var(--red)"><div class="stat-val"><?php echo $overdue; ?></div><div class="stat-label">Overdue</div></div>
</div>

<div class="grid-2" style="margin-bottom:22px">
  <div class="card card-pad">
    <div class="section-eyebrow">Last 7 Days</div>
    <div class="section-title" style="margin-bottom:14px">Tasks Completed</div>
    <canvas id="trendChart" height="200"></canvas>
  </div>
  <div class="card card-pad">
    <div class="section-eyebrow">Current Workload</div>
    <div class="section-title" style="margin-bottom:14px">Open Tasks by Priority</div>
    <canvas id="priChart" height="200"></canvas>
  </div>
</div>

<div class="card card-pad">
  <div class="section-eyebrow">By Subject</div>
  <div class="section-title" style="margin-bottom:14px">Task Breakdown per Subject</div>
  <?php if (empty($subjLabels)): ?>
    <div class="empty-state"><div class="empty-title">No subjects yet</div><div class="empty-sub">Add subjects and tasks to see this chart.</div></div>
  <?php else: ?>
    <canvas id="subjChart" height="110"></canvas>
  <?php endif; ?>
</div>

<script>
const chartFont = { family: "'Sora',sans-serif" };
Chart.defaults.color = 'rgba(200,220,255,.65)';
Chart.defaults.font.family = "Sora, sans-serif";
Chart.defaults.borderColor = 'rgba(90,120,190,.12)';

new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?php echo json_encode($trendLabels); ?>,
    datasets: [{
      label: 'Completed',
      data: <?php echo json_encode($trendData); ?>,
      borderColor: '#3b9eff',
      backgroundColor: 'rgba(26,108,245,.15)',
      tension: .35, fill: true, pointBackgroundColor: '#3b9eff', pointRadius: 4
    }]
  },
  options: { plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, ticks:{stepSize:1} } } }
});

new Chart(document.getElementById('priChart'), {
  type: 'doughnut',
  data: {
    labels: ['High','Medium','Low'],
    datasets: [{
      data: [<?php echo $priData['high']; ?>, <?php echo $priData['medium']; ?>, <?php echo $priData['low']; ?>],
      backgroundColor: ['#ff5c5c','#f5c842','#3b9eff'],
      borderColor: '#0a1330', borderWidth: 3
    }]
  },
  options: { plugins:{legend:{position:'bottom'}} }
});

<?php if (!empty($subjLabels)): ?>
new Chart(document.getElementById('subjChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($subjLabels); ?>,
    datasets: [
      { label: 'Completed', data: <?php echo json_encode($subjDone); ?>, backgroundColor: '#2ecf7a', borderRadius: 6 },
      { label: 'Open', data: <?php echo json_encode($subjOpen); ?>, backgroundColor: '#1a6cf5', borderRadius: 6 }
    ]
  },
  options: { plugins:{legend:{position:'bottom'}}, scales:{ x:{ stacked:false }, y:{ beginAtZero:true, ticks:{stepSize:1} } } }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/core/layout_foot.php'; ?>
