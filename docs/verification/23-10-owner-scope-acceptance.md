# 23.10 owner scope acceptance

This is the literal reconciliation of the two owner files dated 23.10. The
canonical authorities remain the existing Medical Profiles, Sessions,
Attachments, Feedback, Tracker, Attribution, Booking, Commerce, Finance and
Analytics modules.

## Current implementation iteration

This iteration started from `715b561b9e08fbc7f468f314b6f045121397da3b`.
The initial application implementation is in
`2576673fa49f0466e096e96cd9aa98c71191d7e5`. The previous staging-verified
application source is `a687639cc4cff72ee952809adb960c673ce7547f`; it includes
the PostgreSQL-compatible aggregate grouping correction and matching regression
coverage. This follow-up's application candidate is
`499c7322929a30387d56c57df36ead0c01b74750`; it adds the problem-tag and
special-segment semantics correction described below. The follow-up candidate
is not deployed because the protected staging release path still stops on the
known remote `ENOSPC` preflight failure.
The acceptance-document commits are documentation-only and are recorded in
the verification section below after the final document commit.

The current bounded pass started from `56820ac063b2424984a2c86dcf0c78d0c1599571`.
It addresses only the demographics denominator mismatch. The application
candidate is `9c77520fd06dbd4643de16f952420b415c4cab64`: demographics now use
the complete final client cohort, while Acquisition remains period-bound. The
candidate also contains the focused browser regression for the shared cohort
and demographic denominator. It has not been deployed to staging.

The two owner files were read literally. An old `ЖДËМ ПОДТВЕРЖДЕНИЯ` marker is
treated as a deferral in the 23.10 text, not as a current owner prohibition:
the latest owner decision requires a configurable mechanism with safe defaults
and synthetic verification. `IMPLEMENTED` therefore means that the technical
capability and its owner-editable seam exist; it does not mean that unprovided
medical/business content has been approved by the owner.

## PR #61 follow-up defect closure

Starting SHA for this follow-up: `263ed0550411bd6735feb9bb9ae2f186c5094f00`.
The checks below cover the six confirmed findings reproduced from that
candidate; they extend the acceptance matrix rather than creating a second
analytics or medical authority.

| # | Finding | Implemented | Authoritative path | Regression / acceptance evidence | PostgreSQL relevance | Browser relevance |
|---:|---|---|---|---|---|---|
| 1 | XLSX formula injection | YES | `AnalyticsReportExport::writeRow()` writes every export cell with an explicit PhpSpreadsheet type; no untrusted `fromArray()` path remains | `AnalyticsReportExportTest::test_formula_like_labels_remain_exact_explicit_strings_and_numbers_remain_numeric` opens the actual XLSX and checks exact values, string types, non-formula cells, numeric, boolean and null types | YES — labels and aggregates originate from tenant data; current candidate PostgreSQL gate is reported separately | YES — download path must be exercised at the final staged revision |
| 2 | Partial analytics shown as full success | YES | `AnalyticsConsole::calculateDimensions()`, `analyze()` and `export()` preserve successful dimensions, record human errors, log only safe context, and make export atomic | `AnalyticsDashboardWidgetTest` covers single failure, mixed success/warning and no partial workbook; all-success regression remains green | YES — dimension authorization/query failures run against organization-scoped data; current candidate PostgreSQL gate is reported separately | YES — warning, retry and download behavior require final staged browser verification |
| 3 | Invalid custom range silently defaulted | YES | `DashboardPeriod::validatedCustomRange()` is shared by console analyze/export; Filament field errors are populated by `validatedStateAndPeriod()` | `DashboardPeriodTest` covers missing, malformed, reversed, overlong, exact 366-day ranges; console test covers reject, correction, analyze and export | YES — period filtering and organization timezone boundaries require PostgreSQL verification | YES — invalid/corrected form and reload require final staged browser verification |
| 4 | Previous-period comparison wrong across DST | YES | `DashboardPeriod::previousCalendarPeriod()` shifts organization-local calendar dates before one UTC conversion; `AnalyticsMetricEvaluator` delegates to it | `DashboardPeriodTest` covers New York spring-forward/fall-back, today, 7-day, custom and non-DST boundaries | YES — timestamp/query boundaries require PostgreSQL verification | YES — analytics period UI requires final staged browser verification |
| 5 | Root-cause free text lacked backend limit | YES | shared `MedicalSessionFieldLimits::MaxTextLength` is used by form, CreateSession, UpdateSession and `MedicalSessionTestWriter`; validation is multibyte-safe and transactional | `MedicalPainFlowTest::test_root_cause_free_text_has_a_unicode_safe_authoritative_limit_without_partial_writes` covers 10,000 accepted, 10,001 rejected and reload preservation | YES — encrypted session write and rollback require PostgreSQL verification | YES — CRM validation and corrected submit require final staged browser verification |
| 6 | Feedback classification could cross clients | YES | `AcceptAnalyticsClassification::acceptFeedback()` checks run client against locked submission client before theme synchronization inside the existing transaction | `AnalyticsAiClassificationTest::test_feedback_classification_rejects_a_run_for_another_client_in_the_same_organization` proves controlled rejection, unchanged review state and no theme pivot write | YES — organization/client guard and transaction atomicity require PostgreSQL verification | YES — review/accept error and normal matching-client flow require final staged browser verification |

## Problem-tag and segment semantics follow-up

This follow-up started from `df9dac20272b1e486125b2861d85dfaf145a59a1` after
the selected client-segment OR correction. The confirmed problem-tag defect was
that `problem_tag_mode=any` applied every selected category with `where`,
therefore requiring all categories. The fix keeps `all` as AND, makes `any`
use a grouped OR, preserves exclude semantics as rejection when any selected
category exists, and normalizes the special `all` segment out of the typed
multiselect before the cohort query is built.

The regression contract is
`ClientCohortQueryTest::test_problem_tag_modes_use_any_or_all_and_exclude_any_matching_tag`
plus
`ClientCohortQueryTest::test_all_segment_is_normalized_before_multiselect_query`.
The synthetic fixture covers neck-only, back-only, both-category and
neither-category clients. Hosted candidate run `41286645922` executes these
feature tests against the PostgreSQL service; exact job status is recorded in
the verification section below. The new application candidate remains
`NOT VERIFIED` in staging because staging is still on
`a687639cc4cff72ee952809adb960c673ce7547f`.

## Demographics cohort denominator follow-up

