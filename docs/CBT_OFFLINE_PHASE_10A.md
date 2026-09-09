# CBT Offline Continuity (Phase 10A)

Browser/local storage provides **continuity**. The Laravel server remains **authoritative**.

## Scope

Phase 10A implements a local exam engine so an already-started attempt can continue when connectivity drops.

| Phase | Status |
|-------|--------|
| 10A Local offline CBT engine | Implemented |
| 10B Server synchronization | Implemented — see `docs/CBT_OFFLINE_PHASE_10B.md` |
| 10C Offline submission / reconciliation | **Not implemented** |

## Architecture

- IndexedDB database: `supreme_reagan_cbt` (versioned; stores: `exam_packages`, `attempts`, `answers`, `sync_queue`, `metadata`)
- Abstraction: `/site/JS/cbt/offline-store.js` + `/site/JS/cbt/offline-session.js`
- Attempt UI: `/site/JS/portal-cbt.js` saves to IndexedDB **before** optional online autosave
- Package API: `GET /api/v1/cbt/attempts/{attempt}/offline-package` (owner-only, in-progress, frozen exam snapshots)
- Timer: uses authoritative `ends_at` with `server_now` clock offset; offline does **not** extend time
- Service worker: `/cbt-sw.js` caches CBT static assets + last-seen student CBT shell pages only (no API/payment/admin caching)

## Security limitations

Local storage is **not tamper-proof**. Students control their device. Continuity only — marking, keys, scores, and results stay server-side. Packages are validated to exclude answer-key fields.

## Local cleanup (future)

Phase 10A does **not** auto-delete completed local packages. Phase 10B+ should garbage-collect after confirmed server sync.

## Manual checks

1. Start exam online → answer → DevTools Offline → continue → refresh → answers + timer recover
2. Go online again → UI shows reconnection; online autosave resumes (no “synced” claim beyond confirmed API saves)
3. Close tab → reopen attempt URL → local recovery
4. Short-duration exam offline past `ends_at` → UI locks answers; no extra time
