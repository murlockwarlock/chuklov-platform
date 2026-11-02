# 23.10 owner-scope acceptance matrix

This is the current evidence ledger for the two authoritative owner sources,
read in full:

- `ТЗ_для_Ивана_заполнение_данных_клиента_23_10_26.md`;
- `ФИЛЬТРЫ КЛИЕНТОВ.docx`.

`IMPLEMENTED` describes the runtime seam and regression coverage. `POSTGRESQL
VERIFIED` describes an executed PostgreSQL check. `BROWSER VERIFIED` requires
an observable browser journey. Evidence from `01014d0` is inherited only for
paths not changed afterwards; the only application-code delta from that SHA to
the final application SHA is owner-facing age-range and purchase-group
validation feedback. Owner acceptance remains deliberately `NOT RUN`.

## Runtime boundary

| Item | Value |
|---|---|
| Starting PR SHA | `2c4010fef38d13e37a3c03f91d33e8a216200b7e` |
| Starting runtime SHA | `b743d67dc769e08ca8cc6b948803903fe95c70b7` |
| Previous exact application SHA | `01014d0ba5a06adc846568343c43e709ff3a441a` |
| Final application SHA | `d168eeaf93209585cb36644460949c307d671403` |
| Exact staging SHA | `d168eeaf93209585cb36644460949c307d671403` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 Draft, Open, DO NOT MERGE |
| Owner acceptance | `NOT RUN` |

## Medical source matrix

| ID / source requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED | OWNER CONTENT REQUIRED |
|---|---|---|---|---|---|---|
| M1. Exact active session order; no active legacy editors | YES; `root_cause_hypothesis` is history-only/read-only | PASS | VERIFIED — exact final SHA compact regression plus prior full create/edit/view journey | PASS | NOT RUN | Clinical catalog content |
| M2. Repeatable optional pain constructor; 0/NULL and empty-row safety | YES | PASS | VERIFIED — three-row add/delete/reload journey on `01014d0`; final path unchanged | PASS | NOT RUN | Frequency-unit choices |
| M3. Carry-forward and per-pain history | YES | PASS | VERIFIED — exact final session 25 showed previous after `1/10`, `0/10`, `3/10` and current before `Не указано`; prior full reload journey | PASS | NOT RUN | None |
| M4. Configurable clinical tests, groups, values, lifecycle, import seam, counters | YES | PASS | PARTIALLY VERIFIED — synthetic catalog/abnormal/counters/lifecycle passed; CSV/XLSX import and 40–60-row usability not exercised | PASS | NOT RUN | Real clinical catalog and values |
| M5. Causes: derived, manual correction, restore, deduplicated history | YES | PASS | VERIFIED — synthetic abnormal cause correction/restore/reload | PASS | NOT RUN | Real cause catalog |
| M6. Max two root causes, free text, session-local storage, Unicode boundary | YES | PASS | VERIFIED — two roots/free text/reload; exact final form has no second legacy editor; 10,000-Unicode boundary is backend-tested, not typed manually | PASS | NOT RUN | Real root-cause catalog |
| M7. Exact protocol label and nearby non-AI Fill action | YES | PASS | VERIFIED — exact final SHA text/action unchanged; prior exact journey proved notification and text preservation | PASS | NOT RUN | Future AI wording, if activated |
| M8. Subjective feelings | YES | PASS | VERIFIED — save/view/reload and result summary | PASS | NOT RUN | Owner wording |
| M9. Visual posture diagnostics; old objective editor read-only | YES | PASS | VERIFIED — saved/viewed; no active objective-change editor | PASS | NOT RUN | Owner wording |
| M10. Deterministic result Fill, edit, overwrite cancel/confirm, NULL-safe summary | YES | PASS | VERIFIED — exact final SHA generated, edited, cancelled, confirmed; prior exact save/reload | PASS | NOT RUN | Owner-authored wording beyond deterministic summary |
| M11. Separate before/after private photo galleries | YES | PASS | VERIFIED — three before/two after, preview/download/unlink/reload on `01014d0`; final path unchanged | PASS | NOT RUN | Size/format policy within hard cap |
| M12. Portal structured pains, own scores, free complaints, CRM first-session init | YES | PASS | VERIFIED — fresh normal QA email re-auth on final SHA; Portal synthetic client showed scores `0/5/10`, free complaint, no global/frequency/time controls; CRM modal showed same values; first-session carry-forward was previously proven | PASS | NOT RUN | Owner profile content |
| M13. Repeatable injuries/operations with conditional UX | YES | PASS | VERIFIED — prior Portal 0/1/3/remove-middle/reload journey; final CRM modal showed exact remaining operation/injury rows | PASS | NOT RUN | Real injury/operation content |
| M14. Anamnesis master-only and retained | YES | PASS | VERIFIED — final Portal has no anamnesis; direct Portal session redirects to admin login; CRM master field is visible/editable; prior save/reload evidence | PASS | NOT RUN | Owner anamnesis text |
| M15. `Результаты анализов` uploads across Portal/CRM | YES | PASS | VERIFIED — final Portal shows block; final CRM `Файлы и МРТ` shows Portal files and master upload; authorized download was previously exercised | PASS | NOT RUN | None |
| M16. Separate audio section, playback/download/delete/reload, client denial | YES | PASS | VERIFIED — full CRM upload/play/download/delete/reload journey on `01014d0`; final SHA relation tab and policy are present, with no code delta in this path | PASS | NOT RUN | Formats/max business size |

