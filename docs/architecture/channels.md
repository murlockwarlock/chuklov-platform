# Channels

Telegram is the first adapter through Nutgram. The `MessagingChannel` port exposes a typed capability snapshot; the Telegram adapter exposes Mini App and inline-button capabilities without leaking Telegram objects into Application/Domain workflows. Core conversation recording accepts normalized channel values and an allowlisted provider metadata shape.

The `/start` handler translates Telegram input into a localized, config-driven menu or, when a connection token is present, translates authentic bot evidence into the verified Client identity Application action. Mini App initData is checked in the Telegram infrastructure adapter before the client identity Application action runs. Telegram display names/usernames are never linkage evidence. Email, MAX, and Instagram remain distinct future channel identities/capability adapters; connection does not imply notification consent. See REQ-CHANNEL-*, REQ-IDENTITY-002, ADR-004, ADR-005, and the channels/telegram skills.
