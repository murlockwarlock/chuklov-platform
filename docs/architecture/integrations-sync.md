# Integrations and Synchronization

Internal PostgreSQL is source of truth. External events enter a verified/deduplicated Inbox; internal changes create transactional Outbox records for retryable adapters. External providers never mutate domain tables directly.

B2B SalesCall video provisioning follows ADR-020: local SalesCall scheduling and shared Specialist occupancy remain authoritative, while Zoom is a post-commit provider projection. This does not introduce two-way external calendar synchronization. See REQ-SYNC-*, REQ-B2B-001, ADR-012, and ADR-020.
