# ADR-007: RAG Retrieval Boundary

- Status: Accepted
- Date: 2026-08-12

## Context

Retrieval must be replaceable, scoped, testable, and protected from prompt injection.

## Decision

Application code depends on `KnowledgeRetriever`; Phase 1 implementation is pgvector. Retrieval always filters organization and allowed KB scope and returns source references.

## Consequences

Documents are untrusted data. Backend replacement and hybrid/reranking changes require regression evidence.
