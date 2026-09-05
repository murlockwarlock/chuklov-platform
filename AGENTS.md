# Chuklov Repository Guide

## Start Here

Current phase: Phase 1. Current milestone: see `PROJECT_STATUS.md`.

Load context progressively:

1. Read this file and `PROJECT_STATUS.md`.
2. Find the relevant `REQ-*` rows in `docs/product/requirements.md`.
3. Read only the linked architecture document, ADR, skill, code, and tests.
4. Open private immutable sources, when available locally, or the master plan only for provenance disputes, scope changes, or unresolved ambiguity.

Laravel Boost is the primary version-aware Laravel source. Check lockfiles first, then use Boost `search-docs` before relying on version-sensitive Laravel, Filament, Livewire, Inertia, Tailwind, Horizon, or AI SDK APIs.

## Source of Truth

Use this precedence:

1. Latest confirmed client business behavior: v2.2, client changelog, owner-confirmed decisions.
2. `docs/product/requirements.md`.
3. `CHUKLOV_MASTER_DEVELOPMENT_PLAN_V5_2_FINAL.md` for implementation architecture.
4. Accepted ADRs.
5. Installed code, migrations, contracts, and tests.
6. Official documentation for installed versions.
7. Historical AI-generated technical suggestions as context only.

Client documents define business requirements. The master plan and ADRs define technical architecture. Private source materials under `docs/product/source-requirements/` are local-only and must never be committed.

When sources genuinely conflict and existing architecture does not resolve them, add a precise entry to `docs/product/open-questions.md` and continue unblocked work. Never silently reinterpret scope.

Project `.codex/config.toml` contains durable repository MCP configuration only. Do not modify it for transient or session-specific MCP approval decisions; user-specific approval preferences belong in the user's Codex configuration.

## Architecture Invariants

- Modular monolith; no microservices without an accepted ADR and demonstrated operational need.
- Phase 1 is single-organization runtime with a multi-organization-ready data model.
- `organization_id` is the tenant/security boundary. `master_id` never is.
- Organization context is resolved server-side; never trust request-supplied organization IDs.
- HTTP, Filament, Vue, Telegram, jobs, and adapters call the Application layer.
- Business logic does not live in controllers, Filament Resources, Vue components, channel handlers, adapters, or queue jobs.
- Dependencies point HTTP/adapters → Application → Domain; Infrastructure implements ports.
- CRM, responsive web, mobile web, and Telegram Mini App share one application/domain core.
- Telegram is a channel adapter, not the product core.
- External integrations use explicit adapters and idempotent Inbox/Outbox when implemented.
- Durable claims and ownership fences are established in PostgreSQL before external I/O; external calls do not run under long-lived row-locking transactions.
- Any bounded multi-step operation shares one absolute deadline; provider/SDK retries and queue timeout ordering are part of its safety contract.
- Business timings and managed content are configuration, not hardcoded behavior.
- Booking and payment states remain separate.
- Payments use an auditable ledger and server-side confirmation.
- AI is organization-owned, provider-independent, structured, and clearly distinct from confirmed specialist facts.
- RAG is organization-scoped. Tenant clinical data never becomes shared platform data by default.
- Files are private through Laravel Storage. Phase 1 does not require S3.
- Raw DICOM, Celery, premature SaaS runtime, and Phase 2/3 features are out of current implementation scope.

Established ports belong at real boundaries: `MessagingChannel`, `PaymentGateway`, `KnowledgeRetriever`, `Storage`, integration/sync adapters, AI providers, and `AiWorkflowEngine`. Do not add speculative interfaces elsewhere.

## Module Boundaries

Production modules live under `app/Modules/<Module>/{Application,Domain,Infrastructure}`. Framework entry points remain in their conventional Laravel locations and must stay thin.

Milestone 0 implemented modules:

- Organizations: server-derived context and ownership boundary.
- Services: architectural proof slice only.
- AI: SDK fake/test foundation only.
- Channels: Nutgram adapter skeleton only.

Consult `docs/architecture/modules.md` before adding a module dependency.

## Coding Rules

- Use explicit types, cohesive classes, small methods, clear names, and tests.
- No explanatory comments or docstrings in production code. Put non-obvious invariants in architecture docs or ADRs.
- No TODO, FIXME, dead code, debug code, sample/demo behavior, or unused dependencies.
- Prefer the simplest implementation preserving current requirements and extension points.
- Do not add abstractions solely for possible future reuse.
- Activate `.agents/skills/durable-workflows` when implementing or reviewing persisted, replayable, delayed, queued, scheduled, webhook/callback, or external-side-effect workflows.
- Prefer production files under 300–400 lines. Review responsibility near 500 lines. Handwritten production files over 700 lines require a documented reason and are normally unacceptable.
- Split by responsibility, not arbitrary line count. Keep controllers, handlers, jobs, resources, and Vue components focused.
- AGENTS.md should remain about 250–350 lines or less; PROJECT_STATUS.md under 150 lines. Roadmap summarizes milestones and never duplicates requirements.
- Use PHPUnit, not Pest, unless an ADR changes the test runner.

