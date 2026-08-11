# Security Policy

Report vulnerabilities privately to the project owner; do not open a public issue containing exploit details or client data.

Baseline rules:

- no secrets, real customer records, medical documents, or production exports in Git;
- organization context is server-derived and every owned query is scoped;
- policies/application services enforce authorization;
- private storage is default and file access is authorized;
- credentials use framework encryption and are masked after save;
- logs and queue payloads exclude secrets and sensitive free text;
- webhook/payment authenticity, replay protection, and idempotency are mandatory before enabling an integration;
- dependency advisories are part of `make quality`.

See `docs/architecture/security.md`, `data-classification.md`, and `docs/operations/incident-response.md`.
