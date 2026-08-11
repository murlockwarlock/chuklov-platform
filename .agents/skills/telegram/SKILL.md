---
name: telegram
description: Implement Telegram transport and Mini App integration through Nutgram without leaking business logic into handlers. Use for Telegram commands, updates, webhooks, identity linking, or Mini App launch behavior.
---

# Telegram

1. Read the affected `REQ-*`, `docs/architecture/channels.md`, and `docs/architecture/security.md`.
2. Verify installed Nutgram APIs with primary documentation before changing handlers or webhook setup.
3. Keep handlers as adapters that parse input, call Application code, and render channel output.
4. Resolve organization and user identity server-side; validate Telegram init data when Mini App authentication is introduced.
5. Do not log tokens, raw sensitive updates, payment data, or health data.
6. Design idempotency for retried updates before enabling external delivery.
7. Fake transport in tests and avoid live Telegram calls in CI.
8. Keep MAX, Instagram, and other channel work deferred until their milestone.
