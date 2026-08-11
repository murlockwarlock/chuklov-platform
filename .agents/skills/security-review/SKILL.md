---
name: security-review
description: Review Chuklov changes for tenant isolation, authorization, secrets, sensitive data, storage, queues, integrations, and dependency risk. Use for security reviews and before completing a milestone.
---

# Security Review

1. Read `SECURITY.md`, `docs/architecture/security.md`, `docs/architecture/data-classification.md`, and affected requirements.
2. Trace organization scope from server-side resolution through queries, writes, jobs, events, and adapters.
3. Verify authorization at the server boundary, independent of UI visibility.
4. Inspect validation, mass assignment, logs, exceptions, queue payloads, private storage, callbacks, and outbound provider data.
5. Scan committed configuration and examples for secrets or customer values.
6. Run Composer and npm audits and classify any remaining advisory accurately.
7. Add regression tests for confirmed security invariants.
8. Put reporting guidance in `SECURITY.md`; do not disclose exploitable details in public-facing status documents.
