# Project Status

- Last updated: 2026-08-16
- Current phase: Phase 1 foundation
- Current milestone: Milestone 7 — Medical Profiles / Sessions / Attachments (IN_PROGRESS)
- Status: M0–M6 are CLOSED / ACCEPTED; M7 is IN_PROGRESS (M7A implementation candidate in verification; M7B Session Cockpit is next and NOT STARTED)

## CRM Performance & Staging Runtime Remediation — DEPLOYED / HOSTED CI GREEN — 2026-08-15

- Remediated staging and CRM performance bottleneck: enabled Filament SPA navigation (`->spa()`) with binary attachment/receipt URL exceptions; transitioned container runtime from single-threaded PHP CLI built-in web server to production-grade `php:8.5.9-fpm-bookworm` + Nginx with clean PHP 8.5 OPcache and PID 1 fail-fast supervisor; added repository-tracked staging host Nginx reverse proxy template serving static fingerprinted Vite assets (`/build/*`), fonts, brand, and published vendor styles with long-lived immutable cache headers.
- Eliminated redundant O(N) repeated queries and duplicate reads: request-scoped memoization in `GetMedicalProfile` (keyed by `actor_id:org_id:client_id`) with strict per-read authorization; direct organization context resolution in `ClientPolicy` and `BookingPolicy`; static in-memory memoization in `OrganizationFeatureGate` and `GetBookingLeadTime` with context-scoped invalidation.
- Deployment fixes applied: (1) PHP-FPM global directives were placed after the pool include, triggering `unknown entry 'error_log'` — now ordered correctly; (2) legacy staging workers ran as root under `cap_drop: ALL` against hardened `www-data`-owned runtime directories — app/workers now run as UID/GID `33:33`. The deploy path now guards these runtime assumptions so equivalent legacy drift is detected before release switching.
- Deployed application SHA: `594a38b7dbad16576308ab1965feffb5d88849b0`.
- Hosted CI run `31904011750` green: Quality and integration, Playwright desktop and mobile, Privacy and secret scan, Docker build and runtime health.
- Staging verified at the exact deployed SHA: `/health` healthy; PHP-FPM, container Nginx, OPcache, Horizon, scheduler, and Telegram running; PostgreSQL/pgvector/Redis healthy; private storage protected; `php -S` absent; database not reset; unrelated host services unchanged.
- Automated authenticated AFTER page-timing benchmarks were not completed; perceived CRM/UI speed is evaluated manually by the owner, and no scripted CRM timing benchmark is required unless the owner explicitly requests one.

## Milestone 7A — IMPLEMENTATION CANDIDATE — 2026-08-15

- Implemented M7A scope (`REQ-CLIENT-002`, `REQ-MEDICAL-SEC-001`, `REQ-ATTACHMENT-001`, `REQ-ATTACHMENT-002`, ADR-017). M7B Session Cockpit and M8+ remain unstarted.
- ADR-017 accepted: Class C clinical fields (`anamnesis`, `complaints_goals`, `operations_injuries`, `medicines`, `supplements`) are encrypted at rest with AES-256-CBC and HMAC-SHA256 authenticated envelopes using `Illuminate\Encryption\Encrypter` behind `MedicalEncryptorInterface` and `MedicalKeyResolverInterface`. Database models store raw ciphertext with an explicit `encryption_key_version` and rotation seam.
- Dedicated medical encryption secret configured outside the database via medical configuration (`config/medical.php`), decoupling medical encryption from application key rotation.
- Forward-only additive PostgreSQL migrations created: `2026_08_15_140000_create_medical_profiles_table.php` (composite foreign key `(organization_id, client_id)` referencing `clients(organization_id, id)` and unique `[organization_id, client_id]`) and `2026_08_15_140001_create_medical_attachments_table.php` (not-null client ownership and composite foreign keys to `clients` and `organization_memberships` with restrict-on-delete semantics).
- Application actions `GetMedicalProfile` and `UpdateMedicalProfile` enforce server-derived organization context and authorization (`OrganizationPermission::ViewClients`/`ManageClients`) and length limits (<= 10,000 chars per field).
- Private attachment storage on disk `private` under UUID-based paths `medical/attachments/{organization_id}/{uuid}.{ext}` via `AttachmentStorageInterface` / `PrivateMedicalAttachmentStorage`. Uploads enforce server-side MIME sniffing, explicit raw DICOM rejection (detecting `.dcm`/`.dicom`, MIME `application/dicom`, and 128-byte preamble with `DICM` signature), configurable file size limits (default 20 MB recorded in ASM-008), and SHA-256 checksums.
- Attachment taxonomy strictly restricted to confirmed requirements: `medical_report` and `posture_photo`.
- Fail-closed runtime scanner `FailClosedAttachmentScanner` binds by default in normal runtime to quarantine unconfigured uploads. Test fake `LocalDeterministicAttachmentScanner` is retained solely for testing.
- Temporary signed download URL generation (`GetTemporaryAttachmentUrl`, 15-minute default TTL) and streaming controller `AdminMedicalAttachmentController` (`GET /admin/attachments/{uuid}`) requiring valid signatures, staff authorization, and cleared status without exposing storage paths.
- Filament CRM integration: `ClientResource` infolist displays decrypted fields and temporary signed download links; dedicated `editMedicalProfile` modal action handles medical updates cleanly without touching ordinary client profile saves.
- Security allowlists updated in `RecordAuditEvent` (`medical.profile.created`, `medical.profile.updated`, `attachment.uploaded`, `attachment.downloaded`) with zero plaintext clinical data; Monolog processor `RedactSensitiveLogData` masks medical field names; scan metadata is sanitized to allowlisted keys.
- Local verification: 34 Unit tests (61 assertions) and 252 Feature tests (1549 assertions) pass; 61 PostgreSQL integration tests (151 assertions) pass; 24 Playwright e2e tests pass; Pint, ESLint, Larastan (0 errors), vue-tsc (0 errors), Vite build, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. Local `make quality`, `make privacy`, and `make ci` pass.
- Hosted exact-SHA CI run `31895042962` is green for `48c12884ff86adc9c33691ba05964b0ec87935e1`: Quality/integration `95036954471`, Privacy/secret scan `95036954456`, Docker/runtime health `95036954441`, Playwright desktop/mobile `95036954586`.
- Guarded staging now runs exact application SHA `48c12884ff86adc9c33691ba05964b0ec87935e1` at `https://crm.psysoldatov.ru`; forward migrations applied without database reset; dedicated medical encryption configuration verified; staging smoke verified encryption at rest, cross-org isolation, fail-closed runtime scanner quarantine, quarantined download blocking, and temporary signed URLs.
- Status: M7 remains IN_PROGRESS; M7B Session Cockpit is next and NOT STARTED; OQ-013 remains OPEN.

