# ADR-012: Integration Inbox and Outbox

- Status: Accepted
- Date: 2026-08-12

## Context

External APIs/webhooks fail, retry, replay, and conflict independently of internal transactions.

## Decision

Verified inbound events enter a deduplicated Inbox before application commands. Internal changes create transactional Outbox records processed by retryable adapters.

## Consequences

External providers cannot directly mutate tables. Each integration defines idempotency and conflict policy.