## Organization and Authorization

- Every organization-owned query must be scoped explicitly or by a proven organization-aware abstraction.
- Policies/application services enforce access; hiding UI is not authorization.
- Route/model binding and Filament resource queries must prevent cross-organization IDOR.
- Jobs carry safe identifiers and derive/validate context during handling.
- Add cross-organization tests for every organization-owned feature.

## Security

- Never commit credentials, tokens, customer data, generated secrets, or production configuration.
- Integration secrets are encrypted, masked, rotatable, audited, and never returned after save.
- Root encryption secrets remain outside the database.
- Do not log auth headers, cookies, webhook secrets, medical text/files, or payment-card data.
- Queue payloads contain identifiers, not sensitive free text.
- Validate webhook authenticity and replay/idempotency before application commands.
- Private storage is the default. Authorize every file access.
- Do not weaken security or static analysis for convenience.

## Database and Migration Rules

- PostgreSQL is authoritative; SQLite may be used only for fast isolated tests that do not hide PostgreSQL behavior.
- PostgreSQL session-state helpers must preserve caller state across outer and nested/savepoint transactions; real PostgreSQL tests are required for locks, savepoints, `SET LOCAL`, pgvector, and races.
- Production migrations are forward-only by default and must support expand/contract deployment.
- Use FKs, indexes, uniqueness, checks, and transactions where invariants require them.
- Never edit an already-deployed migration. Large data migrations are separate operations.
- Application rollback does not imply destructive database rollback.
- Test PostgreSQL-specific behavior against the Compose pgvector service.

## Quality Gates

### Local Development Feedback

Agents must not run heavy verification locally by default. Do not locally run `make ci`, `make test-integration`, `npm run test:e2e`, full Playwright suites, `npx playwright install`, Docker image builds, `docker compose up` for the complete stack, or Docker runtime health suites unless the user explicitly requests local execution.

Allowed local checks: targeted unit or feature test files/filters, lint/static analysis on changed files, formatting on changed files (`vendor/bin/pint --dirty`), and other low-resource checks that do not start Docker, browser, or container infrastructure. Stop any check that begins consuming significant CPU/memory and move verification to hosted CI.

### Hosted CI Is Authoritative

Heavy hosted CI is a manually triggered candidate gate. Agents may make multiple local commits without hosted CI while building a coherent vertical slice; CI is not a save button or per-fix feedback loop.

For candidate application code:

1. Finish the coherent candidate with focused local feedback and understood documentation/status.
2. Push the candidate SHA or branch.
3. Manually dispatch the blocking CI workflow for that candidate ref.
4. Inspect the exact-SHA hosted CI result.
5. If it finds a real defect, batch the related remediation and dispatch one new candidate.
6. Another candidate run is justified when high-risk tenant, security, encryption, migration, or concurrency behavior changes.

Do not claim full verification from local checks alone. Report the exact candidate SHA, hosted CI run ID, job statuses, and staging SHA if deployment was performed.

The manually dispatched candidate workflow runs PHPUnit unit/feature/integration, Pint, Larastan, ESLint, `vue-tsc`, Vite build, Composer audit, npm audit, Docker build/runtime health, and privacy/secret scan.

The full Playwright suite runs through the separate scheduled/manual E2E workflow until it is stable enough to return as a blocking smoke gate. Scheduled E2E failures are investigated separately and do not automatically block unrelated feature work.

Never claim a check passed unless it was executed. Record exact results and skips in `PROJECT_STATUS.md`. Use `PASS` only for an executed passing check; use `EXISTS — NOT RUN LOCALLY` for an unexecuted PostgreSQL/integration check.

### Staging Is Not CI

Never use persistent staging as a replacement for isolated CI. Do not run destructive or full automated test suites against staging. Deploy to staging only after the exact candidate SHA has passed all required hosted CI jobs. Staging may then be used for guarded deployment verification, synthetic smoke tests, real browser performance measurements, and environment-specific checks that cannot be meaningfully reproduced in GitHub Actions.

After a staging deployment, run `./scripts/staging-smoke.sh`. Use `./scripts/staging-smoke.sh --deep` when the milestone requires reversible domain verification. Extend this repository-owned harness when a new staging check is needed; do not create ad-hoc staging smoke scripts while the harness can own the check. The app container root filesystem is read-only, so smoke PHP is streamed through stdin and must not rely on `docker cp` or container-local temporary files.

### Performance & Runtime Quality Gate