The confirmed defect was that `DemographicsAnalytics` applied the selected
DashboardPeriod to `clients.created_at` after constructing the cohort. An old
client could therefore be present in the cohort preview and other dimensions
but disappear from age, sex, children/adults and country aggregates. That
incorrectly treated acquisition-by-period as the demographic denominator.

The fix keeps the existing `ClientCohortQuery` authority and starts every
demographic aggregate from the full organization-scoped final cohort. The
period-created filter remains only for the existing concrete-`Client` path.
`AcquisitionAnalytics` was not changed and continues to count only clients
created in the selected period. Age is calculated once against the local end
of the selected period in the organization IANA timezone; no second UTC
conversion is applied. Unknown birth dates and countries remain safe labeled
categories (`Не указано` / `Не указана`).

The regression fixture has three clients in one organization: an old female
KZ client, a period-created male KZ client and an older RU client with no birth
date or sex, plus a foreign-organization client. The cohort preview and
demographics both return 3; sex is 1/1/1, countries are KZ=2 and RU=1, ages
are 30–44=2 and unspecified=1, adults are 2 and unspecified=1, and all
percentages use 3 as denominator. Acquisition returns only the one new
period-created client. The birthday regression covers a birthday exactly on
the local period-end reference date and the following day.

Regression coverage is in
`DemographicsAndServicesAnalyticsTest::test_demographics_uses_the_full_cohort_while_acquisition_remains_period_bound`,
`DemographicsAndServicesAnalyticsTest::test_demographics_uses_the_period_end_for_birthday_boundaries`
and
`AnalyticsReportExportTest::test_demographics_export_keeps_the_full_cohort_denominator`.
The XLSX regression opens the generated workbook and checks that the old client
is present on `Демография` and that the cohort metadata count matches the
demographic denominator. The focused CRM browser flow also asserts that
`Найдено клиентов: N` equals `Клиентов: N` after Analyze.

## Literal reconciliation matrix

The following matrix is the current implementation decision for every numbered
requirement in the two supplied files. The path column names the read/write
authority, not only the visible Filament field.

### Medical file: `ТЗ_для_Ивана_заполнение_данных_клиента_23_10_26.md`

| Status | Literal owner requirement | Фактическое поведение / authoritative path | Расхождение | Решение | Regression evidence | PostgreSQL relevance | Browser relevance |
|---|---|---|---|---|---|---|---|
| IMPLEMENTED | Session order: pains, tests, causes, root causes, protocol, subjective feelings, posture, result, before/after photos; no global VAS or objective-changes field | `SessionForm`, `CreateMedicalSession`, `EditMedicalSession`, `SessionInfolist`, `ClinicalTestProtocol`, `GenerateSessionResult` | Old global fields remain only in the persisted legacy model | Hidden from active create/edit; retained read-only in legacy/history projections | `tests/Feature/Sessions/SessionCockpitTest.php`, `tests/Feature/Sessions/MedicalSessionTest.php` | YES — encrypted session fields and normalized relations | REQUIRED — current implementation candidate needs CRM browser run |
| IMPLEMENTED | Each pain is a repeatable row; all row fields optional; frequency can be unspecified, permanent, or count plus period; 0 differs from no score | `MedicalPainInput`, `MedicalSessionPainWriter`, `MedicalProfilePainWriter`, `SessionForm` | A fully empty repeater row has no business meaning | Empty row is skipped; partial row persists only supplied values; count requires count and active period | `tests/Unit/MedicalPainInputTest.php`, `tests/Feature/MedicalPainFlowTest.php` | YES — nullable encrypted scores and normalized pain rows | REQUIRED — empty/partial/0/NULL UI states |
| IMPLEMENTED | First session carries profile pains; later sessions carry prior rows with empty current before and visible prior after; history is per pain | `MedicalSessionPainInitializer`, `MedicalSessionPainReader`, session dynamics and client-card projections | No deviation | Existing structured rows are reused; after=0 is carried as a real value | `tests/Feature/MedicalPainFlowTest.php`, `tests/Feature/Sessions/SessionCockpitTest.php` | YES — session lineage and nullable score persistence | REQUIRED — first/second session reload journey |
| IMPLEMENTED | Configurable clinical-test catalog, grouped list, before/after quick values, abnormal counters, CSV/XLSX import, historical inactive values | `MedicalClinicalCatalog`, `ClinicalTestProtocol`, `MedicalSessionTestWriter`, `MedicalClinicalCatalogConfiguration`, catalog import actions | Old text placeholder is not the owner flow | Active catalog renders as one list; empty catalog has a CRM setup CTA; inactive saved tests remain readable | `tests/Feature/MedicalClinicalCatalogConfigurationTest.php`, `tests/Feature/MedicalPainFlowTest.php` | YES — catalog/result organization scope and historical rows | REQUIRED — 5/60-item list, live counters, mobile |
| IMPLEMENTED | Causes per abnormal test, aggregate without duplicates, manual correction, return to derived list | `ClinicalTestProtocol`, `MedicalSessionTestWriter`, cause catalog and session form controls | No authoritative algorithm is invented | Derived cause IDs are explicit; manual override is persisted and reversible | `tests/Feature/MedicalPainFlowTest.php`, `tests/Feature/Sessions/SessionCockpitTest.php` | YES — normalized cause references and transaction | REQUIRED — list-based selection and reload |
| IMPLEMENTED | Up to two root causes, selected cause or free text, stored per session | root-cause repeater in `SessionForm`, session create/update writer, `MedicalSessionFieldLimits` | No deviation | Free text is not restricted to catalog causes; 10,000 Unicode-character backend limit is shared with UI | `tests/Feature/MedicalPainFlowTest.php`, `tests/Feature/Sessions/MedicalSessionTest.php` | YES — protected write and no-partial-write invariant | REQUIRED — 10,000/10,001 validation path |
| IMPLEMENTED | Free-text algorithm/protocol and a `Заполнить` button that does not overwrite text or call AI yet | `SessionForm` hint action, `GenerateSessionResult` remains deterministic result authority | Old wording deferred AI, not the button | Notification explicitly says automatic fill is not connected; existing text is untouched | `tests/Feature/Sessions/SessionCockpitTest.php` | YES — encrypted protocol persistence | REQUIRED — click and verify unchanged text |
| IMPLEMENTED | Subjective feelings field in the requested order | `UpdateSession`, `SessionForm`, `SessionInfolist` | No deviation | Existing encrypted field retained | `tests/Feature/Sessions/SessionCockpitTest.php` | YES — encrypted field | REQUIRED — edit/view/reload |
| IMPLEMENTED | Visual posture diagnostics replaces objective changes | `UpdateSession`, `SessionForm`, `SessionInfolist` | Legacy objective text is not deleted | New writes use posture field; historical value remains readable where applicable | `tests/Feature/Sessions/SessionCockpitTest.php` | YES — encrypted field | REQUIRED — edit/view/reload |
| IMPLEMENTED | Deterministic result summary from pains, subjective feelings, posture and test counters; manual edit and overwrite confirmation | `GenerateSessionResult`, session page actions and result field | AI prose and unprovided effectiveness formula remain intentionally absent | Result is deterministic and editable; overwrite asks for confirmation | `tests/Feature/Sessions/SessionCockpitTest.php`, `tests/Feature/Analytics/AnalyticsDashboardWidgetTest.php` | YES — protected session write | REQUIRED — generate/edit/confirm/reload |
| IMPLEMENTED | Two explicit before/after photo galleries with preview, date, download, unlink, reload; reports cannot be photos | `LinkSessionAttachment`, `ListSessionAttachments`, `UnlinkSessionAttachment`, `SessionInfolist`, `ViewMedicalSession`, private attachment stack | `general` files remain a separate session-files area | `before`/`after` accepts image attachment types only; unlink removes the link, not the source attachment | `tests/Feature/Sessions/SessionAttachmentTest.php`, `tests/Feature/Attachments/MedicalAttachmentTest.php` | YES — ownership, private storage and link lifecycle | REQUIRED — 3 before/2 after preview/download/unlink |
| IMPLEMENTED | Client profile contains per-complaint 0–10 scores and no frequency/time-of-day controls | `MedicalProfileForm`, Portal medical-profile projection, `MedicalProfilePainWriter/Reader` | Existing legacy global values remain historical only | Profile writes use structured complaint rows and carry into first session | `tests/Feature/MedicalPainFlowTest.php`, `tests/Feature/September26MedicalProfileTest.php` | YES — encrypted profile rows | REQUIRED — client save/reload and CRM projection |
| IMPLEMENTED | Repeatable operations and injuries with year/description; legacy singular fields are not lost | `MedicalProfileHistoryWriter/Reader`, `medical_profile_history_entries`, Portal `MedicalProfile.vue`, CRM clinical projection | Legacy free text is not split into guessed events | New writes use the canonical structured history; legacy values remain historical/readable | `tests/Feature/September26MedicalProfileTest.php`; existing Portal E2E structured-history coverage | YES — canonical history rows and tenant scope | REQUIRED — 0/1/3 rows, remove middle row, reload |
| IMPLEMENTED | Anamnesis is master-only; client cannot view/edit it | `MedicalProfileAuthorization`, CRM clinical infolist/form, Portal public projection | Existing client-entered text is retained | Portal projection excludes anamnesis; authorized CRM retains optional field | `tests/Feature/September26MedicalProfileTest.php`, `tests/Feature/ClientWorkspaceUxATest.php` | YES — protected profile authorization | REQUIRED — client denial and CRM visibility |
| IMPLEMENTED | Client uploads analyses/MRI/reports; master sees and can upload in private files | existing client attachment ingress, private storage authorization, client workspace attachments | No deviation | Existing attachment authority reused; no analytics free-text export | `tests/Feature/September26MedicalAttachmentTest.php`, `tests/Feature/Attachments/ClientAttachmentsUxTest.php` | YES — private storage metadata and authorization | REQUIRED — Portal upload and CRM counterpart |
| IMPLEMENTED | Separate master-only audio section with title, upload, playback, download, delete; configurable safe format/size policy | `ClientAudioRecordingsRelationManager`, `MedicalAudioPolicy`, `UploadMedicalAttachment`, `MedicalAudioSettings` | Business format/size values were not supplied | Safe server allowlist/default and organization policy are editable in CRM; policy cannot exceed server hard cap | `tests/Feature/Attachments/AudioRecordingsTest.php`, `tests/Feature/Attachments/MedicalAudioPolicyTest.php` | YES — private storage and organization policy | REQUIRED — upload/play/download/delete/reload and client denial |

