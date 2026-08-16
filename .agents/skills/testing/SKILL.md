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
7. Push the candidate SHA and let blocking PR/main CI run quality, integration, Docker runtime, and privacy checks. The full Playwright suite runs through the scheduled/manual E2E workflow until it is stable enough to return as a blocking smoke gate; investigate its failures separately from unrelated feature work.
8. Report only commands actually run, with pass, fail, or skipped reasons.
