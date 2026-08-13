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

Objective: implement the organization-scoped scheduling foundation and then complete the booking lifecycle without reopening M3 or entering M5+ scope.

Affected requirements and modules: REQ-TIMEZONE-001/002, REQ-BOOKING-001/002/003/004/005/006/008/009, REQ-CLIENT-003, REQ-SERVICE-002, REQ-SPECIALIST-001; Scheduling, Organizations, Services, Specialists, Identity, Security, CRM, and Client Portal.

Non-goals: OQ-002 cancellation/reschedule policy and payment consequences; notifications, finance, medical, surveys, RAG, AI, SaaS, marketplace, and real providers.

#### M4A — scheduling foundation — implemented

- Recurring specialist working hours, date exceptions, unavailable periods, organization-level lead time, typed visit formats, booking persistence, immutable booking events, and explicit UTC/IANA projections.
- One Application availability path consumes service duration/buffer, exceptions, unavailable periods, lead time, existing blocking bookings, and specialist/client timezone contexts.
- PostgreSQL composite ownership FKs, checks, GiST exclusion constraints, specialist-row transaction locking, and focused Unit/Feature/PostgreSQL coverage are in place.
- CRM configuration is limited to working hours, lead time, exceptions, and unavailable periods. Portal exposes a read-only explicit availability projection.

#### M4B — booking lifecycle and CRM/client flow — implementation complete, local and hosted gates passed

- Resolved OQ-009 as an explicit tenant-safe Specialist-Service many-to-many assignment and added CRM create/remove management with Application authorization.
- Corrected HOME_VISIT `PENDING_REVIEW` to remain non-blocking; added typed approval/rejection transitions, protected availability recheck, immutable booking events, and reason metadata.
- Added restriction-aware client Service → Specialist → slot → format booking flow for OFFICE/ONLINE creation and HOME_VISIT requests, plus organization-scoped CRM booking inspection/actions.
- Added PostgreSQL assignment ownership constraints and a true process-level competing booking test alongside focused Feature/Unit coverage.

#### M4C — reschedule/cancel/history/home/online completion — pending

- Resolve OQ-002 before self-service cancellation/rescheduling.
- Add explicit immutable lifecycle events, home-visit approval/payment handoff, online meeting-link completion, and client history/home/online behavior only after their accepted policies are available.

M4B checkpoints complete: focused tests → PostgreSQL integration and process-level race test → make quality → make ci → Playwright booking flow → exact-SHA hosted CI run 31654103577 for pushed SHA f0a0357dd79d9d5a0cb0de1ca8b1dbdf0a032d31 → status/report update.