### Analytics file: `ФИЛЬТРЫ КЛИЕНТОВ.docx`

| Status | Literal owner requirement | Фактическое поведение / authoritative path | Расхождение | Решение | Regression evidence | PostgreSQL relevance | Browser relevance |
|---|---|---|---|---|---|---|---|
| IMPLEMENTED | Complaints/problems become filterable tags; AI may suggest common or new tags | `SuggestAnalyticsTags`, `AcceptAnalyticsClassification`, `SyncAnalyticsClassificationCandidates`, taxonomy catalogs and candidate review | AI must not silently create active tags | Existing suggestions, new candidates, review/rename/merge/reject/stale lifecycle are organization-scoped; new-candidate generation is safe-disabled until enabled | `tests/Feature/Analytics/AnalyticsAiClassificationTest.php`, `tests/Feature/Analytics/AnalyticsTagCandidateLifecycleTest.php` | YES — classification transaction and tenant scope | REQUIRED — CRM review/correction |
| IMPLEMENTED | Average pain for the selected client population | `PainAnalytics`, `ClientCohortQuery`, typed analytics source | No deviation | NULL scores are excluded; 0 remains numeric zero | `tests/Feature/Analytics/PainAnalyticsTest.php`, `tests/Feature/Analytics/ClientCohortQueryTest.php` | YES — aggregate query semantics | REQUIRED — console result |
| IMPLEMENTED | Results after client session order 1/2/3 and reduction percentage | `PainAnalytics`, `AnalyticsMetricEvaluator`, `ClientSessionAxisCatalog` | “Course” has no canonical entity in the existing model | UI/export use owner-configurable chronological client-session semantics and version the rule; standard pain reduction is the existing single formula authority | `tests/Feature/Analytics/PainAnalyticsTest.php`, `tests/Feature/Analytics/ComputedAnalyticsMetricsTest.php` | YES — session ordering and period boundaries | REQUIRED — dimension and metric preview |
| IMPLEMENTED | Satisfaction 0–10 attached to each client and aggregated | Feedback submission writer/analytics plus the forward PostgreSQL check migration | Historical 1–10 constraint was narrower than the owner UI | Forward migration permits 0..10; invalid values remain rejected | `tests/Feature/FeedbackThemesAnalyticsTest.php`, `tests/Feature/Analytics/FeedbackAnalyticsTest.php` | REQUIRED — PostgreSQL CHECK is authoritative | REQUIRED — client/CRM submission and average |
| IMPLEMENTED | Client can state what was missing after treatment/course; therapist can enter it; repeated themes aggregate | existing Feedback submission/Portal flow, `FeedbackThemeCatalog`, scenario engine `booking.completed` rules, `FeedbackAnalytics` | Exact business timing/text was not supplied | CRM-manageable scenario delay/template and configurable themes; safe default can be disabled; existing booking-completed feedback is preserved | `tests/Feature/FeedbackThemesAnalyticsTest.php`, `tests/Feature/MilestoneFiveScenarioTest.php`, `tests/Feature/Analytics/FeedbackAnalyticsTest.php` | YES — encrypted source text, theme pivots and scenario idempotency | REQUIRED — client submit, CRM classify, reload |
| IMPLEMENTED | Goals/tasks are tagged, linked to clients and counted as repeated requests | existing Tracker task/goal-tag authority, `GoalAnalytics`, classification and client-card projection | No second goals store is introduced | Profile goal text enters existing classification/Tracker path; owner can configure goal taxonomy | `tests/Feature/Analytics/GoalAnalyticsTest.php`, `tests/Feature/PortalTrackerPurchaseTest.php`, `tests/Feature/Analytics/AnalyticsAiClassificationTest.php` | YES — Tracker organization scope | REQUIRED — client and CRM views |
| IMPLEMENTED | All/new/no visits/warm lead/sleeping/cold lead/blacklist/penalty/deleted categories | `ClientSegmentQuery`, `ClientSegmentConfiguration`, `ClientSegmentSettings`, archive state and canonical booking/penalty authorities | “Штрафник” and “Удалены” have no safe invented formula/physical-delete query | Penalty is an owner-managed restriction/tag marker; warm-lead service groups and sleeping threshold are configurable; archived history is used for deleted/archive reporting without weakening retention | `tests/Feature/Analytics/ClientSegmentQueryTest.php`, `tests/Feature/Analytics/ClientCohortQueryTest.php` | YES — segment queries, archive state and historical bookings | REQUIRED — settings, filters and empty states |
| IMPLEMENTED | Age breakdown and configurable age groups | `DemographicsAnalytics`, `ClientAgeRangeCatalog`, `AnalyticsAgeRangeSettings` | No approved labels beyond the requested examples | Default ranges are safe and replaceable/reorderable/deactivatable in CRM | `tests/Feature/Analytics/DemographicsAndServicesAnalyticsTest.php`, `tests/Feature/Analytics/ClientCohortQueryTest.php` | YES — organization timezone age boundary | REQUIRED — settings/filter/result |
| IMPLEMENTED | Men/women/children counts and percentages, unknown-safe | `DemographicsAnalytics`, cohort gender/child filters | No deviation | Unknown values are not forced into a known category; zero total is safe | `tests/Feature/Analytics/DemographicsAndServicesAnalyticsTest.php` | YES — aggregate query | REQUIRED — console result |
| IMPLEMENTED | Country counts and percentages | `DemographicsAnalytics`, cohort country filter | No deviation | Unknown country remains `Не указано` | `tests/Feature/Analytics/DemographicsAndServicesAnalyticsTest.php` | YES — aggregate query | REQUIRED — console result |
| IMPLEMENTED | Services, visits and specialist statistics | `ServiceVisitAnalytics`, `SchedulingAnalytics`, `BookingStatusAnalytics`, `AnalyticsReportExport` | “Мастера” already exist, so the specialist dimension is now included | Completed visits only for visit counts; cancelled/no-show are not counted as completed; period/filter/timezone is shared | `tests/Feature/Analytics/DemographicsAndServicesAnalyticsTest.php`, `tests/Feature/Analytics/PurchaseAndBookingStatusAnalyticsTest.php` | YES — booking status/period query | REQUIRED — services/specialists UI |
| IMPLEMENTED | Monetary LTV and configurable percentage/delta presentation | existing financial authority, `AnalyticsMetricEvaluator`, `AnalyticsMetricSettings` typed metric builder | Owner did not provide a new LTV formula | Monetary LTV remains canonical; “change to previous period, %” is a configurable typed metric with explicit basis/NULL policy | `tests/Feature/Analytics/ComputedAnalyticsMetricsTest.php`, `tests/Feature/Analytics/AnalyticsMetricConfigurationTest.php` | YES — integer minor-unit finance source | REQUIRED — metric selection/result |
| IMPLEMENTED | Analytics console with selectable sections, Analyze, charts and Excel | `AnalyticsConsole`, `resources/views/filament/pages/analytics-console.blade.php`, `AnalyticsReportExport` | No deviation | UI separates cohort, dimensions and period/time rules; live preview and human labels are shown | `tests/Feature/Analytics/AnalyticsDashboardWidgetTest.php`, `tests/Feature/Analytics/AnalyticsReportExportTest.php` | YES — all selected dimensions share cohort query | REQUIRED — multi-select, loading/error/retry/mobile |
| IMPLEMENTED | Finance: income, expenses, cash movement, accounts/cashboxes, selectable period | `FinanceAnalytics`, existing Finance ledger/reconciliation authority | No second ledger is introduced | Existing currency-safe minor-unit ledger is reused | `tests/Feature/Analytics/FinanceMovementAnalyticsTest.php`, `tests/Feature/Analytics/AnalyticsProjectionTest.php` | YES — ledger, currency and period | REQUIRED — console/export |
| IMPLEMENTED | Configurable acquisition sources: Telegram, Instagram, SMS, Stories, Contact, YouTube, TikTok, recommendations, partners, web/search/UTM/other | `AttributionSourceCatalog`, `AttributionSourceSettings`, `AcquisitionAnalytics` | Existing owner aliases are preserved | Defaults are added only to an empty catalog; aliases/stable keys/order/active state remain owner-editable | `tests/Feature/Analytics/AttributionSourceCatalogTest.php`, `tests/Feature/Analytics/AnalyticsProjectionTest.php` | YES — organization-scoped mapping | REQUIRED — settings/filter/export |
| IMPLEMENTED | One-off sessions, packages/programs, certificates, products | `PurchaseAnalytics`, Commerce purchase snapshots and catalog groups | No new purchase authority | Owner-editable purchase groups map onto existing commerce catalog types | `tests/Feature/Analytics/PurchaseAndBookingStatusAnalyticsTest.php`, `tests/Feature/Analytics/AnalyticsReportExportTest.php` | YES — purchase status/type and period | REQUIRED — result/export |
| IMPLEMENTED | Completed, rescheduled and cancelled order/booking statuses | `BookingStatusAnalytics`, canonical `BookingStatus` and `BookingEvent` lifecycle | Owner wording is not a second order enum | Human labels map to canonical lifecycle; requested/confirmed/no-show remain available where useful | `tests/Feature/Analytics/PurchaseAndBookingStatusAnalyticsTest.php` | YES — booking/event authority | REQUIRED — status filters/results |

