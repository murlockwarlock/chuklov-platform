# 23.10 owner-scope acceptance matrix

This ledger reconciles the two primary owner sources, read in full:

- `ТЗ_для_Ивана_заполнение_данных_клиента_23_10_26.md`;
- `ФИЛЬТРЫ КЛИЕНТОВ.docx`.

## Current final handoff (authoritative)

This section supersedes the historical matrix retained below. It describes the
consolidated F1–F9 fix pass and the exact candidate currently deployed to
staging.

| Item | Current value |
|---|---|
| Starting PR SHA | `bd163dec17eeeb03dfaf498701bdba22cba0a917` |
| Starting application SHA | `b2b605ac0bd0038ae42088dded5d08e999d2acfa` |
| Application/runtime SHA | `d3b3931f963ce3499c877d7d01c91de42f22272a` |
| Exact staging SHA | `d3b3931f963ce3499c877d7d01c91de42f22272a` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 |
| PR state | `Draft / Open / DO NOT MERGE` |
| Owner acceptance | `NOT RUN` |

The current candidate closes F1–F9 and the corrected PostgreSQL NPS race
evidence NV1. A10 remains closed: known-country total is derived from the same
materialized buckets as the distribution; `Не указана` remains visible and in
the percentage denominator but is not counted; empty and unknown-only cohorts
return zero; filters, drill-down and tenant isolation use the same cohort.

### Consolidated current capability matrix

Every row below is `IMPLEMENTED / POSTGRESQL VERIFIED / BROWSER VERIFIED /
STAGING VERIFIED / OWNER ACCEPTED = NOT RUN`. Browser evidence is the final
hosted Chromium CRM run `41456681294` (four real journeys), Portal run
`41456476224` (two real journeys), and hosted WebKit run `41453380561` (four
real CRM journeys); unchanged source coverage is additionally inherited from
the prior WebKit runs recorded in the historical section.

| ID | Current result | Closed finding / evidence |
|---|---|---|
| M1 | PASS | Exact active session order and legacy read-only fields; current Chromium medical journey. |
| M2 | PASS | Optional per-pain fields, nullable/zero scores and empty-row handling; PG/feature regression. |
| M3 | PASS | First-session/profile fallback, subsequent carry-forward and prior after=0; PG/Chromium regression. |
| M4 | PASS | Owner-configurable clinical catalog and inactive historical readability; existing catalog evidence. |
| M5 | PASS | Derived/manual causes and restore path; existing medical evidence. |
| M6 | PASS | Max two root causes, free text, session-local storage; existing medical evidence. |
| M7 | PASS | Protocol `Заполнить` remains contextual and non-AI; existing medical evidence. |
| M8 | PASS | Subjective feelings write/read/reload; current draft-result path. |
| M9 | PASS | Posture write/read and no active legacy objective editor; existing medical evidence. |
| M10 | PASS | Result Fill now uses validated current draft; cancel/confirm semantics and reload covered by Chromium. |
| M11 | PASS | Private before/after media and authorization; existing protected-media evidence. |
| M12 | PASS | Portal structured pains and CRM path; existing Portal/CRM evidence. |
| M13 | PASS | Portal injuries/operations repeaters; existing Portal/CRM evidence. |
| M14 | PASS | Master-only anamnesis; existing authorization evidence. |
| M15 | PASS | Private analyses/reports; existing protected-file evidence. |
| M16 | PASS | Master-only private audio; existing protected-media evidence. |
| A1 | PASS | Empty taxonomy, NeedsReview, accept/rename/merge/reject/stale and current-run fencing; current Chromium + PG. |
| A2 | PASS | Average pain denominator and NULL/0 semantics; existing PG/XLSX evidence. |
| A3 | PASS | Problem-specific 1/2/3 axis and duplicate-row semantics; existing PG/XLSX evidence. |
| A4 | PASS | Satisfaction 0–10 including both boundaries; current feature regression. |
| A5 | PASS | Client/therapist text survives independently and themes remain configurable; current feature regression. |
| A6 | PASS | Goals/tasks and taxonomy reuse; existing analytics evidence. |
| A7 | PASS | Atomic saved groups, versions, archive and stale/concurrent additions; current feature/Chromium regression. |
| A8 | PASS | Human-configured age ranges and overlap validation; existing analytics evidence. |
| A9 | PASS | Sex/children counts, percentages, unknown and tenant-safe drill-down; existing analytics evidence. |
| A10 | PASS | Distribution, percentages, known-country total, unknown bucket, drill-down and tenant isolation; current Chromium/XLSX/PG evidence. |
| A11 | PASS | Services/visits/specialists and Excel; existing analytics evidence. |
| A12 | PASS | Monetary LTV retained; no invented active percentage KPI; owner formula remains configurable. |
| A13 | PASS | Finance period/tenant semantics and ordinary expenses with an unrestricted cohort object; current PG/feature regression. |
| A14 | PASS | Human acquisition source labels/aliases with hidden stable keys; existing analytics evidence. |
| A15 | PASS | Purchase grouping and duplicate protection; existing analytics evidence. |
| A16 | PASS | Booking statuses and history events kept separate; existing analytics evidence. |

