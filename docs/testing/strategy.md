# Testing Strategy

- Unit: pure invariants/value objects/context behavior.
- Feature: HTTP/Inertia, policies, application actions, Filament foundations, faked external AI/providers.
- Integration: real PostgreSQL/pgvector, Redis/queues, and private storage.
- E2E: Playwright desktop/mobile/Mini App and critical business paths as milestones implement them.
- Security: cross-organization/client, IDOR, forged identity/webhook, replay, upload, payment tampering, log/payload leakage.

Use focused tests during local development for immediate feedback. Coding agents do not run Docker, Playwright, heavy integration suites, or `make ci` locally unless the owner explicitly authorizes it. Blocking PR/main CI runs deterministic quality, PostgreSQL/Redis integration, privacy, and Docker runtime checks. The full Playwright suite runs nightly or manually until it is stable enough to return as a blocking smoke gate; a scheduled E2E failure is investigated separately and does not automatically block unrelated feature work. Never replace PostgreSQL-specific integration coverage with SQLite.

M0 foundation verification additionally covers Docker build-context privacy, idempotent application-key initialization, secret scanning, Filament tenant/role boundaries and CRM-to-Portal flow, fake-only Nutgram handler loading, Playwright desktop/mobile smoke, container health, and Horizon runtime status.
