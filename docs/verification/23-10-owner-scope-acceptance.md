# 23.10 owner-scope reconciliation

This document is the current evidence matrix for the two owner sources dated
23.10. The sources were reread in full from:

- `ТЗ_для_Ивана_заполнение_данных_клиента_23_10_26.md`;
- `ФИЛЬТРЫ КЛИЕНТОВ.docx`.

The matrix distinguishes code presence from observable proof. `OWNER ACCEPTED`
is intentionally `NOT RUN`: only the owner can accept business content.

## Candidate and evidence boundary

| Item | Value |
|---|---|
| Starting PR SHA | `2c4010fef38d13e37a3c03f91d33e8a216200b7e` |
| Starting runtime SHA | `b743d67dc769e08ca8cc6b948803903fe95c70b7` |
| Final runtime/application SHA | `6c51f7e773fddb2a31105186f93019354a6e41d2` |
| Final PR SHA | documentation-only commit after this reconciliation (recorded in Git history) |
| Staging application SHA | `6c51f7e773fddb2a31105186f93019354a6e41d2` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 Draft, Open, DO NOT MERGE |
| Owner acceptance | `NOT RUN` |

`IMPLEMENTED` means that the runtime seam exists and is covered by focused
regression evidence. `POSTGRESQL VERIFIED` means that the relevant PostgreSQL
schema/query/transaction check was actually run. `BROWSER VERIFIED` means that
the user-visible action was exercised in a browser on the final staging
application SHA. A green unit test, a DOM field, or an HTTP 200 is not browser
verification.

## Medical source matrix