### Consolidated findings and evidence status

| Finding | Current status |
|---|---|
| F1 profile pains resurrected after an empty prior session | CLOSED; focused PHPUnit regression PASS. |
| F2 inactive historical period/tag rejected by real save path | CLOSED; focused PHPUnit regression PASS. |
| F3 result Fill ignored unsaved draft | CLOSED; current Chromium medical journey PASS. |
| F4 taxonomy mutation invalidated the run under review | CLOSED; focused lifecycle/Chromium regression PASS. |
| F5 stale saved-group form archived concurrent additions | CLOSED; transactional/version and Chromium regression PASS. |
| F6 positive score discarded feedback text | CLOSED; Portal + CRM boundary regression and Portal Chromium PASS. |
| F7 ordinary expenses disappeared with neutral cohort object | CLOSED; focused finance regression PASS. |
| F8 automatic AI suggestion trigger missing | CLOSED; configurable safe-disabled job and synthetic-provider regression PASS. |
| F9 abandoned Processing classification was unrecoverable | CLOSED; lease reclaim/fencing regression PASS. |
| NV1 PostgreSQL NPS duplicate/concurrency evidence | CLOSED; hosted run `41450734917`, 109 tests / 670 assertions PASS. |
| A10 country total | CLOSED; exact current application SHA evidence above. |

### Current quality gates

- Local focused PHPUnit: PASS — 130 tests / 748 assertions.
- Targeted PHPStan for changed backend paths: PASS; Pint, frontend lint,
  `vue-tsc`, build and `git diff --check`: PASS.
- Hosted PostgreSQL concurrency: PASS — `41450734917`.
- Hosted Chromium changed CRM journeys: PASS — `41456681294`, 4/4.
- Hosted Portal feedback journeys: PASS — `41456476224`, 2/2. The earlier
  attempts were corrected E2E fixture/selector failures; the final run
  exercises the enabled feedback form and preserves text while changing 6 to
  10.
- Hosted WebKit changed CRM journeys: PASS — `41453380561`, 4/4.
- Focused Quality run `41450740192` was executed before the final type-only
  cleanup: feature/Pint/frontend/build/audit passed; repository-wide PHPStan
  also reported the then-redundant draft-state guard plus unrelated baseline
  diagnostics. Final targeted PHPStan for all changed backend paths is PASS.
- Protected deploy and `scripts/staging-smoke.sh`: PASS at exact
  `d3b3931f963ce3499c877d7d01c91de42f22272a`; public staging DNS was
  unavailable, so the authorized SSH loopback health tunnel was used.

### Current conclusion

`BLOCKING = 0`, `REAL BUG = 0`, and no material machine-verifiable source
requirement remains unverified in the reviewed scope. Owner-authored clinical
catalogs, feedback wording/themes, goal taxonomy, production AI provider and
prompt, thresholds/mappings, audio policy, effectiveness formula, LTV
percentage formula, and legal/medical copy remain legitimate owner content or
configuration decisions; their configurable mechanisms and safe defaults are
implemented. Owner acceptance remains `NOT RUN`.

