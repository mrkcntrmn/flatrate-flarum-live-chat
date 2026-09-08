# Security — FORUM-CHAT-001B

FlatRate hardening status for MUST_FIX items from
[`docs/forum-chat-001a-neon-audit.md`](https://github.com/mrkcntrmn/flatrate-wiki/blob/main/docs/forum-chat-001a-neon-audit.md).

```text
CHAT_IP_PERSISTENCE=false
CHAT_EMAIL_NOTIFICATIONS=false
CHAT_ATTACHMENTS_INITIAL=false
CHAT_INDEXING=false
SHARED_PUBLIC_PUSHER_CHANNEL=false
DM_COUPLING=false
REALTIME_IMPLEMENTATION_DECISION=PENDING
PUSHER_SELECTED=false
PUSHER_AUTHORIZED=false
PUSHER_CONFIGURED=false
```

## MUST_FIX classification

| ID | Status | Proof |
| --- | --- | --- |
| SERVER_SIDE_ENABLED_PERMISSION | FIXED_AND_TESTED | `ChatAuthorization::assertEnabled` on list/read/post; `ChatAuthorizationTest` |
| PUBLIC_ROOM_AUTHZ | FIXED_AND_TESTED | `assertCanReadRoom` / `assertCanPost`; guests cannot post; type=0 denied |
| PER_ROOM_REALTIME_ISOLATION | FIXED_AND_TESTED | `PerRoomChannelNamer` + `FakeRealtimePublisher`; Toyota cannot receive GM; no shared `public` channel in `ChatSocket` |
| FLOODGATE | FIXED_AND_TESTED | `Api\Throttler\ChatMessage` binds `neonchat.chatmessages.post`; `FloodgateAndSerializerSafetyTest` |
| GUEST_ENUMERATION | FIXED_AND_TESTED | Guests cannot fetch message history (`assertCanReadMessages`); `ChatAuthorizationTest` |
| USER_CREATED_CHANNEL_DISABLE | FIXED_AND_TESTED | `CreateChatHandler` / `EditChatHandler` / `DeleteChatHandler` require admin-rooms; `PrivateChatAndMutationDisableTest` |
| PRIVATE_PM_GROUP_DISABLE | FIXED_AND_TESTED | type=0 rejected; `DM_COUPLING=false`; `PrivateChatAndMutationDisableTest` |
| SCRIPT_REEXECUTION_REMOVAL | FIXED_AND_TESTED | `ChatState.renderChatMessage` no longer creates `script` tags; dist patched; tests assert absence |
| SERIALIZER_ALLOWLISTS | FIXED_AND_TESTED | `MessageSerializer` / `ChatSerializer` explicit allowlists; no `getAttributes()`; no IP |
| CANONICAL_ROOM_IDENTITY | FIXED_AND_TESTED | migration `room_key`/`scope_type`/`scope_key` + uniques; embedded `resources/room-catalog.json` (42 rooms) |
| MODERATION_ENFORCEMENT | FIXED_AND_TESTED | `DeleteMessageHandler` owner-or-moderate; suspended cannot post |
| CHAT_ATTACHMENTS_DISABLED | FIXED_AND_TESTED | Forum attribute `attachments=false`; no FoF Upload wiring |
| CHAT_INDEXING_DISABLED | FIXED_AND_TESTED | `noindex` meta on forum frontend content |
| PUBLIC_CHANNEL_AUTOJOIN_ON_READ | FIXED_AND_TESTED | `Chat::getChatUser` no longer attaches; join only via `ensureMembership` on post |
| CHAT_IP_PERSISTENCE | FIXED_AND_TESTED | `Message::build` forces `ip_address=null`; controller does not forward REMOTE_ADDR |
| CHAT_EMAIL_NOTIFICATIONS | FIXED_AND_TESTED | No notification blueprints; forum attribute `email_notifications=false` |

```text
MUST_FIX_REMAINING_COUNT=0
```

## Remaining risks (non-blocking for B)

- Real transport (Pusher vs websocket) not selected — PENDING by design.
- Disposable full Flarum HTTP matrix may be partial; PHPUnit covers AuthZ/domain negatives.
- Frontend UX polish deferred to CHAT-001C.
