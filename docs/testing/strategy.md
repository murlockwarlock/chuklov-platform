# Testing Strategy

- Unit: pure invariants/value objects/context behavior.
- Feature: HTTP/Inertia, policies, application actions, Filament foundations, faked external AI/providers.
- Integration: real PostgreSQL/pgvector, Redis/queues, and private storage.
- E2E: Playwright desktop/mobile/Mini App and critical business paths as milestones implement them.
- Security: cross-organization/client, IDOR, forged identity/webhook, replay, upload, payment tampering, log/payload leakage.

Use focused tests during local development for immediate feedback. Authoritative full verification runs on GitHub Actions hosted CI — push a candidate SHA/branch and let the hosted workflow execute `make quality`, `make test-integration`, Playwright, Docker runtime health, and privacy/secret scanning. Use `make ci` locally only when the user explicitly requests it. Never replace PostgreSQL-specific integration coverage with SQLite.

M0 foundation verification additionally covers Docker build-context privacy, idempotent application-key initialization, secret scanning, Filament tenant/role boundaries and CRM-to-Portal flow, fake-only Nutgram handler loading, Playwright desktop/mobile smoke, container health, and Horizon runtime status.