- Correctness tests alone do not prove acceptable runtime characteristics; list/collection/hot read paths must consider per-record DB/I/O/decryption, bounded query and payload/memory growth, relevant indexes, and cache/invalidation.
- Runtime/deployment changes must be verified through production-like runtime paths, not development shortcuts such as `php -S`.
- Hosted CI remains authoritative for heavy verification; perceived staging CRM/UI speed is evaluated manually by the owner unless automated measurement is explicitly requested.
- Agents MUST NOT create CRM timing benchmark scripts/harnesses unless explicitly requested by the owner.

## Dependencies and Migrations

- One JavaScript manager: npm. Keep only `package-lock.json`.
- Install from lockfiles in CI/production.
- Verify package APIs against installed versions and official docs.
- Dependency upgrades, especially majors, are separate reviewed tasks. Do not mass-update or delete lockfiles.
- Add a dependency only for a current, documented capability.

## Documentation Policy

After meaningful work update only the relevant documents:

- implementation/product change → `CHANGELOG.md`;
- requirement/scope change → `docs/product/requirements-changelog.md` and affected REQ rows;
- architecture decision → ADR;
- milestone/current state → `ROADMAP.md` and concise `PROJECT_STATUS.md`;
- new durable workflow → focused `.agents/skills/<area>/SKILL.md`.

Do not copy the master plan or source requirements into derived documentation. Reference REQ IDs and ADRs. `PLANS.md` contains active plans only; remove completed plans after their durable outcomes move to the proper docs.

## Ambiguity and Completion

Make reversible implementation assumptions only when they do not alter business scope; record them in `docs/product/assumptions.md`. Questions affecting money, scope, UX, legal/medical behavior, security, or architecture go to `docs/product/open-questions.md` and block only the dependent work.

A task is complete only when relevant REQs, boundaries, organization isolation, security, tests, static/lint/build checks, migrations, and documentation are all accurate and no temporary residue remains.

## Superpowers integration

Superpowers is available as an engineering skill set. Use it when it materially
improves correctness, debugging, review quality, or delivery reliability. The
Chuklov instructions override generic Superpowers methodology. Superpowers is a
risk amplifier, not a default amount of ceremony.

### Risk-based use

Use Superpowers aggressively for work involving:

- PostgreSQL migrations, schema, or constraints
- concurrency, idempotency, finance, rewards, or ledger behavior
- tenant isolation, authorization, security, protected/Class C data
- timezone semantics, queues, retries, or external API lifecycles
- unclear cross-layer regressions or destructive/data-loss-sensitive operations

Especially prefer `systematic-debugging`, `verification-before-completion`,
`test-driven-development`, `requesting-code-review`, and
`receiving-code-review`. Use worktrees, subagents, and detailed plans only when
task size or parallelism actually benefits from them.

### Bounded work fast path

When the root cause or area, desired behavior, existing abstraction, and
acceptance criteria are clear, do not force brainstorming ceremony, broad
specification discovery, repository-wide archaeology, long implementation
plans, subagent decomposition, a separate worktree purely for ceremony, or
strict full TDD for trivial copy/template-only fixes. Use:

inspect relevant code → add appropriate regression coverage → implement →
verify at the real risk boundary → report

For example, a confirmed notification bug where
`booking.visit_format_label` and `booking.location_label` both render `Онлайн`
needs no brainstorming phase.

### Verification must match the claim

Unit/feature tests provide logic and regression signal; browser/E2E checks actual
UI interaction; PostgreSQL checks production database semantics; staging checks
production-equivalent integration acceptance; external API checks reachability;
and owner manual checks establish owner acceptance. SQLite green is not
PostgreSQL verification, a live process is not external integration proof, and
injected internal state is not proof of a real UI interaction.

### Project precedence

When instructions conflict, use this order:

1. latest owner instruction
2. current task
3. owner-accepted architecture and behavior
4. current authoritative TZ/changelog
5. current repository implementation
6. generic Superpowers methodology

### Architecture

Superpowers must not cause the agent to rebuild an accepted subsystem, create a
second source of truth, introduce unnecessary frameworks or infrastructure,
reopen accepted architecture without a concrete defect, broaden a bounded task
into a general audit, invent legal/business/financial/medical rules, or block
delivery on cosmetic findings. Extend existing abstractions first.

### Review

Classify findings as `BLOCKING`, `REAL BUG`, `WORTHWHILE`, or `COSMETIC`. Fix
`BLOCKING` and `REAL BUG` findings, use judgment for `WORTHWHILE` findings, and
do not repeat review cycles for `COSMETIC` findings.

### Git/deployment

For major work, branch from current main, record the Starting SHA and Final SHA,
and use a Draft PR. Do not merge or deploy production without owner permission.
Production remains closed until the project's production milestone.

### Practical rule

Use the minimum process necessary for production-quality correctness. High-risk
work receives more structure; obvious bounded fixes remain fast.

The lightweight outcome protocol is
[`docs/engineering/superpowers-evaluation.md`](docs/engineering/superpowers-evaluation.md).

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