## Milestone 6 — CLOSED / ACCEPTED — 2026-08-15

- Remediation started from pre-remediation M6 application SHA `b0bacaf4130f192f95dea07744f8560ebaf4b611`; the final remediation application SHA is `72b4987124c4897bb6fe34bd74443848f6f85eea`. M5B application behavior remains unchanged.
- Added forward-only organization-scoped finance bootstrap `2026_08_15_100002_bootstrap_finance_currency_configuration`: a single existing priced Service currency becomes an explicit force-single configuration, no-priced organizations remain unconfigured for owner setup, multiple priced currencies fail before writes, and existing owner configuration is never overwritten. Configuration saves now validate existing Service/obligation currencies and all required directed FX paths atomically; same-currency paths require no rate.
- Open-balance reconciliation now sums settlement entries in settlement currency and values remaining base/display debt with the immutable obligation conversion snapshot. Payment-time rate snapshots remain historical ledger evidence; later rates cannot create a negative display/base balance. Invalid valuation snapshots fail visibly, and Portal/Scenario/CRM projections do not clamp impossible values.
- Focused M6 Feature coverage passes 15 tests/122 assertions; PostgreSQL Integration coverage passes 55 tests/145 assertions; M5B regression coverage passes 11 tests/42 assertions; relevant CRM/Portal Playwright coverage passes 22/22 desktop/mobile. Local `make quality`, `make privacy`, and `make ci` pass.
- Hosted exact-SHA CI run `31884758963` is green for `72b4987124c4897bb6fe34bd74443848f6f85eea`: Quality/integration `95012327744`, Privacy/secret scan `95012327712`, Docker/runtime health `95012327769`, and Playwright desktop/mobile `95012327733`.
- Guarded staging now runs exact application SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` at `https://crm.psysoldatov.ru`; the forward-only bootstrap migration applied without a database reset, health reports database/pgvector/Redis/private storage healthy, Horizon is running, scheduler and Telegram containers are running, and the isolated Compose app/Horizon/scheduler/Telegram images all match the remediation SHA. The pre-smoke staging inventory contained one organization, no priced Services, and no Finance configuration rows.
- Rollback-safe staging Finance smoke created a temporary pre-M6-like USD-priced Service and confirmed Booking, ran bootstrap twice, verified the Service remained listed, rejected missing directed FX atomically, preserved an owner-edited configuration on repeat bootstrap, completed the Booking into an obligation, exercised obligation-rate `90` with a later payment-rate `200`, and verified settlement `5000` USD minor units, display/base `450000` RUB minor units, Portal `450000` RUB, CRM `50.00 USD` settlement outstanding, Scenario debt `450000` RUB, and exact zero after full settlement. Temporary rows were rolled back; no real provider, payment, reminder, or delivery was used.
- Independent remediation re-review returned GO FOR M6 CLOSEOUT. M6 is CLOSED / ACCEPTED: the rollout/currency configuration and mixed-FX open-balance blockers are CLOSED; accepted application SHA is `72b4987124c4897bb6fe34bd74443848f6f85eea`, exact hosted CI run is `31884758963`, and staging runs the same SHA.
- `REQ-CURRENCY-001`–`REQ-CURRENCY-003` and `REQ-PAYMENT-001`–`REQ-PAYMENT-005` are accepted for M6. `REQ-PAYMENT-006` and OQ-005 remain future/outside M6 for M13; OQ-014 remains OPEN; M7+ are NOT STARTED.

