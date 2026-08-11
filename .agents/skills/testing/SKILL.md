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
6. Run the narrowest useful checks while iterating, then `make quality` and applicable integration gates before handoff.
7. Report only commands actually run, with pass, fail, or skipped reasons.