## Analytics source matrix

| ID / source requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED | OWNER CONTENT REQUIRED |
|---|---|---|---|---|---|---|
| A1. Taxonomy, common tag, AI existing/new candidate lifecycle, filters/statistics | YES; lifecycle and empty-taxonomy support are implemented/tested | PASS | NOT VERIFIED — review UI was opened and a real action was attempted, but provider/prompt/catalog content was absent, so no candidate lifecycle was fabricated | PASS | NOT RUN | Taxonomy labels, AI prompt/provider and activation |
| A2. Average pain; NULL excluded, 0 included, denominator disclosed, screen/XLSX | YES | PASS | VERIFIED — pain dimension, shared cohort and Excel action on exact prior runtime; final path unchanged | PASS | NOT RUN | None |
| A3. Therapy result 1/2/3 and configured percentage | YES; problem-specific axis, no invented formula | PASS | NOT VERIFIED — populated synthetic 1/2/3 fixture was not created in this browser pass | PASS | NOT RUN | Effectiveness formula/metric definition |
| A4. Satisfaction 0–10, including 0 and 10 | YES; PostgreSQL CHECK and client/manual paths | PASS | PARTIALLY VERIFIED — aggregate screen was rendered; client submission of both boundary values was not re-exercised in this pass | PASS | NOT RUN | None |
| A5. “What was missing” client/therapist paths and themes | YES; configurable scenario and safe disabled default | PASS | NOT VERIFIED — no owner-authored scenario/theme was enabled | PASS | NOT RUN | Feedback wording, timing and themes |
| A6. Client goals/tasks, repeated categories and same AI taxonomy | YES; existing goal/tag authority is reused | PASS | NOT VERIFIED — populated goal/classification journey was not run | PASS | NOT RUN | Goal taxonomy/content |
| A7. Client categories and saved/manual/dynamic groups; atomic CRUD/versioning/archive/concurrency | YES | PASS | VERIFIED — manual/dynamic create, reload, invalid second row no-partial-write, archive and stale-editor error on `01014d0`; final code delta does not touch this path | PASS | NOT RUN | Segment thresholds/service mappings |
| A8. Age filters/ranges/timezone and human configuration | YES; generated hidden keys and overlap validation | PASS | VERIFIED — final SHA owner UI: overlap produced human notification, correction/archive/reload preserved defaults; console preview used same settings | PASS | NOT RUN | Real age labels/ranges |
| A9. Sex/women/men/children, unknown-safe counts and exact drill-down | YES | PASS | PARTIALLY VERIFIED — demographic charts/drill-down rendered; all unknown/tenant variants were not separately seeded | PASS | NOT RUN | None |
| A10. Country, unknown bucket, exact missing drill-down, tenant isolation | YES | PASS | PARTIALLY VERIFIED — country filter and aggregate drill-down rendered; missing-country/second-tenant browser slice was not separately opened | PASS | NOT RUN | None |
| A11. Services/visits/specialists, semantics, drill-down and Excel | YES; existing Service UI remains authority | PASS | VERIFIED — populated service/specialist rows, completed/cancelled semantics, drill-down and console export evidence on `01014d0`; final path unchanged | PASS | NOT RUN | Owner service categories |
| A12. LTV percentage without invented formula | YES; monetary LTV remains; percentage disabled/configuration-required | PASS | VERIFIED — final metric settings showed `ltv_change_percent` disabled and explicit configuration-required copy; console showed no fake KPI | PASS | NOT RUN | Exact LTV% formula/activation |
| A13. Finance income/expenses/cash/accounts, periods, authorization, Excel | YES | PASS | VERIFIED — populated finance screen and export action on `01014d0`; no finance code changed afterwards | PASS | NOT RUN | Currency/business policy |
| A14. Acquisition source labels/aliases/order/UTM with hidden stable keys | YES | PASS | VERIFIED — final settings expose human labels/aliases/order; console showed source aggregation; technical keys are hidden | PASS | NOT RUN | Owner aliases/mappings |
| A15. Purchase grouping over canonical types; duplicate guard and historical display | YES | PASS | VERIFIED — final SHA human selector and duplicate notification; populated purchase grouping displayed human labels on prior console | PASS | NOT RUN | Owner purchase grouping |
| A16. Booking statuses/events completed/rescheduled/cancelled | YES; status and history event axes remain separate | PASS | VERIFIED — populated counts/filter/drill-down on prior exact console; final path unchanged | PASS | NOT RUN | None |