## Milestone 5 — CLOSED / ACCEPTED — 2026-08-15

- Implementation started from repository HEAD `7e8a7f7f87fab3b4ed17d2e4fceec0c5e77f579a`; the accepted deployed baseline remains `a487f5c216b43e687b26d6916e6af5032fbe4429`.
- M5B extends the accepted M5A engine with typed `onboarding.started` events, configurable bounded repeat sequences, post-session +24h/+48h/conditional +72h seed rules, typed onboarding/consent/qualifying-next-booking conditions, configurable retention windows, and same-organization member/role recipient validation with verified-channel revalidation.
- Re-engagement consumes the internal `ClientOnboarding` progress record only; the removed visible Portal wizard, surveys/tests, AI prompts, communication-preference model, and all M6+ behavior remain out of scope.
- Retention counts only future `REQUESTED` or `CONFIRMED` bookings within the configured rule window. Materialization and execution both evaluate the typed condition; action template, condition, recipient, channel, render, sequence, and repeat values remain immutable snapshots.
- OQ-014 records the still-unconfirmed business/clinical meaning of the conditional +72h follow-up. The seed uses only a neutral typed completed-booking guard until the owner decides otherwise.
- Focused M5B Feature coverage passes 11 tests/42 assertions; focused M5A regression coverage passes 54 tests/202 assertions; PostgreSQL integration coverage passes 46 tests/104 assertions; focused scenario Playwright passes 2/2 desktop/mobile. `make quality` passes with 27 Unit tests/49 assertions and 215 Feature tests/1,323 assertions; `make ci` passes with healthy PostgreSQL/Redis and the same 46 PostgreSQL integration tests/104 assertions.
- Hosted exact-SHA CI run `31844393385` is green for `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457`: Quality and integration `94907770561`, Privacy and secret scan `94907770571`, Docker build/runtime health `94907770625`, and Playwright desktop/mobile `94907770772`.
- Guarded staging deployment from the accepted deployed baseline `a487f5c216b43e687b26d6916e6af5032fbe4429` succeeded for exact implementation candidate `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457`. The M5B migration is applied; application health reports database, pgvector, Redis, and private storage healthy; Horizon is running; the scheduler lists `scenarios:run`; app, Horizon, scheduler, and Telegram containers are running; the application-level scenarios queue depth and failed scenario-job count are both zero.
- Staging M5B smoke ran the idempotent notification seeder twice, resulting in two localized `post-session-follow-up` templates, six +24h/+48h/conditional +72h rules, and no actions or client records created by the seed. Authenticated CRM browser smoke confirmed the business-labeled rules page, seeded delays/triggers, rule detail/template projection, and scenario-history empty state with zero browser errors. The temporary owner-scoped smoke account was removed. No external Telegram delivery was attempted.
- Independent Sol Max acceptance accepted M5B implementation checkpoint `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457` for M5 closeout. OQ-014 remains OPEN; no M5 production behavior was changed during M6 remediation.

## Client Portal booking acceptance remediation — 2026-08-15

- Code remediation started from `996dc6fcb0088d512f7c516eff1e2851c1cae02a`; deployed code revision is `9250376f2d8ae6b87e29286570291c152b803e2e`.
- The supplied designer brand assets were then restored as the only Portal/browser/Apple/Filament assets in `a487f5c216b43e687b26d6916e6af5032fbe4429`, which is deployed to staging.
- Booking and reschedule calendars now request the complete visible month from the server-authoritative availability path. Month navigation reloads that exact range, outside-month cells are distinct/unavailable, and rescheduling does not hide earlier policy-valid future dates in the same month.
- The 320px multi-specialist/multi-format flow now retains full selected service, specialist, and format context at selection, calendar, confirmation, and success stages without ellipsis or horizontal overflow. The focused worst-case Playwright path covers service, specialist, format, calendar/time, confirmation, and success.
- Booking query, creation, availability, reschedule, cancellation, conflict, cutoff, stale-version, and timezone failures use a shared RU/EN client error mapping. Focused coverage proves English responses are English and Russian responses remain Russian.
- A real Telegram bot update no longer processes `/start web_…` twice: the generic Telegram-link handler excludes browser-login tokens. Focused fake-bot coverage asserts a single successful reply; a true authenticated Telegram-client Mini App/session was unavailable, so no real in-client Telegram smoke is claimed.
- The Portal uses the supplied designer Russian and English CHUKLOV circular logos; the browser, Apple touch, and Filament icon use the supplied English designer mark. All legacy and generated brand files are removed and regression-tested as absent.
- Verification: focused Feature tests `15/15` (150 assertions), local `make quality`, local `make ci`, and full Playwright `24/24` passed. Hosted exact-SHA CI run `31837128566` passed quality/integration, Playwright desktop/mobile, Docker runtime health, and privacy/secret scan for `9250376f2d8ae6b87e29286570291c152b803e2e`; the same four hosted checks passed for the designer-asset correction in run `31838329916` at `a487f5c216b43e687b26d6916e6af5032fbe4429`.
- Guarded staging deployment from expected `d24554d01f149c1fd24c2ac7443c75162186a408` succeeded for `9250376f2d8ae6b87e29286570291c152b803e2e`, and a subsequent guarded exact-SHA deployment from that revision succeeded for `a487f5c216b43e687b26d6916e6af5032fbe4429`; each includes a validated staging database backup, health, Horizon, scheduler, Telegram runtime, and protected-host baseline checks. Public staging browser smoke at desktop/390px/360px/320px confirms the supplied logo/icon assets load, no horizontal overflow, and no page errors. Authenticated staging booking flows were not synthesized; their evidence is local and hosted Playwright.
- Scope remained the three Portal NO-GO blockers plus the owner-reported Telegram duplicate reply and requested favicon/brand correction. M5B and unrelated milestones remain unstarted.

