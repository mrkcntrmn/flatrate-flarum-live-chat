# FORUM-MESSAGING-008UI — Grouped Message Identity + Mirrored Metadata

Canonical source of truth for this tranche lives in the Live package:

- `mrkcntrmn/flatrate-flarum-live-chat` → `docs/forum-messaging-008ui-grouped-presentation.md`

## Summary

Messages V2 Live/group chat now groups consecutive same-author messages (≤5 minutes, events break groups). Identity and timestamp render once per group; own headers are mirrored (`time … Nickname: [avatar]`); V2 drops the per-message ellipsis.

## Acceptance fixture

Room: `/messages/live/community-general-live`

Expected after consecutive sends within 5 minutes:

```text
[Tech avatar] Tech_00:                    now
              Yo
              Second incoming message


now                              Wizard: [Wizard avatar]
                                  Testing 1
                                  Testing 2
                                  Testing 3
```

One avatar/nickname/timestamp per group; zero V2 `•••` controls.

## Work order

`FORUM-MESSAGING-008UI-GROUPED-MESSAGE-PRESENTATION-R1`
