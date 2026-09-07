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
      // No default/seeded admin or faculty account — this app is
      // student-only, so every account (including the first one ever
      // registered on this device) is just a plain student account.
      nextId: { users: 2, subjects: 4, tasks: 4, schedule: 6, notes: 2, events: 3, groups: 1, messages: 1, notifications: 1 },
      users: [
        { id: 1, name: 'Juan Dela Cruz', email: 'juan@gmail.com', password: 'juan123', role: 'student', is_approved: 1, theme_color: '#1a6cf5', avatar: null, created_at: now, last_seen: now }
      ],
      subjects: [
        { id: 1, user_id: 1, subject_name: 'Web Systems and Technologies', subject_code: 'IT301', instructor: 'Prof. R. Santos', units: 3.0, color: '#1a6cf5' },
        { id: 2, user_id: 1, subject_name: 'Database Management', subject_code: 'IT302', instructor: 'Prof. M. Reyes', units: 3.0, color: '#f5c842' },
        { id: 3, user_id: 1, subject_name: 'Data Structures & Algorithms', subject_code: 'CS201', instructor: 'Prof. A. Cruz', units: 3.0, color: '#2ecf7a' }
      ],
      tasks: [
        { id: 1, user_id: 1, subject_id: 1, title: 'Finish Login Page UI', description: 'Apply the dark theme to the login form.', due_date: todayStr(), due_time: null, priority: 'high', status: 'pending', completed_at: null },
        { id: 2, user_id: 1, subject_id: 2, title: 'ERD for Project', description: 'Draw entity relationship diagram.', due_date: addDays(2), due_time: null, priority: 'medium', status: 'pending', completed_at: null },
        { id: 3, user_id: 1, subject_id: 3, title: 'Sorting Algorithm Reading', description: 'Read chapter 4 - Merge Sort & Quick Sort.', due_date: addDays(5), due_time: null, priority: 'low', status: 'completed', completed_at: now }
      ],
      schedule: [
        { id: 1, subject_id: 1, day_of_week: 'Mon', start_time: '08:00', end_time: '09:30', room: 'Rm 204' },
        { id: 2, subject_id: 2, day_of_week: 'Tue', start_time: '10:00', end_time: '11:30', room: 'Rm 210' },
        { id: 3, subject_id: 3, day_of_week: 'Wed', start_time: '13:00', end_time: '14:30', room: 'Lab 3' },
        { id: 4, subject_id: 1, day_of_week: 'Thu', start_time: '08:00', end_time: '09:30', room: 'Rm 204' },
        { id: 5, subject_id: 2, day_of_week: 'Fri', start_time: '10:00', end_time: '11:30', room: 'Rm 210' }
      ],
      notes: [
        { id: 1, user_id: 1, subject_id: 1, title: 'Reminder', content: 'Bring laptop charger every Monday & Thursday.', is_pinned: 1, updated_at: now }
      ],
      events: [
        { id: 1, user_id: 1, title: 'Midterm Exams Start', event_date: addDays(10), event_type: 'exam', description: 'Coverage: Chapters 1-5' },
        { id: 2, user_id: 1, title: 'Project Deadline - IT301', event_date: addDays(3), event_type: 'deadline', description: 'Submit final system with documentation.' }
      ],
      groups: [],
      messages: [],
      notifications: []
    };
  }

  function todayStr(){ return new Date().toISOString().slice(0,10); }
  function addDays(n){ const d = new Date(); d.setDate(d.getDate()+n); return d.toISOString().slice(0,10); }

  function load() {
    let raw = localStorage.getItem(LS_KEY);
    let db;
    if (!raw) {
      db = seedDb();
      localStorage.setItem(LS_KEY, JSON.stringify(db));
      return db;
    }
    try { db = JSON.parse(raw); } catch(e){ db = seedDb(); localStorage.setItem(LS_KEY, JSON.stringify(db)); return db; }
    // Migration: databases saved before the Notifications feature existed
    // won't have this table yet — patch it in place instead of resetting
    // everything, so nobody's existing account/tasks/messages are lost.
    if (!Array.isArray(db.notifications)) db.notifications = [];
    if (!db.nextId) db.nextId = {};
    if (!db.nextId.notifications) db.nextId.notifications = 1;
    return db;
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
    u.last_seen = new Date().toISOString();
    save(db);
    localStorage.setItem(SESSION_KEY, String(u.id));
    return { ok:true };
  }
  function logout() { localStorage.removeItem(SESSION_KEY); location.href = 'login.html'; }
  // Student-only registration — there is no role picker and no approval
  // step. Every account created here is a plain student account that can
  // sign in immediately.
  function register({ name, email, password }) {
    const db = load();
    const em = email.trim().toLowerCase();
    if (!isGmail(em)) {
      return { ok:false, msg:'Please use a valid Gmail address (must end with @gmail.com) — this is where your due-date reminders will be sent.' };
    }
    if (db.users.some(u => (u.email || '').toLowerCase() === em)) {
      return { ok:false, msg:'That Gmail is already registered.' };
    }
    const id = nextId(db, 'users');
    const user = {
      id, name: name.trim(), email: em, password,
      role: 'student',
      is_approved: 1,
      theme_color: '#1a6cf5', avatar: null,
      created_at: new Date().toISOString(), last_seen: new Date().toISOString()
    };
    db.users.push(user);
    save(db);
    return { ok:true };
  }
  function requireAuth() {
    const u = currentUser();
    if (!u) { location.href = 'login.html'; return null; }
    return u;
  }
  // Every account is a student account that manages its own planner data,
  // so there's no separate admin/faculty tier gating what can be added,
  // edited, or deleted.
  function canManage(user) { return !!user; }

  // Online/offline presence, shown on the Group Messages page so people
  // can see who's currently active in a chat.
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
  // Device internet connection — Wi-Fi or mobile data. This is about
  // THIS device's own connection (separate from isOnline(u) above,
  // which is about when an ACCOUNT was last active). It's what powers
  // the Wi-Fi/Mobile Data/Offline pill in the topbar, and is what gates
  // Group Messages: sending/receiving only works while connected —
  // turn off Wi-Fi and mobile data and messages.html stops letting you
  // send or pull in new messages until a connection comes back.
  // ---------------------------------------------------------------
  function netInfo() {
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    // conn.type (when supported, e.g. Chrome/WebView on Android) is
    // one of: 'wifi' | 'cellular' | 'none' | 'bluetooth' | 'ethernet' |
    // 'wimax' | 'other' | 'unknown'. Not every browser exposes it, so
    // this degrades gracefully to just navigator.onLine elsewhere.
    return { browserOnline: navigator.onLine, type: (conn && conn.type) || 'unknown' };
  }
  function isConnected() {
    const info = netInfo();
    if (!info.browserOnline) return false;
    if (info.type === 'none') return false;
    return true;
  }
  function connectionLabel() {
    if (!isConnected()) return { text: 'Offline', cls: 'offline', icon: '⚠️' };
    const t = netInfo().type;
    if (t === 'wifi') return { text: 'Wi-Fi', cls: 'online', icon: '📶' };
    if (t === 'cellular') return { text: 'Mobile Data', cls: 'online', icon: '📱' };
    return { text: 'Online', cls: 'online', icon: '🌐' };
  }
  function onConnectivityChange(cb) {
    window.addEventListener('online', cb);
    window.addEventListener('offline', cb);
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && conn.addEventListener) conn.addEventListener('change', cb);
  }

  // ---------------------------------------------------------------
  // In-app Notifications — whenever an admin/faculty account posts or
  // adds something new in Tasks, Class Schedule, Subjects, Notes, or
  // the Calendar, everyone else gets a notification about it in the
  // bell icon that now appears in the topbar on every page. Delivery
  // to the bell/toast only happens while this device is connected
  // (see isConnected() above) — the item itself is still saved either
  // way, it just shows up as "new" the moment a connection is back.
  // ---------------------------------------------------------------
  function pushNotification(db, actor, { category, title, body }) {
    if (!Array.isArray(db.notifications)) db.notifications = [];
    if (!db.nextId.notifications) db.nextId.notifications = 1;
    const id = nextId(db, 'notifications');
    db.notifications.unshift({
      id, category, title, body,
      created_by: actor ? actor.id : null,
      created_by_name: actor ? actor.name : 'System',
      created_at: new Date().toISOString(),
      read_by: []
    });
    if (db.notifications.length > 200) db.notifications.length = 200; // keep it bounded
    return id;
  }
  function notificationsFor(db, user) {
    return (db.notifications || []).filter(n => n.created_by !== user.id);
  }
  function unreadNotifications(db, user) {
    return notificationsFor(db, user).filter(n => !(n.read_by || []).includes(user.id));
  }
  function markNotificationRead(db, user, id) {
    const n = (db.notifications || []).find(n => n.id === id);
    if (n && !(n.read_by || []).includes(user.id)) { n.read_by = n.read_by || []; n.read_by.push(user.id); save(db); }
  }
  function markAllNotificationsRead(db, user) {
    let changed = false;
    notificationsFor(db, user).forEach(n => {
      n.read_by = n.read_by || [];
      if (!n.read_by.includes(user.id)) { n.read_by.push(user.id); changed = true; }
    });
    if (changed) save(db);
  }
  const NOTIF_CATEGORY_HREF = {
    tasks: 'tasks.html', schedule: 'schedule.html', subjects: 'subjects.html',
    notes: 'notes.html', calendar: 'calendar.html', analytics: 'analytics.html', chat: 'messages.html'
  };
  const NOTIF_CATEGORY_ICON = {
    tasks: '✅', schedule: '🗓️', subjects: '📘', notes: '📝', calendar: '📅', analytics: '📊', chat: '💬'
  };
  function timeAgo(iso) {
    const s = Math.max(1, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
    if (s < 60) return s + 's ago';
    const m = Math.floor(s / 60); if (m < 60) return m + 'm ago';
    const hr = Math.floor(m / 60); if (hr < 24) return hr + 'h ago';
    return Math.floor(hr / 24) + 'd ago';
  }

  // Wires up the bell icon that renderShell() puts in every topbar:
  // unread badge count, the dropdown list, mark-as-read, and a toast
  // the moment a new item comes in (while connected).
  function initNotifUI(user) {
    const bell = document.getElementById('notifBell');
    const panel = document.getElementById('notifPanel');
    const countEl = document.getElementById('notifCount');
    if (!bell) return;
    const seenKey = 'sp_notif_last_toasted_' + user.id;

    function renderPanel(mine, unread) {
      const items = mine.slice(0, 20);
      panel.innerHTML = items.length === 0
        ? `<div class="notif-empty">No notifications yet.</div>`
        : `<div class="notif-panel-head"><span>Notifications</span>${unread.length ? `<button type="button" id="notifMarkAll">Mark all read</button>` : ''}</div>` +
          items.map(n => `
            <a class="notif-item ${(n.read_by || []).includes(user.id) ? '' : 'unread'}" data-notif="${n.id}" href="${NOTIF_CATEGORY_HREF[n.category] || '#'}">
              <span class="notif-icon">${NOTIF_CATEGORY_ICON[n.category] || '🔔'}</span>
              <span class="notif-body">
                <span class="notif-title">${h(n.title)}</span>
                ${n.body ? `<span class="notif-sub">${h(n.body)}</span>` : ''}
                <span class="notif-time">${timeAgo(n.created_at)} · ${h(n.created_by_name || '')}</span>
              </span>
            </a>`).join('');
      const markAllBtn = document.getElementById('notifMarkAll');
      if (markAllBtn) markAllBtn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); markAllNotificationsRead(load(), user); tick(); });
      panel.querySelectorAll('[data-notif]').forEach(a => a.addEventListener('click', () => markNotificationRead(load(), user, parseInt(a.dataset.notif, 10))));
    }

    function tick() {
      const db = load();
      const mine = notificationsFor(db, user);
      const unread = mine.filter(n => !(n.read_by || []).includes(user.id));
      countEl.style.display = unread.length ? 'inline-flex' : 'none';
      countEl.textContent = unread.length > 9 ? '9+' : String(unread.length);

      if (isConnected() && mine.length) {
        const lastToasted = parseInt(localStorage.getItem(seenKey) || '0', 10);
        const freshest = Math.max(...mine.map(m => m.id));
        mine.filter(n => n.id > lastToasted).sort((a, b) => a.id - b.id)
          .forEach(n => toast(`${NOTIF_CATEGORY_ICON[n.category] || '🔔'} ${n.title}`));
        if (freshest > lastToasted) localStorage.setItem(seenKey, String(freshest));
      }
      if (panel.classList.contains('show')) renderPanel(mine, unread);
    }

    bell.addEventListener('click', (e) => {
      e.stopPropagation();
      panel.classList.toggle('show');
      if (panel.classList.contains('show')) {
        const db = load(); const mine = notificationsFor(db, user);
        renderPanel(mine, mine.filter(n => !(n.read_by || []).includes(user.id)));
      }
    });
    document.addEventListener('click', (e) => { if (panel.classList.contains('show') && !panel.contains(e.target) && !bell.contains(e.target)) panel.classList.remove('show'); });

    tick();
    onDbChange(tick);
    const timer = setInterval(tick, 8000);
    window.addEventListener('beforeunload', () => clearInterval(timer));
  }

  // Wires up the Wi-Fi/Mobile Data/Offline pill that renderShell() puts
  // in every topbar next to the date.
  function initConnPill() {
    const pill = document.getElementById('connPill');
    if (!pill) return;
    function refresh() {
      const info = connectionLabel();
      pill.className = 'pill conn-pill ' + (info.cls === 'online' ? 'conn-online' : 'conn-offline');
      pill.textContent = `${info.icon} ${info.text}`;
    }
    refresh();
    onConnectivityChange(refresh);
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
        <nav class="sb-nav">${navHtml}</nav>
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
          <div class="tb-right">
            <span class="pill conn-pill" id="connPill">🌐 …</span>
            <div class="notif-wrap" id="notifWrap">
              <button type="button" class="notif-bell" id="notifBell" title="Notifications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-count" id="notifCount" style="display:none">0</span>
              </button>
              <div class="notif-panel" id="notifPanel"></div>
            </div>
            <span class="pill gold">📅 ${dateStr}</span>
          </div>
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

    // heartbeat: mark this user active now, and again periodically while
    // the tab stays open, so messages.html can show accurate online/offline
    // status — see isOnline().
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
    initConnPill();
    initNotifUI(user);
    const content = document.getElementById('content');
    renderFn(content, { user, db, save, nextId, canManage: canManage(user) });
  }

  return {
    load, save, nextId, resetAll, exportData, importData,
    currentUser, login, logout, register, requireAuth, canManage, isGmail,
    isOnline, touchLastSeen, onDbChange,
    h, initials, avatarHtml, fmtDate, fmtTime, toast,
    renderShell, toggleSidebar, openModal, closeModal, initPage,
    todayStr, addDays,
    EMAILJS_CONFIG, sendDueEmail, checkDueReminders,
    netInfo, isConnected, connectionLabel, onConnectivityChange,
    pushNotification, notificationsFor, unreadNotifications,
    markNotificationRead, markAllNotificationsRead
  };
})();
