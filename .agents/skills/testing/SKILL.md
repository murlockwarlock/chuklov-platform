---
name: testing
description: Select and execute Chuklov unit, feature, integration, frontend, and future E2E coverage. Use when adding tests, diagnosing failures, or verifying a quality gate.
---

# Testing

1. Read `docs/testing/strategy.md` and the acceptance criteria of the affected
   `REQ-*` entries.
2. Test Domain behavior with unit tests, HTTP and authorization behavior with
   feature tests, and PostgreSQL, pgvector, Redis, or workers with integration
   tests. Use fakes for AI, payments, channels, and external integrations in
   normal CI.
3. Prefer observable behavior assertions, including tenant isolation and
   meaningful failure paths, over implementation coupling.
4. Use the narrowest useful local checks while iterating. Do not run full
   suites, Docker, Playwright, or `make test-integration` locally by default.
5. The repository CI and E2E workflows are manually dispatched or scheduled;
   a push does not automatically produce a blocking run.
6. Use this bounded flow when remote evidence is needed:

   `inspect → focused local checks → push coherent candidate → authorized
   exact-SHA staging or hosted evidence → batch fixes → final candidate → one
   final hosted gate when required → owner acceptance`.

7. Do not rerun full suites after a pure UI/copy change unless the touched risk
   or an explicit release gate requires it.
8. Report only commands actually run, with `PASS`, `VERIFIED`, skipped, or
   blocker status stated separately.
