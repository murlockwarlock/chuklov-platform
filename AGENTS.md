# Chuklov Repository Guide

## Start Here

Current phase: Phase 1. Current milestone: see `PROJECT_STATUS.md`.

## DEFAULT DELIVERY MODE — FAST_PATH

FAST_PATH is the default mode for this repository. Optimize for working user behavior first, focused verification second, and no ceremony that does not reduce the actual risk.

For bounded fixes and normal product work:

reproduce the exact owner scenario → inspect only affected code → implement → run focused checks only → deploy to authorized staging → verify the exact user-visible scenario → owner recheck → run one final hosted CI candidate before merge if code changed.

Do not make these prerequisites for every staging iteration:

- full hosted CI
- full Playwright
- full PHPUnit
- broad static analysis
- full frontend build matrix
- documentation cleanup
- PR-report cleanup
- full AI evaluation packs
- repeated review cycles
- unrelated regression repair

If the owner says the user-visible behavior is still broken, the task is failed regardless of green internal checks. Never spend hours fixing unrelated tests before verifying the requested behavior itself.

FAST_PATH normally covers CRM layout and action fixes, visibility, labels, copy, forms, prompt management UX and content, bounded AI or Telegram behavior, ordinary Portal regressions, frontend rendering, non-destructive evaluation materialization/UI fixes, and small application logic regressions with known scope. FAST_PATH still requires checks relevant to the changed behavior.

## FULL_RISK

Use FULL_RISK only when the change genuinely involves destructive or complex migrations, schema or constraint semantics, money/ledger/payouts, concurrency/locking/idempotency, tenant isolation, authorization, security, protected/Class C data, irreversible operations, timezone persistence or conversion semantics, queue or external API lifecycle with duplicate-delivery risk, or data-loss risk.

FULL_RISK preserves strong PostgreSQL, concurrency, security, integration, and recovery gates. Do not promote a task to FULL_RISK only because a file is large, many tests exist, a CI workflow exists, a PR is old, Playwright exists, or the feature touches both frontend and backend.

## OWNER ACCEPTANCE FIRST

When the owner provides an exact broken scenario, that scenario is the primary acceptance test. A green test that does not exercise it does not close the task.

Examples:

- If `Привет, ты кто?` causes human handoff, acceptance is that it produces a normal AI reply.
- If a button gives no reaction, acceptance is click button → visible result or human-readable error.
- If a prompt is hidden, acceptance is open prompt → immediately see the active prompt and primary actions.

Do not spend time on unrelated Playwright selectors, baseline CI failures, documentation, cosmetic review findings, or unrelated test failures while the exact owner scenario is still broken.

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

Focused verification is the default. Do not locally run `make ci`, `make test-integration`, `npm run test:e2e`, full Playwright suites, `npx playwright install`, Docker image builds, `docker compose up` for the complete stack, or Docker runtime health suites for FAST_PATH unless the user explicitly requests it or the changed risk requires it.

Allowed local checks: targeted unit or feature test files/filters, directly affected lint/static analysis, focused frontend checks, formatting on changed files (`vendor/bin/pint --dirty`), and other low-resource checks that do not start Docker, browser, or container infrastructure. Stop any check that begins consuming significant CPU/memory and move only the necessary verification to hosted CI.

### Local / CI / Staging workflow

Staging is the primary production-equivalent integration and acceptance environment. It runs the production-like PostgreSQL, Redis, queues, scheduler, migrations, and application paths used for acceptance.

Do not start or keep a heavy production-like stack on the local Mac by default: PostgreSQL, Redis, queue workers, scheduler, full Docker Compose, and other background services are out of the default local loop. Use the Mac for editing, Git, focused unit tests without external infrastructure, PHP syntax checks, `git diff --check`, fast linters/formatters, and other cheap feedback. Run heavy integration suites, PHPStan/Larastan, frontend typecheck/builds, long test suites, and isolated PostgreSQL tests on CI or a remote runner when the existing workflow supports it.

