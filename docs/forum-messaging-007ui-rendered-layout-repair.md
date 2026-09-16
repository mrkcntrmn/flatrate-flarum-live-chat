# FORUM-MESSAGING-007UI — Rendered Message Layout Repair

Document class: **ACTIVE_IMPLEMENTATION_PLAN**  
Authority scope: **Messages V2 Live/group conversation presentation**  
Success authority: **rendered browser geometry + owner visual acceptance**  
Production mutation: **NOT AUTHORIZED BY THIS WORK ORDER**  
Primary implementation repo: `mrkcntrmn/flatrate-flarum-live-chat`  
Planning/status authority: `mrkcntrmn/flatrate-wiki`  
Last reviewed: **2026-09-16**

```text
WORK_ORDER=FORUM-MESSAGING-007UI
CLASS=RENDERED_LAYOUT_REPAIR
PREDECESSOR=FORUM-MESSAGING-006UI
OWNER_VISUAL_EVIDENCE=FAIL
SOURCE_IMPLEMENTATION_STATUS=COMPLETE_PENDING_CI_AND_PROMOTION
PRODUCTION_MUTATION_AUTHORIZED=false
OWNER_VISUAL_UX_SIGNOFF=FAIL
LIVE_SELF_AVATAR_PASS=false
LIVE_SELF_METADATA_PASS=false
LIVE_SELF_ROW_GEOMETRY_PASS=false
RESPONSIVE_PASS=local_fixture_pass
OTHER_USER_PRESENTATION_REGRESSION_ALLOWED=false
MESSAGE_BODY_PREVIEW_REGRESSION_ALLOWED=false
FORUM_MESSAGING_007UI=IN_PROGRESS
```

## 1. Failure evidence (production, 353px)

Owner production screenshot after FORUM-MESSAGING-006UI deploy showed:

```text
OWN_NICKNAME_RENDERED=true
OWN_TIMESTAMP_RENDERED=true
OWN_AVATAR_VISIBLY_RENDERED=false
METADATA_VERTICAL_COLLISION_PRESENT=true
OUTGOING_BUBBLE_AVATAR_LANE_RESERVED=false
OWNER_VISUAL_UX_SIGNOFF=FAIL
```

Browser inspection on `https://forum.flatrate.wiki/messages/live/community-general-live` at 353px confirmed:

```text
SESSION_USER_ID=2
SESSION_USER_NICKNAME=Wizard
SESSION_USER_AVATAR_URL_PRESENT=true
OWN_AVATAR_DOM_PRESENT=true
OWN_AVATAR_NONZERO_RECT=true
OWN_AVATAR_COMPUTED_POSITION=absolute
OWN_MESSAGE_WRAPPER_DISPLAY=block
LEGACY_ABSOLUTE_METADATA_ACTIVE=true
bubbleCoversAvatarCenter=true
nameTimestampIntersect=true
```

Classification: **LAYOUT_RENDERING_FAILURE** (not missing session avatar data).

## 2. Root cause

```text
ROOT_CAUSE_PRIMARY=
  Legacy ChatViewport.less selectors under
  .ChatViewport .wrapper .message-wrapper ...
  beat the shallower FORUM-MESSAGING-005/006UI overrides under
  .ChatViewport--messagesV2 .message-wrapper--own ...
  Specificity: legacy avatar (0,4,0) > V2 avatar (0,3,0);
  legacy .toolbar .right (0,6,0) > V2 .right (0,4,0).
  Computed result: avatar stayed position:absolute and painted under the bubble;
  metadata .right stayed absolute → nickname/timestamp collisions and weak row height.

ROOT_CAUSE_SECONDARY=
  Own-message layout depended on flex-direction:row-reverse with avatar/content
  DOM order instead of an explicit reserved avatar column.
```

## 3. Architecture decision

```text
STRUCTURE FIRST → RESERVED GEOMETRY → RESPONSIVE CONSTRAINTS → AESTHETIC OVERLAP
```

- Messages V2 own rows use dedicated DOM: `ChatMessage-row--own` → content → meta → bubble → avatar
- CSS Grid lane: `minmax(0,1fr) 28px` under
  `.ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own`
- Intentional `translateX(-2px)` only after lane reserved
- Metadata is a real flex row (`ChatMessage-meta`); no absolute `.right` for V2 own
- Canonical `authorForPresentation()` + ID-based ownership preserved
- Other-user / legacy markup unchanged

## 4. Implementation refs

```text
LIVE_BASE_SHA=fd6dad47e8a9bda63271abc6074b1b2e528d2720
BRANCH=fix/forum-messaging-007ui-rendered-own-layout
FILES=
  js/src/forum/components/ChatMessage.js
  resources/less/forum/ChatViewport.less
  js/tests/forum-messaging-007ui*.js|mjs
  js/tests/forum-messaging-005ui.test.js
  js/tests/forum-messaging-006ui.test.js
  js/tests/messaging-provider.test.js
  js/package.json
  .github/workflows/ci.yml
```

## 5. Test evidence (local)

```text
LIVE_TESTS=PASS (13/13 including RENDERED 353px geometry)
LIVE_BUILD=PASS
LIVE_LINT=PASS (prettier format-check)
STATIC_ARCHITECTURE_TESTS=PASS
RENDERED_007UI_GEOMETRY=PASS
RESPONSIVE_MATRIX=
  320/353/360/375/390/430/768/1024 short+long = PASS
353PX_NUMERICAL=
  AVATAR_WIDTH=28 AVATAR_HEIGHT=28 position=relative
  AVATAR_TEXT_INTERSECTION=false
  NAME_TIMESTAMP_INTERSECTION=false
  META_BUBBLE_INTERSECTION=false
  ROW_NEXT_ROW_INTERSECTION=false
```

## 6. Production promotion plan (prepared, not executed)

```text
OLD_LIVE_PIN=flatrate/flarum-live-chat:dev-main#fd6dad47e8a9bda63271abc6074b1b2e528d2720
NEW_LIVE_PIN=flatrate/flarum-live-chat:dev-main#<merge_sha_after_pr>
ROLLBACK_PIN=flatrate/flarum-live-chat:dev-main#fd6dad47e8a9bda63271abc6074b1b2e528d2720
EXPECTED_COMPOSER_CHANGE=pin Live package to NEW_LIVE_PIN only
RESTART_REQUIRED=true
EXPECTED_DOWNTIME=brief Soft Update / pod restart window
POST_DEPLOY_HEALTH_CHECKS=
  /messages loads
  Live room opens
  own short+long at 353/390/desktop
  other-user presentation unchanged
  chat lists still show no message-body preview
PRODUCTION_BROWSER_ACCEPTANCE=PENDING_SEPARATE_AUTHORIZATION
PRODUCTION_MUTATED=false
```

## 7. Owner acceptance state

```text
OWNER_VISUAL_UX_SIGNOFF=PENDING
FORUM_MESSAGING_007UI=IN_PROGRESS
READY_FOR_FORUM_MESSAGING_007UI_PRODUCTION_PROMOTION_REVIEW=<after CI green + PR merge>
```
