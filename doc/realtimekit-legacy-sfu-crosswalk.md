# RealtimeKit -> Legacy SFU Crosswalk

## Goal
Use RealtimeKit's documented behavior as the target contract, then harden the legacy SFU engine to match that behavior where practical.

## Ground Truth Sources
- `realtime kit dev docs.md` (root)
- `cloudflare sfu dev docs.md` (root)
- Installed SDK API surface: `node_modules/@cloudflare/realtimekit/dist/index.d.ts`
- Current implementation:
  - `resources/js/stores/managers/RealtimeKitManager.ts`
  - `resources/js/stores/managers/StreamManager.ts`
  - `resources/js/stores/managers/SignalingManager.ts`
  - `resources/js/stores/managers/UnifiedMediaManager.ts`

## RealtimeKit "DNA" (observable contract)
1. Event-first model: react to `videoUpdate`, `audioUpdate`, `screenShareUpdate`, participant map events, active speaker events.
2. Identity split: session-scoped `id` vs persistent `userId`.
3. View-driven subscriptions: `setViewMode()` and `setPage()` define what is active on screen.
4. Self media APIs are explicit and simple: `enable/disableAudio`, `enable/disableVideo`, `enable/disableScreenShare`.
5. Quality telemetry is first-class: `networkQualityScore`, `mediaScoreUpdate`.
6. SDK owns remote media propagation; app logic should not reinvent room-level transport semantics.

## Legacy SFU Current Behavior (high level)
1. Presence protocol + `sfu-media-ready` + `request-media-info` drive pull orchestration.
2. Track pull retries/backoff are app-managed and can thrash under late MID propagation.
3. Selective subscription depends on `visibleParticipantIds` and a `:screen` key suffix for screen streams.
4. Camera/screen synchronization can diverge when visibility logic prioritizes `:screen` and camera pull is gated.

## Contract Crosswalk
1. RealtimeKit: "event reflects state".
Legacy: frequent polling/retry loops.
Upgrade: prefer signal/event transitions; gate retries behind state-change triggers.

2. RealtimeKit: one participant identity with media facets.
Legacy: participant key and `participant:screen` key can drift.
Upgrade: normalize visibility by base participant id, then resolve facets (camera/screen) independently.

3. RealtimeKit: view mode controls subscription intent.
Legacy: visibility list may include only `:screen`, causing camera starvation.
Upgrade: when `pid:screen` is visible, treat base `pid` as visible for camera pull eligibility.

4. RealtimeKit: media toggles are direct and idempotent.
Legacy: toggles can trigger repeated pull-renegotiate cycles.
Upgrade: debounce/serialize toggle-induced pulls and add per-participant pull generation tokens.

5. RealtimeKit: quality metrics are consumption signals.
Legacy: quality and handshake states can disagree.
Upgrade: introduce explicit handshake state machine (`connecting` -> `media-ready` -> `stable`).

## Immediate Fixes (highest ROI)
1. Fix UI runtime error in meetings tile path (`ParticipantTile.vue` temporal dead-zone around `localVideo`).
2. Visibility normalization rule:
- If `visible` contains `pid:screen`, also mark `pid` visible for camera eligibility.
3. Pull dedupe token:
- ignore stale retries once a newer pull generation exists for the same participant.
4. MID readiness guard:
- only send `sfu-media-ready` payloads with non-placeholder MIDs, and re-broadcast once camera MID becomes available.

## Theory -> Test Plan
1. Define invariants
- I1: If remote camera becomes enabled, remote side receives a camera track within SLA (e.g., < 3s in LAN test).
- I2: Enabling screen share must not suppress camera pull eligibility.
- I3: Repeated `sfu-media-ready` must not create duplicate video transceivers for same participant facet.

2. Build deterministic scenarios
- S1: Chrome shares screen then enables camera; Firefox must receive both.
- S2: Firefox toggles camera repeatedly while screen active; Chrome remains stable.
- S3: Late joiner during active screen+camera gets both tracks without manual retry.

3. Add instrumentation
- per participant/facet pull generation id
- state transition logs: `waiting-mids`, `pulling`, `track-received`, `stable`
- duplicate transceiver detection warning with participant facet keys

4. Acceptance criteria
- no "no valid tracks after 5 attempts" in successful scenario
- no duplicate active video tracks for same facet
- UI enters call view only after minimum handshake criteria are met

## Suggested Rollout
1. Stabilization patch set (logic only, no UX changes).
2. Add transition/loading state hooks to handshake milestones.
3. Canary with production logs for 24-48h.
4. Iterate on thresholds/retry timings using observed RTT/loss buckets.
