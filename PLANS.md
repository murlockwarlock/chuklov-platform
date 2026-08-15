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

### M6 Finance / Multi-Currency — implementation candidate

#### 1. Objective

Build the organization-scoped financial core for exact multi-currency pricing, completed-visit obligations, immutable payments, derived receivables, CRM management, client visibility, a deterministic fake gateway, reconciliation, and Scenario Engine debt reminders.

#### 2. Explicit non-goals

- Do not review, accept, redesign, or change M5B Scenario Engine semantics; leave OQ-014 unresolved.
- Do not implement REQ-PAYMENT-006, OQ-005, or any real payment provider, checkout, webhook signature algorithm, refund API, tax/VAT, credit, wallet, cancellation fee, no-show fee, or accounting chart.
- Do not start M7 or any M7+ milestone; keep receipt storage generic and narrow.

#### 3. Affected requirements

Implement `REQ-CURRENCY-001`, `REQ-CURRENCY-002`, `REQ-CURRENCY-003`, `REQ-PAYMENT-001`, `REQ-PAYMENT-002`, `REQ-PAYMENT-003`, `REQ-PAYMENT-004`, and `REQ-PAYMENT-005`. Keep `REQ-PAYMENT-006` OPEN/FUTURE according to repository convention.

#### 4. Existing M0–M5 concepts reused

- Server-derived `OrganizationContext`, organization-scoped composite foreign keys, `OrganizationAuthorizer`, roles, permissions, audit allowlists, and private `private` storage disk.
- M3 `Service.price_minor`/`price_currency` as the fixed-price source; fixed supported service prices take precedence over conversion.
- M4 `Booking`, `BookingStatus::Completed`, `CompleteBooking`, immutable booking events, and booking/client/service organization relations.
- M5 `RecordScenarioEvent`, transactional `scenario_events`, typed conditions, immutable action snapshots, delivery idempotency, and the existing scheduler/delivery path.
- Current Filament resource/page adapters, Inertia/Vue portal shell, localization, factories, PHPUnit, PostgreSQL integration suite, and Playwright harness.

#### 5. New domain/application concepts

- `Finance` module with controlled `CurrencyCatalog`, `CurrencyCode`/definition metadata, exact `Money`, `RoundingMode`, rate snapshots, currency configuration, obligation/ledger models, derived status/reconciliation, receipt boundary, and `PaymentGateway`.
- Application actions for currency settings/rates, completed-booking obligation materialization, manual payments, corrections, fake initiation/settlement, reconciliation, receipt access, CRM queries, and portal projections.
- Typed ledger entry/source/payment methods and a typed finance Scenario event/condition boundary only.

#### 6. Data model and migrations

Add forward-only PostgreSQL-compatible migrations for organization currency configuration, allowed currencies, manual rates, financial obligations, immutable ledger entries, optional receipt metadata, gateway transactions/provider events, and durable finance idempotency keys. Add organization-scoped composite foreign keys, unique identities, signed/positive amount checks, rate checks, role/currency checks, correction linkage, and PostgreSQL immutability triggers. Do not edit accepted migrations.

#### 7. Money representation and precision

Use integer minor units for persisted money, bounded to PostgreSQL signed `bigint`, with explicit currency and centralized per-currency scale. Use Brick Math decimal/integer operations for parsing, comparison, conversion, and overflow detection; never use float/double. Keep formatting outside arithmetic.

#### 8. Currency configuration

Persist base currency, display currency, force-single-currency, and deterministic rounding mode per organization. Persist enabled currency rows separately. Validate all roles and conversions server-side; never trust organization IDs or raw internal values from CRM/Portal forms.

#### 9. FX rate model

Store manual rates as exact PostgreSQL `NUMERIC(38,18)` with unambiguous direction `1 source = rate target`, organization scope, version, effective timestamp, and actor. Require source/target to be enabled and distinct; reject zero/negative/ambiguous rates. No external FX API.

#### 10. Historical conversion snapshots

Every obligation and ledger conversion stores source/target amounts and currencies, exact rate text, rate identity/version/timestamp, source/target scales, rounding mode, and conversion direction. Current rate edits never mutate snapshots.

#### 11. Financial obligation semantics

Create one immutable obligation for a priced booking when the authorized M4 completion transaction reaches `COMPLETED`; no obligations for cancelled, rejected, pending review, no-show, or unpriced services. Snapshot service/price/currency and all currency roles. Enforce one organization-scoped obligation per booking and idempotent replay.

#### 12. Ledger invariants

Ledger entries are append-only financial truth with explicit signed settlement/application amounts, payment/base/display/settlement currency roles, type, source, actor, occurrence time, notes, evidence, and idempotency. Derived totals are sums of valid entries; no independent editable debt/status fields.

