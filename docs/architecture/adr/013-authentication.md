# ADR-013: Authentication Approach

- Status: Proposed
- Date: 2026-08-12

## Context

Privileged CRM requires secure Laravel session authentication; client web auth method is not yet confirmed. Telegram Mini App uses verified initData.

## Decision

Use session/CSRF authentication for CRM and server verification for Telegram. Defer ordinary client web mechanism to OQ-001; never make Telegram the sole identity model.

## Consequences

M1 provides staff identity and organization RBAC for the CRM boundary. M2 adds only the approved Telegram Mini App verified-session foundation and does not implement ordinary client browser authentication. This ADR remains Proposed and must be finalized after owner confirmation of ordinary client web authentication.
