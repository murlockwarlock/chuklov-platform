# ADR-005: Verified Identity Linking

- Status: Accepted
- Date: 2026-08-12

## Context

One person may use multiple channels, but name, username, and profile-text similarity are unsafe evidence.

## Decision

Keep client identities separate and link only through verified authentication/channel evidence. In M2, email verification creates a verified email identity and an authenticated web client can connect Telegram only through a short-lived single-use server token plus authentic Telegram bot evidence. Tokens and channel identities are organization-scoped; duplicate and cross-client linkage is rejected deterministically.

## Consequences

Duplicate identities may exist until verified; linking is auditable and organization-scoped.
