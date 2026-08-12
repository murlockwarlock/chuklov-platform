# Deployment

M16 will implement ADR-014: exact revision → locked install/build → shared environment/private storage → preflight → backward-compatible migration → health check → atomic revision switch → graceful Horizon reload → recorded revision.

`make deploy` is intentionally guarded until this runbook is implemented and production authority/configuration exists.

## M1 legacy membership transition

The M1 expand phase creates memberships and deterministically backfills legacy staff and administrator users while retaining `users.organization_id` and `users.is_admin`. The legacy columns remain available to the previous application revision during an atomic release transition.

Their destructive removal is deferred to a later contraction migration and release after the previous revision is no longer served. It must not be reintroduced into the M1 deployment path or treated as an automatic database rollback. M1 has not been production-deployed; the pre-release migration was changed before any production contraction occurred.
