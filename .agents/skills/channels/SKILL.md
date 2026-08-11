---
name: channels
description: Evolve channel-independent messaging and its adapters. Use when adding or changing MessagingChannel behavior, message rendering, identity mapping, delivery, or a new channel integration.
---

# Channels

1. Read `docs/architecture/channels.md`, `docs/architecture/conversations.md`, and affected requirements.
2. Keep shared business workflows channel-neutral behind the established `MessagingChannel` boundary.
3. Put transport parsing and rendering in adapters, not Domain or Application behavior.
4. Preserve organization scope, channel identity mapping, consent, and idempotency.
5. Avoid a lowest-common-denominator API; represent supported capabilities explicitly when a real second adapter requires them.
6. Record a material boundary change in an ADR.
7. Use fakes in automated tests and never call external channels from normal CI.
