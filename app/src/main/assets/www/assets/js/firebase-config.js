/* =====================================================================
   FIREBASE CONFIG — paste your own project's keys here.

   HOW TO GET THESE VALUES:
   1. Go to https://console.firebase.google.com and create a project
      (free "Spark" plan is enough for a school project).
   2. In the project, click the "</>" (web) icon to register a web app.
   3. Firebase will show you a config object exactly like the one below.
      Copy your real values into SP_FIREBASE_CONFIG.
   4. In the left menu go to Build > Firestore Database > Create database
      (start in "test mode" for now, or use the firestore.rules file
      included in this zip).
   5. In the left menu go to Build > Authentication > Sign-in method,
      and enable "Anonymous" sign-in. This app still uses your own
      username/password screens — anonymous auth is only used quietly
      in the background so Firestore knows the request is "logged in".
   ===================================================================== */

window.SP_FIREBASE_CONFIG = {
  apiKey: "PASTE_YOUR_API_KEY",
  authDomain: "PASTE_YOUR_PROJECT.firebaseapp.com",
  projectId: "PASTE_YOUR_PROJECT_ID",
  storageBucket: "PASTE_YOUR_PROJECT.appspot.com",
  messagingSenderId: "PASTE_YOUR_SENDER_ID",
  appId: "PASTE_YOUR_APP_ID"
};