## Historical matrix from the pre-consolidated pass (retained for audit trail)

The following sections preserve the earlier A10-focused evidence and are not
the current status. When a SHA or status below differs from the current
handoff, the current handoff above is authoritative.

`IMPLEMENTED` means that the runtime seam exists. `POSTGRESQL VERIFIED` and
`BROWSER VERIFIED` are separate executed checks. `STAGING VERIFIED` refers to
the exact deployed application SHA below. `OWNER ACCEPTED` remains `NOT RUN`.
Synthetic QA data proves configurable mechanisms; it is not production content.

## Runtime boundary

| Item | Value |
|---|---|
| Starting PR SHA for this final verification pass | `886af5af20a205dbb0f2c559f08d3bd136763e3c` |
| Starting application SHA for this final verification pass | `8c1e407505b19b9a1704d200b9df9ee43557ffc3` |
| Historical reconciliation baseline | PR `2c4010fef38d13e37a3c03f91d33e8a216200b7e`, runtime `b743d67dc769e08ca8cc6b948803903fe95c70b7` |
| Final PR SHA | Documentation-only closeout commit after the application SHA; exact final head is reported with the final handoff |
| Final application SHA | `b2b605ac0bd0038ae42088dded5d08e999d2acfa` |
| Exact staging SHA | `b2b605ac0bd0038ae42088dded5d08e999d2acfa` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 Draft, Open, DO NOT MERGE |
| Owner acceptance | `NOT RUN` |

This pass extends the existing demographics result with `country_count`,
derived from the same materialized country buckets that render the distribution.
No separate country query or normalization authority was introduced. No medical
runtime path changed after the previously deployed medical evidence; that
inheritance is called out explicitly in the relevant rows.

## Medical source matrix

| ID / source requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED | OWNER CONTENT REQUIRED |
|---|---|---|---|---|---|---|
| M1. Exact active session order; no active legacy editors | YES; legacy values remain history/read-only | PASS | VERIFIED — Chromium session regression; medical path unchanged from `d168eeaf` to final SHA. Order observed: pain, tests, causes, root causes, protocol, subjective, posture, result, photos; no global VAS, objective editor, or active `root_cause_hypothesis` | PASS | NOT RUN | Clinical catalog content |
| M2. Repeatable optional pain constructor; 0/NULL and empty-row safety | YES | PASS | VERIFIED — Chromium three-row add/save/reload/delete/reload journey; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Frequency-unit choices |
| M3. Carry-forward and per-pain history | YES | PASS | VERIFIED — Chromium second-session journey showed previous after beside current empty before, including previous `0`; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | None |
| M4. Configurable clinical tests, groups, values, lifecycle, import seam, counters | YES | PASS | VERIFIED — Chromium CRM synthetic catalog: CSV import (60 rows), XLSX import, 40–60-row session usability at 390px, before/after controls and counters; synthetic definitions deactivated after test | PASS | NOT RUN | Real clinical test names/values |
| M5. Causes: derived, manual correction, restore, deduplicated history | YES | PASS | VERIFIED — Chromium synthetic abnormal-test cause correction, restore-derived action and reload; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Real cause catalog |
| M6. Max two root causes, free text, session-local storage, Unicode boundary | YES | PASS | VERIFIED — Chromium two roots/free text/reload and no second legacy editor; 10,000-Unicode boundary covered by backend regression | PASS | NOT RUN | Real root-cause catalog |
| M7. Exact protocol label and nearby non-AI Fill action | YES | PASS | VERIFIED — Chromium showed exact label and nearby `Заполнить`; notification only, no AI call, existing text unchanged; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Future AI wording, if activated |
| M8. Subjective feelings | YES | PASS | VERIFIED — Chromium save/view/reload; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Owner wording |
| M9. Visual posture diagnostics; old objective editor read-only | YES | PASS | VERIFIED — Chromium save/view/reload and absence of active objective-changes editor; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Owner wording |
| M10. Deterministic result Fill, edit, overwrite cancel/confirm, NULL-safe summary | YES | PASS | VERIFIED — Chromium fill, edit, cancel-preserve, confirm-replace, reload; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Owner-authored wording beyond deterministic summary |
| M11. Separate before/after private photo galleries | YES | PASS | VERIFIED — Chromium 3-before/2-after upload, preview, download, unlink and reload; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Size/format policy within hard cap |
| M12. Portal structured pains, own scores, free complaints, CRM first-session init | YES | PASS | VERIFIED — Chromium normal QA Portal auth: 3 complaints with scores `0/5/10`, free complaint, no global/frequency/time controls; CRM saw same values and first-session carry-forward; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Owner profile content |
| M13. Repeatable injuries/operations with conditional UX | YES | PASS | VERIFIED — Chromium Portal none/one/three/delete-middle/reload for injuries and operations; CRM saw exact remaining rows; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Real injury/operation content |
| M14. Anamnesis master-only and retained | YES | PASS | VERIFIED — Chromium Portal had no anamnesis/edit control and direct request was unauthorized; CRM master field saved/reloaded; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Owner anamnesis text |
| M15. `Результаты анализов` uploads across Portal/CRM | YES | PASS | VERIFIED — Chromium Portal upload/reload/download, CRM `Файлы и МРТ` visibility, authorized master upload and private access; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | None |
| M16. Separate audio section, playback/download/delete/reload, client denial | YES | PASS | VERIFIED — Chromium CRM 3 synthetic audio files, playback/download/delete/reload; Portal section and direct access denied; final medical path unchanged from `d168eeaf` | PASS | NOT RUN | Owner formats/max business size |

