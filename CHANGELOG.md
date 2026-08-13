# Changelog

All notable implementation changes are recorded here. Requirement changes belong in `docs/product/requirements-changelog.md`; architectural rationale belongs in ADRs.

## [Unreleased]

### Added

- Milestone 4A scheduling foundation: organization-scoped specialist working hours, date exceptions, unavailable periods, configurable lead time, UTC/IANA availability projections, typed visit formats, booking persistence, immutable booking events, PostgreSQL interval conflict protection, CRM scheduling configuration, and a client-safe Portal availability read path.
- Milestone 4B booking lifecycle: explicit organization-scoped Specialist-Service assignments, assignment-safe availability and booking creation, non-blocking HOME_VISIT review requests, protected approval/rejection actions, immutable lifecycle events, organization-scoped CRM booking surfaces, and the shared Portal Service → Specialist → slot → format flow.
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
