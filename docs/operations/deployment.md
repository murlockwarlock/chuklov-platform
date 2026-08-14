# Deployment

Production deployment remains deferred to M16 under ADR-014. The explicit staging target is available for owner-authorized review deployments:

```bash
git fetch origin main
make deploy-staging REVISION=<new-full-sha> EXPECTED_CURRENT_REVISION=<expected-current-full-sha>
```

The isolated staging host uses `scripts/deploy-staging.sh`. Copy `.env.staging-deploy.example` to the ignored `.env.staging-deploy`, fill the SSH connection locally, fetch the remote revision, and deploy an exact commit reachable from `origin/main`. `make deploy` remains guarded because production deployment is still deferred to M16.

The script refuses a dirty working tree, unexpected current revision, unexpected app binding, or incomplete Compose service set. It captures nginx, nftables, listening ports, systemd, PM2, Docker, and host PostgreSQL baselines; creates and validates a staging-only PostgreSQL dump; builds locked PHP/Node artifacts; validates the updated Compose file; runs forward staging migrations; atomically switches the release; recreates app, Horizon, scheduler, and Telegram; reconciles PostgreSQL/Redis without needless stateful restart; verifies health; and compares unrelated infrastructure with the baseline. Failure after switching restores the previous application release and runtime Compose file. It never rolls back migrations automatically, deletes volumes, prunes Docker, or changes nginx/firewall configuration.

Deployment credentials and the staging application environment remain outside Git. The committed example contains names and non-secret defaults only.

## M1 legacy membership transition

The M1 expand phase creates memberships and deterministically backfills legacy staff and administrator users while retaining `users.organization_id` and `users.is_admin`. The legacy columns remain available to the previous application revision during an atomic release transition.

Their destructive removal is deferred to a later contraction migration and release after the previous revision is no longer served. It must not be reintroduced into the M1 deployment path or treated as an automatic database rollback. M1 has not been production-deployed; the pre-release migration was changed before any production contraction occurred.