### Shared cohort, drill-down and export contract

`ClientCohortFilters` is the typed, organization-scoped filter value object and
`ClientCohortQuery` is the single query authority. It authorizes the actor,
applies the organization boundary server-side and returns an Eloquent query
with correlated `EXISTS`/subqueries rather than materializing all client IDs.
Analytics Console, every selected dimension, typed metrics, drill-down and
`AnalyticsReportExport` use that authority. Different filter fields combine
with AND; multiple values in one field combine with OR; problem-tag `any` is OR,
problem-tag `all` is AND, exclude rejects a client when any selected excluded
category exists, and the special `all` segment is normalized out before
multiselect evaluation; NULL, 0, false and no related row remain distinct.

The implemented filter surface is: explicit include/exclude clients, saved
groups, current segments, archive state, blacklist, problem tags, feedback
themes, goal tags, age/ranges, gender, child/adult, countries, source aliases,
services/service groups, specialists, booking statuses/events, completed-visit
count, last-visit range, purchase groups/statuses, pain-score presence/range
and satisfaction range. The console shows `Найдено клиентов: N` before Analyze;
drill-down uses `Показать клиентов`, a human-readable reason and an authorized
client-card link without medical free text.

Saved groups use `analytics_client_groups`, existing organization-scoped
membership rows for manual groups, typed dynamic rules, immutable
`analytics_client_group_versions`, active/archive lifecycle, actor audit and
the CRM `ClientCohortGroups` page. No historical definition is physically
deleted.

