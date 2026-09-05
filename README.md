# Student Planner — Android App (Java, Android Studio)

> **Combined build:** this project now bundles the static HTML/JS
> version of Student Planner directly inside the app
> (`app/src/main/assets/www/`) instead of loading a hosted PHP URL.
> The app works fully offline — its "database" is `localStorage`
> inside the WebView, which Android keeps across app launches. The
> sections below (setting `app_url`, hosting on InfinityFree, etc.)
> describe the **old** hosted-backend setup and no longer apply — kept
> only because the original `backend/` PHP project is still included
> in this project in case that hosted version is wanted again later.
> Skip straight to "Step 1" / "Step 2b" below to build the APK; nothing
> needs to be edited in `strings.xml` anymore.
>

This is a real native Android project written in Java. It's a WebView
shell — your PHP/MySQL app is still the actual app; this Java code just
wraps it in a proper Android window with:

- No browser address bar, full-screen native feel
- Pull-to-refresh
- Proper Android back-button navigation through page history
- File upload support (e.g. avatar photo, message attachments)
- A "No connection" screen if the phone has no internet

## Before you build: set your app's URL

Open **`app/src/main/res/values/strings.xml`** and change this line to
your actual hosted URL (the InfinityFree one, once it's live):

```xml
<string name="app_url">https://yoursubdomain.epizy.com/login.php</string>
```

If you want to test against your PC's local XAMPP first (phone and PC
on the same WiFi), use your PC's local IP instead, e.g.:
```xml
<string name="app_url">http://192.168.1.10/student_planner/login.php</string>
```
(Find your PC's IP via `ipconfig` in Command Prompt, look for "IPv4
Address".)

## Step 1 — The easiest way: let GitHub build the APK for you (no Android Studio needed)

This project includes `.github/workflows/build-apk.yml` — a robot
build server that GitHub runs for free. It has full internet access to
Google's Android SDK, so it can build the real `.apk` file even though
this couldn't be done locally in a sandboxed environment. You just
upload the project; GitHub does the actual compiling.

1. Go to https://github.com and sign in (or create a free account).
2. Click the **+** (top right) → **New repository**.
3. Name it anything (e.g. `student-planner-app`) → keep it **Public**
   or **Private**, doesn't matter → click **Create repository**.
4. On the new repo's page, click **uploading an existing file** (link
   in the middle of the page).
5. Open the extracted `StudentPlannerApp` folder on your PC, select
   **everything inside it** (not the outer folder itself — go inside
   first, then select all: `app`, `backend`, `.github`, `build.gradle`,
   `settings.gradle`, `gradle.properties`, `gradle`, `README.md`).
6. **Drag all of that** into the GitHub upload page.
7. Scroll down, click **Commit changes**.
8. Click the **Actions** tab (top of the repo page). You'll see a
   workflow run start automatically (yellow dot → green check when
   done, takes a few minutes).
9. Once it's green, click into that run → scroll down to
   **Artifacts** → download **student-planner-debug-apk** (a zip
   containing your `.apk`).
