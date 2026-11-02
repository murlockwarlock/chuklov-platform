# 23.10 owner-scope acceptance matrix

This ledger reconciles the two primary owner sources, read in full:

- `ТЗ_для_Ивана_заполнение_данных_клиента_23_10_26.md`;
- `ФИЛЬТРЫ КЛИЕНТОВ.docx`.

`IMPLEMENTED` means that the runtime seam exists. `POSTGRESQL VERIFIED` and
`BROWSER VERIFIED` are separate executed checks. `STAGING VERIFIED` refers to
the exact deployed application SHA below. `OWNER ACCEPTED` remains `NOT RUN`.
Synthetic QA data proves configurable mechanisms; it is not production content.

## Runtime boundary

| Item | Value |
|---|---|
| Starting PR SHA for this final verification pass | `508d89dba1574a42bdeabe7bc30c8a3775dde9ca` |
| Starting application SHA for this final verification pass | `8c1e407505b19b9a1704d200b9df9ee43557ffc3` |
| Historical reconciliation baseline | PR `2c4010fef38d13e37a3c03f91d33e8a216200b7e`, runtime `b743d67dc769e08ca8cc6b948803903fe95c70b7` |
| Final PR SHA | Documentation-only closeout commits after the application SHA; exact final head is reported with the final handoff |
| Final application SHA | `8c1e407505b19b9a1704d200b9df9ee43557ffc3` |
| Exact staging SHA | `8c1e407505b19b9a1704d200b9df9ee43557ffc3` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 Draft, Open, DO NOT MERGE |
| Owner acceptance | `NOT RUN` |

The final application SHA contains three analytics candidate-review fixes. No
medical runtime path changed after the previously deployed medical evidence;
that inheritance is called out explicitly in the relevant rows.

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
| A10. Country, unknown bucket, exact missing drill-down, tenant isolation | YES | PASS | VERIFIED — exact final SHA Chromium org-A KZ/RU/missing-country fixture; missing-country drill-down reason and exact client; org-B control excluded; base state preserved | PASS | NOT RUN | None |
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
- The exact staging application SHA remains `8c1e407505b19b9a1704d200b9df9ee43557ffc3`;
  the WebKit evidence is tied to the unchanged runtime plus test-only commits
  after that application SHA.

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

- Targeted PHPUnit: PASS (8 tests, 26 assertions).
- Changed-file PHPStan: PASS.
- `vendor/bin/pint --dirty`: PASS.
- `git diff --check`: PASS.
- Hosted PostgreSQL gates for the affected owner scope: PASS; no DB-sensitive
  production code changed after the existing gate, so no broad rerun was
  triggered for this documentation-only closeout.
- Hosted WebKit final runs: PASS — `41431787618`, `41430185522`, `41431100891`.
- No local browser processes were started during this final verification pass.
- Protected staging deploy of `8c1e407505b19b9a1704d200b9df9ee43557ffc3`:
  PASS.
- `scripts/staging-smoke.sh` at the exact SHA: PASS (health, migrations,
  PostgreSQL/pgvector, Redis, Horizon, scheduler, Mailpit, Telegram, private
  storage, CRM, Portal and companion checks).

## Remaining distinctions

### BLOCKING

`0` reproduced runtime blockers.

### REAL BUG

`0` unresolved source-scope runtime bugs. The two browser defects above are
fixed and regression-covered.

### NOT VERIFIED

`0` machine-verifiable source requirements remain unverified. Hosted WebKit
passed the required representative Portal, medical, upload/media, AI review,
saved-group, analytics, responsive and navigation paths.

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