#### 13. Manual payment lifecycle

Authorized staff use one Application action with server-derived organization, obligation, actor, method, idempotency scope, exact amount/currency, occurred time, note, and optional receipt. Lock the obligation row, verify current balance and enabled currency, convert deterministically, append the ledger entry, receipt metadata, audit event, and finance Scenario outbox event atomically.

#### 14. Partial-payment lifecycle

Allow multiple positive manual/fake settlement entries while `sum(applied) < obligation`. Reject zero, negative, invalid precision, unsupported currencies, cross-organization subjects, invalid actor, and overpayment. Locking plus database uniqueness protects concurrent final settlement.

#### 15. Receivable/debt semantics

Receivables are a read/reconciliation projection of immutable obligation amount minus signed valid ledger entries. Derive `outstanding`, `partially paid`, and `settled` statuses; reject corrections that would produce a negative applied balance. Aggregate debt in the obligation base currency using historical base snapshots.

#### 16. Fake PaymentGateway

Define a provider-neutral port for server-calculated initiation, trusted normalized settlement evidence, verified provider-event boundary, idempotency/deduplication, and reconciliation. Bind a deterministic fake adapter only for tests/development/controlled staging; never let a frontend redirect or amount mark an obligation paid.

#### 17. Idempotency/concurrency

Use durable organization-scoped payload hashes for obligation creation, manual payment, correction, fake initiation, fake settlement, and provider event IDs. Same key/same payload replays the original result; same key/different payload fails. Add PostgreSQL process-level races for payment, settlement, obligation, event, and key creation.

#### 18. Reconciliation

Provide a typed reconciliation result exposing obligation, currency roles, snapshots, ledger entries, applied/outstanding amounts, status, and inconsistencies without rewriting history. Fake provider state and internal settlement are deterministically comparable.

#### 19. Scenario Engine debt-reminder integration

Extend only the existing typed event/condition registries and allowlists for a minimal organization/client finance event carrying safe identifiers and derived totals. Reuse M5 outbox, materialization, condition snapshot, delivery idempotency, and execution recheck so settled debt suppresses stale reminders. Do not add a reminder job, scheduler, template engine, or delivery system.

#### 20. CRM UX

Add business-readable finance settings/rates page, organization-scoped obligations/receivables and ledger history views, manual payment and correction actions, receipt evidence/download, and safe selectors. Hide immutable IDs, JSON, idempotency keys, provider data, and destructive edit/delete operations.

#### 21. Client Portal UX

Add an organization/client-scoped finance destination and useful debt summary/history showing service/visit, obligation, paid, remaining, currency, status, history, and authorized receipt access. Preserve the accepted shell/localization and keep the server authoritative.

#### 22. Receipt/file handling

Use the existing private disk through one narrow storage boundary. Validate MIME by content, allow only small PDF/JPEG/PNG receipts, use UUID-safe private paths and metadata, authorize every download, and do not claim malware scanning or implement the M7 attachment subsystem.

#### 23. Organization isolation and authorization

Add application/policy/resource/portal checks for every finance read/write, derive organization from context, validate composite ownership of clients/bookings/services/rates/ledger/gateway/receipts, require the existing finance permission for staff mutations, and add adversarial cross-org/client IDOR coverage.

#### 24. Audit/privacy

Extend the action-specific audit allowlist for currency/rate/payment/correction/fake settlement/reconciliation mutations. Exclude notes where unnecessary, receipt bytes, raw provider payloads/signatures, credentials, and internal technical keys from logs, audit metadata, scenario payloads, and user UI.

#### 25. Tests

Add Money unit tests; currency/rate/price precedence tests; obligation and M4 completion regressions; manual/partial/overpayment/correction/ledger/reconciliation tests; fake gateway/provider event tests; authorization/IDOR/privacy tests; scenario reminder tests; PostgreSQL constraint/immutability/process-race integration tests; and deterministic CRM/Portal Playwright workflows with 320/360/390px checks.

#### 26. Staging/rollback

Run focused tests, M5 scenario regressions, `make quality`, `make privacy`, `make ci`, and relevant Playwright. Commit a focused M6 application SHA, verify hosted exact-SHA CI, deploy that exact SHA through guarded staging with backup and expected-revision protection, apply only forward migrations, verify health/runtime and controlled fake-only finance smoke. Never reset/wipe staging or auto-reverse migrations.

#### 27. Documentation

Record the implementation candidate in `PROJECT_STATUS.md`, `ROADMAP.md`, `CHANGELOG.md`, `docs/product/requirements-changelog.md`, and affected architecture/ADR documents. Correct the known M5B starting-SHA typo if present. Remove this plan only after its outcomes are durably recorded; leave M5B and M6 pending independent acceptance and stop before M7.
