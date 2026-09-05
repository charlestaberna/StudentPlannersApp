<?php
// Expects (optionally) before include:
//   $pageTitle, $pageSub, $activeNav (one of: dashboard,tasks,schedule,subjects,notes,calendar,analytics,accounts)
$pageTitle  = $pageTitle  ?? 'Dashboard';
$pageSub    = $pageSub    ?? '';
$activeNav  = $activeNav  ?? '';

function navClass($key, $active){ return 'sb-link' . ($key === $active ? ' active' : ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($pageTitle); ?> — Student Planner</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">

<!-- PWA: makes this installable on Android as a home-screen app -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#060d1f">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<link rel="icon" href="assets/icons/icon-192.png">
</head>
<body>
<div class="app">

  <div class="modal-bg" id="sidebarBg" onclick="toggleSidebar(false)" style="z-index:60;display:none"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <div class="sb-brand-ring">
        <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      </div>
      <div>
        <div class="sb-brand-name">Student<br>Planner</div>
      </div>
    </div>

    <nav class="sb-nav">
      <a href="index.php" class="<?php echo navClass('dashboard',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        Dashboard
      </a>
      <a href="tasks.php" class="<?php echo navClass('tasks',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Tasks✅
      </a>
      <a href="schedule.php" class="<?php echo navClass('schedule',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Class Schedule
      </a>
      <a href="subjects.php" class="<?php echo navClass('subjects',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        Subjects
      </a>
      <a href="notes.php" class="<?php echo navClass('notes',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><path d="M4 4h16v12H8l-4 4V4z"/></svg>
        Notes
      </a>
      <a href="calendar.php" class="<?php echo navClass('calendar',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="8" cy="15" r="1"/><circle cx="12" cy="15" r="1"/><circle cx="16" cy="15" r="1"/></svg>
        Calendar
      </a>
      <a href="analytics.php" class="<?php echo navClass('analytics',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>
        Analytics
      </a>
      <a href="messages.php" class="<?php echo navClass('chat',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Group Messages
      </a>
      <a href="profile.php" class="<?php echo navClass('profile',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
        My Profile
      </a>

      <?php if ($isAdmin): ?>
      <div class="sb-section-label">Admin</div>
      <a href="accounts.php" class="<?php echo navClass('accounts',$activeNav); ?>">
        <svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><path d="M17 3.5a4 4 0 0 1 0 7.5M23 21v-2a4 4 0 0 0-3-3.9"/></svg>
        Manage Accounts
      </a>
      <?php endif; ?>
    </nav>

    <div class="sb-foot">
      <a href="profile.php" class="sb-user-link">
        <div class="sb-user">
          <?php echo renderAvatar($user['name'], $user['avatar_path'] ?? null, 'sb-user-av'); ?>
          <div>
            <div class="sb-user-name"><?php echo h($user['name']); ?></div>
            <div class="sb-user-role"><?php echo h($user['role']); ?></div>
          </div>
        </div>
      </a>
      <form method="POST" action="logout.php">
        <button type="submit" class="sb-logout">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign Out
        </button>
      </form>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div class="flex items-center gap-10">
        <div class="hamburger" onclick="toggleSidebar(true)">
          <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </div>
        <div>
          <div class="tb-title"><?php echo h($pageTitle); ?></div>
          <?php if ($pageSub): ?><div class="tb-sub"><?php echo h($pageSub); ?></div><?php endif; ?>
        </div>
      </div>
      <div class="tb-right">
        <span class="pill gold">📅 <?php echo date('l, M j, Y'); ?></span>
      </div>
    </div>

    <div class="content">