M4 source decision: the source makes a configurable clinical catalog, groups,
allowed values, abnormal state, lifecycle, before/after values and counters
mandatory. CSV/XLSX import is stated as desirable, and 40–60 findings are a
usability expectation rather than a request for real medical content. The
current implementation includes both import formats, so both were exercised
with synthetic labels and then safely deactivated.

## Analytics source matrix

| ID / source requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED | OWNER CONTENT REQUIRED |
|---|---|---|---|---|---|---|
| A1. Taxonomy, common tag, AI existing/new candidate lifecycle, filters/statistics | YES; empty-taxonomy lifecycle and review audit implemented | PASS | VERIFIED — exact final SHA Chromium synthetic empty taxonomy: new candidate → NeedsReview, accept new, edit-and-accept, reject, merge, explicit authoritative rename, stale-source rejection; accepted tags appeared in owner taxonomy/filter context; candidate setting restored disabled | PASS | NOT RUN | Taxonomy labels, AI prompt/provider and activation |
| A2. Average pain; NULL excluded, 0 included, denominator disclosed, screen/XLSX | YES | PASS | VERIFIED — Chromium shared cohort pain screen and Excel export; final analytics path unchanged from `d168eeaf` | PASS | NOT RUN | None |
| A3. Therapy result 1/2/3 and configured percentage | YES; problem-specific axis, no invented formula | PASS | VERIFIED — exact final SHA Chromium synthetic Neck fixture: problem sessions after `8/4/1`; duplicate pain rows in one session did not create a second number; global-axis toggle and restore exercised; XLSX independently contained `8/4/1` | PASS | NOT RUN | Effectiveness formula/metric definition |
| A4. Satisfaction 0–10, including 0 and 10 | YES | PASS | VERIFIED — exact final SHA Chromium Portal submitted `0`, CRM submitted `10`, reload and analytics aggregate showed 2 ratings / average 5; invalid `11` and `-1` returned controlled normal validation errors | PASS | NOT RUN | None |
| A5. “What was missing” client/therapist paths and themes | YES; configurable scenario/theme and safe disabled default | PASS | VERIFIED — exact final SHA Chromium synthetic owner theme: Portal client submission, CRM manual entries/theme assignment, reload, aggregate `2` mentions / average 5 and drill-down; theme deactivated after test | PASS | NOT RUN | Feedback wording, timing and production themes |
| A6. Client goals/tasks, repeated categories and same AI taxonomy | YES; existing Tracker/task authority reused | PASS | VERIFIED — exact final SHA Chromium synthetic Tracker goals: repeated goal tag counted as 3 tasks across 2 clients, filter and drill-down shown; synthetic goal candidate accepted through the same review lifecycle and disabled after fixture cleanup | PASS | NOT RUN | Goal taxonomy/content |
| A7. Client categories and saved/manual/dynamic groups; atomic CRUD/versioning/archive/concurrency | YES | PASS | VERIFIED — Chromium manual/dynamic create/reload, invalid later row with no partial write, explicit archive and stale-editor error; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Segment thresholds/service mappings |
| A8. Age filters/ranges/timezone and human configuration | YES; generated hidden keys and overlap validation | PASS | VERIFIED — Chromium owner UI overlap rejection, correction, archive/reload and console use; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Real age labels/ranges |
| A9. Sex/women/men/children, unknown-safe counts and exact drill-down | YES | PASS | VERIFIED — exact final SHA Chromium org-A fixture: female, male and missing-gender rows; unknown drill-down reason and exact client; same-looking org-B client excluded; base state preserved | PASS | NOT RUN | None |
| A10. Country distribution, percentages, total known countries, unknown bucket, exact drill-down, tenant isolation | YES | PASS | VERIFIED — exact final SHA Chromium org-A fixture: KZ 2 (50%), RU 1 (25%), `Не указана` 1 (25%), `Стран представлено: 2`; KZ filter gives total 1; missing-only keeps the unknown bucket with total 0; exact missing-country drill-down and org-B exclusion remain covered | PASS | NOT RUN | None |
| A11. Services/visits/specialists, semantics, drill-down and Excel | YES; existing Service UI remains authority | PASS | VERIFIED — Chromium populated service/specialist rows, completed/cancelled semantics, drill-down and Excel; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Owner service categories |
| A12. LTV percentage without invented formula | YES; monetary LTV remains, percentage disabled/configuration-required | PASS | VERIFIED — Chromium metric settings and console showed no active fake percentage; monetary metric remained available; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Exact LTV% formula/activation |
| A13. Finance income/expenses/cash/accounts, periods, authorization, Excel | YES | PASS | VERIFIED — Chromium isolated synthetic finance fixture and export; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Currency/business policy |
| A14. Acquisition source labels/aliases/order/UTM with hidden stable keys | YES | PASS | VERIFIED — Chromium human settings and source aggregation; stable keys hidden; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Owner aliases/mappings |
| A15. Purchase grouping over canonical types; duplicate guard and historical display | YES | PASS | VERIFIED — Chromium human selector, duplicate rejection and populated human labels; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | Owner purchase grouping |
| A16. Booking statuses/events completed/rescheduled/cancelled | YES; status and history event axes remain separate | PASS | VERIFIED — Chromium populated counts/filter/drill-down; completed status was not conflated with history events; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN | None |

