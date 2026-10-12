# FORUM-LIVE-ADMIN-001 — R1 read-only monitoring candidate

**Control tracker:** [FLATRATE.WIKI #1262](https://github.com/mrkcntrmn/flatrate-wiki/issues/1262)

**Scope:** Flarum-owned admin-only 46-room census and current aggregate usage.
**Status:** SOURCE CANDIDATE — NOT QUALIFIED / NOT RELEASED.
**Boundaries:** No production deploy, no member-rollout change, no schema apply, no room reconciliation, no mutation of chat records, users or settings by monitoring reads.

## R1 UI and API
- New Flarum extension admin settings panel: **Live Chat Rooms — Administration & Monitoring**.
- `flatrate-live-chat.admin_dashboard_enabled` is strictly default OFF; existing settings still render and retain their original authorization.
- Admin-only `GET /api/flatrate-live-chat/admin/rooms/dashboard`, optional `?presence_room=<canonical-key>`.
- The API checks **Flarum** `ChatAuthorization::assertAdmin` before returning hidden room metadata. Root-site Mission Control roles do not supersede this domain check.
- 46 embedded catalog entries compared with persisted room records for present/missing/drift (scope, title, type, visibility, audience).
- Canonical human messages only (`neonchat_messages.type = 0`, `deleted_by IS NULL`); group-only SQL aggregates for 1h, 24h, 7d posts, distinct posters in 24h, most recent posting time. Nothing serializes member IDs or message text.
- Presence requests are **count-only, one selected canonical room per user click**, from the existing Centrifugo presence reader. `available=false` with `liveUserCount=null` means UNKNOWN, not 0. Admin viewing the page does not subscribe to realtime.
- API response carries no-store/noindex and explicit not-instrumented limitations. No error/latency history is claimed.
- Admin can use current extension settings for operational controls; the panel itself is read-only.

## R1 security / performance qualification still required before release
- Full authenticated integration matrix: guest, ordinary member, approved beta member, moderator, admin, suspended admin. Flarum admin allows only the last non-suspended actor. Missing gate returns 404 even for admins.
- Fault fixtures: 46 exact, 1 missing, 1 duplicated key, 1 extra canonical-like row, title/scope/audience/visibility drift. Ensure state counts reconcile and no data leak.
- Presence: selected key only; malicious `channel`/URL/unknown key denied; Centrifugo 0, unavailable, timeout and recovery. Verify no 46-room provider fan-out.
- Aggregates: 0 messages, deleted/system messages, distinct posters, UTC rolling boundaries; query-plan test with production-scale data and appropriate indexes. Deep history may make all-time last activity expensive, optimize before promotion if needed.
- Frontend: extension settings rendering survives R1, responsive table, search/keyboard navigation, screen-reader statuses, empty/error states.
- PHP unit + Flarum 1.8.19 integration + JS build + tracked dist freshness and exact-head CI. If tracked `js/dist` is stale, rebuild with the pinned dependencies and commit artifacts before treating the PR as qualified.
- Verify deployment-specific requirement persistence after the October 9 incident before touching PikaPods. Production rollback: dashboard flag OFF, then exact previous extension requirement pin. No automatic schema or provider changes.

## Follow-on tranches (not implemented in R1)
**R2** audited operational controls: explicit confirmed General/Admin/Beta/User switches, bounded per-room posting pause and moderation actions under real server-enforced permissions, reason/expected-state/audit and rollback. Existing General Live operational master must stay General-only; Brand hidden/staff-preview is independent.

**R3** durable observability: historical snapshots, error/failure rates and latency after actual instrumentation, alerts with freshness/threshold/dedup, moderation inbox and policy-controlled responses. No second message store; no raw message-body analytics.

**R4** Mission Control: private link/module and, later, federated read-only health summary through server-to-server double authorization; never browser-held privileged credentials.

```text
R1_READ_ONLY=true
NEW_ADMIN_DASHBOARD_DEFAULT_OFF=true
EXISTING_GENERAL_MASTER_UNCHANGED=true
MEMBER_BETA_GATE_UNCHANGED=true
BROAD_USER_LIVE_UNCHANGED=true
LIVE_ROOMS_MUTATED=false
PRODUCTION_MUTATED=false
```
