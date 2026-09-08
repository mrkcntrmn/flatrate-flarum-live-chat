# Upstream attribution

This repository begins as an unmodified baseline import of Neon Chat
(Flarum live chat extension).

Upstream provenance (do not conflate with the owned package identity):

| Field | Value |
| --- | --- |
| SOURCE | xelson/flarum-ext-chat |
| REPOSITORY | https://github.com/Xelson/flarum-ext-chat |
| VERSION | v1.1.5 |
| COMMIT | a7489ac183764eef12969135d6b665ac5eb18272 |
| LICENSE | MIT |
| UPSTREAM_AUTHORS | Push-EDX / Xelson |
| Baseline tag | upstream/neon-v1.1.5 |

Owned package identity (derivative; separate from SOURCE):

| Field | Value |
| --- | --- |
| OWNED_PACKAGE | flatrate/flarum-live-chat |
| OWNED_EXTENSION_ID | flatrate-live-chat |
| OWNED_REPO | mrkcntrmn/flatrate-flarum-live-chat |

The first commit in this repository preserves the upstream tree at the
commit above, plus this `UPSTREAM.md` attribution file. The upstream
`LICENSE` file is retained unchanged.

Do not rewrite `SOURCE` or `COMMIT` during rebrand or hardening. CI guards
fail if provenance drifts from the pin above.
