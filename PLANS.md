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

### M4 Scheduling / Booking

Accepted M3 base: b15866f007be4a5db8397120d91f4691ce85382b
Accepted M4A base: af298476aad216b4083b93fc6f6d60f81ca3e52b
Accepted M4B base: 2bf8d502b204f982205ab466fa1c4e7c6a4873e5

Objective: complete the organization-scoped scheduling and booking lifecycle without reopening M3 or entering M5+ scope.

Affected requirements and modules: REQ-TIMEZONE-001/002, REQ-BOOKING-001/002/003/004/005/006/007/008/009/010/011, REQ-SCHEDULING-001, REQ-CLIENT-003, REQ-SERVICE-002, REQ-SPECIALIST-001; Scheduling, Organizations, Services, Specialists, Identity, Security, CRM, and Client Portal.

Non-goals: Notifications, finance ledger/payment providers, medical, surveys, RAG, AI, SaaS, marketplace, and external calendar/video providers.

#### M4A — scheduling foundation — implemented

- Recurring specialist working hours, date exceptions, unavailable periods, organization-level lead time, typed visit formats, booking persistence, immutable booking events, and explicit UTC/IANA projections.
- One Application availability path consumes service duration/buffer, exceptions, unavailable periods, lead time, existing blocking bookings, and specialist/client timezone contexts.
- PostgreSQL composite ownership FKs, checks, GiST exclusion constraints, specialist-row transaction locking, and focused Unit/Feature/PostgreSQL coverage are in place.
- CRM configuration is limited to working hours, lead time, exceptions, and unavailable periods. Portal exposes a read-only explicit availability projection.

#### M4B — booking lifecycle and CRM/client flow — accepted

- Resolved OQ-009 as an explicit tenant-safe Specialist-Service many-to-many assignment and added CRM create/remove management with Application authorization.
- Corrected HOME_VISIT `PENDING_REVIEW` to remain non-blocking; added typed approval/rejection transitions, protected availability recheck, immutable booking events, and reason metadata.
- Added restriction-aware client Service → Specialist → slot → format booking flow for OFFICE/ONLINE creation and HOME_VISIT requests, plus organization-scoped CRM booking inspection/actions.
- Added PostgreSQL assignment ownership constraints and a true process-level competing booking test alongside focused Feature/Unit coverage.

#### M4C — reschedule/cancel/history/home/online completion — complete

- Resolved OQ-002, OQ-010, OQ-011, and OQ-012 in the product decision records.
- Added organization/actor-scoped PostgreSQL booking-creation idempotency with request-hash replay protection and scheduled retention pruning.
- Added cutoff-aware client/staff cancellation and rescheduling that preserve Booking identity/calendar UID, recheck authoritative availability, preserve the old time on conflict, and append immutable events.
- Added typed completion, NO_SHOW, terminal transition enforcement, manual ONLINE meeting-link action, HOME_VISIT withdrawal/approval handoff, party size, and booking-specific destination support without finance/provider behavior.
- Added shared Portal My bookings/detail/history/timezone/reschedule/cancel surfaces and CRM lifecycle/history/needs-attention actions.
- Added one reusable schedule-mutation impact calculator with explicit CRM acknowledgement and durable booking preservation across schedule, Specialist, Service, and assignment changes.

M4C checkpoints passed: focused tests, PostgreSQL integration and process-level race test, make quality, make ci, Playwright desktop/mobile booking management, and exact-SHA hosted CI run 31691868383 for 048c193a37bb2da110a1340093a3d8cb4c443916.

#### M4 final-review remediation — complete

- Remediation base: 4e0b0685e5f62c6973969f1f373b14af79e2490d. Fixed M4-01 through M4-14 without reopening M0–M3 or redesigning Scheduling.
- Added expected-event-version optimistic reschedule protection, required durable creation idempotency with replay-before-mutable-checks, CRM booking creation, direct Service Catalog enforcement, bound schedule-impact previews, exception-deletion protection, structural needs-attention analysis, safe CRM history projection, display-local date filtering, current client-timezone presentation, locked Specialist impact calculation, atomic combined configuration save, and accurate audit mutation labels.
- Adverse verification includes stale reschedule and replay-after-mutation tests, required-key/invalid-link/authorization tests, impact-set changes, both large timezone-offset directions, DST spring-gap/fall-overlap behavior, atomic rollback, immutable BookingEvent DELETE, CRM creation, and true PostgreSQL same-Booking/same-key process races.
- Final local gates passed: 56 focused Unit/Feature tests/246 assertions; 13 focused PostgreSQL tests/34 assertions; `make quality` (15 Unit/32 assertions, 137 Feature/736 assertions, clean static/lint/build/audits); `make ci` (43 integration tests/90 assertions); Playwright 6/6 desktop/mobile. M5+ remains out of scope; next action is independent Sol recheck.