## Console, charts and Excel

| Requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED |
|---|---|---|---|---|---|
| Selectable sections, `Анализировать`, preview idle/loading/success/invalid/error, retry and shared cohort | YES | PASS | VERIFIED — Chromium 390/768/1440: live preview, correction without refresh, invalid-date field error, valid empty `0`, Analyze, reload/back/forward; no query failure was forced destructively | PASS | NOT RUN |
| Charts use the same aggregate payload as tables | YES | PASS | VERIFIED — Chromium Analyze rendered matching table/chart values; final analytics candidate-only code delta does not touch this path | PASS | NOT RUN |
| Excel: clients, filters, thematic sheets, timezone/period/rule versions/generated_at, typed cells and injection safety | YES | PASS | VERIFIED — Chromium real `.xlsx` downloads, including exact-final A3 `8/4/1`; PhpSpreadsheet independently read typed cells and formula-injection safeguards | PASS | NOT RUN |
| 0/1/many dimensions, empty vs invalid/error, reload/back/forward, responsive layout | YES | PASS | VERIFIED — Chromium 390/768/1440 plus hosted WebKit desktop/390px representative paths; empty, invalid, reload and history states remained distinct | PASS | NOT RUN |

## Exact browser evidence

- Browser projects: Chromium and hosted WebKit, using the private QA CRM
  profile and a separately authenticated synthetic Portal client; no owner
  credentials or MFA changes.