| ID / source requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED | OWNER CONTENT REQUIRED |
|---|---|---|---|---|---|---|
| M1. Active session order: pain → tests → causes → root causes → protocol → subjective → posture → result → photos; no active legacy editors | YES. `root_cause_hypothesis` is retained read-only; legacy observations are retained for history only; exact labels/order are in the session form. | PASS: session limits/relations and transactional writes covered by session feature tests and local PG migration gate. | VERIFIED. Final staging create/edit/view showed the required order, read-only legacy block, and no editable global VAS/objective-change field. | PASS: exact SHA deployed and health/smoke passed. | NOT RUN | Clinical catalog content. |
| M2. Repeatable pain constructor, optional fields, frequency/time units, 0 vs NULL, empty-row safety | YES. Add/delete rows, configurable units, nullable scores, no fake empty medical row. | PASS: nullable score/frequency checks and session persistence verified on local PostgreSQL. | VERIFIED. Three rows were saved/reloaded; unspecified/permanent/count frequency, count/week, 0, NULL and partial values were observed. | PASS | NOT RUN | Owner-selected frequency units/content. |
| M3. Carry-forward and per-pain history | YES. Previous names/frequency/time copy; current before remains NULL; previous after is retained. | PASS: session ordering and nullable score queries covered. | VERIFIED. Second staging session showed all three copied pains, previous after 1/0/3, empty current before, and client history. | PASS | NOT RUN | None beyond owner acceptance. |
| M4. Configurable clinical test catalog, groups, values, abnormal flag, lifecycle, import seam, counters | YES. Owner configuration UI and synthetic fixtures exist; inactive historical values remain readable. | PASS: catalog/session relations and constraints covered by feature tests/local PG gate. | PARTIALLY VERIFIED. Synthetic test, section, values, abnormal flag and active lifecycle were configured and used in a session; CSV/XLSX import and 40–60-row usability were not exercised in this run. | PASS | NOT RUN | Real clinical test catalog and values. |
| M5. Test causes: many, configurable, deduplicated aggregate, manual correction/restore | YES. Derived/manual/restore paths and catalog lifecycle exist. | PASS: cause/session transaction and organization scoping covered. | VERIFIED. Synthetic abnormal test cause was selected, manually edited, restored, reloaded and displayed in history. | PASS | NOT RUN | Real cause catalog. |
| M6. Max two root causes plus free text, session-local, Unicode 10,000 boundary, no partial write | YES. Shared multibyte limit, max-two validation, transactional writer; legacy hypothesis is not a second editor. | PASS: local PostgreSQL migration/write gate and focused session tests. | VERIFIED. Two root causes and comments were saved/reloaded; no active hypothesis editor appeared. Unicode boundary was not typed through browser in this run. | PASS | NOT RUN | Real root-cause catalog. |
| M7. Exact protocol label and nearby Fill notification without AI/mutation | YES. Exact label and action are adjacent; create action explains save-first and does not mutate text. | PASS: session field persistence tests. | VERIFIED. Text stayed unchanged and the save-first notification appeared. | PASS | NOT RUN | Owner wording for future AI activation, if desired. |
| M8. Subjective feelings field, save/view/reload | YES | PASS | VERIFIED. Saved text was visible in view and in the generated result summary. | PASS | NOT RUN | Owner wording/content. |
| M9. Visual posture diagnostics replaces active objective-change editor | YES. Exact field and read-only legacy history. | PASS | VERIFIED. Saved posture text was visible; no active objective-change editor appeared. | PASS | NOT RUN | Owner wording/content. |
| M10. Result field Fill summary, editable, overwrite confirmation/cancel, deterministic NULL-safe summary | YES. Summary uses pain rows, subjective, posture and abnormal counts; overwrite is explicit. | PASS: export/session aggregation and nullable score checks. | VERIFIED. Generated, edited, cancelled overwrite, confirmed overwrite, saved and reloaded the result on staging. | PASS | NOT RUN | Owner-authored result wording beyond deterministic summary. |
| M11. Separate before/after photo galleries, preview/download/unlink/private auth | YES. Private upload, typed image validation, gallery relation and generic unlink preserve the source file. | PASS: attachment relations, tenant guards and private storage checks. | VERIFIED. Three before and two after uploads, preview popup, download, unlink and reload were exercised; four linked photos remained. | PASS | NOT RUN | Owner size/formats within hard cap. |
| M12. Portal structured pains with own scores plus free complaints; CRM first-session initialization | YES. Portal/master storage and first-session carry-forward seams exist. | PASS: medical profile pain constraints and session initialization tests/local PG. | NOT VERIFIED. Saved Portal browser session is expired (401); no new authentication challenge was generated. | Code deployed; smoke only | NOT RUN | Owner-entered profile content. |
| M13. Repeatable injuries/operations with presence toggle and conditional UX | YES. Portal repeaters/toggles and master read path exist; singular legacy fields retained. | PASS: profile JSON/row persistence and tenant checks. | NOT VERIFIED. Portal session expired; conditional 0/1/3-row interaction was not exercised in browser. | Code deployed; smoke only | NOT RUN | Real injury/operation content. |
| M14. Anamnesis is master-only; old client content is retained | YES. Master field and portal authorization are separate. | PASS: policy/organization tests and profile persistence. | NOT VERIFIED. Portal denial and master edit were not both exercised because Portal session is expired. | Code deployed; smoke only | NOT RUN | Owner-entered anamnesis text. |
| M15. Portal “Результаты анализов” upload and CRM/private files cross-surface | YES. Portal upload, CRM file area and private authorization seams exist. | PASS: attachment type/tenant/private-storage tests. | NOT VERIFIED. Portal cross-surface upload was not exercised because the saved Portal session is expired. | Code deployed; smoke only | NOT RUN | None beyond owner content. |
| M16. Separate CRM audio section, playback/download/delete/reload, client denial, owner policy | YES. Audio catalog/storage, policy UI and client denial seam exist with hard server cap. | PASS: private attachment authorization and tenant checks. | PARTIALLY VERIFIED. CRM browser uploaded 3 WAV files, opened playback, downloaded, deleted one and reloaded; Portal denial was not exercised because the saved Portal session is expired. | PASS | NOT RUN | Formats/max business size and real audio files. |

## Analytics source matrix

