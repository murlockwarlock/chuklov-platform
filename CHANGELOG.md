# Changelog

All notable implementation changes are recorded here. Requirement changes belong in `docs/product/requirements-changelog.md`; architectural rationale belongs in ADRs.

## [Unreleased]

- CRM Performance & Staging Runtime Remediation:
  - Enabled Filament SPA mode (`->spa()`) with binary attachment and receipt download URL exceptions in `AdminPanelProvider`.
  - Replaced single-threaded PHP CLI built-in web server with production-like concurrent `php:8.5.9-fpm-bookworm` + Nginx container runtime on port 8000 with clean PHP 8.5 OPcache configuration and a fail-fast PID 1 process supervisor.
  - Added repository-tracked staging host Nginx reverse proxy template in `docker/host-nginx/chuklov-staging-tls.conf` serving static fingerprinted assets (`/build/*`), fonts, brand, and published vendor styles directly with long-lived immutable cache headers while proxying dynamic application endpoints.
  - Eliminated duplicate reads and decryption on Client View infolist via request-scoped memoization in `GetMedicalProfile` (keyed by `actor_id:org_id:client_id`) with strict per-read authorization evaluation and cache invalidation in `UpdateMedicalProfile`.
  - Remediated repeated queries in CRM Clients and Bookings lists by resolving organization context directly in `ClientPolicy` and `BookingPolicy`, request-scoped memoization in `OrganizationFeatureGate` and `GetBookingLeadTime`, and context-scoped cache invalidation.
  - Added regression test suite `tests/Feature/PerformanceBoundedQueryTest.php` and Playwright E2E SPA navigation test verifying DOM preservation across route transitions.

- M7A Medical Profile + Medical Encryption + Private Attachment Security Foundation implementation candidate:
  - ADR-017 accepted and recorded.
  - Forward-only additive PostgreSQL migrations for `medical_profiles` and `medical_attachments` with composite foreign keys `(organization_id, client_id)` referencing `clients(organization_id, id)` and `(organization_id, uploaded_by_user_id)` referencing `organization_memberships(organization_id, user_id)`.
  - Dedicated versioned medical encryption secret outside database/APP_KEY dependency via `config/medical.php`, with `MedicalKeyResolverInterface` / `AppKeyMedicalKeyResolver` and `MedicalEncryptorInterface` / `MedicalDataEncryptor` supporting key versioning and rotation.
  - Class C sensitive clinical fields (`anamnesis`, `complaints_goals`, `operations_injuries`, `medicines`, `supplements`) encrypted at rest with AES-256-CBC and HMAC-SHA256 authenticated envelopes.
  - Application actions `GetMedicalProfile` and `UpdateMedicalProfile` with strict organization authorization and length validation (<= 10,000 chars per field).
  - Private attachment storage on disk `private` under UUID-named paths `medical/attachments/{organization_id}/{uuid}.{ext}` via `AttachmentStorageInterface` / `PrivateMedicalAttachmentStorage` with configurable file size limit (`MEDICAL_ATTACHMENT_MAX_BYTES`, default 20 MB recorded in ASM-008).
  - Server-side MIME sniffing (`finfo`), explicit raw DICOM rejection (magic bytes `DICM` at offset 128, MIME, and extension), and SHA-256 checksum tracking.
  - Attachment taxonomy restricted to confirmed types: `MedicalReport` ('medical_report') and `PosturePhoto` ('posture_photo').
  - Fail-closed runtime scanner `FailClosedAttachmentScanner` quarantining unconfigured uploads; deterministic scanner `LocalDeterministicAttachmentScanner` retained solely as a test fake.
  - Authorized temporary signed download URL generation `GetTemporaryAttachmentUrl` and controller `AdminMedicalAttachmentController` (`GET /admin/attachments/{uuid}`) requiring valid signatures, staff authorization, and cleared scan status without exposing storage paths.
  - Clean client save boundary in CRM: ordinary client profile editing never touches medical ciphertext; medical profiles are managed via dedicated modal action and explicit application service.
  - Security allowlists updated in `RecordAuditEvent` (`medical.profile.created`, `medical.profile.updated`, `attachment.uploaded`, `attachment.downloaded`); Monolog log redactor `RedactSensitiveLogData` updated to mask medical field names; scan metadata sanitized to allowlist keys.
  - Comprehensive unit, feature, PostgreSQL integration, and Playwright tests. M7B Session Cockpit and M8+ remain unstarted.

