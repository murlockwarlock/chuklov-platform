# Security Architecture

Primary controls: server-derived organization context, scoped queries/policies, private storage, secure sessions/CSRF, verified webhooks/Mini App auth, encrypted integration credentials, minimal queue payloads, safe logs, dependency audits, and cross-boundary tests.

M0 proves request organization input is ignored and CRM access requires an organization administrator. Expand the suite with each owned entity. See REQ-SEC-* and ADR-015.
