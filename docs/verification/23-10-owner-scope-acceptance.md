# 23.10 owner-scope acceptance matrix

This ledger reconciles the two primary owner sources, read in full:

- `ТЗ_для_Ивана_заполнение_данных_клиента_23_10_26.md`;
- `ФИЛЬТРЫ КЛИЕНТОВ.docx`.

## Current Medical final handoff (authoritative for this review)

This section is the fresh consolidated Medical evidence pack. It supersedes
older Medical rows below; the analytics ledger is retained separately for audit
history and is not re-audited by this pass.

| Item | Current value |
|---|---|
| Starting PR SHA | `eb800e9b8fcbd4e59cae0f4c2965bf9aa52a9cdc` |
| Starting application SHA | `ab4e3db9e396cc2f782bdf9cab8693c4be79d7a1` |
| Final application SHA | `ec2c26798333616c3bda0f90b8894a84734c3326` |
| Exact staging SHA | `ec2c26798333616c3bda0f90b8894a84734c3326` |
| Evidence-test head before this documentation update | `320c2db78d665936d92e5f648c6100ca474701bb` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 |
| PR state | `Draft / Open / DO NOT MERGE` |
| Owner acceptance | `NOT RUN` |

### Fresh Medical M1–M16 matrix

Each row is `IMPLEMENTED`; DB-sensitive rows also have the hosted PostgreSQL
gate below. Chromium evidence is the fresh exact-runtime journey; WebKit is the
focused MR-1 journey where noted; staging is the protected deployment at the
exact application SHA above. Owner acceptance remains `NOT RUN`.

| ID | IMPLEMENTED | PostgreSQL | Chromium | WebKit | Staging | OWNER ACCEPTANCE |
|---|---|---|---|---|---|---|
| M1 | Yes — exact active order, create/edit, no global VAS/old objective/root-cause hypothesis editors | PASS | VERIFIED — CRM order: боли → тесты → причины → первопричина → протокол → субъективные ощущения → осанка → результат → фото; create and edit checked | Not required for focused MR-1 | PASS | NOT RUN |
| M2 | Yes — independent repeatable pains, frequency, time-of-day, nullable/0 scores, special complaints | PASS | VERIFIED — consolidated run `41513311767` plus focused M2 run `41512574506`: 3 independent rows, save/reload/edit/delete, permanent/count-period, multi time-of-day, NULL and explicit 0 | Not required for focused MR-1 | PASS | NOT RUN |
| M3 | Yes — first profile seed, previous-session carry-forward, empty previous session stays `[]`, inactive historical references and tenant checks | PASS | VERIFIED — first/next/delete-all flows, previous after beside empty current before, zero retained, per-pain history opened from UI | Not required for focused MR-1 | PASS | NOT RUN |
| M4 | Yes — owner-configurable catalog, before/after, abnormal state and inactive historical readability | PASS | VERIFIED — synthetic owner/admin catalog and same catalog authority in session | Not required for focused MR-1 | PASS | NOT RUN |
| M5 | Yes — abnormal-test derivation, manual correction, restore-derived path, deduplication | PASS | VERIFIED — synthetic abnormal test → causes, manual correction, restore, save/reload | Not required for focused MR-1 | PASS | NOT RUN |
| M6 | Yes — max two configurable references plus free text and historical inactive readability | PASS | VERIFIED — one/two roots, third rejected, free text and reload | Not required for focused MR-1 | PASS | NOT RUN |
| M7 | Yes — authored protocol text and nearby non-AI `Заполнить` behavior | PASS | VERIFIED — authored text preserved; Fill is deterministic/non-AI and reload-safe | Not required for focused MR-1 | PASS | NOT RUN |
| M8 | Yes — editable protected subjective feelings and result input | PASS | VERIFIED — unique text save/reload and included in result | PASS — create draft value included | PASS | NOT RUN |
| M9 | Yes — editable current posture, no conflicting legacy editor | PASS | VERIFIED — unique posture save/reload and included in result | PASS — create draft value included | PASS | NOT RUN |
| M10 | Yes — deterministic current-draft Result Fill, overwrite confirmation and edit parity | PASS | VERIFIED — CREATE unsaved pain/subjective/posture/test changes reflected; Cancel preserves manual text, Confirm replaces, save/reload; EDIT parity also passed | PASS — run `41500554077`, 1/1, 52.6s | PASS | NOT RUN |
| M11 | Yes — private before/after galleries, multiple files, preview/download/unlink/reload | PASS | VERIFIED — 3 before + 2 after, upload dates visible, preview/private download, unlink one and reload | Out of focused MR-1 scope | PASS | NOT RUN |
| M12 | Yes — Portal structured complaints with own nullable scores, free complaint, no frequency/time/global scale | PASS | VERIFIED — Portal 390px save/reload; CRM same structured data and first-session initialization | Not required for focused MR-1 | PASS | NOT RUN |
| M13 | Yes — injuries and operations repeaters with presence UX and no resurrection | PASS | VERIFIED — both collections 0/1/3, delete middle, save/reload, CRM exact remaining rows | Not required for focused MR-1 | PASS | NOT RUN |
| M14 | Yes — master/staff-only anamnesis with protected tenant authorization | PASS | VERIFIED — Portal hidden/direct denied; CRM master edit/save/reload | Not required for focused MR-1 | PASS | NOT RUN |
| M15 | Yes — authorized private analyses/reports across Portal and CRM | PASS | VERIFIED — Portal upload/reload/download, CRM shared visibility and staff upload, unauthorized/cross-tenant denied, no raw path | Not required for focused MR-1 | PASS | NOT RUN |
| M16 | Yes — separate master-only audio records with title/file/play/download/delete/reload | PASS | VERIFIED — 3 synthetic records, play/download, delete one, reload leaves 2; Portal denied | Not required for focused MR-1 | PASS | NOT RUN |

