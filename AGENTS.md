# Chuklov Repository Guide

## Authority and current state

This file is the repository engineering authority. `PROJECT_STATUS.md` is a
short current snapshot, not a second instruction registry.

When sources conflict, use this order:

1. latest owner instruction;
2. current task prompt;
3. owner-accepted decisions;
4. current requirements and changelog;
5. accepted ADRs;
6. current code and tests as implementation evidence;
7. status and verification documents as evidence only;
8. historical plans, reports, and source material;
9. generic framework defaults.

Resolve a conflict at the highest applicable source. Do not silently
reinterpret business scope. Record a material unresolved question in
`docs/product/open-questions.md` while continuing unblocked technical work.

Use these terms precisely:

- `IMPLEMENTED`: the code path exists; this is not proof that the product is
  correct or accepted.
- `PASS`: a named check was actually executed and passed.
- `VERIFIED`: the observable capability has sufficient evidence at the layers
  required by its risk.
- `OWNER ACCEPTED`: the owner explicitly accepted the resulting business or
  UX behavior.
- `CONTENT REQUIRED`, `CONFIGURATION REQUIRED`, `PROVIDER NOT CONFIGURED`, and
  `EXTERNAL OBSERVATION NOT RUN` describe remaining inputs or evidence; they
  are not automatically unfinished engineering.

Green tests do not mean product-ready. Do not upgrade `PASS` to `VERIFIED` or
`OWNER ACCEPTED` without the corresponding evidence.

## Start and scope

Use the minimum context that can answer the task:

1. read this file and the current snapshot in `PROJECT_STATUS.md`;
2. find the affected `REQ-*` rows in `docs/product/requirements.md`;
3. read only the linked architecture document, accepted ADR, relevant
   `.ai/rules` files, skill, code, and tests;
4. read private source packs, the master plan, or historical evidence only for
   provenance, scope, or unresolved architecture ambiguity.

Inspect the affected path and its existing abstractions before editing. Use
bounded inspection: extend the existing subsystem, keep one authoritative
source per business rule, and do not build a parallel subsystem. Do not read
the entire repository, history, or old plans without a concrete reason.

For version-sensitive Laravel, Filament, Inertia, Tailwind, Horizon, or AI SDK
APIs, check lockfiles and use the available Laravel Boost documentation search.
Read `.ai/rules/index.md` and every matching rule before editing a path. Use a
repo skill only when its trigger matches the task; skills add domain procedure,
not a competing priority system.

## Engineering defaults

- Work at senior-team level: find and understand the existing implementation before extending it.
- Do not create parallel entities, tables, services, catalogs, authorities, or hidden fallback paths.
- Keep business rules explicit and centralized. Do not replace missing decisions with heuristics, guesses, or temporary behavior presented as permanent.
- Add only the smallest abstraction needed at a real boundary. Keep future extension possible without speculative complexity.
- Keep comments, abstractions, repeated documentation, and defensive checks proportional to the risk and contract.
- Do not rewrite working modules for a new design or architectural style without an accepted requirement.

## Delivery modes

`FAST_PATH` is the default for bounded product work:

`reproduce exact owner scenario → inspect affected code → implement → focused
checks → authorized staging iteration and batch fixes when needed → final
candidate → required hosted/staging evidence → owner acceptance`.

Do not make full CI, full PHPUnit, full Playwright, broad static analysis,
frontend matrices, documentation cleanup, repeated reviews, or unrelated
regression repair prerequisites for every small iteration. A focused browser
journey is enough for a small UI change when browser evidence is available.

Use `FULL_RISK` only for changes involving destructive or complex migrations,
schema or constraint semantics, money or ledgers, concurrency or
idempotency, tenant isolation, authorization, security or protected data,
irreversible operations, timezone persistence, queue/external lifecycle, or
data-loss risk. Apply the matching domain skill and evidence gates.

## Business data and scope

Missing business data, authored content, thresholds, mappings, formulas,
medical doctrine, legal copy, and provider credentials do not block building
the surrounding mechanism. Implement the complete safe mechanism, expose
business values through normal authorized CRM controls, verify fields,
validation, storage, and lifecycle with safe fictional data, and report the
remaining value as required configuration/content. Never present fictional
data as a real client's information or medical result. Never invent medical
norms, diagnoses, scoring, formulas, recommendations, or other medical
doctrine as a business rule.

Never invent medical doctrine, clinical recommendations, formulas, legal text,
or authored content. Use disabled or fail-closed behavior when activation
needs an absent value. If changing a business value requires a developer or a
deployment, the configurable feature is not complete.

