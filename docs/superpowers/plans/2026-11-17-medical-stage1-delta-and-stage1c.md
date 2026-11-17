# Plan: Medical Stage 1B delta and Stage 1C

## Global constraints

- Base Package A on PR #82 SHA `4d9b8ca8b36b412813363236308c6b3cfaf2a43e`.
- Preserve PR #80-82 branches and Draft state. Create a dependent Draft PR for each package; never merge or mark Ready.
- Use the three 16.11 source files as provenance and update the two existing requirements files in place. Preserve stable IDs and history; clearly separate Zhenya-confirmed decisions, Denis proposals, and open questions.
- Extend existing application/catalog/storage/messaging abstractions. Do not add AI, pulse wave, a parallel test engine, a second cause store, or “Наши специалисты”.
- No local application/database/integration tests. Use focused syntax/document checks locally and staging PostgreSQL/browser evidence for product behavior.

## Task 1: 16.11 requirements delta and source traceability

1. Add the 16.11 source ledger and update existing medical REQs, including T13, test 32, general indexes, multi-primary causes, complex cases, priority/stabilization, counters, client root-cause history, CRM-configured knowledge/norms, confirmed T31 direction, and deferred scope. Record test/REQ traceability.
2. Run the focused documentation checks and inspect the diff for duplicate IDs, source conflicts, stale pulse-wave requirements, and unmarked proposals.

## Task 2: Stage 1B engine, persistence, CRM and history

1. Write failing focused tests for the T13 registry/payload, test 32 structured subtasks, general index, cause limits/complexity, stabilization/priority metadata, counters, and root-cause history. Run the narrowest permitted check on the staging runner when available.
2. Extend the existing enum, preset, catalog form, engine, writer, reader, history reader, and counter calculation additively. Keep old results readable and encrypted.
3. Add safe migrations and CRM controls for new additive metadata and client-level root-cause history. Verify tenant constraints, authorization, and rollback behavior by inspection and staging migration checks.
4. Exercise the affected CRM session journeys and reload/history behavior on staging. Fix only blocking or real bugs in scope.
5. Commit Package A, push, create Draft PR A against the PR #82 head branch, and record starting/final/staging SHAs.

## Task 3: Stage 1C measurements and protected client media

1. Write failing tests for vitals/anthropometry calculations and validation, photo galleries, private files, audio delivery lifecycle, avatar fallback, and PDF inclusion/exclusion.
2. Add additive persistence and application actions for session measurements, anthropometry, six photo galleries, medical files, audio delivery status, and client avatar metadata. Reuse existing private attachment storage and Telegram adapter boundaries.
3. Replace the Stage 1C session-form placeholder with usable tablet-friendly fields, history, and validation. Add client-card Photos, medical files, audio, avatar, and PDF actions with authorization and empty/error/success states.
4. Verify private download/delete, no public URLs, idempotent send/retry states, PDF Cyrillic/layout/exclusion rules, and tenant isolation on staging PostgreSQL.
5. Commit Package B, push, create Draft PR B against Package A, and record exact staging evidence and remaining owner-acceptance items.

## Task 4: staging verification, dependent Draft PRs and final review

1. Deploy each exact candidate to staging through the approved staging workflow, run PostgreSQL and sequential Chromium/WebKit journeys, and classify evidence as PASS, VERIFIED, PARTIALLY VERIFIED, NOT RUN, BLOCKING, or REAL BUG.
2. Review the full branch range with a fresh reviewer, fix only Critical/Important findings using RED→GREEN focused checks, and re-check final SHAs before reporting.

## Review focus

- Existing encrypted payload compatibility and row identity.
- Tenant scoping, authorization, private storage, and external-send idempotency.
- First-time usability of CRM screens and tablet session flow.
- No unconfirmed medical norm or AI behavior presented as fact.