### Fresh evidence runs

- PostgreSQL Medical gate: hosted run `41493607472`, job `125442690438` —
  PostgreSQL connection/schema confirmed; `122 passed`, `829 assertions`.
  The focused suite covered MedicalPainFlow, first-session profile
  initialization, carry-forward, previous-after `0`, zero-pain no-fallback,
  inactive historical period/tag references, foreign/tenant rejection,
  nullable scores and `0` vs `NULL`, session ordering/exclusion, transactions,
  and private media metadata/auth constraints.
- Chromium CRM Medical journey: fresh consolidated hosted run `41513311767`,
  job `125510392061` — 5/5 passed in 2.4 minutes on application SHA
  `ec2c267…`; exact active order, medical catalog/causes/root causes,
  CREATE and EDIT Result Fill, three independent pains with permanent and
  count-period frequency, multi time-of-day, NULL/0, delete/reload,
  3+2 photos, upload dates, private preview and download, unlink/reload,
  audio and per-pain history were exercised.
- Focused M2 rerun: hosted run `41512574506`, job `125507898728` — 1/1
  passed in 26.7s on evidence-test head `320c2db7…`; persisted frequency,
  time-of-day and explicit zero were asserted after reload before deleting the
  middle pain.
- Chromium Portal journey: hosted run `41494732858`, job `125446582917` —
  mobile 390px, 1/1 passed in 45.1s on application SHA `ec2c267…`; profile
  pains, score `0`, nullable/free complaint, no forbidden controls,
  injuries/operations 0/1/3/delete-middle/reload, Portal files, CRM
  cross-surface visibility and denied Portal anamnesis/audio paths were
  exercised.
- Focused WebKit MR-1: fresh hosted run `41514224413`, job `125513519189` —
  1/1 passed in 1.1 minutes on final PR head `87e77a0c…`; CREATE draft values,
  explicit zero, confirmation Cancel/Confirm, save and reload passed.
- Protected staging deploy: `make deploy-staging` succeeded; read-only
  `/opt/chuklov-staging/REVISION` matched `ec2c267…`; app, PostgreSQL,
  Redis, Horizon, scheduler, Telegram and private storage health were green.
  `scripts/staging-smoke.sh` passed all checks (public DNS required a local
  `curl --resolve` transport override; no staging configuration changed).

### Medical findings and completeness

- MR-1 was a confirmed REAL BUG: CREATE Result Fill previously stopped at
  `Сначала сохраните сеанс`. It is fixed by shared draft-to-result generation
  from current unsaved form state; no hidden save and no AI call.
- Harness-only issues found during the pass (WebKit photo/select locators and
  the deploy normalizer temporary path) were fixed without changing Medical
  runtime semantics. They are not remaining Medical findings.
- Medical BLOCKING: `0`.
- Medical REAL BUG: `0` (MR-1 closed).
- Medical MATERIAL NOT VERIFIED: `0`.
- Additional literal Medical source gaps: **No additional literal Medical
  source gap found**.