| ID / source requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED | OWNER CONTENT REQUIRED |
|---|---|---|---|---|---|---|
| A1. Complaint taxonomy, common tag, AI existing/new candidate review lifecycle, filters/statistics | YES. Existing/new/NeedsReview/accept/rename/merge/reject/stale/audit/org/permission paths share taxonomy authority; empty taxonomy can accept a new candidate. | PASS: candidate uniqueness, tenant scope, review transitions and feedback/client guards covered by focused tests/local PG. | PARTIALLY VERIFIED. Final staging console filter/analyze flow ran; candidate review and AI new-tag lifecycle were not clicked in this browser run. | PASS | NOT RUN | Real taxonomy labels, AI prompts and activation policy. |
| A2. Average pain, NULL excluded/0 included, denominator disclosed, screen/XLSX | YES. Count is `COUNT(score)` and 0 remains valid. | PASS: local PG aggregation/read-only checks and focused tests. | PARTIALLY VERIFIED. Pain dimension, shared cohort count and XLSX action ran; a populated pain fixture was not available in this staging slice. | PASS | NOT RUN | None. |
| A3. Therapy result 1/2/3 and percentage, problem-specific session axis | YES. Typed axis and configured metric infrastructure; no invented formula. | PASS: window/ranking and aggregate tests/local PG. | NOT VERIFIED for populated 1/2/3 rows in this browser run; console Analyze and configuration-required state were observed. | PASS | NOT RUN | Owner formula/metric definition where not supplied. |
| A4. Satisfaction 0–10, client/manual entry, aggregate and PostgreSQL CHECK | YES. Portal/CRM paths and score validation. | PASS: `feedback_submissions.score_check` verified on PostgreSQL. | NOT VERIFIED for client submission in browser; console feedback section was included in a final Analyze. | PASS | NOT RUN | None beyond content. |
| A5. “What was missing” client/therapist paths, themes, timing and safe disabled default | YES. Configured scenarios, portal submission, CRM manual path and theme aggregation; no-content state is disabled safely. | PASS: feedback tables/constraints and theme aggregation tests. | NOT VERIFIED for Portal/CRM submission in browser; safe no-content state was included in final console output. | PASS | NOT RUN | Feedback wording, timing and themes. |
| A6. Client goals/tasks attached to client, categories counted, same AI taxonomy/manual correction | YES. Existing goal/tag authority is reused; no second task store. | PASS: goal/client/tag organization tests. | NOT VERIFIED for a populated goal workflow in browser. | PASS | NOT RUN | Goal taxonomy/content. |
| A7. Client categories, saved/manual and dynamic groups, atomic CRUD/versioning/concurrency/archive | YES. Preflight validation, single transaction, immutable versions, tenant/stale guards and explicit archive. | PASS: migrations, unique indexes, FKs and local PG transaction/schema gate; hosted race gate not rerun in this pass. | NOT VERIFIED for saved-group CRUD/concurrency in browser. | PASS | NOT RUN | Segment thresholds/service mappings. |
| A8. Age filter/stats/ranges/timezone; owner never types internal keys | YES. Generated hidden keys, overlap validation, human labels and timezone end-date. | PASS: local PG timezone/query checks and focused tests. | PARTIALLY VERIFIED. Age min/max invalid/corrected/empty flows and demographic Analyze ran; owner range CRUD was not exercised. | PASS | NOT RUN | Real age ranges/labels. |
| A9. Sex/women/men/children, unknown-safe counts/percentages and exact drill-down | YES. Full-cohort demographics and typed missing-value predicates. | PASS: local PG aggregate/drill-down checks. | PARTIALLY VERIFIED. Demographics, charts and exact aggregate drill-down ran on the final console; all unknown/tenant variants were not separately seeded. | PASS | NOT RUN | None. |
| A10. Country filter, unknown bucket, exact missing drill-down, tenant isolation | YES. Unknown typed predicate and organization scope. | PASS: local PG aggregate/drill-down checks. | PARTIALLY VERIFIED. Country filter changed the preview and aggregate drill-down ran; missing-country and second-tenant slices were not separately opened. | PASS | NOT RUN | None. |
| A11. Services/visits/specialists, owner-managed service grouping, exact drill-down and XLSX | YES. Existing Service UI remains authority; analytics consumes configured category. | PASS: local PG aggregate/window checks. | NOT VERIFIED for populated service/specialist rows in browser. | PASS | NOT RUN | Owner service categories. |
| A12. LTV percentage output without invented formula | YES. Canonical monetary LTV remains; undefined percent metric is inactive/configuration-required and explicit activation is preserved. | PASS: metric configuration migration/queries verified locally on PostgreSQL. | PARTIALLY VERIFIED. Final console showed configuration-required/disabled metric messaging; owner activation/formula editing was not exercised. | PASS | NOT RUN | Exact LTV% formula and activation. |
| A13. Finance income/expenses/cash/accounts, periods, auth, minor units, XLSX | YES. Existing finance authority and analytics export path. | PASS: local PG finance aggregation/schema checks. | NOT VERIFIED for populated finance rows in browser. | PASS | NOT RUN | Currency/business policy. |
| A14. Configurable acquisition sources, aliases, active/order/UTM; hidden stable keys | YES. Human label/alias/order UI generates/retains stable keys. | PASS: source settings constraints and organization scope. | PARTIALLY VERIFIED. Source filter changed the preview and displayed the human label Telegram; settings CRUD/UTM mapping was not exercised. | PASS | NOT RUN | Owner source labels, aliases and mappings. |
| A15. Purchase grouping over canonical types; hidden keys, duplicate guard, historical unknown/deactivation | YES. Human type selector and generated group key; historical mappings remain readable. | PASS: purchase JSON snapshots, unique mappings and tenant checks. | NOT VERIFIED for purchase settings or populated purchase rows in browser. | PASS | NOT RUN | Owner purchase grouping. |
| A16. Booking statuses/events completed/rescheduled/cancelled, counts/filter/drill-down | YES. Status and event axes remain separate. | PASS: local PG booking aggregate/event checks. | NOT VERIFIED for populated booking status/event rows in browser. | PASS | NOT RUN | None. |

