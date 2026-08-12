# ADR-002: Organization Tenancy Boundary

- Status: Accepted
- Date: 2026-08-12

## Context

Phase 1 is single-organization, while future SaaS needs data isolation. Historical `master_id` tenancy conflates specialist and owner.

## Decision

Use `organization_id` for ownership/security. Resolve context server-side from authenticated membership or server configuration; never request input. Do not install a heavy tenancy runtime in Phase 1.

## Consequences

Every owned query, policy, job, audit event, AI/RAG operation, and integration must carry validated organization context. M1 application authorization rejects a target organization that is not the current server-derived context, even when a user has memberships in multiple organizations.