10. Unzip that, transfer `app-debug.apk` to your phone (USB, Drive,
    email — whatever's easiest), open it on the phone, allow install
    from this source when asked, tap **Install**.

That's it — no SDK download, no Gradle sync, no local build tools.
Every time you push a change to the repo, it rebuilds automatically.

> ⚠️ Before uploading, don't forget to set your real hosted URL in
> `app/src/main/res/values/strings.xml` (see below) — otherwise the
> app will try to load the placeholder URL.

---

## Step 2b (alternative) — Build locally with Android Studio instead

1. Download from https://developer.android.com/studio
2. Run the installer, keep default options (it will also install the
   Android SDK automatically — this step downloads a few GB, so it
   takes a while).
3. Open Android Studio once so it finishes its first-time setup wizard.
4. Extract `StudentPlannerApp.zip` somewhere simple, e.g.
   `C:\Projects\StudentPlannerApp`.
5. In Android Studio: **File → Open** → select the extracted
   `StudentPlannerApp` folder → **OK**.
6. Android Studio will start **"Gradle Sync"** — a progress bar at the
   bottom. First sync downloads Gradle + dependencies, can take
   several minutes. Just wait for it to finish (bottom bar disappears,
   no red errors).

## Step 2b.1 — Run it on your phone (fastest way to test)

1. On your Android phone: **Settings → About phone** → tap **Build
   number** 7 times to unlock Developer Options.
2. **Settings → Developer options** → enable **USB debugging**.
3. Connect phone to PC via USB cable. A prompt appears on the phone —
   tap **Allow**.
4. In Android Studio, your phone's name should appear in the device
   dropdown (top toolbar, next to the green ▶ Run button).
5. Click the green ▶ **Run** button.
6. Android Studio builds the app and installs it straight onto your
   phone automatically — it'll open on its own.

This is the quickest way to test changes without dealing with APK
files at all.

## Step 2b.2 — Build an installable `.apk` file locally

1. In Android Studio menu: **Build → Build Bundle(s) / APK(s) → Build
   APK(s)**.
2. Wait for the build to finish — a notification appears bottom-right:
   **"APK(s) generated successfully"** with a **locate** link.
3. Click **locate** — this opens the folder containing:
   ```
   app/build/outputs/apk/debug/app-debug.apk
   ```
4. Copy that `app-debug.apk` to your phone (via USB transfer, Google
   Drive, email to yourself, etc.).
5. On the phone, open the file — Android will ask to allow install
   from this source (allow it once), then tap **Install**.

This debug APK works fine for personal use / school demo. It's not
signed for the Play Store, but that's not needed for your use case.

## Troubleshooting

| Problem | Fix |
|---|---|
| Gradle sync fails / stuck | Check your PC's internet connection — first sync needs to download files from Google's servers. |
| Blank white screen when app opens | Double-check `app_url` in `strings.xml` is correct and reachable — test the same URL in your phone's Chrome browser first. |
| "Cleartext traffic not permitted" error | Only happens with `http://` (not `https://`) URLs — this project already allows it via `network_security_config.xml`, so this shouldn't occur, but if it does, confirm that file wasn't removed. |
| File upload (avatar photo) doesn't open camera/gallery | Make sure you allowed the Camera/Photos permission prompt when it appeared. |
| Phone not showing in device dropdown | Make sure USB debugging is enabled and you tapped "Allow" on the phone's USB debugging prompt. Try a different USB cable/port if needed. |

## Project structure

```
StudentPlannerApp/
├── .github/workflows/build-apk.yml   ← builds the APK automatically on GitHub
├── backend/                          ← your PHP/MySQL app (host this on InfinityFree)
│   ├── login.php, index.php, tasks.php, ...
│   ├── student_planner.sql
│   ├── manifest.json / service-worker.js  (PWA support)
│   └── db.php                        ← edit with your InfinityFree DB credentials
├── app/
│   ├── build.gradle              ← app dependencies & SDK versions
│   └── src/main/
│       ├── AndroidManifest.xml    ← permissions, app entry point
│       ├── java/.../MainActivity.java   ← all the WebView logic
│       └── res/
│           ├── layout/activity_main.xml  ← screen layout
│           ├── values/strings.xml         ← ⚠️ your app_url goes here
│           ├── values/colors.xml
│           ├── values/themes.xml
│           ├── xml/network_security_config.xml
│           └── mipmap-*/                  ← app icons (all densities)
├── build.gradle                   ← project-level Gradle config
└── settings.gradle
```

**How the pieces fit together:** `backend/` is the real application —
host it on InfinityFree (or anywhere with PHP+MySQL). The Android app
(`app/`) is just a window that opens that hosted URL. They're two
separate deployments: one goes to a web host, the other becomes an
APK on your phone — connected only by the URL in `strings.xml`.