## Console, charts and Excel

| Requirement | IMPLEMENTED | POSTGRESQL VERIFIED | BROWSER VERIFIED | STAGING VERIFIED | OWNER ACCEPTED |
|---|---|---|---|---|---|
| Selectable sections with checkboxes, `Анализировать`, loading/success/warning/error/retry, shared base cohort | YES. `AnalyticsConsole` exposes explicit status/error state and preserves successful dimensions without pretending partial success is full success. | PASS: focused dashboard/cohort/export tests and local PG query gate. | PARTIALLY VERIFIED. Final staging browser selected multiple sections, ran Analyze on cohorts 50 and 0, and exercised validation; partial-failure retry was not forced. | PASS: exact SHA deployed; smoke PASS. | NOT RUN |
| Graphical presentation uses the same aggregate values as screen | YES. Chart payload is derived from the same dimension result. | PASS in focused analytics tests. | PARTIALLY VERIFIED. Final Analyze rendered 92 SVG chart elements and matching screen counts; each chart series was not independently recalculated in browser. | PASS | NOT RUN |
| Excel download: Клиенты, Фильтры, thematic sheets, timezone/period/rule versions/generated_at, explicit cell types/formula safety | YES. Export writer uses explicit types and formula-injection protection; undefined metrics are configuration-required. | PASS: XLSX tests plus local/hosted PG source queries. | VERIFIED for the user action: final staging browser downloaded a real `.xlsx` after Analyze. Workbook cell/type assertions are covered locally. | PASS | NOT RUN |
| 0/1/many dimensions, empty cohort distinct from invalid/error, reload/back/forward/mobile overflow | YES. Preview state is `idle/loading/success/invalid/error`; failed queries do not return 0. | PASS: focused preview/error tests. | VERIFIED. Final staging browser observed non-empty 50, filtered/empty 0, reversed-date field error, correction, restore, reload/back/forward and no horizontal overflow at 390/768/1440. | PASS | NOT RUN |

## Known remaining evidence limits

- Portal browser acceptance is `NOT VERIFIED` because the saved QA Portal
  session returned 401. No credentials or MFA material was printed or
  regenerated in this pass.
- Analytics browser acceptance is complete for the console contract on the
  final staging SHA. Individual source dimensions marked `NOT VERIFIED` or
  `PARTIALLY VERIFIED` above still need their own populated fixtures and
  actions before they can be upgraded.
- Owner acceptance is `NOT RUN`.
- Real medical catalog values, authored feedback wording, business thresholds,
  mappings, audio policy choices, AI prompt activation and the exact LTV%
  formula remain owner content/configuration decisions. The technical seams
  and safe defaults are implemented.

## Verification records

- Focused local regression set: 158 tests / 977 assertions, PASS.
- Local checks: `vendor/bin/pint --dirty`, `git diff --check`, and the target
  analytics/medical feature suites, PASS.
- Local PostgreSQL: non-destructive migrations and read-only checks for the
  new migrations, unique indexes, tenant FKs/checks, nullable pain scores,
  feedback 0–10 constraint, JSON snapshots, timestamps/timezones, window
  functions and pgvector, PASS.
- Staging deploy: exact application SHA
  `6c51f7e773fddb2a31105186f93019354a6e41d2`; both new migrations applied;
  health and `scripts/staging-smoke.sh` PASS.
- Final staging browser evidence used the QA CRM account and a synthetic
  client/catalog only. Medical session IDs 24 and 25 covered the complete
  session path; the analytics console covered non-empty/empty/error/reload/
  history/mobile flows; the CRM audio relation covered three uploads,
  playback, download, delete and reload.

## Deliberately not changed

- PR #67 release-retention work was not modified.
- No medical reference catalog, effectiveness formula, LTV% formula, legal
  text, AI prose or authored feedback wording was invented.
- No physical deletion shortcut was added for historical groups, files,
  sessions or archived clients.
- No production deployment, merge, squash, rebase, force-push or Ready
  transition was performed.
