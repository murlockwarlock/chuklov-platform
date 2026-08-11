# Integrations and Synchronization

Internal PostgreSQL is source of truth. External events enter a verified/deduplicated Inbox; internal changes create transactional Outbox records for retryable adapters. External providers never mutate domain tables directly.

Calendar conflict policy requires a later ADR. See REQ-SYNC-* and ADR-012.
