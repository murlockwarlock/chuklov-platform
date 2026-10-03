# ADR-025: Client Companion Retry, Escalation, and Human Takeover

- Status: Accepted
- Date: 2026-10-02

## Context

Client Companion previously treated repeated AI execution failures and specialist escalation as human takeover. This paused subsequent client turns without an answer, falsely described technical incidents as client requests, and discarded paused turns when AI resumed. The product needs separate, auditable states for technical execution, a client specialist request, actual staff ownership, and AI pause.

## Decision

1. A technical execution failure never creates `HumanRequested` and never changes a usable AI conversation to `human_handoff`. Retry eligibility is a deterministic Application policy. Retryable categories are provider unavailability, invalid structured output, retrieval failure, queue failure, rate limiting, and expired execution deadline. Missing/disabled/misconfigured capabilities, unavailable budget, invalid/oversized input, unsupported media, and incomplete media groups do not offer Retry.
2. The inbound message and logical `CompanionTurn` remain stable. Every AI execution has a durable `CompanionTurnAttempt` with a fresh execution identity. A retry references the failed assistant message, validates client/organization, context epoch, latest turn, takeover time, and current failure under the conversation lock, then runs the same inbound input against current runtime configuration. Earlier attempts and output messages remain available for diagnostics; no duplicate client message is created.
3. Explicit client requests create `HumanRequested`; safety findings keep their own reason. Escalations are independent of automation state. Different reasons may remain open together, with one open escalation per organization/conversation/reason. Notifications describe the actual reason. Repeated technical failures use existing safe `CompanionFallbackFailed` Scenario events and do not enter the specialist-request workflow.
4. Only an authorized staff action deliberately enters `human_handoff` and records `last_human_takeover_at`. Open escalation or conversation viewing does not pause AI. Resume returns to `ai_active`, closes open escalations as staff-resolved, and cancels paused client turns rather than generating delayed replies.
5. Portal and Telegram use the same retry and specialist-request Application actions. Adapters own only presentation, callback/route identity, and visible results. A legacy automatic-failure pause is restored only where the open escalation evidence is exclusively `RepeatedExecutionFailure` and there is no later staff reply or takeover audit; ambiguous histories require staff review.

## Consequences

- `CompanionTurn` is the logical client input; `CompanionTurnAttempt` is execution history. A new additive table preserves attempt provenance and gives retries independent AI idempotency keys.
- Technical failure, specialist notification, and human takeover have distinct client/staff copy and workspace labels. A specialist request may remain open while the assistant answers.
- PostgreSQL conversation locks serialize retry, takeover, and resume. Tenant-scoped foreign keys and identity checks prevent cross-client retry.
- Resume never replays old medical input automatically. Staff must trigger a new client turn if a new AI response is needed.
