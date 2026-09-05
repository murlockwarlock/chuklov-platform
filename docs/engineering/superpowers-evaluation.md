# Superpowers evaluation

## Purpose and window

Measure whether selective Superpowers usage reduces rework and escaped defects
without materially slowing normal development. Evaluate the next 5–8 meaningful
PRs/tasks after this integration. Do not count typo-only work unless it exposes
process overhead. Do not build telemetry, dashboards, databases, scripts, or
analytics for this experiment.

## Per-task record

Record the following in the PR body or final task report:

```text
PROCESS MODE: FAST_PATH | SELECTIVE_SUPERPOWERS | FULL_RISK_WORKFLOW
SUPERPOWERS SKILLS ACTUALLY USED: <names or none>
```

List a skill only when it materially affected the workflow. Useful examples are
`systematic-debugging`, `test-driven-development`,
`verification-before-completion`, `requesting-code-review`, and
`subagent-driven-development`.

A compact per-task record may use:

```text
TASK:
PROCESS MODE:
SUPERPOWERS SKILLS ACTUALLY USED:
ESCAPED REAL BUGS:
REWORK CYCLES:
CANDIDATE CHURN:
TIME TO OWNER ACCEPTANCE:
VERIFICATION RELEVANCE:
UNNECESSARY PROCESS: planning/brainstorming | full-suite/review | bounded-fix
USEFUL BUGS CAUGHT EARLY: before staging / before owner acceptance
```

## Primary metrics

For each task, record approximate values where observable and otherwise state
`NOT OBSERVABLE`.

### Escaped real bugs

Count `REAL BUG` or `BLOCKING` defects discovered only after the candidate was
previously considered implementation-complete. Examples include staging finding
broken external connectivity, owner clicks finding a broken UI flow, PostgreSQL
exposing a schema/concurrency defect missed by SQLite, or real Telegram output
exposing a formatting defect missed by tests. Lower is better.

### Rework cycles

Count substantive code-fix cycles required after initial implementation, hosted
CI, staging, or an owner-acceptance attempt. Exclude cosmetic and docs-only
updates. Lower is better.

### Candidate churn

Count substantive candidate SHAs after the first `ready for verification`
candidate. For example, A → real bug → B → staging defect → C means churn of 2.
Lower is better.

### Time to owner acceptance

Approximate wall-clock time from implementation start to the candidate presented
for owner acceptance. Use only observable, honest estimates; precision is not
required. Optimize for time to an actually accepted working feature, not merely
implementation completion. Lower is better.

### Verification relevance

For each major defect, record one of:

- `GOOD` — an appropriate risk-specific check could reasonably have caught it
- `MISSED` — the appropriate check existed or was practical but did not catch it
- `NOT PRACTICALLY TESTABLE`

UI interaction defects belong to browser/E2E verification; PostgreSQL
concurrency defects to PostgreSQL concurrency tests; and Telegram reachability
defects to a real Bot API smoke check.

## Secondary overhead

Track lightly per task:

- unnecessary planning/brainstorming: `YES` or `NO`
- unnecessary full-suite/review cycles: `YES` or `NO`
- excessive process for a bounded fix: `YES` or `NO`
- useful bugs caught before staging because of the workflow: count
- useful bugs caught before owner acceptance because of the workflow: count

Do not optimize for test count, lines of code, assertion count, or longer
reasoning.

## Rough baseline

Use recent project experience qualitatively; do not perform a historical audit
or reconstruct exact hours. Known failure patterns include tests passing while
RichEditor toolbar interaction failed, a healthy worker while real Telegram API
access failed, SQLite passing while PostgreSQL semantics needed separate proof,
and staging/manual acceptance finding defects after earlier green checks. The
baseline conclusion is that escaped real-world defects and repeated candidate
cycles have occurred often enough that reducing them is valuable.

## Decision after 5–8 tasks

Keep selective Superpowers if escaped `BLOCKING`/`REAL BUG` defects and
substantive rework decrease materially without materially slowing bounded
`FAST_PATH` tasks. Tune it if correctness improves but small tasks repeatedly
incur unnecessary process. Remove or heavily restrict it if rework does not
improve while completion time or process overhead rises substantially. Base the
decision on outcomes, not enthusiasm for the methodology.

## Do not game the metrics

Do not delay completion declarations, relabel real bugs as expected, run excess
tests to improve apparent verification, create unnecessary phases, claim owner
acceptance early, call staging production, call SQLite PostgreSQL, or call a
mocked external API a real verification. The main measure is time and rework
required to reach a real, working, owner-accepted candidate.