## Console, charts and Excel

| Requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED |
|---|---|---|---|---|---|
| Selectable sections, `Анализировать`, preview status/error/retry and shared cohort | YES; explicit idle/loading/success/invalid/error state | PASS | VERIFIED — exact console journeys covered populated, empty `0`, invalid-date field error, correction, reload and mobile overflow; partial-failure injection was not forced | PASS | NOT RUN |
| Charts use the same aggregate payload as tables | YES | PASS | VERIFIED — prior Analyze rendered matching tables and 92 SVG chart elements; final console loaded with the same runtime path | PASS | NOT RUN |
| Excel: clients, filters, thematic sheets, timezone/period/rule versions/generated_at, typed cells and injection safety | YES | PASS | VERIFIED — real `.xlsx` download `analytics-2026-10-04-2026-11-02.xlsx` on exact prior runtime; writer/type/security checks pass | PASS | NOT RUN |
| 0/1/many dimensions, empty vs invalid/error, reload/back/forward, responsive layout | YES | PASS | VERIFIED — Chromium 390/768/1440 console journeys; WebKit was blocked by local DNS, not application behavior | PASS | NOT RUN |

## Acceptance records

- Portal authentication: saved client-2 state returned 401; a synthetic
  `@qa.invalid` client was authenticated through the ordinary Portal email
  code flow and staging Mailpit, without owner credentials or auth bypass.
- Exact final medical regression (`d168eeaf`): ordered labels were observed;
  no global VAS or active objective editor; protocol text stayed unchanged;
  result overwrite cancel preserved edited text and confirm replaced it with a
  deterministic summary; session 25 showed previous after `1/10`, `0/10`,
  `3/10` and current before `Не указано`.
- Exact final owner settings: overlapping age range and duplicate active
  purchase mapping both produced human validation notifications and no partial
  write; rows were removed and base configuration reloaded.
- Exact final staging smoke: `scripts/staging-smoke.sh` PASS, including health,
  PostgreSQL/pgvector, Redis, Horizon, scheduler, Mailpit, private storage,
  CRM and Portal projections.
- No migration was required for the two browser-found fixes. Existing
  PostgreSQL gates for migrations, tenant FKs, nullable medical values,
  feedback checks, JSON snapshots, timestamps/timezones, window functions,
  aggregates, finance and XLSX sources remain PASS.
- Focused regression tests added for the two browser-found validation defects:
  `AnalyticsAgeRangeSettingsTest` and `AnalyticsPurchaseGroupSettingsTest`.
- Local checks PASS: targeted PHPUnit, `vendor/bin/pint --dirty`,
  `git diff --check`, and changed-file syntax/static checks.
- WebKit acceptance is `NOT VERIFIED`: the local Playwright WebKit process
  could not resolve the staging hostname; no product failure was inferred.

## Defects and limits

### Defects found and fixed

1. Overlapping age-range submission raised a validation exception without a
   visible owner notification. Fixed with a Filament validation notification
   and regression coverage; deployed in `966072d` and retained in final SHA.
2. Duplicate active purchase mapping had the same silent-failure behavior.
   Fixed with a human notification and regression coverage; deployed in final
   SHA `d168eeaf`.

No reproduced source-scope `BLOCKING` or unresolved `REAL BUG` remains.

### Remaining NOT VERIFIED

- AI candidate browser lifecycle (provider/prompt/taxonomy content absent).
- Populated synthetic A3 1/2/3 therapy-axis fixture.
- Populated A5 feedback-theme and A6 goal/task classification journeys.
- Some unknown/second-tenant drill-down variants and partial-failure retry.
- WebKit browser project because of the local DNS blocker.

### Owner content required

Real clinical tests/causes/root causes, feedback wording/timing/themes, goal
taxonomy, AI prompt/provider activation, business thresholds and mappings,
audio formats/max size, effectiveness definition, exact LTV percentage formula,
and any legal copy remain configurable owner decisions. Safe defaults are
disabled where activation would otherwise invent business meaning.

### Deliberately not changed

- PR #67 release-retention work.
- Medical/business catalogs, formulas, legal text, AI prose or feedback copy.
- Production deployment, merge, squash, rebase, force-push or Ready transition.
- The unrelated staging reset failure for synthetic client 291; the normal UI
  reported a safe reference and preserved data, so no destructive workaround
  was used.