## Client Portal booking visual remediation — 2026-08-14

- Starting code revision: `694d98c9d0392ba50dfd70bc088a5918b18f0a6f`.
- Rebuilt the client booking surface around the approved CHUKLOV reference: focused service rows, meaningful specialist/format decisions, calendar plus selected-day time choices, and confirmation summary. One valid specialist is auto-selected; booking availability remains server-authoritative.
- Removed client-facing date-range controls and multi-day slot dumps. The mobile booking flow hides the persistent bottom navigation while the focused wizard is active so the calendar and CTA remain fully readable; normal Portal destinations retain bottom navigation.
- Follow-up after staging review: a successful booking now renders a dedicated result card instead of leaving the calendar and availability slots underneath the success message; the result retains service, specialist, date/time, and format details, and calendar/time headings have explicit spacing.
- Follow-up after staging review: the persistent Portal shell now uses the full committed `chuklov-logo.jpg` asset with its inscription instead of the cropped mark-only asset; regenerated browser/Apple/Filament PNG icons use the same full-mark source, and the generic profile description was removed.
- The branding baseline was deployed at `9a9e6c9cc3218045178c5b09c4bfd0f5b8fcfe2a`; the booking-details/reschedule remediation is deployed at `d24554d01f149c1fd24c2ac7443c75162186a408` on `https://crm.psysoldatov.ru`.
- Booking details now load without availability, payment, or timezone implementation panels. The home card has no non-interactive reschedule control; rescheduling is an explicit details action with a selected-day calendar/time grid and confirmation. The narrow-shell fix prevents the long service title from expanding a 320px viewport.
- Verification: `make quality`, `make ci`, focused Portal Playwright `16/16`, and real staging smoke at desktop/390px/320px pass. Staging browser checks report no home reschedule label, no initial availability dump, no visible payment/timezone implementation text, seven readable weekday cells, no horizontal overflow at all tested widths, successful reschedule completion, and empty browser/page error collections. Live Telegram launch link resolves to `@chuklovtestbot`; an authenticated Telegram-client session was not available for a true in-client Mini App smoke, so no synthetic initData result is reported as live Telegram verification.
- M5B and unrelated milestones remain unstarted.

## Completed Remediation

- Staging follow-up simplified the Portal entry copy, added ordinary-browser Telegram authentication through a browser-bound single-use bot deep link, retained verified Mini App initData and email OTP, and persisted client-facing copy rules. M5B remains unstarted.
- Staging Telegram follow-up enables the existing `ClientRecords` feature gate only for organization `chuklov`, adds automatic Mini App authentication, and trusts the isolated HTTPS proxy for generated Portal URLs. M5B remains unstarted.
- M0-AUDIT-H01/H02 remediated with a deny-by-default Docker context, deterministic privacy regression, and repeat-safe `APP_KEY` initialization.
- M0-AUDIT-M01 through M06 remediated: expanded CI, Filament tenant/workflow coverage, fake-only Nutgram handler evidence, guarded User privilege fields, local unreachable-object pruning, and privacy-policy correction.
- Safe LOW cleanup completed: skill link/size cleanup, port alignment, demo/redundant scaffold removal, and Horizon snapshot scheduling.
- Local quality, integration, Playwright, privacy/secret, Docker build, runtime health, and Horizon gates pass.
- Fresh-clone and hosted checks from earlier milestones remain historical evidence; their pre-rewrite SHA values are not acceptance revisions.

## Milestone 1

- Implemented organization memberships with owner/administrator/staff roles, context-bound policies, client identity foundations, typed settings and feature controls, encrypted rotatable credentials, safe audit events, and log redaction.
- Added PostgreSQL-oriented constraints, composite organization/client foreign keys, ownership indexes, and a legacy-user membership backfill without rewriting M0 migration history.
- Added focused organization isolation, IDOR, membership/RBAC, mass-assignment, feature/settings, identity, credential, audit, and logging regression coverage.
- M0 remains accepted; no M0 re-audit or fresh-clone verification was performed in this milestone.

