# ADR-001: Modular Monolith

- Status: Accepted
- Date: 2026-08-12

## Context

Phase 1 needs one coherent transactional core and has no demonstrated independent scaling/runtime/team boundary.

## Decision

Build one modular Laravel application with explicit Application/Domain/Infrastructure boundaries. Microservice extraction requires a separate ADR and evidence such as compute isolation, runtime, deployment, security, or ownership needs.

## Consequences

Deployments and transactions remain simple; module coupling must be controlled in code review and tests.