The export has explicit typed-cell writing for formula-like strings and adds
`Клиенты` and `Фильтры` metadata alongside thematic sheets. It records human
filters, AND/OR semantics, include/exclude choices, period, date basis,
timezone, segment-state meaning, session-axis rule/version and generation
time. It does not export protected medical free text.

The `YES` implementation statuses in the two matrices describe the actual
technical capability and owner-editable seam. They are not owner acceptance and
do not upgrade an unexecuted browser or external-provider observation to
`VERIFIED`; those verification states are recorded below.

## Medical owner file

| # | Scope | Implemented | Authoritative path | Regression / acceptance evidence | PostgreSQL relevance | Browser relevance |
|---:|---|---|---|---|---|---|
| 1 | Session field order | YES | app/Filament/Resources/Clients/Resources/Sessions/Schemas/SessionForm.php, EditMedicalSession.php, SessionInfolist.php | tests/Feature/Sessions/SessionCockpitTest.php | YES — session persistence remains the database authority | YES — session create/edit/view journey |
| 2 | Pain constructor | YES | MedicalSessionPainInitializer, CreateMedicalSession, CreateSession, SessionForm | tests/Feature/MedicalPainFlowTest.php, SessionCockpitTest::test_filament_create_form_prefills_profile_pains_for_the_first_session | YES — normalized pain rows and nullable scores | YES — first-session form |
| 3 | Pain carry-forward/history | YES | MedicalSessionPainReader, MedicalSessionPainInitializer, GetSessionDynamics | MedicalPainFlowTest, SessionCockpitTest including first-session prefill regression | YES — previous/current rows and NULL/0 persistence | YES — second-session journey |
| 4 | Clinical tests | YES | ClinicalTestProtocol, MedicalClinicalCatalog, MedicalSessionTestWriter, ImportMedicalClinicalCatalogCsv | MedicalClinicalCatalogConfigurationTest, MedicalPainFlowTest; covers XLSX, 60 active tests, blank rows and inactive historical tests | YES — normalized test/value/result rows | YES — list protocol, live counters and reload |
| 5 | Causes | YES | ClinicalTestProtocol, MedicalClinicalCatalog, MedicalSessionTestWriter | MedicalPainFlowTest configured causes/results round trip | YES — organization-scoped cause/value references | YES — list protocol cause selection |
| 6 | Root causes | YES | MedicalSessionTestWriter, session form manual/derived cause controls | MedicalPainFlowTest root-cause and manual-override cases | YES — max-two normalized root-cause rows | YES — manual override and restore |
| 7 | Protocol | YES | GenerateSessionResult, EditMedicalSession deterministic fillResult action | SessionCockpitTest deterministic summary and confirmation | YES — encrypted session field | YES — confirmation and overwrite journey |
| 8 | Subjective feelings | YES | UpdateSession, SessionForm, SessionInfolist | SessionCockpitTest session snapshot/reopen coverage | YES — encrypted session field | YES — edit/view/reload |
| 9 | Posture diagnostics | YES | UpdateSession, SessionForm, SessionInfolist | SessionCockpitTest session snapshot/reopen coverage | YES — encrypted session field | YES — edit/view/reload |
| 10 | Result | YES | GenerateSessionResult, SessionForm, SessionInfolist | SessionCockpitTest deterministic result regression | YES — encrypted session field | YES — result generation and reload |
| 11 | Before/after photos | YES | LinkSessionAttachment, ListSessionAttachments, SessionInfolist, ViewMedicalSession, private attachment URLs | SessionAttachmentTest linking/unlinking, tenant ownership and PDF rejection | YES — gallery links, ownership and attachment constraints | YES — two galleries, preview, download, unlink, reload |
| 12 | Profile pains | YES | MedicalProfilePainWriter, MedicalProfilePainReader, GetClientMedicalProfile, ClientWorkspaceInfolist | MedicalPainFlowTest, September26MedicalProfileTest | YES — encrypted profile pain rows | YES — profile edit and client-card history |
| 13 | Injuries/operations | YES | MedicalProfileHistoryWriter/Reader, portal controller/application, resources/js/Pages/Portal/MedicalProfile.vue | September26MedicalProfileTest structured 5-row save/reload/remove; tests/e2e/portal.spec.ts 0/1/3-row journeys | YES — medical_profile_history_entries is the canonical store | YES — Portal repeaters and CRM projection |
| 14 | Anamnesis master-only | YES | MedicalProfileAuthorization, ClinicalProfileInfolist, portal medical-profile projection | September26MedicalProfileTest, ClientWorkspaceUxATest authorization coverage | YES — protected encrypted profile data | YES — portal does not render/edit anamnesis |
| 15 | Analyses/reports | YES | private attachment upload/download authorization and client attachment stack | September26MedicalAttachmentTest, ClientWorkspaceUxATest | YES — private storage metadata and ownership | YES — Portal upload and CRM access |
| 16 | Audio | YES | ClientAudioRecordingsRelationManager, AttachmentType::AudioRecording, private storage | tests/Feature/Attachments/AudioRecordingsTest.php | YES — private attachment rows/storage metadata | YES — master-only play/download/delete |

