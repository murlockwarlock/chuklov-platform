# 23.10 owner scope acceptance

This is the literal reconciliation of the two owner files dated 23.10. The
canonical authorities remain the existing Medical Profiles, Sessions,
Attachments, Feedback, Tracker, Attribution, Booking, Commerce, Finance and
Analytics modules.

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

Local checks for this follow-up are PASS: PHP syntax on every changed PHP
file, Pint on every changed production/test file, changed-file PHPStan with
zero diagnostics, `git diff --check`, and the focused analytics, period,
classification, feedback and medical regression suites. The XLSX regression
parses a real generated workbook with PhpSpreadsheet.

PostgreSQL, exact-final-SHA staging and browser acceptance remain environment
gates and are not upgraded from local PASS results. They must be recorded in
the final PR handoff as PASS, PARTIAL or NOT RUN only after the corresponding
environment actually exercises this candidate. Owner acceptance remains NOT
RUN until the owner performs it.
