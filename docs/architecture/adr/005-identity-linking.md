# ADR-005: Verified Identity Linking

- Status: Accepted
- Date: 2026-08-12

## Context

One person may use multiple channels, but name similarity is unsafe evidence.

## Decision

Keep client identities separate and link only through verified phone/email, authenticated explicit linking, or an equivalent confirmed mechanism.

## Consequences

Duplicate identities may exist until verified; linking is auditable and organization-scoped.