## Milestone 1 remediation

- Corrected the PostgreSQL Filament/Portal regression by binding the test to the same server-derived organization used by request middleware; no tenant boundary was weakened.
- Deferred destructive legacy-user column removal to a later contraction release; populated PostgreSQL backfill coverage verifies administrator/staff roles, idempotence, legacy-column retention, and multi-membership preservation.
- Made audited M1 mutations transactional, replaced denylist audit sanitization with action-specific metadata allowlists, enforced the Service Catalog entitlement in Application and policy paths, hardened sensitive model state against mass assignment, and redacted complete authentication values across configured log channels.
- Existing milestone report/review/audit Markdown artifacts are ignored and removed from the repository; the remediation report is local-only.

## Milestone 2

- Implemented the shared responsive Client Portal path for desktop, mobile browser, and Telegram Mini App runtime mode through the same Inertia/Vue pages and Application actions.
- Added a centralized restrained health/wellness visual foundation for the portal: warm-neutral canvas, sage brand, terracotta accent, typography, spacing, surfaces, borders, radii, controls, feedback states, and responsive layout primitives; CRM styling remains independent.
- Added server-verified Telegram initData authentication with signature validation through Nutgram, freshness and replay controls, session regeneration, organization-scoped client resolution, and redacted audit metadata.
- Added passwordless email authentication with normalized identities, hashed single-use codes, expiry, bounded attempts/rate limits, provider-neutral delivery, and session regeneration.
- Added verified Telegram identity linking/reuse through short-lived server tokens and authentic bot evidence; token replay, forged identity, cross-organization, and frontend-override paths are rejected.
- Remediated M2 replay canonicalization, fail-closed authentication mail transport handling, concurrent Telegram link initiation, and concurrent legal publication with PostgreSQL invariants and stable-row serialization.
- Removed tracked private master/report documents from the current index and rewritten reachable history; RECHECK, review/report/audit, master-plan, and source-requirement policies are now ignored while local copies remain available.
- Added deterministic organization/client uniqueness, progressive profile confirmation, versioned onboarding progress, a localized configurable Telegram menu, and the capability-aware channel boundary.
- Added organization/client-scoped conversations and normalized idempotent message persistence with an allowlisted metadata shape.
- Added organization-scoped versioned legal documents, immutable published versions, exact-version portal consent evidence, and platform-controlled Phase 1 legal management; organizations cannot self-enable organization-managed wording.
- Established canonical PostgreSQL timezone-aware M2 instants, IANA timezone validation/default resolution, centralized portal date/time formatting (`DD-MM-YYYY`, `HH:mm`), and future calendar/email readiness boundaries.
- No medical, survey, scheduling, payment, AI, broadcast, subscription, MAX, Instagram, or other later-milestone behavior was added.

## Blockers / Open Questions

- OQ-001 and OQ-006 are resolved for M2 by the owner-confirmed decisions recorded in `docs/product/open-questions.md`.
- OQ-002 and OQ-009 were resolved during M4B/M4C. OQ-010, OQ-011, and OQ-012 were resolved during M4C; no M4 blocker remains and no unconfirmed payment, refund, or multi-location policy was invented.

## Milestone 3

- Added organization-scoped Filament CRM resources and Application actions for Clients, Service/catalog records, Specialists/Practitioners, and managed localized portal content.
- Client CRM edits preserve verified channel identity semantics and provide audited self-service booking restriction history; no medical fields were added.
- Specialists remain distinct organization-owned identities. Optional staff links use active same-organization `OrganizationMembership` rows and composite database ownership constraints; `users.organization_id` is not used.
- Service catalog configuration supports localized content, category, timing, formats, active state, catalog type, payment policy, and integer minor-unit prices with explicit currency. Products have no commerce workflow.
- No scheduling, booking, payment, medical, notification/scenario, AI, RAG, broadcast, subscription, external-channel, or Phase 2 SaaS behavior was added.

## Milestone 4A

- Accepted M3 base is b15866f007be4a5db8397120d91f4691ce85382b; M3 remains closed and was not re-audited.
- Added organization-scoped recurring specialist working hours, date exceptions, unavailable periods, organization-level booking lead time, typed visit formats, booking persistence, immutable booking events, and the authoritative availability Application path.
- Availability uses specialist-or-organization IANA scheduling timezone, separate client display timezone, DST-safe local wall-clock conversion, service duration/buffer, lead time, exceptions, unavailability, and blocking booking intervals.
- PostgreSQL composite ownership FKs, important checks, GiST exclusion constraints, specialist-row transaction locking, and online-only meeting metadata checks protect scheduling invariants. No application-only check-then-insert strategy is used.
- Added CRM scheduling configuration, exception/unavailable-period resources, and a small authenticated Portal availability projection with explicit Inertia props.
- At M4A close, OQ-002 and OQ-009 were intentionally left open. M4B resolved OQ-009 through explicit assignments. M4C resolves OQ-002, OQ-010, OQ-011, and OQ-012; no M5+ behavior was started.
- Focused verification passes: 16 scheduling Unit/Feature tests/46 assertions and 37 PostgreSQL integration tests/67 assertions. Final make quality and make ci both pass: 13 Unit tests/28 assertions, 99 Feature tests/540 assertions, Larastan 0 errors, clean Pint/ESLint/vue-tsc/Vite, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities. Playwright was not run because M4A adds no interactive booking flow.

