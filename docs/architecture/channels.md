# Channels

Telegram is the first adapter through Nutgram. The `MessagingChannel` port exposes a typed capability snapshot; the Telegram adapter exposes Mini App and inline-button capabilities without leaking Telegram objects into Application/Domain workflows. Core conversation recording accepts normalized channel values and an allowlisted provider metadata shape.

The `/start` handler translates Telegram input into a localized, config-driven menu or, when a connection token is present, translates authentic bot evidence into the verified Client identity Application action. Mini App initData is checked in the Telegram infrastructure adapter before the client identity Application action runs. Telegram display names/usernames are never linkage evidence. Email, MAX, and Instagram remain distinct future channel identities/capability adapters; connection does not imply notification consent.

M5A keeps inbound/conversation `MessagingChannel` separate from the narrower provider-neutral outbound `NotificationChannel` port. The Scenario module supplies a `NotificationMessage` and receives typed delivery outcomes; the Telegram adapter owns Nutgram calls and returns only a safe provider reference/error classification. Ordered channel candidates and durable delivery rows are resolved by Scenario Application services, not by provider objects. Missing or unverified identities are unavailable and may fall through; retryable outcomes reuse the same delivery idempotency key and do not immediately send a duplicate. No real MAX, Instagram, email notification, or other future provider is implemented.

See REQ-CHANNEL-*, REQ-IDENTITY-002, REQ-NOTIFY-*, ADR-004, ADR-005, ADR-009, ADR-012, and the channels/telegram skills.
