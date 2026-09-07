---
name: deployment
description: Prepare or execute Chuklov deployment, rollback, backup, restore, and operational verification. Use only when deployment work is explicitly authorized and the relevant milestone permits it.
---

# Deployment

1. Read `docs/operations/deployment.md`, `rollback.md`, `backup-restore.md`, and `secrets-recovery.md`.
2. Verify the intended environment and exact artifact before any mutation; never infer production authorization.
3. Keep secrets outside the repository and use environment-specific secret storage.
4. Take and verify a recoverable backup before destructive or irreversible changes.
5. Keep migrations compatible with the rollback strategy and deploy workers with matching code.
6. Record operational results in the appropriate release/status record without exposing credentials when a release record is required.
7. The repository deployment and rollback targets remain guarded until Milestone 16 supplies an approved environment.

## Staging Iteration — FAST_PATH

For an authorized FAST_PATH staging iteration, focused checks are enough before deploy. Verify the exact user-visible scenario after deploy. No broad release ceremony is required, and staging must not be used for destructive changes or full automated suites. If browser tooling is unavailable, do not block a bounded staging recheck.

## Production Release — FULL_RISK

Production release remains separate from staging iteration. Preserve the strict release, backup, health, database, queue, smoke, PostgreSQL, security, and authorization gates required by the changed risk. Never deploy production without explicit owner authorization; production remains closed until Milestone 16.
