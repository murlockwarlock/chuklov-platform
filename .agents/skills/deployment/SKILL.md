---
name: deployment
description: Prepare or execute Chuklov deployment, rollback, backup, restore, and operational verification. Use only when deployment work is explicitly authorized and the relevant milestone permits it.
---

# Deployment

1. Read `docs/operations/deployment.md`, `rollback.md`, `backup-restore.md`, and `secrets-recovery.md`.
2. Verify the intended environment and exact artifact before any mutation; never infer production authorization.
3. Keep secrets outside the repository and use environment-specific secret storage.
4. Take and verify a recoverable backup before destructive or irreversible changes.
5. Run quality gates before release and health, database, queue, and smoke checks after release.
6. Keep migrations compatible with the rollback strategy and deploy workers with matching code.
7. Record operational results in the appropriate release/status record without exposing credentials.
8. The repository deployment and rollback targets remain guarded until Milestone 16 supplies an approved environment.