- Legitimate Medical OWNER CONTENT REQUIRED: real clinical test catalog,
  causes/root-cause labels, medical wording, audio MIME/size policy values,
  effectiveness formula and legal copy. Synthetic labels proved the
  owner-configurable mechanisms; these values do not block implementation.

The older analytics-focused handoff and matrices below are retained as an
audit trail and are not current Medical evidence.

## Current Analytics final handoff (authoritative for this review)

This is the one fresh consolidated Analytics evidence pack for the final
implementation pass. It supersedes the older Analytics rows below; no old run
is used to downgrade a requirement that is directly covered here.

| Item | Current value |
|---|---|
| Starting PR SHA | `d83da17a4f3a508a5d5da1f087bf35a35b93edfd` |
| Starting application SHA | `ec2c26798333616c3bda0f90b8894a84734c3326` |
| PR HEAD at evidence execution | `3631a6f36976fc007572f1027bf3099b827cd49e` |
| Final application SHA | `3631a6f36976fc007572f1027bf3099b827cd49e` |
| Exact staging SHA | `3631a6f36976fc007572f1027bf3099b827cd49e` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 |
| PR state | `Draft / Open / DO NOT MERGE` |
| Owner acceptance | `NOT RUN` |
| Medical status | `MEDICAL READY FOR OWNER ACCEPTANCE` (unchanged) |

### Fresh A1–A16 matrix

Every row below is `IMPLEMENTED / VERIFIED`. The hosted PostgreSQL job used
the production-equivalent PostgreSQL/pgvector service and the hosted Chromium
pack exercised the real CRM controls, reloads, exports and negative paths.

| ID | IMPLEMENTED | PostgreSQL | Chromium | Staging | OWNER ACCEPTANCE |
|---|---|---|---|---|---|
| A1 | Yes — complaints/categories, multiple tags, tenant-scoped taxonomy and automatic candidate lifecycle | PASS — analytics feature/AI/lease/tenant suite | VERIFIED — taxonomy/candidate review and source-save → afterCommit → queue → provider → NeedsReview → review/association; safe-disabled path | PASS — exact SHA and smoke | NOT RUN |
| A2 | Yes — average pain before/after with nullable exclusion and zero inclusion | PASS — PainAnalytics/Projection/XLSX tests | VERIFIED — non-trivial cohort, denominator and drill-down/export agree | PASS | NOT RUN |
| A3 | Yes — problem-specific session 1/2/3 axis and configured metric seam | PASS — Goal/Pain/session-window tests | VERIFIED — dedicated problem-session journey, duplicate-row protection, screen/XLSX agreement | PASS | NOT RUN |
| A4 | Yes — client-specific satisfaction 0–10 with idempotent/concurrent contract | PASS — NPS PostgreSQL concurrency | VERIFIED — 0 and 10, missing distinct, invalid values rejected | PASS | NOT RUN |
| A5 | Yes — score plus text, Portal/CRM entry, themes and aggregation | PASS — feedback/theme analytics tests | VERIFIED — positive/zero text retained, theme aggregation and reload | PASS | NOT RUN |
| A6 | Yes — goals/tasks, repeated categories, shared configurable taxonomy and period basis | PASS — GoalAnalytics plus cohort/drill-down regressions | VERIFIED — repeated task categories, filter/drill-down and current `starts_on` period semantics | PASS | NOT RUN |
| A7 | Yes — built-in/manual groups, archive/delete, versions and atomic stale handling | PASS — saved-group transaction/version tests | VERIFIED — create/edit/reload/archive, stale conflict and concurrent addition survives | PASS | NOT RUN |
| A8 | Yes — owner-managed human age ranges and overlap validation | PASS — age configuration/analytics tests | VERIFIED — invalid overlap → correction → save, boundaries and unknown DOB | PASS | NOT RUN |
| A9 | Yes — sex/children counts, percentages, unknown and tenant-safe drill-down | PASS — demographic/cohort tests | VERIFIED — multiple values, unknown denominator and drill-down | PASS | NOT RUN |
| A10 | Yes — country distribution, percentages and known-country count | PASS — country aggregate/predicate/XLSX tests | VERIFIED — KZ/RU/unknown, `Стран представлено: N`, unknown-only/empty zero, filtered KZ=1 and tenant isolation | PASS | NOT RUN |
| A11 | Yes — services, visits and specialists with period/cohort semantics | PASS — aggregate/drill-down tests | VERIFIED — multiple service/specialist aggregates, percentages, drill-down and export | PASS | NOT RUN |
| A12 | Yes — monetary LTV retained; percentage mode is safe-disabled/configurable without invented formula | PASS — finance/metric tests | VERIFIED — monetary metric available; no fabricated percentage calculation | PASS | NOT RUN |
| A13 | Yes — income, ordinary expenses, movement/accounts and period | PASS — FinanceMovementAnalytics/PostgreSQL tests | VERIFIED — unrestricted ordinary expense `2500`, `-2500` movement, screen/XLSX and meaningful cohort behavior | PASS | NOT RUN |
| A14 | Yes — owner-managed acquisition labels/aliases with hidden stable keys | PASS — attribution/source tests | VERIFIED — multiple sources/unknown, counts/percentages, drill-down and XLSX | PASS | NOT RUN |
| A15 | Yes — owner-managed purchase mappings with duplicate validation | PASS — purchase-group tests | VERIFIED — invalid → correction → save, multiple categories, filter/statistics/export/reload | PASS | NOT RUN |
| A16 | Yes — event-aware completed/rescheduled/cancelled booking status analytics | PASS — booking/event analytics tests | VERIFIED — repeated events, selected period, cohort, screen/XLSX and drill-down | PASS | NOT RUN |

