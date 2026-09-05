# Student Planner — HTML/JS Version

This is a converted version of the original PHP + MySQL "Student Planner" app.
It runs entirely as static HTML, CSS and JavaScript — **no PHP, no MySQL, no
server required.** Just open `login.html` in a browser (or host this folder
on any static web host).

## What changed vs. the original

The original app used PHP to run SQL queries against a MySQL database on a
server. HTML by itself can't run a server-side language or a database, so to
make a version that actually works as plain HTML, all of that logic was
rewritten in JavaScript, and the database was replaced with the browser's
built-in `localStorage`:

- Every `.php` page became a `.html` page with the same look and the same
  features, but the logic that used to live in PHP now runs in a `<script>`
  block using JavaScript.
- `db.php` / MySQL → `assets/js/app.js`, which keeps all data (accounts,
  tasks, subjects, schedule, notes, events, groups, messages) as JSON in
  `localStorage`, scoped to whichever browser/device opens the page.
- Session login (`$_SESSION`) → a logged-in user id stored in `localStorage`.
- File uploads (avatars, chat images) → images are read in the browser and
  stored as base64 data directly in `localStorage`, instead of being saved
  as files on a server.
- Password hashing is not meaningful for a file that runs entirely in the
  visitor's own browser with no server to protect, so passwords are kept as
  plain values in local storage — fine for a demo/personal planner, but treat
  this the way you'd treat any local-only tool, not as production auth.

## Data & multi-device note

Because everything is saved with `localStorage`, data lives **only in the
browser/device where you use it** — there's no shared server. Two people
opening these files on two different computers will each get their own,
separate data. If you need real multi-user syncing, you'd want to keep the
original PHP + MySQL version (included alongside this one) and host it on a
PHP + MySQL server.

## Demo logins (seeded automatically the first time you open it)

- **admin@gmail.com / admin123** (role: admin)
- **juan@gmail.com / juan123** (role: student)

Sign-in now uses a Gmail address instead of a username (see `assets/js/app.js`
→ `login`/`register`/`isGmail`). You can also register a new account from
`register.html` — it must be a `@gmail.com` address. Admins can approve
pending accounts and change roles from **Manage Accounts**.

## Gmail due-date reminders (optional)

`assets/js/app.js` can email a reminder to a user's Gmail when one of their
tasks or calendar events is due, using [EmailJS](https://www.emailjs.com) —
no server needed, but it does need internet access. It's off by default.
Look for `EMAILJS_CONFIG` near the top of the "Gmail due-date notifications"
section in `app.js` and follow the steps in the comment above it (make a free
EmailJS account, connect Gmail there, create a template, paste in your Public
Key / Service ID / Template ID, set `enabled: true`).

## Backup & Restore (My Profile page)

Since data only lives in this device's `localStorage`, use the **Download
Backup** / **Restore From Backup** buttons on `profile.html` before
uninstalling the app, switching phones, or handing the app to someone else —
otherwise that data has nowhere else to live.

## Files

- `login.html`, `register.html` — sign in / sign up
- `index.html` — dashboard
- `tasks.html`, `subjects.html`, `schedule.html`, `notes.html`,
  `calendar.html`, `analytics.html`, `messages.html`, `profile.html` — main app
- `accounts.html` — admin-only account management
- `assets/css/style.css` — the original shared theme, unchanged
- `assets/js/app.js` — the client-side "backend" (data + auth + layout)
- `original-php-version/` — the complete, untouched original PHP + MySQL
  project (all files, uploads, and `student_planner.sql`), kept here so
  nothing from the original was removed.

## Fixes in this update

- **Profile picture / name / password disappearing after refresh or Back.**
  The page was loading the logged-in user and the database as two separate
  copies of the same data. Saving a new avatar updated one copy while the
  other (unsaved) copy got written to localStorage, so the change never
  actually stuck. Both now come from a single shared copy, so changes save
  correctly every time.
- **Back button showing old/stale data** (profile, chat, tasks, notes,
  calendar, subjects, schedule, analytics). Browsers sometimes restore a
  page from "bfcache" (a snapshot) instead of re-running its script when
  you hit Back, so it can show data from before your last change. Every
  page now force-reloads itself when restored this way, so it always shows
  the latest saved data.
- **Chat messages**: now read from the same fixed data source above, so
  who sent what, and to which group, stays correct and doesn't reset.
- **Admin/faculty edits to tasks, subjects, schedule, notes, calendar
  events**: these were already saved correctly to shared data and are
  visible to every account **on the same browser/device** — see the
  important note below about what "shared" means here. Nothing is lost
  unless it's explicitly deleted.
- **Registering as Admin**: the sign-up form now has an "Admin" option.
  Choosing it does not grant admin access — the new account sits as
  **Pending** until an existing, already-approved admin approves it from
  Manage Accounts. Students/Faculty still just need approval; only the
  very first account on a device is auto-approved.
- **Online/offline status**: each account's last-seen time now updates
  automatically while a tab is open. The Online/Offline indicator is only
  shown on **Manage Accounts**, which is already restricted to admins —
  students and faculty never see anyone's online status, including their
  own.
- Students already could view (but not edit) Tasks, Subjects, Schedule,
  Notes, Calendar, and Analytics, and could already use Group Messages
  freely — that access model was already correct and is unchanged.
  Faculty could already edit those same sections — also unchanged.

### Important limitation — please read

This is the **localStorage-only** version: there is no server or shared
database, so "everyone who logs into the website" really means **everyone
who opens these files in the same browser on the same device**. If a
student opens the site on their own phone or a different computer, they
will **not** see what an admin added on another device — that data simply
isn't there to sync. If you need real multi-device/multi-user syncing
(so an admin's changes show up for every student anywhere), you need the
original PHP + MySQL version included in `original-php-version/`, hosted
on an actual PHP + MySQL server. Nothing about localStorage can fix that;
it's a browser storage limit, not a bug.

## Resetting the demo data

Open the browser console on any page and run:

```js
SP.resetAll();
```

This clears all local data and reseeds the original sample accounts/tasks.
