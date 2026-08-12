# Implementation Plans

Use an active plan for non-trivial work spanning modules, migrations, security boundaries, external integrations, or multiple quality gates.

Each plan contains:

1. Objective and explicit non-goals.
2. Affected `REQ-*` IDs and modules.
3. Data/migration impact.
4. Compatibility, privacy, security, and organization-isolation risks.
5. Implementation sequence with verifiable checkpoints.
6. Unit, feature, integration, security, and E2E tests.
7. Documentation and ADR changes.
8. Final quality gate and rollback considerations.

Keep only current/relevant plans here. Completed plans are removed after outcomes are reflected in code, tests, ADRs, CHANGELOG, ROADMAP, and PROJECT_STATUS.

## Active Plans

### M2 continuation: resolved authentication, Telegram linking, and legal readiness

- Objective: finish the already-built M2 Client Portal foundation after the owner resolved OQ-001 and OQ-006.
- In scope: passwordless email code authentication; verified, single-use Telegram connection tokens; organization-scoped versioned legal documents and exact-version consent evidence; shared responsive portal onboarding; focused security and PostgreSQL coverage.
- Explicit non-goals: M0/M1 re-audit, M3 work, scheduling, payments, medical records, surveys, AI/RAG, broadcasts, subscriptions, MAX, Instagram, notification scenarios, `.ics` generation, and calendar providers.
- Affected requirements/modules: `REQ-PORTAL-005`, `REQ-SEC-002`, M2 portal/Identity/Channels modules, legal-document readiness, and the existing Client/ClientChannelIdentity boundary.
- Data impact: additive migrations for nullable pre-profile client names, email-auth challenges, Telegram link tokens, legal documents, and consent-to-document references; preserve expand/contract compatibility and existing identity uniqueness.
- Security risks: OTP and connection-token secrecy, replay/expiry, session regeneration, request-controlled tenant/client overrides, cross-organization linking, and immutable published legal evidence.
- Sequence/checkpoints: implement application/domain boundaries and migrations; add controllers/routes and shared portal flows; add focused feature and PostgreSQL tests; update traceability/docs; run the final quality gate and one desktop/mobile Playwright smoke; commit, push, and verify the exact hosted revision.
- Cleanup: stop only temporary verification services/processes, preserve persistent volumes, and leave no watch/background process running.
