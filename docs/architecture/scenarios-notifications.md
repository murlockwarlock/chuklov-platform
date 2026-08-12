# Scenarios and Notifications

M2 provides only the provider-neutral email delivery boundary needed for passwordless authentication. Email is also a future communication channel, but a verified channel is not a notification preference and does not imply consent to every scenario. M5 will model typed triggers, conditions, delays, templates/prompts, scheduled actions, deliveries, channel capabilities, notification preferences, and idempotency keys. v2.2 timings become editable seed data.

The future notification configuration must let each organization resolve internal recipients per domain event. Resolution may target one or several specific organization members, organization roles, and independently selected verified delivery channels where supported. Recipient/channel configuration is data and policy, never a hardcoded list of Telegram IDs; the implementation belongs to the Scenario/Notification milestone and is not part of M3.

See REQ-NOTIFY-* and ADR-009.