## Architecture and security invariants

- Keep the modular monolith. No microservices or speculative framework without
  an accepted ADR and an operational need.
- `organization_id` is the tenant and security boundary; `master_id` is not.
  Resolve organization context server-side and never trust request-supplied
  tenant IDs.
- HTTP, Filament, Vue, Telegram, jobs, and adapters call the Application
  layer. Keep business decisions out of controllers, resources, components,
  channel handlers, adapters, and jobs.
- Dependencies point entrypoints/adapters → Application → Domain;
  Infrastructure implements ports. Add ports only at real boundaries.
- CRM, Portal, mobile web, and Telegram share the same application/domain core;
  Telegram is an adapter, not a second product core.
- Keep booking and payment state separate. Use auditable server-side payment
  evidence and idempotent external boundaries.
- Keep AI organization-owned, structured, provider-independent, and distinct
  from specialist-confirmed facts. Keep RAG organization-scoped and files
  private by default.
- Enforce authorization independently of UI visibility. Do not log secrets,
  auth material, medical text/files, payment data, or sensitive free text in
  queue payloads.

## Business UI release gate

First-time usability is mandatory for every new or substantially changed CRM,
Portal, or Mini App surface. An ordinary user without code, database, or ТЗ
knowledge must understand in 5–10 seconds what the screen is, why it exists,
what is already configured or happening, what they can do, and what changes
after the action.

Treat it as a `REAL BUG` when ordinary work requires a developer or database
knowledge, a giant repeater/backend-shaped form, raw keys/enums/IDs, numeric
ordering instead of reorder interaction, a dead-end empty state, unexplained
settings, or inconsistent CTA/navigation. The full canonical rule is
`.ai/rules/product-ui.md`.

Existing UI is not automatically accepted UX. Preserve only UX explicitly
accepted by the owner; new or unaccepted UI must pass the first-time usability
gate. The owner confirms the result, but is not exploratory QA: the agent must
catch broken CRUD, navigation, missing states, and obvious UX defects before
asking for owner acceptance.

## Data and environments

- The Mac is an 8 GB workstation and thin control plane only. Use it only for
  editing, Git, SSH, `git diff --check`, and lightweight syntax checks that do
  not run tests.
- Do not run application, database, integration, or browser tests, Docker,
  PostgreSQL, Redis, full builds, heavy linters, or browser test suites on the
  Mac. If staging is unavailable, report the capability blocker instead of
  replacing staging with local testing.
- All application, database, integration, and browser testing is performed on
  staging. PostgreSQL is the only database used for testing. Do not use SQLite,
  including for local preliminary checks.
- Hosted CI is not a substitute for staging acceptance and is used only when a
  separate repository gate explicitly requires it.
- Staging is the primary and, before production exists, the only product
  acceptance environment for PostgreSQL, Redis, queues, scheduler, migrations,
  integrations, and real user paths.
- Do not start local PostgreSQL, Redis, workers, scheduler, full Compose, or
  heavy browser/integration infrastructure on the Mac. Use staging for product
  evidence; on the Mac, only the lightweight checks listed above are allowed.
- Destructive tests run only against isolated PostgreSQL databases or isolated
  data inside staging. Before a risky operation, determine its impact and
  create a recoverable backup.
- Do not run `migrate:fresh`, `db:wipe`, `TRUNCATE`, or equivalent destructive
  operations against the shared staging database. Do not destroy QA access,
  required data, or audit history. CI does not replace staging acceptance.
- `.codex/staging-deploy.md` contains local environment/deploy mechanics only;
  it is not a source of product requirements and must not contain committed
  credentials.

## Verification and QA

Verify the user-visible contract, not just implementation presence. For a
changed action, prove as applicable:

`open → action → second action → result → reload → return`.

Include authorization, validation, error → fix, cancel, 0/1/many, related
flows, responsive states, and affected actor views. Browser acceptance must
use the same controls a user clicks; DOM presence, HTTP 200, a route, a row,
or a mocked method is not behavioral proof. Async work must prove processing,
authoritative state, failure/retry behavior, and the resulting UI/message.

Scale evidence to risk. Do not rerun unchanged heavy checks or perform a
whole-repository/Medical/Analytics audit after a bounded UI or copy fix.
After sufficient acceptance, review the touched scope and return one
consolidated list of findings; do not run an infinite audit loop or stop at the
first finding.