## Milestone 4B — Booking lifecycle and CRM/client flow

- Accepted M4A base is af298476aad216b4083b93fc6f6d60f81ca3e52b; M3 remains closed and was not re-audited.
- OQ-009 is resolved as an explicit organization-scoped many-to-many `specialist_service_assignments` relation. CRM assignment actions enforce same-organization ownership, authorization, uniqueness, active-record booking rules, and historical-booking preservation.
- Added non-blocking HOME_VISIT `PENDING_REVIEW`, typed approval/rejection actions, transactionally rechecked approval, immutable booking events, organization-scoped CRM booking list/detail/actions, and the shared client Portal booking journey for OFFICE/ONLINE/HOME_VISIT.
- Focused M4B verification passes: 23 Unit/Feature tests/93 assertions; focused PostgreSQL assignment/pending/race coverage passes with 10 tests/20 assertions. Final `make quality` passes with 13 Unit tests/28 assertions, 106 Feature tests/587 assertions, Pint, Larastan 0 errors, vue-tsc/Vite, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities; the new page's auto-fixable ESLint warnings were corrected before the final CI gate. Final `make ci` passes with healthy PostgreSQL/Redis, clean ESLint, and 40 PostgreSQL integration tests/78 assertions. Final Playwright passes 4/4 on desktop and mobile, including the interactive client booking journey.
- Exact-SHA hosted CI run 31654103577 passes quality/integration, Playwright desktop/mobile, Docker runtime health, and privacy/secret scan for accepted M4B.

## Milestone 4C — final Scheduling / Booking lifecycle

- Accepted M4B base is 2bf8d502b204f982205ab466fa1c4e7c6a4873e5; the separate backlog-normalization documentation commit is ce2110a. M4A/M4B remain accepted and were not re-audited.
- Resolved OQ-002 with an organization-configurable cutoff (Phase 1 default 1440 minutes), reasoned staff override, pending HOME_VISIT withdrawal, stable identity/calendar UID reschedule, and no M4 payment/refund side effects.
- Resolved OQ-010 with reusable future-booking impact calculation, explicit CRM acknowledgement, derived needs-attention visibility, audit evidence, and preservation of existing bookings.
- Resolved OQ-011 with typed terminal NO_SHOW after scheduled start for authorized staff; resolved OQ-012 with one organization-level Phase 1 office and booking-specific HOME_VISIT destination.
- Added PostgreSQL-backed booking idempotency with request-hash replay protection and retention pruning; lifecycle actions/events for cancel/reschedule/confirm/complete/no-show/meeting URL; client My bookings/history/timezone surfaces; CRM management; and HOME_VISIT/ONLINE completion boundaries. No M5+ behavior was started.
- Final local verification passes: focused M4 lifecycle/scheduling coverage 35 tests/163 assertions plus 4 scheduling Unit tests/7 assertions; focused PostgreSQL coverage 10 tests/22 assertions including a process-level competing-booking race; `make quality` 13 Unit tests/28 assertions and 122 Feature tests/664 assertions with clean Pint, Larastan, ESLint, vue-tsc, and Vite; `make ci` 40 PostgreSQL integration tests/78 assertions with healthy PostgreSQL/Redis, Composer audit with 0 advisories, and npm audit with 0 vulnerabilities; Playwright 6/6 desktop/mobile tests.
- Exact-SHA hosted CI run 31691868383 passed for 048c193a37bb2da110a1340093a3d8cb4c443916: quality/integration, Playwright desktop/mobile, Docker build/runtime health, and privacy/secret scan.

## Milestone 4 final-review remediation

- Remediation started from independent-review SHA 4e0b0685e5f62c6973969f1f373b14af79e2490d. M0–M3 were not re-audited and M5+ was not started.
- Fixed M4-01 through M4-14: optimistic event-version reschedule conflicts; required durable booking idempotency and replay-before-mutable checks; CRM booking creation; direct Service Catalog gates; bound schedule-impact projection/acknowledgement including exception deletion; structural needs-attention; safe CRM BookingEvent projection; display-local date filtering; current client-timezone surfaces; locked Specialist impact state; atomic scheduling configuration; and accurate audit labels.
- Adverse focused verification passes: 58 Unit/Feature tests/256 assertions; PostgreSQL 13 tests/34 assertions including real process-level same-Booking reschedule and same-key creation races plus BookingEvent DELETE immutability; DST spring-gap/fall-overlap Unit coverage; CRM and Portal regressions.
- `make quality` passes: 15 Unit tests/32 assertions, 139 Feature tests/746 assertions, clean Pint/ESLint/Larastan/vue-tsc/Vite, Composer audit with 0 advisories, npm audit with 0 vulnerabilities. `make ci` passes with healthy PostgreSQL/Redis and 43 integration tests/90 assertions. Playwright passes 6/6 desktop/mobile.
- Final implementation SHA: 289ec953cd4f4d11b0a5b8cf803dbfdd064d6a7e. Hosted exact-SHA CI run 31701386393 passed privacy/secret scan, quality/integration, Docker runtime health, and Playwright desktop/mobile. `.codex/config.toml` remains unchanged; M5+ remains untouched.

