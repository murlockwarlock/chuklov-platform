---
name: security-review
description: Review Chuklov changes for tenant isolation, authorization, secrets, sensitive data, storage, queues, integrations, and dependency risk. Use only when that risk is touched or an explicit milestone/final security review is requested.
---

# Security Review

Trigger this skill only when the change touches security, privacy, tenant isolation,
authorization, protected data, secrets, storage access, queues/integrations with
sensitive payloads, or when the owner requests a milestone/final security review.
Do not run a full security audit for ordinary UI, copy, CRUD, or bounded logic
changes.

1. Read `SECURITY.md`, the relevant security/data-classification architecture,
   and affected requirements.
2. Trace organization scope from server-side resolution through queries, writes,
   jobs, events, and adapters; verify authorization independently of UI visibility.
3. Inspect validation, mass assignment, logs, exceptions, queue payloads,
   private storage, callbacks, and outbound provider data.
4. Check committed configuration/examples for secrets or customer values and add
   regression coverage for confirmed security invariants.
5. Run dependency audits only when this review's scope or the selected hosted
   gate requires them; do not make them a default for ordinary changes.
6. Report findings without exposing exploitable details in public status documents.
