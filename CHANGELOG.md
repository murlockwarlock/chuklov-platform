# Changelog

All notable implementation changes are recorded here. Requirement changes belong in `docs/product/requirements-changelog.md`; architectural rationale belongs in ADRs.

## [Unreleased]

### Added

- Laravel 13 modular-monolith foundation with Filament 5, Vue 3/Inertia 3/TypeScript, PostgreSQL/pgvector, Redis/Horizon, Laravel AI SDK, Nutgram, Docker Compose, CI, and reproducible lockfiles.
- Server-derived Organization context, private storage default, health endpoint, and an organization-scoped Service vertical slice shared by CRM and Client Portal.
- Repository harness, normalized requirements, architecture/operations/testing documentation, ADRs, progressive Codex skills, and quality commands.

### Changed

- Composer package metadata identifies the private client project as proprietary.

### Security

- Organization isolation tests, admin access foundation, private filesystem configuration, safe AI fakes, and dependency audit gates.
