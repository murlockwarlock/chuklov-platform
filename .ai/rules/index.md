# Project Rules Index

Before planning or editing, read the rule files whose globs match the path.
`product-ui.md` is the canonical rule for ordinary client, owner, staff, CRM,
Portal, Vue, Blade, and Mini App UI. Path-specific files add constraints only;
they do not restate product UI or copy guidance.

| Applies to | Rule file |
| --- | --- |
| `{app/Models/**,app/Http/**,app/Filament/**,app/Jobs/**,database/migrations/**,tests/Unit/**,tests/Feature/**,tests/Integration/**,tests/Support/**,app/Modules/*/Domain/**,app/Modules/*/Infrastructure/**,app/Modules/*/Jobs/**,app/Modules/*/Application/**}` | `.ai/rules/application.md` |
| `app/Modules/Broadcasts/**` | `.ai/rules/broadcasts.md` |
| `{app/Filament/**,app/Http/Controllers/Portal/**,resources/js/**,resources/views/**}` | `.ai/rules/product-ui.md` |
| `app/Filament/**` | `.ai/rules/filament.md` |
| `{.dockerignore,docker/**,scripts/check-docker-context.sh}` and `{Makefile,composer.json,scripts/initialize-app-key.sh,scripts/check-app-key-idempotence.sh}` | `.ai/rules/general.md` |
| `{app/Modules/**,app/Jobs/**,database/migrations/**,tests/Integration/**}` | `.ai/rules/integration.md` |
| `resources/js/**` | `.ai/rules/js.md` |
| `app/Modules/Knowledge/**` | `.ai/rules/knowledge.md` |
| `app/Modules/Scenarios/**` | `.ai/rules/scenarios.md` |

High-risk workflows must also read
`docs/architecture/concurrency-and-external-effects.md` and
`.ai/rules/integration.md`. The integration rule is mandatory for PostgreSQL,
migrations, jobs, queues, leases, retries, external side effects, or
process-level concurrency tests.
