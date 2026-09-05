/* =====================================================================
   STUDENT PLANNER — client-side app core
   Everything the PHP+MySQL backend used to do (auth, tasks, schedule,
   subjects, notes, calendar, analytics, accounts, messages) is
   reproduced here using localStorage as the "database". No server or
   PHP is required — every page in this folder can be opened directly
   in a browser (or hosted as plain static files) and it will work,
   with all data kept in the browser it runs in.
   ===================================================================== */

const SP = (() => {
  const LS_KEY = 'sp_db_v1';
  const SESSION_KEY = 'sp_session_user_id';
  const ONLINE_WINDOW_MS = 2 * 60 * 1000; // considered "online" if seen in the last 2 minutes

  // ---------------------------------------------------------------
  // Fix: the browser's back/forward cache (bfcache) can restore an
  // old, already-rendered version of a page without re-running the
  // code that loads fresh data from localStorage. That's what made
  // things like the profile picture, chat messages, or admin edits
  // look like they "disappeared" after pressing Back. Forcing a
  // reload when a page is restored from bfcache fixes that.
  // ---------------------------------------------------------------
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) location.reload();
  });

  // Lets a page react when ANOTHER browser tab/window (e.g. a different
  // logged-in account open side-by-side on the same device) saves new
  // data — this is what makes chat feel "live" between two people without
  // any backend: the `storage` event only fires in tabs that did NOT make
  // the change, which is exactly what we want here.
  function onDbChange(cb) {
    window.addEventListener('storage', function (e) {
      if (e.key === LS_KEY) cb();
    });
  }

  // ---------------------------------------------------------------
  // Seed data (mirrors student_planner.sql sample rows)
  // ---------------------------------------------------------------
  function seedDb() {
    const now = new Date().toISOString();
    return {
      nextId: { users: 3, subjects: 4, tasks: 4, schedule: 6, notes: 2, events: 3, groups: 1, messages: 1 },
      users: [
        { id: 1, name: 'System Admin', email: 'admin@gmail.com', password: 'admin123', role: 'admin', is_approved: 1, theme_color: '#1a6cf5', avatar: null, created_at: now, last_seen: now },
        { id: 2, name: 'Juan Dela Cruz', email: 'juan@gmail.com', password: 'juan123', role: 'student', is_approved: 1, theme_color: '#1a6cf5', avatar: null, created_at: now, last_seen: now }
      ],
      subjects: [
        { id: 1, user_id: 2, subject_name: 'Web Systems and Technologies', subject_code: 'IT301', instructor: 'Prof. R. Santos', units: 3.0, color: '#1a6cf5' },
        { id: 2, user_id: 2, subject_name: 'Database Management', subject_code: 'IT302', instructor: 'Prof. M. Reyes', units: 3.0, color: '#f5c842' },
        { id: 3, user_id: 2, subject_name: 'Data Structures & Algorithms', subject_code: 'CS201', instructor: 'Prof. A. Cruz', units: 3.0, color: '#2ecf7a' }
      ],
      tasks: [
        { id: 1, user_id: 2, subject_id: 1, title: 'Finish Login Page UI', description: 'Apply the dark theme to the login form.', due_date: todayStr(), due_time: null, priority: 'high', status: 'pending', completed_at: null },
        { id: 2, user_id: 2, subject_id: 2, title: 'ERD for Project', description: 'Draw entity relationship diagram.', due_date: addDays(2), due_time: null, priority: 'medium', status: 'pending', completed_at: null },
        { id: 3, user_id: 2, subject_id: 3, title: 'Sorting Algorithm Reading', description: 'Read chapter 4 - Merge Sort & Quick Sort.', due_date: addDays(5), due_time: null, priority: 'low', status: 'completed', completed_at: now }
      ],
      schedule: [
        { id: 1, subject_id: 1, day_of_week: 'Mon', start_time: '08:00', end_time: '09:30', room: 'Rm 204' },
        { id: 2, subject_id: 2, day_of_week: 'Tue', start_time: '10:00', end_time: '11:30', room: 'Rm 210' },
        { id: 3, subject_id: 3, day_of_week: 'Wed', start_time: '13:00', end_time: '14:30', room: 'Lab 3' },
        { id: 4, subject_id: 1, day_of_week: 'Thu', start_time: '08:00', end_time: '09:30', room: 'Rm 204' },
        { id: 5, subject_id: 2, day_of_week: 'Fri', start_time: '10:00', end_time: '11:30', room: 'Rm 210' }
      ],
      notes: [
        { id: 1, user_id: 2, subject_id: 1, title: 'Reminder', content: 'Bring laptop charger every Monday & Thursday.', is_pinned: 1, updated_at: now }
      ],
      events: [
        { id: 1, user_id: 2, title: 'Midterm Exams Start', event_date: addDays(10), event_type: 'exam', description: 'Coverage: Chapters 1-5' },
        { id: 2, user_id: 2, title: 'Project Deadline - IT301', event_date: addDays(3), event_type: 'deadline', description: 'Submit final system with documentation.' }
      ],
      groups: [],
      messages: []
    };
  }

  function todayStr(){ return new Date().toISOString().slice(0,10); }
  function addDays(n){ const d = new Date(); d.setDate(d.getDate()+n); return d.toISOString().slice(0,10); }

  function load() {
    let raw = localStorage.getItem(LS_KEY);
    if (!raw) {
      const seeded = seedDb();
      localStorage.setItem(LS_KEY, JSON.stringify(seeded));
      return seeded;
    }
    try { return JSON.parse(raw); } catch(e){ const s = seedDb(); localStorage.setItem(LS_KEY, JSON.stringify(s)); return s; }
  }
  function save(db) { localStorage.setItem(LS_KEY, JSON.stringify(db)); }
  function nextId(db, table) { const id = db.nextId[table]++; return id; }

  function resetAll() { localStorage.removeItem(LS_KEY); localStorage.removeItem(SESSION_KEY); location.href = 'login.html'; }

  // ---------------------------------------------------------------
  // Auth
  // ---------------------------------------------------------------
  function currentUser() {
    const db = load();
    const id = parseInt(localStorage.getItem(SESSION_KEY) || '0', 10);
    return db.users.find(u => u.id === id) || null;
  }
  function isGmail(email) { return /^[^\s@]+@gmail\.com$/i.test((email || '').trim()); }

  function login(email, password) {
    const db = load();
    const em = email.trim().toLowerCase();
    const u = db.users.find(u => (u.email || '').toLowerCase() === em);
    if (!u) return { ok:false, msg:'No account found with that Gmail.' };
    if (u.password !== password) return { ok:false, msg:'Incorrect password.' };
    if (!u.is_approved) return { ok:false, msg:'Your account is awaiting admin approval.' };
    u.last_seen = new Date().toISOString();
    save(db);
    localStorage.setItem(SESSION_KEY, String(u.id));
    return { ok:true };
  }
  function logout() { localStorage.removeItem(SESSION_KEY); location.href = 'login.html'; }
  function register({ name, email, password, role }) {
    const db = load();
    const em = email.trim().toLowerCase();
    if (!isGmail(em)) {
      return { ok:false, msg:'Please use a valid Gmail address (must end with @gmail.com) — this is where your due-date reminders will be sent.' };
    }
    if (db.users.some(u => (u.email || '').toLowerCase() === em)) {
      return { ok:false, msg:'That Gmail is already registered.' };
    }
    const isFirst = db.users.length === 0;
    const id = nextId(db, 'users');
    const finalRole = isFirst ? 'admin' : (role || 'student');
    // Only registering as Admin needs approval from an existing true admin now.
    // Student and Faculty accounts are approved immediately.
    const needsApproval = !isFirst && finalRole === 'admin';
    const user = {
      id, name: name.trim(), email: em, password,
      role: finalRole,
      is_approved: needsApproval ? 0 : 1,
      theme_color: '#1a6cf5', avatar: null,
      created_at: new Date().toISOString(), last_seen: new Date().toISOString()
    };
    db.users.push(user);
    save(db);
    return { ok:true, autoApproved: !needsApproval, pendingAdmin: needsApproval };
  }
  function requireAuth() {
    const u = currentUser();
    if (!u) { location.href = 'login.html'; return null; }
    return u;
  }
  function canManage(user) { return user && (user.role === 'admin' || user.role === 'faculty'); }
  function isTrueAdmin(user) { return !!(user && user.role === 'admin' && user.is_approved); }

  // Online/offline presence — intentionally NOT exposed anywhere except
  // accounts.html (which is admin-only), per the "only admin can see
  // who's online/offline" requirement.
  function isOnline(u) {
    if (!u || !u.last_seen) return false;
    return (Date.now() - new Date(u.last_seen).getTime()) < ONLINE_WINDOW_MS;
  }
  function touchLastSeen(userId) {
    const db = load();
    const u = db.users.find(u => u.id === userId);
    if (u) { u.last_seen = new Date().toISOString(); save(db); }
  }

  // ---------------------------------------------------------------
  // Backup / Restore — since this app is fully offline (localStorage
  // only, no server), THIS is what keeps data from getting lost when
  // the app is uninstalled, reset, or handed over to another
  // phone/person: export a JSON backup before that happens, then
  // import it back afterward. Nothing is deleted unless the person
  // using the app deliberately deletes it from inside a page.
  // ---------------------------------------------------------------
  function exportData() {
    const db = load();
    return JSON.stringify(db, null, 2);
  }
  function importData(jsonText) {
    let parsed;
    try { parsed = JSON.parse(jsonText); }
    catch (e) { return { ok:false, msg:'That file/text is not valid backup data.' }; }
    if (!parsed || !Array.isArray(parsed.users) || !parsed.nextId) {
      return { ok:false, msg:'That file does not look like a Student Planner backup.' };
    }
    save(parsed);
    return { ok:true };
  }

  // ---------------------------------------------------------------
  // Gmail due-date notifications — pure client-side (no server), sent
  // through EmailJS (emailjs.com). This lets a plain HTML/JS app send
  // a real email to Gmail without running your own mail server.
  //
  // TO TURN THIS ON:
  //   1. Make a free account at https://www.emailjs.com and connect a
  //      Gmail account under "Email Services" there.
  //   2. Create an "Email Template" with these variables somewhere in
  //      it: {{to_email}} {{to_name}} {{subject}} {{message}}
  //   3. Copy your Public Key, Service ID and Template ID from the
  //      EmailJS dashboard into EMAILJS_CONFIG below and set
  //      enabled: true.
  //   4. The app also needs internet access — the Android project's
  //      AndroidManifest.xml now includes the INTERNET permission for
  //      this (it wasn't needed before, since the app used to be 100%
  //      offline).
  // Until EMAILJS_CONFIG.enabled is set to true, this whole feature is
  // a no-op — the app behaves exactly as before.
  // ---------------------------------------------------------------
  const EMAILJS_CONFIG = {
    enabled: false,
    publicKey: 'YOUR_EMAILJS_PUBLIC_KEY',
    serviceId: 'YOUR_EMAILJS_SERVICE_ID',
    templateId: 'YOUR_EMAILJS_TEMPLATE_ID'
  };

  let _emailjsReady = null;
  function loadEmailJs() {
    if (!EMAILJS_CONFIG.enabled) return Promise.reject('disabled');
    if (_emailjsReady) return _emailjsReady;
    _emailjsReady = new Promise((resolve, reject) => {
      if (window.emailjs) { window.emailjs.init(EMAILJS_CONFIG.publicKey); resolve(window.emailjs); return; }
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js';
      s.onload = () => { window.emailjs.init(EMAILJS_CONFIG.publicKey); resolve(window.emailjs); };
      s.onerror = () => reject('load-failed');
      document.head.appendChild(s);
    });
    return _emailjsReady;
  }
  function sendDueEmail({ to_email, to_name, subject, message }) {
    if (!EMAILJS_CONFIG.enabled || !to_email) return;
    loadEmailJs().then(ejs => {
      ejs.send(EMAILJS_CONFIG.serviceId, EMAILJS_CONFIG.templateId, { to_email, to_name, subject, message })
        .catch(err => console.warn('Due-date email failed to send:', err));
    }).catch(() => {});
  }

  const NOTIFIED_KEY = 'sp_notified_due_v1';
  function getNotified() { try { return JSON.parse(localStorage.getItem(NOTIFIED_KEY) || '[]'); } catch (e) { return []; } }
  function markNotified(key) {
    const list = getNotified();
    if (!list.includes(key)) { list.push(key); localStorage.setItem(NOTIFIED_KEY, JSON.stringify(list)); }
  }

  // Checks the currently logged-in user's tasks/events for anything
  // due today (or overdue) and, once per item per day, emails a
  // reminder to that user's Gmail. Whatever an admin/staff account
  // sets as the due date/time on a task or event is what this reads —
  // no separate setup needed per task.
  function checkDueReminders(db, user) {
    if (!EMAILJS_CONFIG.enabled || !user || !user.email) return;
    const today = todayStr();
    const notified = getNotified();

    (db.tasks || []).forEach(t => {
      if (t.status === 'completed') return;
      if (!t.due_date || t.due_date > today) return;
      const key = `task:${t.id}:${today}`;
      if (notified.includes(key)) return;
      sendDueEmail({
        to_email: user.email,
        to_name: user.name,
        subject: `Reminder: "${t.title}" is due`,
        message: `Hi ${user.name}, your task "${t.title}"${t.due_time ? ` (due ${fmtTime(t.due_time)})` : ''} is due on ${fmtDate(t.due_date)}. Open Student Planner to view it.`
      });
      markNotified(key);
    });

    (db.events || []).forEach(ev => {
      if (!ev.event_date || ev.event_date > today) return;
      const key = `event:${ev.id}:${today}`;
      if (notified.includes(key)) return;
      sendDueEmail({
        to_email: user.email,
        to_name: user.name,
        subject: `Reminder: "${ev.title}" (${ev.event_type})`,
        message: `Hi ${user.name}, "${ev.title}" is scheduled for ${fmtDate(ev.event_date)}. ${ev.description || ''}`
      });
      markNotified(key);
    });
  }

  // ---------------------------------------------------------------
  // Generic helpers
  // ---------------------------------------------------------------
  function h(v) { return (v ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function initials(name) {
    const parts = (name || '').trim().split(/\s+/).slice(0,2);
    const ini = parts.map(p => p[0] ? p[0].toUpperCase() : '').join('');
    return ini || 'S';
  }
  function avatarHtml(user, cls) {
    if (user && user.avatar) return `<img src="${user.avatar}" class="${cls} avatar-img" alt="">`;
    return `<div class="${cls}">${h(initials(user ? user.name : 'S'))}</div>`;
  }
  function fmtDate(dstr, opts) {
    if (!dstr) return '—';
    const d = new Date(dstr + (dstr.length <= 10 ? 'T00:00:00' : ''));
    if (isNaN(d)) return dstr;
    return d.toLocaleDateString('en-US', opts || { month:'short', day:'numeric', year:'numeric' });
  }
  function fmtTime(t) {
    if (!t) return '';
    const [h1,m1] = t.split(':').map(Number);
    const ap = h1 >= 12 ? 'PM' : 'AM';
    const hh = ((h1 + 11) % 12) + 1;
    return `${hh}:${String(m1).padStart(2,'0')} ${ap}`;
  }
  function toast(msg, type='ok') {
    let box = document.getElementById('spToastBox');
    if (!box) {
      box = document.createElement('div');
      box.id = 'spToastBox';
      box.style.cssText = 'position:fixed;top:18px;right:18px;z-index:400;display:flex;flex-direction:column;gap:8px;max-width:320px';
      document.body.appendChild(box);
    }
    const el = document.createElement('div');
    el.className = 'alert ' + (type === 'err' ? 'alert-err' : 'alert-ok');
    el.style.margin = '0';
    el.style.boxShadow = '0 12px 30px rgba(0,0,0,.4)';
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(() => el.remove(), 3200);
  }

  // ---------------------------------------------------------------
  // Layout shell (sidebar + topbar) — mirrors core/layout_head.php & layout_foot.php
  // ---------------------------------------------------------------
  const NAV = [
    { key:'dashboard', href:'index.html', label:'Dashboard', icon:'<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>' },
    { key:'tasks', href:'tasks.html', label:'Tasks', icon:'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>' },
    { key:'schedule', href:'schedule.html', label:'Class Schedule', icon:'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>' },
    { key:'subjects', href:'subjects.html', label:'Subjects', icon:'<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' },
    { key:'notes', href:'notes.html', label:'Notes', icon:'<path d="M4 4h16v12H8l-4 4V4z"/>' },
    { key:'calendar', href:'calendar.html', label:'Calendar', icon:'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="8" cy="15" r="1"/><circle cx="12" cy="15" r="1"/><circle cx="16" cy="15" r="1"/>' },
    { key:'analytics', href:'analytics.html', label:'Analytics', icon:'<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>' },
    { key:'chat', href:'messages.html', label:'Group Messages', icon:'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>' },
    { key:'profile', href:'profile.html', label:'My Profile', icon:'<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>' }
  ];

  function renderShell({ activeNav, title, sub, user }) {
    const navHtml = NAV.map(n => `
      <a href="${n.href}" class="sb-link${n.key===activeNav?' active':''}">
        <svg viewBox="0 0 24 24">${n.icon}</svg>
        ${n.label}
      </a>`).join('');
    const adminHtml = user.role === 'admin' ? `
      <div class="sb-section-label">Admin</div>
      <a href="accounts.html" class="sb-link${activeNav==='accounts'?' active':''}">
        <svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><path d="M17 3.5a4 4 0 0 1 0 7.5M23 21v-2a4 4 0 0 0-3-3.9"/></svg>
        Manage Accounts
      </a>` : '';
    const dateStr = new Date().toLocaleDateString('en-US', { weekday:'long', month:'short', day:'numeric', year:'numeric' });

    document.body.innerHTML = `
    <div class="app">
      <div class="modal-bg" id="sidebarBg" onclick="SP.toggleSidebar(false)" style="z-index:45;display:none"></div>
      <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
          <div class="sb-brand-ring">
            <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          </div>
          <div><div class="sb-brand-name">Student<br>Planner</div></div>
        </div>
        <nav class="sb-nav">${navHtml}${adminHtml}</nav>
        <div class="sb-foot">
          <a href="profile.html" class="sb-user-link">
            <div class="sb-user">
              ${avatarHtml(user, 'sb-user-av')}
              <div>
                <div class="sb-user-name">${h(user.name)}</div>
                <div class="sb-user-role">${h(user.role)}</div>
              </div>
            </div>
          </a>
          <button type="button" class="sb-logout" onclick="SP.logout()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
          </button>
        </div>
      </aside>
      <div class="main">
        <div class="topbar">
          <div class="flex items-center gap-10">
            <div class="hamburger" onclick="SP.toggleSidebar(true)">
              <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </div>
            <div>
              <div class="tb-title">${h(title)}</div>
              ${sub ? `<div class="tb-sub">${h(sub)}</div>` : ''}
            </div>
          </div>
          <div class="tb-right"><span class="pill gold">📅 ${dateStr}</span></div>
        </div>
        <div class="content" id="content"></div>
      </div>
    </div>`;
  }
  function toggleSidebar(open) {
    document.getElementById('sidebar').classList.toggle('open', open);
    document.getElementById('sidebarBg').style.display = open ? 'block' : 'none';
  }
  function openModal(id){ document.getElementById(id).classList.add('show'); }
  function closeModal(id){ document.getElementById(id).classList.remove('show'); }
  document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-bg.show').forEach(m => m.classList.remove('show')); });

  function initPage(opts, renderFn) {
    // IMPORTANT: load the database ONCE and pull `user` out of that same
    // object. Previously `user` (from requireAuth -> currentUser -> its
    // own load()) and `db` (from a second, separate load()) were two
    // different copies of the data. Editing `user` (e.g. saving a new
    // profile picture) and then calling save(db) saved the OTHER copy,
    // silently discarding the change — that's why the avatar/name/password
    // changes on profile.html didn't survive a refresh or the Back button.
    const db = load();
    const sid = parseInt(localStorage.getItem(SESSION_KEY) || '0', 10);
    const user = db.users.find(u => u.id === sid);
    if (!user) { location.href = 'login.html'; return; }
    if (opts.adminOnly && user.role !== 'admin') { document.body.innerHTML = '<div style="padding:40px;color:#fff;font-family:sans-serif">Admins only.</div>'; return; }

    // heartbeat: mark this user active now, and again periodically while
    // the tab stays open, so accounts.html can show accurate online/offline
    // status (admin-only — see isOnline()).
    user.last_seen = new Date().toISOString();
    save(db);
    if (window.__spHeartbeat) clearInterval(window.__spHeartbeat);
    window.__spHeartbeat = setInterval(() => touchLastSeen(user.id), 30000);

    // Gmail due-date reminders: check once now, then every 5 minutes
    // while this page stays open (no-op unless EMAILJS_CONFIG.enabled
    // is turned on — see notes above).
    checkDueReminders(db, user);
    if (window.__spReminderTimer) clearInterval(window.__spReminderTimer);
    window.__spReminderTimer = setInterval(() => checkDueReminders(load(), currentUser()), 5 * 60 * 1000);

    renderShell({ activeNav: opts.activeNav, title: opts.title, sub: opts.sub, user });
    const content = document.getElementById('content');
    renderFn(content, { user, db, save, nextId, canManage: canManage(user) });
  }

  return {
    load, save, nextId, resetAll, exportData, importData,
    currentUser, login, logout, register, requireAuth, canManage, isTrueAdmin, isGmail,
    isOnline, touchLastSeen, onDbChange,
    h, initials, avatarHtml, fmtDate, fmtTime, toast,
    renderShell, toggleSidebar, openModal, closeModal, initPage,
    todayStr, addDays,
    EMAILJS_CONFIG, sendDueEmail, checkDueReminders
  };
})();
