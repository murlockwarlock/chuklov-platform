# ADR-006: PostgreSQL and pgvector

- Status: Accepted
- Date: 2026-08-12

## Context

The product needs transactional CRM/finance data and organization-scoped vector retrieval.

## Decision

Use PostgreSQL as internal source of truth and pgvector for Phase 1 embeddings. Do not add a separate vector database without evidence and ADR.

## Consequences

Integration tests use the real extension; vector dimensions/index changes require explicit migrations.
