---
name: testing
description: Select and execute Chuklov unit, feature, integration, frontend, and future E2E coverage. Use when adding tests, diagnosing failures, or verifying a quality gate.
---

# Testing

1. Read `docs/testing/strategy.md` and the acceptance criteria of the affected `REQ-*` entries.
2. Test Domain behavior with unit tests, HTTP and authorization behavior with feature tests, and PostgreSQL, pgvector, Redis, or workers with integration tests.
3. Use fakes for AI, payments, channels, and external integrations in normal CI.
4. Prefer behavior assertions over implementation coupling.
5. Include tenant-isolation and failure-path coverage for organization-owned behavior.
6. Run the narrowest useful checks locally while iterating (targeted test file or filter, `vendor/bin/pint --dirty`). Do not run `make ci`, `make test-integration`, Playwright, Docker builds, or full integration stacks locally unless the user explicitly requests it.
7. For full verification, push the candidate SHA and let GitHub Actions hosted CI run `make quality`, `make test-integration`, Playwright, Docker runtime health, and privacy/secret scan. Inspect hosted CI logs to diagnose failures.
8. Report only commands actually run, with pass, fail, or skipped reasons.
