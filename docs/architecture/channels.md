# Channels

Telegram is the first adapter through Nutgram. The `MessagingChannel` port exposes a typed capability snapshot; the Telegram adapter exposes Mini App and inline-button capabilities without leaking Telegram objects into Application/Domain workflows. Core conversation recording accepts normalized channel values and an allowlisted provider metadata shape.

The `/start` handler only translates Telegram input into a localized, config-driven menu and renders the channel response. Verified Mini App initData is checked in the Telegram infrastructure adapter before the client identity Application action runs. MAX and Instagram are readiness targets, not current integrations. See REQ-CHANNEL-*, ADR-004, and the channels/telegram skills.