## Analytics owner file

| # | Scope | Implemented | Authoritative path | Regression / acceptance evidence | PostgreSQL relevance | Browser relevance |
|---:|---|---|---|---|---|---|
| 1 | Complaints/tags | YES | SuggestAnalyticsTags, AcceptAnalyticsClassification, medical/session pain authorities, taxonomy groups | AnalyticsAiClassificationTest, PainAnalyticsTest | YES — tenant-scoped classification rows and pivots | YES — review, accept and manual correction |
| 2 | Average pain | YES | PainAnalytics, ClientPainHistory | PainAnalyticsTest, ComputedAnalyticsMetricsTest | YES — aggregate query and nullable score semantics | YES — Analytics console and client card |
| 3 | Therapy results sessions 1/2/3 + % | YES | PainAnalytics standard reduction formula and AnalyticsMetricEvaluator | PainAnalyticsTest, ComputedAnalyticsMetricsTest | YES — period/aggregation semantics | YES — result table and custom metric |
| 4 | Satisfaction 0–10 | YES | Feedback submission validation/storage, FeedbackAnalytics | FeedbackThemesAnalyticsTest, MilestoneElevenADatabaseTest | YES — forward migration 2026_10_30_100000_allow_zero_feedback_score.php; PostgreSQL constraint test is required | YES — Portal and CRM submission |
| 5 | What lacked/themes | YES | existing Feedback submission/scenario engine, FeedbackThemeCatalog, FeedbackAnalytics | tests/Feature/FeedbackThemesAnalyticsTest.php, FeedbackAnalyticsTest | YES — feedback/theme pivots and idempotency | YES — client form, CRM manual entry and configurable scenario |
| 6 | Goals/tasks | YES | AssignTrackerTask, TrackerGoalTagCatalog, GoalAnalytics, existing AI classification; profile-goal prefill in ViewClient | TrackerPlanCrmTest, GoalAnalyticsTest, AnalyticsAiClassificationTest | YES — Tracker task/tag authority | YES — profile goal enters Tracker, tags and aggregates |
| 7 | Client categories | YES | ClientSegmentQuery, ClientSegmentConfiguration, ClientSegmentSettings, Client archive lifecycle | ClientSegmentQueryTest including zero-state, thresholds, warm lead, penalty and archive | YES — archive migration and segment queries must run on PostgreSQL | YES — segment settings and Analytics console |
| 8 | Age | YES | DemographicsAnalytics | DemographicsAndServicesAnalyticsTest | YES — timezone boundary query | YES — console demographics |
| 9 | Sex/children | YES | DemographicsAnalytics | DemographicsAndServicesAnalyticsTest | YES — aggregate query with unknown/zero-safe output | YES — console demographics |
| 10 | Country | YES | DemographicsAnalytics | DemographicsAndServicesAnalyticsTest | YES — aggregate query with unknown values | YES — console demographics |
| 11 | Services + specialists | YES | ServiceVisitAnalytics, AnalyticsConsole, AnalyticsReportExport | DemographicsAndServicesAnalyticsTest, AnalyticsReportExportTest completed/cancelled/no-show and two specialists | YES — completed-booking and period semantics | YES — console specialist section |
| 12 | LTV | YES | AnalyticsMetricConfiguration, AnalyticsMetricEvaluator, FinancialReconciliationContract | ComputedAnalyticsMetricsTest, AnalyticsMetricConfigurationTest | YES — currency-safe integer minor-unit source | YES — monetary LTV and typed percentage metric |
| 13 | Finance | YES | FinanceAnalytics, FinancialReconciliationContract, existing ledger | AnalyticsProjectionTest, FinanceMovementAnalyticsTest | YES — ledger, currency and period authority | YES — finance console/export |
| 14 | Acquisition source | YES | AttributionSourceCatalog, AcquisitionAnalytics, UTM/alias mapping | AttributionSourceCatalogTest, AnalyticsProjectionTest | YES — organization-scoped mappings | YES — source settings/filter/export |
| 15 | Purchase types | YES | PurchaseAnalytics, Commerce purchase snapshots | PurchaseAndBookingStatusAnalyticsTest, AnalyticsReportExportTest | YES — purchase authority and period filters | YES — console/export |
| 16 | Booking/order statuses | YES | BookingStatusAnalytics, canonical Booking lifecycle/events | PurchaseAndBookingStatusAnalyticsTest, AnalyticsDashboardWidgetTest | YES — status/event aggregation | YES — console filters/results |
| — | Charts | YES | resources/views/filament/pages/analytics-console.blade.php responsive bar/list visualizations | AnalyticsDashboardWidgetTest and console browser journey | YES — charts consume backend aggregates | YES — desktop/mobile responsive review |
| — | Excel | YES | AnalyticsReportExport, including semantic Специалисты sheet | AnalyticsReportExportTest parses actual XLSX and checks Unicode headers, filters, metrics and specialist rows | YES — export reflects PostgreSQL-backed aggregates | YES — actual download from console |

## Configurable business-data seams

- Clinical tests, sections, order, active state, values, human labels,
  abnormal flags, causes and problem tags: MedicalClinicalCatalogConfiguration
  plus CSV/XLSX import.
- Feedback themes, Tracker goal tags, complaint/problem tags and taxonomy
  groups: existing CRM configuration pages and classification review.
- Post-course feedback: existing scenario rules/actions, configurable
  booking.completed timing/delay/template and the new
  client.completed_visits condition; the safe default remains disabled until
  an owner creates a rule.
- Client segment thresholds, warm-lead qualifying/target service groups and
  penalty marker: ClientSegmentSettings.
- Acquisition labels, stable keys, aliases, active state and order:
  AttributionSourceSettings; defaults are provisioned only for an empty
  catalog.
- Typed analytics metrics and the LTV comparison preset:
  AnalyticsMetricSettings; no formula-reference pseudo-setting is exposed as
  normal user configuration.
- Archived-client lifecycle: CRM archived state preserves history and is
  reportable as Удалённые / архивные; it does not physically delete medical
  data.

## Literal reconciliation notes

- The owner files use the legacy singular operation/injury fields as the
  starting UX description. The current owner decision requires repeatable
  structured rows, so new Portal writes use medical_profile_history_entries.
  Legacy encrypted fields and legacy years remain readable and are not guessed
  or split automatically.
