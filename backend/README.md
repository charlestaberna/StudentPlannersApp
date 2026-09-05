# Student Planner (PHP + MySQL) — now installable on Android as a PWA

This copy has been updated with **Progressive Web App** support:
`manifest.json`, `service-worker.js`, app icons in `assets/icons/`, and
matching `<link>`/`<meta>` tags added to `core/layout_head.php`,
`login.php`, and `Register.php`.

Once this is hosted online over **HTTPS** (required — Android/Chrome
won't offer the install option over plain HTTP), visiting the site on
an Android phone in Chrome will show an **"Install app" / "Add to
Home screen"** prompt. Installing it puts an icon on the home screen
that opens full-screen, no browser address bar — behaves like a
normal installed app.

## Hosting it online (step by step)

You need PHP + MySQL hosting reachable from the internet. Good options
for a student project, roughly easiest first:

**Option A — Free shared hosting (InfinityFree, or similar cPanel-style host)**
1. Sign up, create a new hosting account/subdomain.
2. In their **File Manager** (or via FTP with FileZilla), upload every
   file in this folder into the site's `htdocs`/`public_html` root.
3. In their **phpMyAdmin**, create a database, then use **Import** to
   load `student_planner.sql`.
4. Edit `db.php` with the DB host/user/pass/name they give you (shared
   hosts usually don't use `localhost`/`root` — they issue you specific
   credentials).
5. Most of these hosts give you free HTTPS automatically. Visit your
   site's `https://` URL — this is required for installability.

**Option B — A VPS (DigitalOcean, Hostinger VPS, etc.) with your own LAMP stack**
More control, but you set up Apache/Nginx + PHP + MySQL + a free
HTTPS certificate (Let's Encrypt / Certbot) yourself.

**Option C — Platform-as-a-service (Railway, Render)**
Modern and quick to deploy, but check their current PHP + MySQL
support and pricing before committing, since offerings change often.

Once it's live at some `https://yourapp.example.com`:

## Installing on Android (step by step)

1. Open **Chrome** on the Android phone.
2. Go to `https://yourapp.example.com/login.php`.
3. Tap the **⋮** menu (top right) → **Add to Home screen** / **Install
   app** (Chrome may also show this as a banner automatically).
4. Confirm — an icon appears on the home screen.
5. Tap it — opens full-screen, feels like a native app, logs in and
   works exactly like the website.

## Notes
- The service worker only caches static files (CSS, icons) — every
  `.php` page always hits the network, since tasks/schedule/messages
  need a live database. There's no real offline mode; that's expected
  for an app with login + a database.
- If "Install app" doesn't appear: double-check the site is served
  over `https://` (not `http://`), and that `manifest.json` and
  `service-worker.js` are reachable at the site root (test by visiting
  `https://yourapp.example.com/manifest.json` directly — it should
  show JSON, not a 404).
- The icons in `assets/icons/` are placeholders — swap in your own
  192×192 / 512×512 PNGs any time if you want a custom app icon.

---


A full student planner web app — Tasks/To-Do, Class Schedule, Subjects,
Notes, Calendar and Analytics — built on the same dark "space" glass-UI
theme as the original login screen.

## 1. Requirements
- PHP 7.4+ (with `mysqli` extension)
- MySQL / MariaDB
- Apache/Nginx or PHP's built-in server

## 2. Create the database — MySQL command

Import the whole schema + sample data in one shot:

```bash
mysql -u root -p < student_planner.sql
```

(Enter your MySQL root password when prompted.) This creates the
`student_planner` database and all 6 tables: `login_accounts`,
`subjects`, `tasks`, `schedule`, `notes`, `events`.

Or, from inside the MySQL shell:

```sql
SOURCE /full/path/to/student_planner.sql;
```

Or via phpMyAdmin: create/select a database → **Import** tab → choose
`student_planner.sql` → Go.

## 3. Configure the connection

Edit `db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'student_planner');
```

## 4. Run it

```bash
php -S localhost:8000
```

then open `http://localhost:8000/login.php` — or drop the folder into
your `htdocs` / `www` folder for XAMPP/WAMP/Laragon.

## 5. Log in

Demo accounts (from the sample data):

| Username | Password  | Role  |
|----------|-----------|-------|
| admin    | admin123  | admin |
| juan     | juan123   | student |

Or click **Create Account** — the very first account ever registered
is auto-approved as admin; every account after that needs admin
approval via **Manage Accounts**.

## Pages
- `index.php` — Dashboard (stats, today's classes, upcoming tasks, quick add)
- `tasks.php` — Tasks / To-Do (add, edit, delete, priority, status, filters)
- `schedule.php` — Weekly class timetable
- `subjects.php` — Manage subjects/courses
- `notes.php` — Pinned notes per subject
- `calendar.php` — Month calendar of tasks & events
- `analytics.php` — Completion rate & productivity charts
- `accounts.php` — Admin: approve/reject/manage users