### Fresh consolidated evidence

- Hosted PostgreSQL Analytics run `41575014914`, job `125716380208` —
  PostgreSQL connection confirmed by `db:show`; `175 passed / 1,199
  assertions` in the Analytics/feedback/M11C command and `3 passed / 21
  assertions` in the filtered concurrency/NPS command: **178 tests / 1,220
  assertions, PASS**.
- Hosted Chromium Analytics run `41574527435`, job `125714854609` — the
  consolidated desktop filter ran **12 tests, 12 passed**. It covered the
  country total/XLSX path, problem-specific session numbering, service and
  problem exact drill-down, typed unknown-country predicate, invalid/corrected
  custom period, configurable metrics and Excel, taxonomy/candidate review,
  automatic AI source lifecycle, saved groups and attribution defaults.
  The focused automatic lifecycle rerun `41573804259` was also 1/1 PASS after
  the final source-scoped regression; the consolidated run is the acceptance
  evidence.
- Protected staging deploy: `make deploy-staging` succeeded for exact
  `3631a6f36976fc007572f1027bf3099b827cd49e`; read-only
  `/opt/chuklov-staging/REVISION` matched exactly. Application, PostgreSQL,
  pgvector, Redis, Horizon, scheduler, Telegram, private storage and protected
  host routing were healthy. `scripts/staging-smoke.sh` returned
  **STAGING SMOKE: PASS**.
- WebKit: `NOT NEEDED` for this Analytics pass. No Analytics production browser
  or UI code changed; the changes are backend period semantics, encrypted
  source-change detection and hosted-test evidence harness. Existing relevant
  WebKit evidence remains applicable, and Chromium is the fresh browser gate.

### Non-numbered literal Analytics requirements

All are `VERIFIED` in the same fresh pack: one global cohort under
`Кого анализируем`; dimensions separately under `Что считаем`; AND across
different groups; same-field OR; tags ANY/ALL/EXCLUDE; explicit period basis
(`tasks.starts_on` for goals/tasks); NULL distinct from 0; checkboxes →
`Анализировать` loading/success → cohort change → second analysis without a
manual refresh; matching on-screen statistics/charts/drill-down/XLSX using the
same cohort; typed/numeric workbook values; drill-down as base cohort AND exact
aggregate predicate with back navigation preserving the original context;
preview validation/application error with visible retry/error rather than a
false `0 клиентов`; saved-group reload, archive and stale/concurrent conflict;
automatic AI source-change lifecycle; and owner-managed configurable labels,
taxonomies, ranges and mappings without developer/deploy changes.

### Defects found and fixed in this pass

- **A6 REAL BUG — fixed:** goals/tasks and the matching goal cohort/drill-down
  used `tasks.created_at` for period filtering, which excluded tasks whose
  `starts_on` was inside the selected period. Runtime fix and regression are in
  `8fd5a9c1`; the final PG/Chromium pack proves `starts_on` semantics.
