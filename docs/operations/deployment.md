# Deployment

Production deployment remains deferred to M16 under ADR-014. The explicit staging target is available for owner-authorized review deployments:

```bash
git fetch origin main
make deploy-staging REVISION=<new-full-sha> EXPECTED_CURRENT_REVISION=<expected-current-full-sha>
```

An explicitly authorized staging candidate branch may set `STAGING_DEPLOY_REF=origin/<branch>`; the exact revision must be reachable from that remote branch. The default remains `origin/main`.

The isolated staging host uses `scripts/deploy-staging.sh`. Copy `.env.staging-deploy.example` to the ignored `.env.staging-deploy`, fill the SSH connection locally, fetch the remote revision, and deploy an exact commit reachable from `origin/main`. `make deploy` remains guarded because production deployment is still deferred to M16.

The script refuses a dirty working tree, unexpected current revision, unexpected app binding, or incomplete Compose service set. It captures nginx, nftables, listening ports, systemd, PM2, Docker, and host PostgreSQL baselines; creates and validates a staging-only PostgreSQL dump; builds locked PHP/Node artifacts; validates the updated Compose file; runs forward staging migrations; atomically switches the release; recreates app, Horizon, scheduler, and Telegram; reconciles PostgreSQL/Redis without needless stateful restart; verifies health; and compares unrelated infrastructure with the baseline. Failure after switching restores the previous application release and runtime Compose file. It never rolls back migrations automatically, deletes volumes, prunes Docker, or changes nginx/firewall configuration.

Host Nginx configuration has a separate, narrowly scoped deployment target because the app release procedure intentionally preserves host routing. Review the exact host diff and current SHA-256 values before running it:

```bash
make deploy-staging-nginx-config \
  REVISION=<full-sha> \
  EXPECTED_SITE_SHA256=<current-vhost-sha256> \
  EXPECTED_LOG_FORMAT_SHA256=<current-log-format-sha256-or-absent>
```

This target installs only the tracked staging TLS vhost and medical-upload log-format file, creates a root-only backup under `STAGING_ROOT/shared/backups`, tests and reloads Nginx, verifies the loaded directives, and restores the previous files automatically if validation or reload fails. The expected hashes make the operation fail closed if the host files changed since review. The regular application deployment continues to preserve Nginx and firewall state.

After deployment, run `./scripts/staging-smoke.sh`. Set `STAGING_SMOKE_USER_ID` and `STAGING_SMOKE_CLIENT_ID` in the ignored `.env.staging-deploy` to stable records in the configured server Organization. The default smoke verifies the deployed SHA, loaded host Nginx medical-upload limit and safe access logging, health, required services, an active Horizon supervisor with workers, authenticated CRM pages, and the Portal session. `./scripts/staging-smoke.sh --deep` additionally runs reversible milestone domain checks such as M9 ingestion/retrieval/provenance and retires synthetic records in `finally`. The harness streams `scripts/staging-smoke.php` through stdin because the app container root filesystem is read-only.

Deployment credentials and the staging application environment remain outside Git. The committed example contains names and non-secret defaults only.

## M1 legacy membership transition

The M1 expand phase creates memberships and deterministically backfills legacy staff and administrator users while retaining `users.organization_id` and `users.is_admin`. The legacy columns remain available to the previous application revision during an atomic release transition.

Their destructive removal is deferred to a later contraction migration and release after the previous revision is no longer served. It must not be reintroduced into the M1 deployment path or treated as an automatic database rollback. M1 has not been production-deployed; the pre-release migration was changed before any production contraction occurred.