A local heavy stack is allowed only when staging or CI is unavailable, the specific defect cannot reasonably be reproduced remotely, or the owner explicitly requests local verification.

Use staging as the authoritative production-equivalent target for PostgreSQL migrations and schema, JSON/JSONB, indexes, foreign keys, unique and check constraints, timestamps/timezones, transactions, locks/concurrency, idempotency/upsert, payment and webhook/reconciliation flows, Redis/queues/workers, scheduler, Telegram, Zoom, production-like application paths, end-to-end checks, and manual acceptance. Do not duplicate a successful staging or remote verification locally only for ceremony.

Never run destructive automated tests such as `migrate:fresh`, database wipes, or truncation against the staging acceptance database. Run those tests only against an isolated PostgreSQL test database/schema or in CI.

The normal workflow is: `code → lightweight local checks → push → CI/remote checks → staging deploy → PostgreSQL/integration/acceptance → fixes`. Production remains outside this workflow and requires separate owner authorization for development or testing.

### Verification Matrix

| Change risk | Minimum useful evidence |
| --- | --- |
| FAST_PATH UI/copy | Focused feature/unit check if useful; exact staging user path; no full Playwright unless directly relevant. |
| FAST_PATH AI | Focused deterministic regression; small representative real-provider or staging scenario; no full eval pack by default. |
| FAST_PATH Telegram/Portal | Focused handler, delivery, or feature check; exact real staging path when available; no broad channel suite unless needed. |
| DB-sensitive | PostgreSQL verification for the affected schema, query, transaction, or session semantics. |
| Concurrency, money, security, tenant isolation, protected data | FULL_RISK verification, including the relevant PostgreSQL, security, and integration gates. |

### Hosted CI Is the Final Candidate Gate

Hosted CI is the final candidate gate before merge, not a save button before every staging iteration. Agents may iterate on authorized staging with focused checks before hosted CI when the change is FAST_PATH.

For FAST_PATH application code:

1. Finish the coherent candidate with focused local feedback; update documentation/status only when materially required.
2. Deploy the bounded candidate to authorized staging.
3. Verify the exact owner-visible scenario and iterate until it works.
4. Push the final candidate SHA or branch.
5. Manually dispatch hosted CI once for that final candidate before merge if code changed.
6. Inspect the exact-SHA result and fix only failures caused by the candidate.

Run hosted CI again only if code changed after the previous run, or if the changed risk genuinely requires a new candidate. Staging data/configuration changes alone do not justify rerunning CI. For FULL_RISK, CI/PostgreSQL/security/concurrency gates may be required before staging where the change could damage shared data or invalidate staging.

The manually dispatched candidate workflow runs PHPUnit unit/feature/integration, Pint, Larastan, ESLint, `vue-tsc`, Vite build, Composer audit, npm audit, Docker build/runtime health, and privacy/secret scan.

The full Playwright suite is a separate scheduled/manual E2E gate; it blocks only when the touched feature or an explicitly requested release path requires it. Scheduled E2E failures are investigated separately and do not automatically block unrelated feature work.

Never claim a check passed unless it was executed. Report only commands actually run. Do not update `PROJECT_STATUS.md` solely to document a small FAST_PATH iteration; record exact candidate SHA, hosted CI run ID, job statuses, and relevant skips when a release/status record is otherwise required. Use `PASS` only for an executed passing check; use `EXISTS — NOT RUN LOCALLY` for an unexecuted PostgreSQL/integration check.

### Staging Is Primary Product Acceptance

Staging is the primary product acceptance environment. For FAST_PATH, authorized staging may be used before full hosted CI. Do not run destructive or full automated suites against staging. Verify the smallest real path that proves the task:

- button fix: click the actual button and observe the result
- prompt UX: open the prompt and verify the active prompt and primary actions are visible
- Companion: send the exact message that previously failed
- Telegram: send a real staging message and verify the reply
- async UI: trigger the action and verify state refreshes automatically

