# 23.10 owner scope acceptance

This is the literal reconciliation of the two owner files dated 23.10. The
canonical authorities remain the existing Medical Profiles, Sessions,
Attachments, Feedback, Tracker, Attribution, Booking, Commerce, Finance and
Analytics modules.

## Medical owner file

| # | Scope | Implemented | Authoritative path | Regression / acceptance evidence | PostgreSQL relevance | Browser relevance |
|---:|---|---|---|---|---|---|
| 1 | Session field order | YES | app/Filament/Resources/Clients/Resources/Sessions/Schemas/SessionForm.php, EditMedicalSession.php, SessionInfolist.php | tests/Feature/Sessions/SessionCockpitTest.php | YES — session persistence remains the database authority | YES — session create/edit/view journey |
| 2 | Pain constructor | YES | MedicalSessionPainInitializer, CreateSession, SessionForm | tests/Feature/MedicalPainFlowTest.php | YES — normalized pain rows and nullable scores | YES — first-session form |
| 3 | Pain carry-forward/history | YES | MedicalSessionPainReader, MedicalSessionPainInitializer, GetSessionDynamics | MedicalPainFlowTest, SessionCockpitTest | YES — previous/current rows and NULL/0 persistence | YES — second-session journey |
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

Local focused backend, frontend typecheck/lint and changed-file PHPStan are
recorded in the task handoff. PostgreSQL, exact-SHA staging and browser
acceptance are environment gates and must be reported separately from these
local PASS results.
