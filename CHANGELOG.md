# Changelog

All notable implementation changes are recorded here. Requirement changes belong in `docs/product/requirements-changelog.md`; architectural rationale belongs in ADRs.

## [Unreleased]

### Added

- Laravel 13 modular-monolith foundation with Filament 5, Vue 3/Inertia 3/TypeScript, PostgreSQL/pgvector, Redis/Horizon, Laravel AI SDK, Nutgram, Docker Compose, CI, and reproducible lockfiles.
- Server-derived Organization context, private storage default, health endpoint, and an organization-scoped Service vertical slice shared by CRM and Client Portal.
- Repository harness, normalized requirements, architecture/operations/testing documentation, ADRs, progressive Codex skills, and quality commands.
- Milestone 0 audit regressions for Docker-context privacy, repeat-safe key initialization, Filament tenant/workflow behavior, Nutgram fake loading, privileged User fields, and Horizon metrics scheduling.
- Milestone 1 organization memberships and explicit RBAC, typed organization settings and feature controls, client/channel identity and consent foundations, encrypted rotatable credentials, safe audit events, redacted logs, and server-side isolation tests.

### Changed

- Composer package metadata identifies the private client project as proprietary.
- Hosted CI now separates quality/integration, Playwright desktop Chromium/mobile WebKit, privacy/secret scanning, and Docker runtime health/Horizon gates.
- Oversized AI SDK and Inertia skills now route to version-aware Boost documentation instead of embedding package manuals.
- Setup preserves an existing `APP_KEY`; host/Compose URL documentation consistently uses port 8000.

### Security

- Organization isolation tests, admin access foundation, private filesystem configuration, safe AI fakes, and dependency audit gates.
- Docker build context is deny-by-default, Gitleaks scans reachable history and Git-relevant working files, privileged User fields are not mass assignable, and private source-history policy is explicit in the master plan.