Report only commands actually run and distinguish `PASS`, `VERIFIED`,
`PARTIALLY VERIFIED`, `NOT VERIFIED`, `NOT IMPLEMENTED`, and external or
configuration blockers. A user-visible scenario that remains broken keeps the
task open regardless of green internal tests.

## Action, error, and diagnostic contract

- Every user action produces a visible pending state, successful result, or human-readable error.
- Validate fields and their dependencies at the authoritative Application boundary and show actionable field errors without discarding entered values.
- Prevent duplicate records, payments, and sends through idempotent server-side operations; a repeated click must not silently create a second effect.
- Verify significant journeys as `open -> action -> second action -> result -> reload -> return`, including error -> correction, cancellation, authorization, and 0/1/many states.
- Significant staging operations and errors must be diagnosable by safe structured context: time, organization, action, result, correlation ID, queue/job, and external call. Never log passwords, tokens, cookies, medical content/files, payment data, or other protected values. The staging procedure defines retrieval and redaction details.

## Local, hosted, and staging workflow

For direct staging browser/SSH acceptance, follow `docs/operations/staging-qa.md`
and reuse the existing thin-control-plane access before proposing infrastructure.

The repository CI and E2E workflows are manually dispatched (`workflow_dispatch`)
with scheduled E2E; a push does not by itself imply a blocking CI run.

For normal code changes:

1. inspect the affected path and run only lightweight repository or documentation checks locally; product testing belongs on staging;
2. push one coherent candidate when remote iteration or evidence is needed;
3. use authorized staging for focused iteration and batch related fixes when
   staging is the right acceptance boundary;
4. finish and push the final candidate at its exact SHA;
5. collect the required hosted/staging evidence for that final candidate,
   manually dispatching hosted CI once when code changed and the risk requires
   it;
6. inspect the exact-SHA result, classify failures as caused by the change,
   baseline, or infrastructure, and request owner acceptance.

Do not rerun a full suite after pure UI/copy changes unless the touched risk or
an explicit release gate requires it. Full Playwright is separate and only
blocks when the touched feature or requested release path requires browser
confidence.

## Git and release control

Before each task, check the current `main`, the affected PRs, and the exact
SHAs they reference. Do not assume branch, merge, or deployment state from
chat context, and do not continue an unrelated PR. Create a dedicated branch
from the verified current base.

For a large or cross-cutting task, use a branch from the current base and a
Draft PR. Record starting and final SHAs when a release/status record needs
them. Do not mark a PR Ready, merge, squash, or deploy production without an
explicit owner command. Production remains outside ordinary staging workflow
and requires separate authorization.

## Documentation and evidence

Keep instructions short and canonical. Put durable engineering rules here,
path-specific constraints in `.ai/rules`, and tool/domain procedures in the
smallest matching skill. Do not copy a master plan or source pack into a new
instruction file.

Files under `docs/verification/`, archived plans, acceptance ledgers,
reports, run logs, and changelog history are evidence or provenance, not active
instructions. They must be marked `EVIDENCE ONLY — NOT CURRENT INSTRUCTIONS`
or `HISTORICAL / EVIDENCE ONLY / NOT CURRENT INSTRUCTIONS` where applicable.
Use the latest owner/task/current requirement authority above if an evidence
document appears to give a conflicting command.

For small fixes, do not update status, changelog, roadmap, ADRs, or reports
unless the change materially alters product behavior, requirements,
architecture, milestone state, or an operational contract. Do not commit
screenshots, logs, dumps, test reports, audit notes, or other task artifacts
unless the owner explicitly requests them.

## Skills and framework guidance

Activate only the relevant Chuklov skill. Keep domain-specific contracts in
their skills: migrations, durable workflows, channels, Telegram, scheduling,
payments, RAG, integrations, deployment, security, CRM, Portal, and AI. The
security skill is conditional on security/privacy/tenant/protected-data scope
or an explicit milestone review. `infer-conventions` is only for an explicit
convention-inference task. Horizon guidance is conditional on Horizon work and
does not require a local heavy runtime. Generic Laravel, Inertia, and Tailwind
skills are not part of the active repo skill set.

Use installed package versions from lockfiles. Keep dependencies and package
managers unchanged unless the task explicitly includes a reviewed dependency
change. Follow existing components, naming, types, and test conventions; do
not add explanatory comments, TODOs, debug code, or speculative abstractions.

This repository uses PHPUnit unless an accepted ADR changes the runner. Use
the narrowest useful command and stop an unexpectedly expensive check.
