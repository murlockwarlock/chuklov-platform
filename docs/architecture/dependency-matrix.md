# Dependency Matrix

Verified 2026-08-12 from official documentation and installed lockfiles. Exact transitive versions remain authoritative in `composer.lock` and `package-lock.json`.

| Component | Locked/runtime version | Governance / compatibility evidence |
|---|---:|---|
| PHP | 8.5.9 | Laravel 13 official installation recommends PHP 8.5; app constraint `^8.3` |
| Composer | 2.10.2 | Host and Docker builder pinned |
| Laravel application skeleton | 13.8.0 | Official `laravel/laravel` scaffold |
| Laravel Framework | 13.25.0 | `composer.lock`; official 13.x docs |
| Filament | 5.7.6 | Requires PHP 8.2+, Laravel 11.28+, Livewire 4+, Tailwind 4+ |
| Livewire | 4.4.0 | Resolved by Filament 5 |
| Inertia Laravel / Vue adapter | 3.3.1 / 3.6.1 | Official v3 docs; PHP 8.2+, Laravel 11+ |
| Vue | 3.5.41 | `package-lock.json` |
| TypeScript / vue-tsc | 5.9.3 / 3.3.9 | `package-lock.json` |
| Tailwind CSS / Vite plugin | 4.3.3 / 4.3.3 | Filament 5 compatible; `package-lock.json` |
| Vite / Laravel Vite plugin | 8.2.1 / 3.2.0 | `package-lock.json` |
| PostgreSQL | 18 (Compose) | pgvector official supported image |
| pgvector | 0.8.2 | Official `pgvector/pgvector:0.8.2-pg18-trixie` image |
| Redis | 8.2.2 | Official image pinned for dev/CI |
| Laravel Horizon | 5.48.3 | Official Laravel 13 Horizon docs |
| Laravel AI SDK | 0.10.3 | Official Laravel 13 AI SDK docs and fake API |
| Nutgram / Laravel bridge | 4.49.1 / 1.7.1 | Official Nutgram Laravel integration; resolves on Laravel 13 |
| Laravel Boost | 2.5.3 dev-only | Official Laravel 13 Boost docs; supports Laravel 10–13 |
| Larastan / PHPStan | 3.10.0 / 2.2.8 dev-only | Level 8 project gate |
| PHPUnit | 12.5.33 | Laravel scaffold lock |
| Node host / container | 25.8.1 / 24.6.0 | Host inspection; reproducible Compose/CI uses Node 24.6.0 |
| npm | 11.11.0 | Sole JS package manager |
| ESLint / TypeScript ESLint | 9.39.5 / 8.67.0 | `package-lock.json` |
| Playwright | 1.62.1 | Infrastructure only in Milestone 0 |

Major/minor upgrades require a separate compatibility review and full regression gate. Do not silently downgrade examples or packages.

Local application data uses `chuklov`; integration tests use the isolated `chuklov_test` database created during initial PostgreSQL container initialization.