## Important Decisions

- Docker build context is deny-by-default; only reviewed runtime Dockerfiles are allowed.
- Routine setup never rotates a nonblank `APP_KEY`; deliberate rotation is a separate authorized operation.
- M0 hosted CI separates quality/integration, Playwright, privacy/secret, and Docker runtime gates for diagnosability.
- Client source documents and full chat history remain local-only; normalized REQs and sanitized repository docs are the Git development source.
- M1 keeps `organization_id` as the security boundary, derives runtime context from server configuration/membership, and does not introduce `master_id`, heavy tenancy, SaaS provisioning, or provider integrations.
- M1 credentials use Laravel encrypted casts and are only exposed through masked representations; audit metadata is action-allowlisted before persistence.

## Latest Verified Local Quality Gate

2026-08-13: Final M4 narrow remediation evidence includes 6 new Feature tests/55 assertions; `make quality` with 15 Unit tests/32 assertions and 145 Feature tests/801 assertions; `make ci` with 43 PostgreSQL integration tests/91 assertions; and Playwright 6/6 desktop/mobile tests. Hosted exact-SHA CI run `31707507497` passed all jobs for `9730d1fca0137c063deabf853b94e80f54a0936`. Independent Sol final recheck accepted M4 and issued `GO FOR MILESTONE 5`. M0–M3 were not re-audited.

## Milestone 4 final narrow remediation

- Started from accepted remediation SHA `cc5e013a546ff7e6ce0dd424e9b9e4d7e501e507`; scope was limited to M4-06, M4-R01, and M4-R02. M0–M3 were not re-audited and M5+ was not started.
- Added a reusable Filament schedule-impact preview/acknowledgement handoff across scheduling mutations, including quick Specialist deactivation and exception deletion. The digest and safe affected-booking projection are retained server-side; stale acknowledgements, including non-empty-to-empty changes, are rejected.
- Synchronized same-page Portal reschedule/timezone state and rotated booking idempotency keys only after accepted creation. Added transition-aware display-local day boundaries for DST midnight gaps/overlaps, with Cairo, Havana, Santiago, Berlin, and large-offset coverage.
- Narrow remediation verification passes: 6 new Feature tests/55 assertions; `make quality` 15 Unit tests/32 assertions and 145 Feature tests/801 assertions; `make ci` 43 PostgreSQL integration tests/91 assertions; Playwright 6/6 desktop/mobile.

## Milestone 4 closeout

- Milestone 4 is CLOSED and accepted after final remediation and independent Sol recheck. No remaining acceptance blocker or remediation requirement remains.
- Final accepted M4 implementation SHA: `9730d1fca0137c063deabaf853b94e80f54a0936`.
- Hosted exact-SHA CI run `31707507497` passed for the accepted implementation SHA.
- Independent Sol final decision: `GO FOR MILESTONE 5`.
- Milestone 5 — Scenario / Notification Engine is next and has not started; no M5 implementation work is recorded.
- Non-blocking M4-15 follow-up: a future focused regression may add a direct fresh-booking attempt with Service Catalog disabled and assert that no `Booking`, `BookingEvent`, or `AuditEvent` is created. This is not an M4 acceptance blocker and does not reopen M4.

## Milestone 5A — Scenario / Notification Engine foundation

- Started from the M4 documentation closeout commit `7b94712f3b50089e221ff7d42ee0327bb89b38e3`; accepted M4 implementation SHA `9730d1fca0137c063deabaf853b94e80f54a0936` remains closed and is not being re-audited.
- The M5A plan was created at the starting base and completed. M5A now provides the durable organization-scoped scenario event/outbox boundary, typed configurable rules and allowlisted conditions, localized immutable template versions, verified recipient/channel resolution, durable scheduled actions, idempotent delivery attempts, CRM configuration/history, and one Booking `COMPLETED` vertical slice.
- Booking completion writes the ScenarioEvent in the same transaction as Booking/BookingEvent/AuditEvent mutation. PostgreSQL state/row locks claim events, actions, and delivery attempts; queue jobs carry identifiers only; Redis/Horizon is not the correctness source.
- Rules and templates are versioned through Application actions with allowlisted AuditEvent metadata. Actions snapshot source event, rule version, recipient, channel order, exact template version, render context, and schedule instant. Delivery outcomes are typed, fallback is ordered, retries retain the durable key, and current-state changes suppress history without deletion.
- Filament provides organization-scoped rule/template configuration and safe scenario-action history. Livewire persistent middleware preserves server-derived organization context for CRM mutations. Staff may inspect scenario history; management mutations require the scenario permission.
- Final local M5A verification: `make quality` passes with 21 Unit tests/43 assertions and 158 Feature tests/868 assertions; Pint, ESLint, Larastan (0 errors), vue-tsc, Vite, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. `make ci` passes with healthy PostgreSQL/Redis and 45 PostgreSQL integration tests/100 assertions. Full Playwright passes 8/8 desktop/mobile tests, including the new scenario CRM flow. Focused M5A PostgreSQL concurrency passes 2/2 tests/9 assertions.
- M5B and later milestones remain unstarted. No Finance, Medical, Surveys, RAG, AI, Broadcasts, subscriptions, payments, MAX, Instagram, calendar/video provider, or production-hardening behavior is in scope.
- The normalized requirements do not define the conditional +72h seed condition. It remains a narrow M5B owner decision; M5A does not guess or implement it.