- **A1 REAL BUG — fixed:** randomized medical encryption caused unchanged pain
  rows to look like source changes, dispatching automatic AI jobs for unrelated
  rows. Profile/session pain writers now preserve ciphertext when plaintext is
  unchanged at the same key version; regression is in `c2c930ce` and the final
  source-save journey proves only the changed source enters the lifecycle.
- E2E-only queue/locator assertions were tightened during the pass; they did
  not change product behavior and are not remaining findings.

### Analytics completeness

- Additional literal Analytics source gaps: **0 — No additional literal
  Analytics source gap found.**
- Analytics BLOCKING: `0`.
- Analytics REAL BUG: `0`.
- Analytics MATERIAL NOT VERIFIED: `0`.
- Legitimate OWNER CONTENT REQUIRED: production tags/categories and thresholds;
  feedback themes and wording; acquisition aliases; purchase mappings; goals
  taxonomy; AI prompt/provider activation; and the owner-defined LTV percentage
  and effectiveness formulas. Synthetic data verified the configurable seams;
  no formula or business content was invented.
- Owner acceptance remains `NOT RUN`; PR remains `Draft / Open / DO NOT MERGE`.

## Historical consolidated handoff (analytics ledger; retained for audit trail)

This section supersedes the historical matrix retained below. It records the
evidence pass, the one minimal runtime fix required by the browser journey, and
the test/documentation head that carries the strengthened regressions.

| Item | Current value |
|---|---|
| Starting PR SHA | `23631d29bf89b7b9b15203863f3df62184fe07d8` |
| Starting application SHA | `d3b3931f963ce3499c877d7d01c91de42f22272a` |
| Evidence-test PR head before this handoff | `e4d6a478ad21e4aa1840d2a30b2f8edd5250e2b1` |
| Current PR HEAD | Documentation-only handoff commit on top of `e4d6a478ad21e4aa1840d2a30b2f8edd5250e2b1` |
| Application/runtime SHA | `ab4e3db9e396cc2f782bdf9cab8693c4be79d7a1` |
| Exact staging SHA | `ab4e3db9e396cc2f782bdf9cab8693c4be79d7a1` |
| Branch / PR | `codex/23-10-medical-analytics` / #61 |
| PR state | `Draft / Open / DO NOT MERGE` |
| Owner acceptance | `NOT RUN` |

F3 and F4 are closed by the focused backend regressions and final hosted
Chromium CRM run `41469799120` (desktop project, 2/2). F8 is now VERIFIED
end-to-end: hosted PostgreSQL run `41480243617` proved the credentialless
provider persistence contract, and hosted Chromium run `41483745051` (job
`125408525760`, checkout `e4d6a478…`, desktop project, 1/1) proved a real CRM
source save → after-commit dispatch → queue worker → controlled synthetic
provider → NeedsReview candidate, with no manual AI action, plus the disabled-
setting negative case and reload persistence. The runtime candidate for that
browser evidence is `ab4e3db9…`; the E2E-only follow-up is `e4d6a478…`.
F9 is closed by the same PostgreSQL run's deterministic lease-fencing
regression.
A10 remains closed: known-country total is derived from the same materialized
buckets as the distribution; `Не указана` remains visible and in the percentage
denominator but is not counted; empty and unknown-only cohorts return zero;
filters, drill-down and tenant isolation use the same cohort.

### Consolidated current capability matrix