- The owner files describe cold lead as a no-show and penalty without
  inventing a business formula. The implementation uses canonical
  BookingStatus::NoShow and an owner-managed existing broadcast-client tag.
- The owner files allow deleted analytics only where historical data can be
  retained safely. The implementation provides an additive archive timestamp,
  preserving protected history instead of weakening physical-deletion/privacy
  rules.
- The accepted universal typed metric builder remains the analytics authority;
  the LTV percentage preset is exposed as a typed metric rather than a
  hardcoded report formula.

## Verification record

Implementation started at `715b561b9e08fbc7f468f314b6f045121397da3b`. The
implementation diff contains 79 files, including Filament pages,
Analytics/Medical application services, four forward-only migrations, Blade
views and regression tests. The previous staging-verified application source
is `a687639cc4cff72ee952809adb960c673ce7547f`; the problem-tag follow-up
application candidate was `499c7322929a30387d56c57df36ead0c01b74750`. The
current demographics application candidate is
`9c77520fd06dbd4643de16f952420b415c4cab64` and extends the existing analytics
authorities with the full-cohort demographic denominator and its regressions.

The four migrations are:

- `2026_10_31_120000_create_analytics_client_groups.php` — organization-scoped
  manual/dynamic saved groups and membership authority;
- `2026_10_31_120100_allow_unspecified_medical_pain_frequency.php` — preserves
  an explicitly unspecified pain frequency without converting it to permanent;
- `2026_10_31_120200_create_analytics_tag_candidates.php` — owner-reviewed AI
  existing/new taxonomy candidate lifecycle;
- `2026_10_31_120300_create_analytics_client_group_versions.php` — immutable
  saved-group definitions and version history.

The previously accepted forward migration
`2026_10_30_100000_allow_zero_feedback_score.php` remains the PostgreSQL
authority for the 0–10 satisfaction constraint.

### WebKit scheduling failures

The original full run was `41195718383` (job
`124521357372`, head `7cb0f96e9c60d58ef55c9914b0d9a07534571f29`). The workflow
ran 105 tests with one worker, Playwright retries `0`, `trace: on-first-retry`,
and no explicit retry. The WebKit runtime was Playwright WebKit 26.5 / build
2336. The workflow used `APP_ENV=testing`, PostgreSQL, Redis, the seeded test
organization, and the `desktop`, `mobile` and `webkit` projects from
`playwright.config.ts`.

| Exact failed test | Project / viewport | Timeout | Failure evidence | Published artifacts |
|---|---|---:|---|---|
| `tests/e2e/crm.spec.ts:895:1 — multi-specialist Journal keeps the accepted layout and slot ownership` | `webkit`; the test explicitly sets `390x900` | test 60 s; assertion 5 s | `expect(locator).toBeVisible()` for `getByRole('heading', { name: 'Журнал записей', exact: true })`; no element after `goto('/admin/bookings?...')`; source line 904 | none: the manual dispatch did not set `publish_diagnostics=true`; no retry means no trace/video; runner log only names `test-results/.../error-context.md` |
| `tests/e2e/crm.spec.ts:1465:1 — staff can create a backdated booking from the ordinary availability form` | `webkit` Desktop Safari project viewport (`1280x720`) | test 60 s | `locator.click` timed out waiting for exact `getByText('CRM Специалист qm6ruugmhk1v')` at source line 1475 after opening `Специалист*` | none for the same reason |

The comparison command executed by the hosted E2E workflow was:

```text
npx playwright test tests/e2e/crm.spec.ts -g 'multi-specialist Journal|staff can create a backdated booking from the ordinary availability form' --project webkit
```

The command used the same workflow setup, browser installation, seed strategy,
environment, worker count, locale/timezone defaults and retry policy for every
SHA. The PR base is `8b85efb65ee5d7cf80940cbec29c26a004871f56`; it was not run
because both exact tests were already passing on the pre-fix baseline and the
current candidate, so an additional base run would not distinguish a product
regression.

| SHA | Hosted runs / attempts | Result |
|---|---|---|
| `263ed0550411bd6735feb9bb9ae2f186c5094f00` | `41199320637`, `41199587646`, `41199587671` | 3/3 exact runs PASS; both tests pass |
| `92ef260997717c4549d1dca1e42b02b079fb4755` | `41199320313`, `41199587531`, `41199587852` | 3/3 exact runs PASS; both tests pass |
| `9de21be39a8d1e01628031fc68f4c2cd38604e4d` | `41202259555` | 2/2 exact tests PASS |
| `92ef260997717c4549d1dca1e42b02b079fb4755`, full WebKit file | `41200144359` | 33 passed, 2 skipped, 0 failed; both scheduling tests pass in the full WebKit file |

Both failures are therefore classified separately as `NON-REPRODUCIBLE
INTERMITTENT E2E FAILURE / likely order or setup dependent; product scheduling
regression not reproduced`. No concrete race or shared-state defect was
localized, so this is not called a proven test defect. No scheduling or booking
production code was changed, and no product defect, data loss, tenant leakage
or incorrect booking ownership was reproduced.

### Current staging and browser evidence

The current application/runtime candidate
`a687639cc4cff72ee952809adb960c673ce7547f` was deployed through the normal
protected process. The four forward-only migrations completed, application,
Horizon, scheduler, Telegram and mail services were healthy, and
`scripts/staging-smoke.sh` reported `STAGING SHA OK a687639` and
`STAGING SMOKE: PASS`.

Actual Chromium staging interaction at 1440px passed the custom-period
missing-date, reversed-date and over-366-day errors, corrected Analyze,
multi-dimension results, XLSX download/ZIP validation, reload and back/forward.
The downloaded workbook contained `Сводка`, `Клиенты`, `Фильтры`, `Демография`,
`Боли`, `Услуги` and `Специалисты`; formula-like synthetic strings remained
explicit string cells without visible apostrophes, while numeric values kept
numeric cells. The same journey in real WebKit passed at desktop and 390x900:
all three validation errors, Analyze, workbook sheets, reload, back/forward and
`scrollWidth === clientWidth` were checked by actual browser interaction.

The staging browser journey covered the shared analytics console and export
contract. It did not constitute owner acceptance of every medical workflow;
the relevant automated medical/session/attachment tests and the existing
staging smoke remain the evidence for those paths.

