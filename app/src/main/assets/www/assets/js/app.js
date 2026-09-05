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

  // ---------------------------------------------------------------
  // Seed data (mirrors student_planner.sql sample rows)
  // ---------------------------------------------------------------
  function seedDb() {
    const now = new Date().toISOString();
    return {
      nextId: { users: 3, subjects: 4, tasks: 4, schedule: 6, notes: 2, events: 3, groups: 1, messages: 1 },
      users: [
        { id: 1, name: 'System Admin', username: 'admin', password: 'admin123', role: 'admin', is_approved: 1, theme_color: '#1a6cf5', avatar: null, created_at: now, last_seen: now },
        { id: 2, name: 'Juan Dela Cruz', username: 'juan', password: 'juan123', role: 'student', is_approved: 1, theme_color: '#1a6cf5', avatar: null, created_at: now, last_seen: now }
      ],
      subjects: [
        { id: 1, user_id: 2, subject_name: 'Web Systems and Technologies', subject_code: 'IT301', instructor: 'Prof. R. Santos', units: 3.0, color: '#1a6cf5' },
        { id: 2, user_id: 2, subject_name: 'Database Management', subject_code: 'IT302', instructor: 'Prof. M. Reyes', units: 3.0, color: '#f5c842' },
        { id: 3, user_id: 2, subject_name: 'Data Structures & Algorithms', subject_code: 'CS201', instructor: 'Prof. A. Cruz', units: 3.0, color: '#2ecf7a' }
      ],
      tasks: [
        { id: 1, user_id: 2, subject_id: 1, title: 'Finish Login Page UI', description: 'Apply the dark theme to the login form.', due_date: todayStr(), priority: 'high', status: 'pending', completed_at: null },
        { id: 2, user_id: 2, subject_id: 2, title: 'ERD for Project', description: 'Draw entity relationship diagram.', due_date: addDays(2), priority: 'medium', status: 'pending', completed_at: null },
        { id: 3, user_id: 2, subject_id: 3, title: 'Sorting Algorithm Reading', description: 'Read chapter 4 - Merge Sort & Quick Sort.', due_date: addDays(5), priority: 'low', status: 'completed', completed_at: now }
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
  function login(username, password) {
    const db = load();
    const u = db.users.find(u => u.username.toLowerCase() === username.trim().toLowerCase());
    if (!u) return { ok:false, msg:'No account found with that username.' };
    if (u.password !== password) return { ok:false, msg:'Incorrect password.' };
    if (!u.is_approved) return { ok:false, msg:'Your account is awaiting admin approval.' };
    u.last_seen = new Date().toISOString();
    save(db);
    localStorage.setItem(SESSION_KEY, String(u.id));
    return { ok:true };
  }
  function logout() { localStorage.removeItem(SESSION_KEY); location.href = 'login.html'; }
  function register({ name, username, password, role }) {
    const db = load();
    if (db.users.some(u => u.username.toLowerCase() === username.trim().toLowerCase())) {
      return { ok:false, msg:'That username is already taken.' };
    }
    const isFirst = db.users.length === 0;
    const id = nextId(db, 'users');
    const user = {
      id, name: name.trim(), username: username.trim(), password,
      role: isFirst ? 'admin' : (role || 'student'),
      is_approved: isFirst ? 1 : 0,
      theme_color: '#1a6cf5', avatar: null,
      created_at: new Date().toISOString(), last_seen: new Date().toISOString()
    };
    db.users.push(user);
    save(db);
    return { ok:true, autoApproved: isFirst };
  }
  function requireAuth() {
    const u = currentUser();
    if (!u) { location.href = 'login.html'; return null; }
    return u;
  }
  function canManage(user) { return user && (user.role === 'admin' || user.role === 'faculty'); }

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
    { key:'tasks', href:'tasks.html', label:'Tasks✅', icon:'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>' },
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
      <div class="modal-bg" id="sidebarBg" onclick="SP.toggleSidebar(false)" style="z-index:60;display:none"></div>
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
    const user = requireAuth();
    if (!user) return;
    if (opts.adminOnly && user.role !== 'admin') { document.body.innerHTML = '<div style="padding:40px;color:#fff;font-family:sans-serif">Admins only.</div>'; return; }
    renderShell({ activeNav: opts.activeNav, title: opts.title, sub: opts.sub, user });
    const content = document.getElementById('content');
    renderFn(content, { user, db: load(), save, nextId, canManage: canManage(user) });
  }

  return {
    load, save, nextId, resetAll,
    currentUser, login, logout, register, requireAuth, canManage,
    h, initials, avatarHtml, fmtDate, fmtTime, toast,
    renderShell, toggleSidebar, openModal, closeModal, initPage,
    todayStr, addDays
  };
})();