Rows below retain the prior source reconciliation. The current evidence additions
are called out explicitly; `OWNER ACCEPTED` remains `NOT RUN`.

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
| M10 | PASS | Result Fill now uses validated current draft; all four draft source categories, cancel/confirm semantics and reload covered by Chromium `41469799120`. |
| M11 | PASS | Private before/after media and authorization; existing protected-media evidence. |
| M12 | PASS | Portal structured pains and CRM path; existing Portal/CRM evidence. |
| M13 | PASS | Portal injuries/operations repeaters; existing Portal/CRM evidence. |
| M14 | PASS | Master-only anamnesis; existing authorization evidence. |
| M15 | PASS | Private analyses/reports; existing protected-file evidence. |
| M16 | PASS | Master-only private audio; existing protected-media evidence. |
| A1 | PASS | Empty taxonomy, NeedsReview, accept/rename/merge/reject/stale and current-run fencing; Chromium `41469799120` plus PostgreSQL `41464731519`. |
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
| F3 result Fill ignored unsaved draft | CLOSED; backend draft-input regression PASS and Chromium `41469799120` proves pain, subjective, posture and test draft values, cancel/confirm, save/reload. |
| F4 taxonomy mutation invalidated the run under review | CLOSED; lifecycle regression and Chromium `41469799120` prove candidate accept/rename, authoritative source association and analytics cohort filtering. |
| F5 stale saved-group form archived concurrent additions | CLOSED; transactional/version and Chromium regression PASS. |
| F6 positive score discarded feedback text | CLOSED; Portal + CRM boundary regression and Portal Chromium PASS. |
| F7 ordinary expenses disappeared with neutral cohort object | CLOSED; focused finance regression PASS. |
| F8 automatic AI suggestion trigger missing | CLOSED; PostgreSQL `41464731519`/`41480243617` prove source mutation → after-commit dispatch → actual job → NeedsReview and safe-disabled behavior; Chromium `41483745051` proves the same path from the real CRM save with a controlled provider, no manual AI action, NeedsReview visibility, reload persistence and disabled-setting negative. A credentialless-provider UUID/FK persistence bug found by this journey was fixed in application SHA `ab4e3db9…` and covered by the focused PostgreSQL regression. |
| F9 abandoned Processing classification was unrecoverable | CLOSED; PostgreSQL `41464731519` proves expiry reclaim, active-lease fencing and stale worker A cannot overwrite worker B. |
| NV1 PostgreSQL NPS duplicate/concurrency evidence | CLOSED; prior hosted run `41450734917` remains the NV1 evidence, 109 tests / 670 assertions PASS. |
| A10 country total | CLOSED; exact current application SHA evidence above. |

### Current quality gates

- Local focused checks: PASS — F3 backend 1 test / 8 assertions; F4 backend 1
  test / 6 assertions; existing F8/F9 feature checks 2 tests / 12 assertions;
  credentialless-provider regression 1 test / 6 assertions; changed PHP syntax,
  Pint, changed-file PHPStan, TypeScript ESLint and `git diff --check` passed.
- Hosted PostgreSQL run `41464731519`, job `125342527970`: PASS — 111 tests /
  688 assertions, including the new F8 after-commit and F9 competing-worker
  tests. Focused hosted run `41480243617`, job `125396327372`: the targeted
  PostgreSQL feature test passed (1 test / 6 assertions); the aggregate Quality
  job was red only on pre-existing unrelated PHPStan baseline findings.
- Hosted Chromium run `41469799120`, job `125360074854`: PASS — 2/2 focused
  desktop journeys (F3 medical draft Fill and F4 candidate-to-source-to-
  analytics). Final F8 Chromium run `41483745051`, job `125408525760`: PASS —
  1/1 desktop journey on `e4d6a478…`; it exercised the real CRM source save,
  automatic worker path, controlled provider, NeedsReview candidate, reload and
  disabled-setting negative. The earlier timeout was the test harness blocking
  its embedded provider with `execFileSync`, not a product failure.
- WebKit was not rerun in this pass: production/browser code did not change and
  the missing contracts were backend/concurrency evidence gaps.
- Protected deploy script deployed exact staging SHA `ab4e3db9e396cc2f782bdf9cab8693c4be79d7a1`;
  two earlier attempts fail-closed on changing unrelated host listening-port
  inventory and automatically restored `d3b3931f…`; the successful attempt
  matched protected host services/routing and reported healthy app, PostgreSQL,
  Redis, Horizon, scheduler and Telegram.
- `scripts/staging-smoke.sh`: PASS at exact
  `ab4e3db9e396cc2f782bdf9cab8693c4be79d7a1`; read-only
  `/opt/chuklov-staging/REVISION` matched, and Nginx, health, queue identity,
  CRM, Portal, private storage and reversible domain checks passed.
  The app, PostgreSQL, Redis, Horizon, scheduler, private storage, CRM and
  Portal checks passed through the authorized SSH loopback health tunnel because
  public staging DNS was unavailable. No DNS or product configuration changed.

### Current conclusion

`BLOCKING = 0`, `REAL BUG = 0`. F3, F4, F8, F9 and exact staging evidence are
verified. The F8 browser journey found and closed one credentialless-provider
provenance persistence bug; the final runtime and hosted browser evidence are
green. Owner-authored clinical catalogs,
feedback wording/themes, goal taxonomy, production AI provider and prompt,
thresholds/mappings, audio policy, effectiveness formula, LTV percentage
formula, and legal/medical copy remain legitimate owner content or
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