- Chromium viewports exercised: 390, 768 and 1440 where the path required it.
- Hosted WebKit runs on the current test head `14fd0f0f` passed the final
  representative paths: Portal/medical profile (`41431787618`), CRM medical,
  uploads, audio, AI review and saved groups (`41430185522`), plus analytics,
  drill-down, Excel, reload/history and invalid/empty preview states
  (`41431100891`). The saved-group and AI flows also have focused WebKit runs
  `41429760328` and `41423048806`.
- The WebKit workflow ran in the hosted CI project with its isolated CI
  application; it did not require the local Mac browser or local DNS/TLS path.
  No product or DNS configuration was changed. Responsive assertions covered
  the required desktop and 390px mobile layouts, including overflow checks.
- The exact staging application SHA for this pass is
  `b2b605ac0bd0038ae42088dded5d08e999d2acfa`. Existing WebKit evidence remains
  valid for the unchanged representative paths; the new country-total
  presentation is covered by the focused Chromium run below.

## Defects found and fixed during this pass

1. Pending AI candidate review controls were disabled by a Repeater callback
   checking an unset hidden id. The control is now enabled for persisted
   candidates (`f8892595`, `c5aad983`).
2. A stale candidate save returned backend validation but no visible browser
   error. The page now shows an actionable danger notification
   (`8c1e4075`).

Targeted regression coverage is in
`tests/Feature/Analytics/AnalyticsTagCandidateLifecycleTest.php`.
No new migration was required. No unrepaired source-scope `BLOCKING` or
`REAL BUG` was reproduced after the fixes.

## Checks and staging

- Targeted PHPUnit: PASS (7 tests, 80 assertions) — demographics cohort and
  real-workbook export regressions.
- Changed-file PHPStan: PASS.
- `vendor/bin/pint --dirty`: PASS.
- `git diff --check`: PASS.
- Hosted PostgreSQL focused regression: run `41439468968`; affected feature
  step PASS (2 tests, 29 assertions). The workflow's overall Quality result
  remained red only for unrelated existing PHPStan baseline errors.
- Hosted WebKit final runs: PASS — `41431787618`, `41430185522`, `41431100891`.
- Hosted Chromium country-total regression: run `41441752998`; 2 focused
  desktop tests PASS (country total/export and existing missing-country
  drill-down).
- Repository-wide CI run `41437318166` retained unrelated baseline/infrastructure
  failures (MilestoneElevenA concurrency, unrelated feature/PHPStan checks and
  Horizon health); the affected country path is covered by the focused runs
  above.
- No local browser processes were started during this final verification pass.
- Protected staging deploy of `b2b605ac0bd0038ae42088dded5d08e999d2acfa`: PASS.
- `scripts/staging-smoke.sh` at the exact SHA: PASS (health, migrations,
  PostgreSQL/pgvector, Redis, Horizon, scheduler, Mailpit, Telegram, private
  storage, CRM, Portal and companion checks).

## Remaining distinctions

### BLOCKING

`0` reproduced runtime blockers.

### REAL BUG

`0` unresolved source-scope runtime bugs. The two browser defects above are
fixed and regression-covered. The country-total source omission is fixed and
covered by the focused cohort, export and browser regressions.

### NOT VERIFIED

`0` machine-verifiable source requirements remain unverified after the focused
PostgreSQL and Chromium runs recorded above. Hosted WebKit passed the required
representative Portal, medical, upload/media, AI review, saved-group,
analytics, responsive and navigation paths.

### OWNER CONTENT REQUIRED

Production clinical test/cause/root-cause catalogs; feedback wording, timing and
themes; goal taxonomy; AI prompt/provider activation; business thresholds and
mappings; audio formats/max business size; effectiveness definition; exact LTV
percentage formula; and any legal copy. Each has a configurable owner seam and
safe disabled/default behavior where activation would otherwise invent meaning.

### Deliberately not changed

- PR #67 release-retention work;
- production business catalogs, formulas, legal text, AI prose or feedback
  copy;
- production deployment, merge, squash, rebase, force-push or Ready transition;
- authentication policy or DNS/product configuration.
