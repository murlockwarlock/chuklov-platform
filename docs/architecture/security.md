# Security Architecture

Primary controls: server-derived organization context, explicit membership roles/policies, scoped queries, private storage, secure sessions/CSRF, verified webhooks/Mini App auth, encrypted integration credentials, minimal queue payloads, safe logs, dependency audits, and cross-boundary tests.

M1 adds organization-scoped clients, channel identities, consents, settings, feature controls, credentials, and audit events. Application actions and policies verify current organization context, active membership, role permission, and feature entitlement; UI visibility is not the authorization boundary. See REQ-SEC-* and ADR-015.

Credential values are stored with Laravel encrypted casts, replace operations update rotation metadata, and normal model/API representations are masked. Audit metadata uses an allow-by-sanitization path and redacts secret-like keys before persistence. Logging taps redact sensitive context and message values.

M0 repository controls deny the working tree from Docker build context except the reviewed runtime Dockerfiles, scan reachable history and the working tree for secrets, preserve an existing `APP_KEY` during repeated setup, and keep tenant/administrator fields outside ordinary User mass assignment.
