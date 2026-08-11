# Security Architecture

Primary controls: server-derived organization context, scoped queries/policies, private storage, secure sessions/CSRF, verified webhooks/Mini App auth, encrypted integration credentials, minimal queue payloads, safe logs, dependency audits, and cross-boundary tests.

M0 proves request organization input is ignored and CRM access requires an organization administrator. Expand the suite with each owned entity. See REQ-SEC-* and ADR-015.

M0 repository controls deny the working tree from Docker build context except the reviewed runtime Dockerfiles, scan reachable history and the working tree for secrets, preserve an existing `APP_KEY` during repeated setup, and keep tenant/administrator fields outside ordinary User mass assignment.
