# CANAI — Mobile (Expo)

Android/iOS app for the CANAI web app: dashboard, AI chat, **Approval Inbox**, automation monitor, notifications (push), usage, PIN / fingerprint confirmation, **emergency stop**, workspace health.

It talks only to the Laravel server (`/api/v1/mobile/*`, login `POST /api/v1/auth/login`). It never talks to the AI engine or the database.

## Run it (development)
```bash
cd apps/mobile
cp .env.example .env            # EXPO_PUBLIC_API_URL=https://your-domain   (or type it on the login screen)
npm install
npx expo start                  # scan the QR code with the Expo Go app… 
```
Expo Go cannot receive push notifications or use a stored fingerprint PIN reliably on all devices; for those use a development build (below).

## Checks (what CI runs)
```bash
npm run typecheck               # TypeScript, strict
npm test                        # API client, step-up flow, formatting, PIN rule
npx expo export --platform android --output-dir /tmp/export    # proves every screen compiles into a real bundle
python3 -m pytest -q ../../tests/mobile                         # app <-> Laravel route contract
```

## Build an installable APK (EAS)
1. `npm i -g eas-cli && eas login` (free Expo account).
2. `eas init` — this writes your project id into `app.json` (`extra.eas.projectId`). Push notifications need it.
3. In `eas.json` replace `https://YOUR-DOMAIN` with your server (https only).
4. `npm run build:apk` — EAS builds in the cloud and gives a download link for the `.apk` (side-load it on a phone).
5. For Google Play: `npm run build:aab`, then `eas submit`.
6. Push on Android needs your Firebase (FCM) credentials once: `eas credentials` → Android → "Google Service Account Key for FCM V1". Expo's push service then relays to the phone. iOS needs an Apple developer account.

Change the app id (`com.jarvisone.app`) in `app.json` before publishing under your own name.

## Security notes
- The login token and (optionally) the PIN are kept in the phone's secure keystore (SecureStore), never in plain storage.
- The PIN is checked by the **server**. The fingerprint only unlocks the PIN stored on the phone; it never replaces the server check.
- Plain `http://` is refused except for a local development server in a dev build.