## Milestone 5A narrow remediation

- Started from reviewed implementation SHA `7633eef136a3280ed7a2348598f2af67b3fe9f2b`; scope remained limited to the six confirmed M5A blockers.
- Retry exhaustion now terminalizes only the exhausted delivery and continues to an eligible fallback. Stale pre-send claims and states between terminal primary and pending fallback return to durable retry discovery, while a genuinely in-flight unknown attempt remains terminally uncertain.
- Internal delivery revalidates active same-organization membership and verified identity. Typed condition configuration rejects malformed structures and invalid booking-status/language values and fails closed during execution.
- ScenarioActions persist the materialized typed condition snapshot, so later rule edits do not rewrite older action semantics. Filament template edits derive immutable identity from the scoped record, create the next immutable version, and preserve existing action pins.
- Focused M5A remediation and prior M5A Unit/Feature coverage passes with 41 tests/145 assertions. Focused PostgreSQL process-concurrency coverage passes with 2 tests/9 assertions. `make quality` passes with 27 Unit tests/49 assertions and 174 Feature tests/929 assertions; Pint, ESLint, Larastan (0 errors), vue-tsc, Vite, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. `make ci` passes with 45 PostgreSQL integration tests/100 assertions. Focused scenario Playwright passes 2/2 desktop/mobile tests including Filament template version editing.
- The Telegram blank-token classification follow-up was not changed. M5B/M6+ remain unstarted, the undefined +72h condition remains unimplemented, and `.codex/config.toml` remains unchanged.

## Milestone 5A closeout

- Milestone 5A is CLOSED / ACCEPTED after final independent recheck. All six confirmed M5A blockers are independently verified fixed.
- Final accepted M5A implementation SHA: `a20379cda0068873f275c86e593eb727436500ed`.
- Hosted exact-SHA CI run `31736555888` passed on its first attempt: quality/integration, Playwright desktop/mobile, privacy/secret scan, and Docker runtime health.
- M5B remains unstarted. Staging review deployment is available through the guarded `make deploy-staging` target; production `make deploy` and `make rollback` remain blocked until M16.

## Product UX / staging review pass

- Started from `37dcae3bbf40edb58924c6ca5675569a85c5e554`; productized the existing Russian Portal and Filament CRM surfaces without starting M5B or later functionality. Technical values are now hidden, translated, or derived at the trusted Application boundary while identity verification, organization isolation, idempotency, concurrency, audit, immutable revisions, scheduling, and durable scenario guarantees remain in place.
- Added the durable `.ai/rules/product-ui.md` rule and path mapping, server-side booking idempotency derivation, Russian business labels/statuses/errors, human-friendly timezone controls, safe scenario/template/content controls, focused Portal/CRM regressions, and desktop/mobile Playwright coverage.
- Final local verification on `33fd6c59d461a7f54d778e1bc355e820c1840ca3`: `make quality` passes with 27 Unit tests/49 assertions and 189 Feature tests/1046 assertions; Pint, ESLint, PHPStan (0 errors), vue-tsc, Vite, Composer audit (0 advisories), and npm audit (0 vulnerabilities) pass. `make ci` passes with healthy PostgreSQL/Redis and 45 Integration tests/100 assertions. Full Playwright passes 14/14 desktop/mobile; focused Portal/CRM/Scenario PHPUnit coverage passes 62/62 tests/369 assertions.
- The first staging attempt safely rolled back after a false host-listening-port comparison caused by trailing whitespace dependent on PID width. The normalizer was corrected and syntax-checked; the second deployment succeeded at exact SHA `33fd6c59d461a7f54d778e1bc355e820c1840ca3`.
- Real staging smoke passed for health, database/pgvector/Redis/private storage, Horizon, nginx/protected-service baselines, Russian Portal entry (`/`), Telegram/email controls, CRM login (`/admin/login`), authenticated-route guards, and Telegram web status expiry. Staging host: `https://crm.psysoldatov.ru`.
