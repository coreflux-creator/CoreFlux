# CoreFlux Mobile

Worker-first React Native (Expo) app for CoreFlux. One codebase ships:
- iOS App Store
- Google Play
- Web PWA (build target)

Consumes the same `/api/*` endpoints that the web SPA does, with JWT auth
via `POST /api/auth/mobile_login` (Sprint 2).

## What's in this MVP

| Tab        | Screen | Spec |
|------------|--------|------|
| Home       | This-week summary (total / billable / OT / pending / draft hours) | Worker dashboard |
| Time       | Pick placement → date → category → hours → save draft / submit   | C1 worker_class flows |
| Receipts   | Camera or photo-library → upload to `/api/ap/receipts/upload`     | C4 evidence bundle feeder |
| Approvals  | Workflow inbox (pull pending + approve / reject inline)           | A1 WorkflowEngine consumer |
| Profile    | Tenant / role / sign out                                           | — |

Auth flow uses `expo-secure-store` for token persistence (in-memory on
web). Refresh-token rotation handled transparently by the API client on 401.

## Run locally

```bash
cd mobile
yarn install
npx expo install --fix     # align peer deps to whatever Expo SDK 55 wants
yarn start                 # opens Metro
```

Then either:
- Scan the QR from Expo Go on a real iPhone/Android (best for Sprint 5
  validation), or
- Press `i` for iOS simulator / `a` for Android emulator.

Configure the API host in `app.json` → `expo.extra.apiBaseUrl` (defaults
to a placeholder — point at your Cloudways URL).

## Build for distribution

```bash
# iOS / Android — uses EAS
npx eas build --platform ios
npx eas build --platform android

# Web PWA
npx expo export --platform web
```

## Push notifications

The PHP backend already writes to `tenant_push_outbox` with the `log`
driver (Sprint 3). To enable real delivery:

- iOS: provide an APNs auth key (`.p8`) + key_id + team_id + bundle_id
  in env (`APNS_AUTH_KEY_PATH` / `APNS_KEY_ID` / `APNS_TEAM_ID` /
  `APNS_BUNDLE_ID`).
- Android / Web: provide `FCM_SERVICE_ACCOUNT_JSON` env path.

Until those are configured the push driver picks `log` automatically and
the user-facing flow proceeds normally — pushes just get audit-logged.

## What's NOT in this MVP

- Recruiter / AM dashboards (planned)
- Platform-user / staffing-exec Executive Snapshot mobile view (planned)
- Offline time entry queue (next polish)
- Photo + signature capture for vendor COIs (pinned)
- Jobsite kiosk / tablet mode (pinned)

## File layout

```
mobile/
├── app.json                 — Expo config, bundle IDs, permissions
├── package.json             — SDK 55 / RN 0.83 / React 19.2
├── babel.config.js
├── tsconfig.json
├── index.ts                 — expo-router entry
├── app/                     — file-based routes (Expo Router)
│   ├── _layout.tsx          — root: gates on auth, renders login or (tabs)
│   ├── login.tsx
│   └── (tabs)/
│       ├── _layout.tsx
│       ├── home.tsx
│       ├── time.tsx
│       ├── receipts.tsx
│       ├── approvals.tsx
│       └── profile.tsx
└── src/
    ├── api/
    │   ├── client.ts        — fetch wrapper + JWT refresh interceptor
    │   └── storage.ts       — expo-secure-store + web fallback
    └── lib/
        ├── auth.ts          — login / logout / device registration
        └── api.ts           — typed endpoint wrappers
```