The problem-tag application commit `499c7322929a30387d56c57df36ead0c01b74750`
and the current demographics candidate
`9c77520fd06dbd4643de16f952420b415c4cab64` were pushed to the PR branch. No
new staging release was forced after the normal protected deploy path stopped
during remote preflight with `ENOSPC` while npm populated
`/root/.npm/_cacache` (exit 228), before migrations, release switching or
application restart. The safety guard was not changed or bypassed. A smoke run
against a detached `a687639c` worktree confirmed the deployed revision remains
`a687639c` and all staging health/CRM/Portal checks passed.

Therefore exact-current-candidate staging is `NOT VERIFIED`; staging
functional acceptance is `PASS` only for the previously verified
runtime-equivalent application revision `a687639cc4cff72ee952809adb960c673ce7547f`.
The exact diff from that deployed application source to the current PR includes
the demographics denominator fix and regression tests, so the new semantics
require a staging recheck after the remote disk-space blocker is resolved.

### XLSX evidence

The existing `AnalyticsReportExportTest` opens a real generated workbook with
PhpSpreadsheet and asserts exact `=`, `+`, `-` and `@` values are string cells,
not formulas, with no visible apostrophe; numeric, boolean and null values keep
their types. The export implementation now also writes `Клиенты` and
`Фильтры` metadata sheets and preserves the selected cohort/period metadata.
The current staging download was a real XLSX and was opened as a ZIP; its
metadata and thematic sheet names were checked in both Chromium and WebKit.
`AnalyticsReportExportTest::test_demographics_export_keeps_the_full_cohort_denominator`
also proves that an old cohort client is present on `Демография` and that the
metadata cohort count equals the demographic denominator.

### Verification status at the current application candidate

- Focused local backend suites: PASS — 43 tests / 341 assertions across
  `DemographicsAndServicesAnalyticsTest`, `ClientCohortQueryTest`,
  `AnalyticsReportExportTest` and `AnalyticsDashboardWidgetTest`. This includes
  the complete-cohort denominator, period-bound acquisition, birthday-boundary
  and XLSX regressions.
- PHP syntax, Pint, changed-file PHPStan and `git diff --check`: PASS;
  changed-file PHPStan diagnostics: 0.
- Focused E2E source lint: PASS — `npx eslint tests/e2e/crm.spec.ts`.
- Problem-tag and special-segment regression: PASS — local `ClientCohortQueryTest`
  is 14/14 with 38 assertions; the two new contracts are 2/2 with 7
  assertions. The hosted PostgreSQL feature job also reports the new
  `any`/`all`/exclude and `all`-normalization tests as PASS.
- Hosted candidate run `41286645922` at application commit `499c7322` ran
  against PostgreSQL. Its Integration foundation, RAG and concurrency jobs and
  privacy scan are PASS. Quality feature tests are PASS for the changed
  `ClientCohortQueryTest` but the full feature job is FAIL on four unrelated
  baseline tests (`GoalAnalyticsTest` twice, `FeedbackThemesAnalyticsTest` and
  the known `MedicalSessionTest` overlap fixture); PHP static checks are also
  the existing repository baseline. Docker runtime health repeats the known
  runner-only `Horizon is inactive` failure. No failure names the changed
  cohort files or new regression tests.
- Hosted focused run `41230782443` at `a687639c`: PostgreSQL feature tests
  PASS, including the corrected group-by queries. The overall Quality job is
  FAIL only at the repository's existing PHP static-analysis baseline (184
  diagnostics; none in changed files); format, frontend lint/typecheck/build,
  Composer audit and npm audit passed. Other jobs were intentionally skipped by
  the focused workflow input.
- Hosted implementation run `41230001307` at the preceding docs head had the
  same PostgreSQL group-by failures before `a687639c` fixed them and a separate
  Docker runtime `Verify Horizon runtime` failure (`Horizon is inactive`) in
  the runner. The normal staging deployment and smoke above verified Horizon
  active for the current candidate; no deployment guard or quality gate was
  bypassed.
- Hosted focused candidate run `41292537566` at application candidate
  `9c77520f` ran the affected feature tests against PostgreSQL and the feature
  step passed. The overall Quality job is FAIL only at the existing repository
  PHP static-analysis baseline; no changed demographics/cohort/export file is
  named in the diagnostics. Format, frontend lint/typecheck/build, migrations
  and dependency audits passed; unrelated integration jobs were intentionally
  skipped by the focused workflow input.
- Hosted focused browser run `41292543763`: PASS — the exact analytics test
  passed in desktop, mobile and WebKit projects, 3/3 tests. The browser
  assertion compared the visible cohort count with the demographic count after
  the real Analyze interaction.
- PostgreSQL: PASS for the affected cohort subquery, organization scope,
  period/timestamp handling, configured age ranges and percentage/count
  assertions in the hosted focused feature step. Exact-current-candidate
  staging PostgreSQL verification remains NOT VERIFIED because the candidate
  was not deployed.
- Scope browser acceptance: PASS for the hosted focused analytics browser
  contract in desktop, mobile and WebKit projects, including the visible shared
  denominator assertion. Exact-current-candidate staging browser acceptance
  remains NOT VERIFIED because the candidate was not deployed.
- Full E2E: PARTIAL — the original run had 98 passed, 5 skipped and 2 failed;
  exact scheduling comparisons were 3/3 PASS on both baseline and current,
  application-source checks passed, and the full WebKit rerun was 33 passed,
  2 skipped, 0 failed. Both scheduling findings remain classified as
  `NON-REPRODUCIBLE INTERMITTENT E2E FAILURE / likely order or setup dependent;
  product scheduling regression not reproduced`.
- Staging functional acceptance: PASS for the previously verified focused
  analytics scope on exact application revision `a687639c`; the current
  demographics candidate `9c77520f` is NOT VERIFIED in staging because the
  guarded release path remains blocked by remote `ENOSPC`.
- Owner acceptance: NOT RUN.
- Remaining in-scope BLOCKING: 0 for this bounded demographics pass.
- Remaining confirmed REAL BUG / follow-up work not addressed in this pass:
  pain-score basis / after_score; problem-specific session axis; aggregate
  drill-down; atomic saved group CRUD; AI candidate lifecycle; legacy
  root-cause field; preview error state; human-friendly config; LTV percentage
  safe default. These findings are not claimed fixed here.

Limitations are the known unrelated repository PHPStan baseline, the partial
historical full-E2E result, the non-reproducible scheduling failures and no
owner acceptance. Business labels, thresholds and catalog content remain
owner-editable configuration; that is intentional and not an implementation
blocker. No credentials, MFA, production data, accepted booking UX or
deployment safety guard were changed.