- M6 is recorded as CLOSED / ACCEPTED after independent remediation re-review returned GO FOR M6 CLOSEOUT. Accepted application SHA `72b4987124c4897bb6fe34bd74443848f6f85eea`, hosted exact-SHA CI run `31884758963`, and staging SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` match; the rollout/currency configuration and mixed-FX open-balance blockers are CLOSED. `REQ-CURRENCY-001`–`REQ-CURRENCY-003` and `REQ-PAYMENT-001`–`REQ-PAYMENT-005` are accepted for M6; `REQ-PAYMENT-006`/OQ-005 remain future M13 work, OQ-014 remains OPEN, and M7+ are NOT STARTED.

- M6 Finance / Multi-Currency implementation candidate: exact integer-minor-unit Money with centralized currency precision, organization-scoped base/display/payment/settlement configuration, manual FX rates and immutable conversion snapshots, fixed-price precedence, completed-booking financial obligations, append-only payment ledger with corrections, manual and partial payments, derived receivables, private receipt evidence, deterministic fake PaymentGateway initiation/settlement/reconciliation, and CRM/Client Portal finance projections.
- M6 reuses the existing Scenario Engine for organization-scoped debt events and configurable outstanding-debt conditions with execution-time rechecks; no second reminder or delivery subsystem was added. The implementation uses no real payment provider, leaves `REQ-PAYMENT-006`/OQ-005 for M13, preserves accepted M5B behavior, and does not start M7+.
- M6 verification reached hosted exact-SHA CI run `31877550150` for application SHA `b0bacaf4130f192f95dea07744f8560ebaf4b611` and guarded staging verification of finance, CRM, Portal, runtime, and responsive surfaces.
- M6 remediation candidate adds an organization-scoped, idempotent currency bootstrap for existing priced Services, atomic validation of required directed FX paths, and immutable obligation-snapshot valuation for open balances. Payment-time FX snapshots remain historical ledger evidence; invalid valuation snapshots fail visibly and Portal/Scenario/CRM projections no longer mask negative derived values.
- M6 remediation application SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` passed exact hosted CI run `31884758963` (Quality/integration `95012327744`, Privacy/secret scan `95012327712`, Docker/runtime health `95012327769`, Playwright desktop/mobile `95012327733`).
- The exact remediation SHA `72b4987124c4897bb6fe34bd74443848f6f85eea` was deployed through the guarded staging path without resetting PostgreSQL. Staging inventory had one organization with no priced Services or Finance configuration; a rollback-safe temporary pre-M6 fixture proved bootstrap/repeat safety, atomic missing-FX rejection, confirmed Booking completion, obligation-snapshot valuation across rates `90` → `200`, Portal/CRM/Scenario projections, and zero after full settlement. No real provider, payment, reminder, or delivery was used.
- M5B scenario-family integration on the accepted M5A engine: configurable post-session +24h/+48h/conditional +72h seeds, internal onboarding re-engagement via typed `onboarding.started` state, configurable retention/no-next-booking conditions, bounded repeat action snapshots, verified internal member/role recipients, and safe CRM scenario/history projections. The exact +72h business condition remains OQ-014; no survey, AI, broadcast, payment, or later-milestone behavior was added.
- M5B implementation candidate `736a78b5fbd5b51e5c9f9b6c5b50ff974a510457` passed hosted CI and guarded staging verification, including the additive migration, idempotent twice-run seed, healthy Horizon/scheduler/Telegram runtime, and authenticated CRM configuration/history smoke without external delivery.
- Authenticated CHUKLOV Client Portal home, branded desktop/mobile shell, reusable booking/service/profile components, direct Profile destination, and RU/EN locale switching with persisted client preference.
- Organization-scoped direct Profile consent presentation that retains immutable exact-version legal evidence without exposing a mandatory onboarding wizard.
- Ordinary-browser Telegram authentication through a short-lived, single-use, browser-bound bot deep link, resolving the same verified organization-scoped Client as the Mini App while retaining passwordless email as an alternative.
- Telegram Mini App entry now submits verified initData automatically once, with a localized recoverable failure state instead of a second manual login step.
- Portal URL generation now honors the HTTPS scheme forwarded by the isolated reverse proxy.
- Telegram `/start` is registered with the Russian `Запустить приложение` description.
- Durable client/CRM copy rules that exclude developer terminology and generic product filler from ordinary user interfaces.
- Exact-revision isolated staging deployment automation with preflight baselines, validated staging database backup, atomic release switching, runtime refresh, health checks, unrelated-service comparisons, and application rollback.
- Portal service cards now support optional configured imagery, use the refreshed CHUKLOV logo/favicon assets, and present a compact reference-inspired booking flow with grouped date/time slots.
- Portal booking now follows a focused service/specialist/format/calendar/confirmation interaction: service rows replace the primary dropdown, only the selected calendar day renders time buttons, month navigation reloads authoritative availability, and slot end times stay out of ordinary client copy.
- Rebuilt the Portal booking presentation around the approved CHUKLOV reference: contained booking card, responsive dot stepper, selectable rows/chips, separated calendar and selected-day time surfaces, readable confirmation summary, and narrow Mini App layouts without clipping or horizontal overflow.

