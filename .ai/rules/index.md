# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| {app/Models/**,app/Http/**,app/Filament/**,app/Jobs/**,database/migrations/**,tests/Unit/**,tests/Feature/**,tests/Integration/**,tests/Support/**,app/Modules/*/Domain/**,app/Modules/*/Infrastructure/**,app/Modules/*/Jobs/**,app/Modules/*/Application/**} | .ai/rules/application.md |
| app/Filament/** | .ai/rules/filament.md |
| {.dockerignore,docker/**,scripts/check-docker-context.sh}, {Makefile,composer.json,scripts/initialize-app-key.sh,scripts/check-app-key-idempotence.sh} | .ai/rules/general.md |
| {app/Modules/**,app/Jobs/**,database/migrations/**,tests/Integration/**} | .ai/rules/integration.md |
| {app/Filament/**,app/Http/Controllers/Portal/**,resources/js/**,resources/views/**} | .ai/rules/js-views.md |
| resources/js/** | .ai/rules/js.md |
| app/Modules/Knowledge/** | .ai/rules/knowledge.md |
| app/Http/Controllers/Portal/** | .ai/rules/portal.md |
| app/Modules/Scenarios/** | .ai/rules/scenarios.md |
| resources/views/** | .ai/rules/views.md |

High-risk workflows must also read `docs/architecture/concurrency-and-external-effects.md` and `.ai/rules/integration.md`. The latter is mandatory for PostgreSQL, migrations, jobs, queues, leases, retries, external side effects, or process-level concurrency tests.
