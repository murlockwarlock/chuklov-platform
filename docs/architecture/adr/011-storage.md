# ADR-011: Private Storage Abstraction

- Status: Accepted
- Date: 2026-08-12

## Context

Medical and posture files cannot be public, while mandatory S3 would add unnecessary Phase 1 operations.

## Decision

Use Laravel Storage with private local server storage by default. Domain code does not know physical paths; S3-compatible storage is a future adapter.

## Consequences

Every download requires authorization/temporary access. Backups must cover private files.