- Milestone 5A Scenario / Notification Engine foundation: organization-scoped PostgreSQL scenario events, typed configurable rules and conditions, localized immutable template versions, verified client/internal recipient resolution, ordered channel fallback, durable scheduled actions, idempotent delivery attempts, PostgreSQL-safe worker claiming, CRM configuration/history, and the Booking `COMPLETED` transactional follow-up slice. M5B scenarios and the undefined +72h condition remain out of scope.

- Milestone 4A scheduling foundation: organization-scoped specialist working hours, date exceptions, unavailable periods, configurable lead time, UTC/IANA availability projections, typed visit formats, booking persistence, immutable booking events, PostgreSQL interval conflict protection, CRM scheduling configuration, and a client-safe Portal availability read path.
- Milestone 4B booking lifecycle: explicit organization-scoped Specialist-Service assignments, assignment-safe availability and booking creation, non-blocking HOME_VISIT review requests, protected approval/rejection actions, immutable lifecycle events, organization-scoped CRM booking surfaces, and the shared Portal Service → Specialist → slot → format flow.
- Milestone 4C final booking lifecycle: organization/actor-scoped idempotent creation, configurable cutoff-aware cancellation/rescheduling, stable booking identity/history, completion and NO_SHOW outcomes, HOME_VISIT withdrawal/approval payment handoff, ONLINE manual-link management, client booking history, CRM lifecycle actions, and explicit schedule-mutation impact acknowledgement.
- Milestone 3 organization-scoped CRM management for Clients, Specialists, localized managed content, and the existing Service catalog, including audited profile/restriction mutations, membership-backed specialist staff links, IANA specialist timezones, catalog type/configuration, and integer minor-unit pricing.
- Laravel 13 modular-monolith foundation with Filament 5, Vue 3/Inertia 3/TypeScript, PostgreSQL/pgvector, Redis/Horizon, Laravel AI SDK, Nutgram, Docker Compose, CI, and reproducible lockfiles.
- Server-derived Organization context, private storage default, health endpoint, and an organization-scoped Service vertical slice shared by CRM and Client Portal.
- Repository harness, normalized requirements, architecture/operations/testing documentation, ADRs, progressive Codex skills, and quality commands.
- Milestone 0 audit regressions for Docker-context privacy, repeat-safe key initialization, Filament tenant/workflow behavior, Nutgram fake loading, privileged User fields, and Horizon metrics scheduling.
- Milestone 1 organization memberships and explicit RBAC, typed organization settings and feature controls, client/channel identity and consent foundations, encrypted rotatable credentials, safe audit events, redacted logs, and server-side isolation tests.
- Milestone 1 remediation for PostgreSQL Filament context regression, expand/contract membership migration safety, atomic audited mutations, action-allowlisted audit metadata, service-catalog entitlement enforcement, complete authentication-header log redaction, and privileged-state mass-assignment hardening.
- Milestone 2 shared responsive Client Portal foundation, versioned progressive onboarding, server-verified Telegram Mini App sessions, organization-scoped verified channel identity linking, localized Telegram bot entry, capability-aware channel boundary, and normalized conversation/message persistence.
- M2 Client Portal visual foundation with centralized warm-neutral/sage tokens, responsive layout primitives, premium wellness surfaces, accessible controls, buttons, notices, stages, and form states shared by portal pages.
- M2 passwordless email authentication with normalized hashed one-time codes, expiry, bounded attempts/rate limits, provider-neutral mail delivery, and Laravel session regeneration.
- M2 Telegram connection tokens with authentic Nutgram bot evidence, replay/expiry protection, organization/client binding, and deterministic identity uniqueness.
- M2 organization-scoped versioned legal documents, immutable published content, exact-version consent evidence, platform-controlled legal-management readiness, and centralized IANA date/time conventions with PostgreSQL timezone-aware instants.