Never use persistent staging as a replacement for isolated CI, and never use CI as a substitute for the exact owner scenario. A green internal test that does not exercise the broken owner behavior does not close the task. Use `./scripts/staging-smoke.sh` when the deployment milestone or changed infrastructure risk requires it; use `--deep` only when the milestone requires reversible domain verification. Extend this repository-owned harness when a new staging check is needed; do not create ad-hoc staging smoke scripts while the harness can own the check. The app container root filesystem is read-only, so smoke PHP is streamed through stdin and must not rely on `docker cp` or container-local temporary files.

### Playwright Scope

Full Playwright runs only when explicitly requested, scheduled, or required for browser-level confidence in the touched feature. A focused browser scenario is enough for one small UI fix when browser tooling is available. Fix Playwright failures only when caused by the current change or when they block the exact owner scenario. If browser tooling is unavailable, do not block a bounded staging recheck.

### Verification Loop and CI Failure Classification

Do not rerun the same heavy verification when the relevant code has not changed. If hosted CI is green on SHA X and only staging data changed, do not rerun it. If PostgreSQL semantics, a concurrency fix, or another high-risk boundary was already verified and remains untouched, do not rerun that verification for ceremony.

Classify CI failures as `CAUSED BY CURRENT CHANGE`, `PRE-EXISTING / BASELINE`, or `INFRASTRUCTURE`. Only failures caused by the current change automatically block bounded delivery. Security, tenant-isolation, protected-data, and data-loss failures remain blocking regardless of classification.

## Reporting

For bounded work, keep the final report short and factual: what was broken, root cause, what changed, exact staging scenario result, focused checks, PostgreSQL status if relevant, final SHA, and whether owner recheck is needed. Do not produce a release-style report for a small fix or spend implementation time polishing it before user-visible behavior works.

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

For small bounded fixes, do not update `CHANGELOG.md`, `PROJECT_STATUS.md`, `ROADMAP.md`, or architecture documents unless the change materially alters product behavior, a requirement, architecture, milestone state, or an operational contract. Do not update documentation just because a button moved or a small bug was fixed. PR body and report cleanup is not part of the inner debugging loop; do it once when the candidate is ready.

When documentation is materially required, update only the relevant document:

- implementation/product change → `CHANGELOG.md`;
- requirement/scope change → `docs/product/requirements-changelog.md` and affected REQ rows;
- architecture decision → ADR;
- milestone/current state → `ROADMAP.md` and concise `PROJECT_STATUS.md`;
- new durable workflow → focused `.agents/skills/<area>/SKILL.md`.

Do not copy the master plan or source requirements into derived documentation. Reference REQ IDs and ADRs. `PLANS.md` contains active plans only; remove completed plans after their durable outcomes move to the proper docs.

## Ambiguity and Completion

Make reversible implementation assumptions only when they do not alter business scope; record them in `docs/product/assumptions.md`. Questions affecting money, scope, UX, legal/medical behavior, security, or architecture go to `docs/product/open-questions.md` and block only the dependent work.

A task is complete when the requested user-visible behavior works, relevant architecture and security boundaries are preserved, checks appropriate to the actual risk are green, PostgreSQL is verified where the change is DB-sensitive, owner-visible staging acceptance is successful for user-facing work, and no blocking real defect remains. More green tests are not a substitute for broken staging behavior.

## Superpowers integration

Superpowers is optional and risk-based, not default ceremony. The Chuklov instructions override generic Superpowers methodology. Superpowers must never force ceremony onto an obvious bounded fix.

### FAST_PATH

Do not activate Superpowers unless a concrete benefit is identified for the current bounded task. Focused reproduction, implementation, checks, staging acceptance, and owner recheck remain sufficient by default.

### FULL_RISK

Use relevant Superpowers skills more aggressively when the change involves:

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
