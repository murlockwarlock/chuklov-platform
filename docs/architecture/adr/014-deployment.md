# ADR-014: Revisioned Atomic Deployment

- Status: Accepted
- Date: 2026-08-12

## Context

In-place updates risk mixed code/assets, stale workers, and unrecoverable rollback.

## Decision

Production uses immutable release directories, shared environment/storage, locked installs, backward-compatible migrations, health checks, atomic symlink switch, and graceful worker restart.

## Consequences

Application rollback switches revision; database rollback is not automatic. M16 implements the runbook.