### Changed

- Portal booking and rescheduling calendars now load the complete visible calendar month from server-authoritative availability, including policy-valid dates before the existing booking date; outside-month dates remain visibly distinct and unavailable.
- Client-visible booking, availability, rescheduling, cancellation, and timezone failures now use the shared RU/EN error mapping instead of exposing Russian-only action errors to English Portal clients.
- The 320px multi-specialist/multi-format booking path preserves readable selected service, specialist, and format context through choice, calendar, confirmation, and success states without horizontal overflow or ellipsis.
- Telegram browser-login `/start web_…` commands no longer fall through to the generic Telegram-link handler after successful authentication.
- The Portal header, browser favicon, Apple touch icon, and Filament favicon now use the supplied designer CHUKLOV logo assets; legacy and generated brand assets are removed.
- The booking time view now hides redundant one-format context controls, keeps progress labels intact at 320px, and uses a stacked mobile confirmation summary to preserve the reference composition and readable touch layout.
- A confirmed booking now ends in a dedicated result state with the selected service, specialist, date/time, and format; the calendar and availability list no longer remain underneath the success message, and booking headings/time context have explicit spacing.
- Booking details now stay compact until the client explicitly starts a reschedule; rescheduling uses the shared calendar and selected-day time grid, while the home booking card no longer shows a non-interactive reschedule label.
- Booking detail times now render once in the client's display timezone, and technical timezone/payment controls are no longer presented as part of the ordinary client action flow.
- The Portal shell now uses the full CHUKLOV logo with its inscription; the same full-mark raster is used for the browser, Apple, and Filament admin icons.
- Removed the visible four-stage client onboarding journey and generic post-authentication Continue entry points. Internal onboarding progress remains available to Application state and legacy mutation compatibility, while optional profile data is progressive and action-specific requirements remain at their business boundary.
- Authentication now redirects directly to the authenticated Portal home; login/provider status is absent from the authenticated home, and the shell leads with committed CHUKLOV brand assets.
- Portal UI strings use one RU/EN localization dictionary with a visible shell switcher; organization-owned service/legal content falls back without fabricated translations.
- Service entry links preserve the selected service, and the Portal auto-selects the only bookable specialist while retaining a selector for organizations with multiple specialists.
- Productized the existing Russian Portal and CRM journeys: removed milestone, runtime, provider, timezone, event-ledger, version, and technical-key leakage; derived booking idempotency server-side; translated statuses, errors, scheduling controls, templates, content, and CRM fields into business language; and kept the underlying security, concurrency, audit, immutable revision, and durable workflow guarantees unchanged.

- Simplified the unauthenticated Portal entry to Russian client-facing login controls and changed the bot `/start` command description to `Запустить приложение`.
- M5A remediation preserves materialized rule-condition semantics, continues ordered fallback after retry exhaustion, safely recovers stale pre-send work, revalidates active internal membership at delivery, rejects invalid typed conditions, and restores immutable template version editing through Filament.
- Filament organization resolution is persistent across Livewire updates, preserving server-derived tenant authorization for scenario configuration mutations; unsupported condition types are rejected and explicit suppressed delivery outcomes close actions without fallback.
- Milestone 3 managed content now permits multiple organization-scoped records per section and locale while preserving ordered portal rendering through a non-unique lookup index.
- Composer package metadata identifies the private client project as proprietary.
- Hosted CI now separates quality/integration, Playwright desktop Chromium/mobile WebKit, privacy/secret scanning, and Docker runtime health/Horizon gates.
- Oversized AI SDK and Inertia skills now route to version-aware Boost documentation instead of embedding package manuals.
- Setup preserves an existing `APP_KEY`; host/Compose URL documentation consistently uses port 8000.

### Security

- Organization isolation tests, admin access foundation, private filesystem configuration, safe AI fakes, and dependency audit gates.
- Docker build context is deny-by-default, Gitleaks scans reachable history and Git-relevant working files, privileged User fields are not mass assignable, and private source-history policy is explicit in the master plan.
- Telegram initData is signature-verified through Nutgram, freshness/replay checked, never trusted from frontend identity fields, and excluded from audit metadata; client sessions resolve only through the server-derived organization context.
- Email auth codes and Telegram connection tokens are short-lived, single-use or bounded, hashed at rest where applicable, absent from audit metadata, and do not select organization/client context from request input.
