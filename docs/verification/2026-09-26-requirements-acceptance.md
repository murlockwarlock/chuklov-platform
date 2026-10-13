# 26.09.2026 — requirements and staging acceptance

Starting main SHA: `c201b41a14d91c57c1890e62737f9e2001a231f1`.
Branch: `codex/26-09-full-implementation`. PR #59 is separate.

Sources: original Downloads LIST 1 DOCX (v2.4, 26.09.2026) and original Downloads LIST 2 Markdown. Quotations below were extracted directly from those files; no prior proof matrix is accepted as implementation evidence.

Initial statuses describe bounded source inspection, not staging proof. No capability is initially ALREADY IMPLEMENTED AND VERIFIED. Every criterion is NOT VERIFIED until its named observable journey is exercised at an exact candidate SHA.

## Shared evidence contract

Current candidate is PARTIAL. The following records distinguish code delivery from acceptance; none is VERIFIED ON STAGING.

- CR-01: Single web_app welcome/Menu Button and managed upload; approved welcome asset and real launch NOT VERIFIED.
- CR-02: Four managed slots and fullscreen/swipe controls; original media, subtitles delivery and mobile playback NOT VERIFIED.
- CR-03: Confirmed given-name projection, legacy full_name preservation and exact localized greeting. Server regressions PASS; browser pending.
- CR-04: Exact empty-state card/CTA and bounded polling. Existing-booking card details and full create/cancel/reschedule/completion browser cycle NOT VERIFIED.
- CR-05: Paid Tracker focus/inline entry and reviewed Road Map CTA implemented. Authoritative 9-systems source missing; complete state lifecycle NOT VERIFIED.
- CR-06: Exact question CTA/description using canonical Companion. Shared history/return/live Telegram journey NOT VERIFIED.
- CR-07: Additive demographics, Profile and CRM fields; cabinet entries. Booking-stage personal-data collection, country autocomplete and country phone masks remain NOT IMPLEMENTED.
- CR-08: Encrypted self-edit/prefill, optimistic snapshot, medical-service continuation and return. Client multi-file selection uploads through existing private Attachments, with independent per-file results and idempotent retries. Legacy combined records remain readable without guessing a split. Complete questionnaire/upload UX remains NOT VERIFIED.
- CR-09: CRM demographics and chronic conditions added, split fields retained. Session projection and every CRM create/edit permission path NOT VERIFIED.
- CR-10: Self-only medical write, scoped return IDs, history encryption, log redaction and no clinical input flashing. Full cross-role/client/tenant/protected-file/browser audit NOT VERIFIED.
- CR-11: Default-true flag, CRM configuration and authoritative booking-result projection. End-to-end medical/non-medical branching NOT VERIFIED.
- L2-05: Face/color 0..10/null component and encrypted questionnaire; CRM Session dynamics and all browser values NOT VERIFIED.
- L2-06: Header uses existing available ServiceCredit with catalogue dialog. Client-selected display currency preference/conversion remains PARTIAL.
- L2-09: Danger role added to existing backdated warning. Mandatory confirmation remains in existing backend; actual red UI/browser bypass test NOT VERIFIED.
- L2-12: Nullable per-format buffers with legacy fallback; common availability and impact/snapshot paths updated. New occupancy/conflict regression PASS; PostgreSQL/concurrency/reschedule lifecycle NOT VERIFIED.

Still NOT IMPLEMENTED in this candidate: family patient identity/invitations/revocation, Portal multi-session basket, the medical-reminder cascade, and the complete schedule-settings restructuring. Existing notes/blacklist/certificates/referral/media-template paths have not received the requested full real lifecycle proof. These are outstanding work, not external blockers.

First candidate: `676c4da3436e30f0268aff2245db61958edb3a6f`, [Draft PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60), no merge. [Hosted CI](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38389322189): unit, integration foundation, integration concurrency, RAG, Docker/runtime and privacy gates PASS; Quality FAIL (13 feature failures, remaining static/build gates not reached). [Focused browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38389447699): 4 PASS / 2 FAIL across Chromium/WebKit. Medical and non-medical booking continuation passed; the profile/medical journey found symptom-row overflow. This is not staging acceptance.

Follow-up changes address wrapping, confirmed submit waiting, missing Mini App configuration, mandatory profile fields, feature-disabled self writes, private-file uploads and PHP multipart limits. Quality steps retain all original checks but execute independently after schema readiness, so a feature failure cannot conceal type/build/security outcomes. A single [starting-main Quality comparison](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38397769054) distinguishes baseline failures from candidate defects. Focused attachment/profile/health/Telegram/CRM tests: 70 tests / 661 assertions PASS; new upload replay/private download/forged-key checks: 3 tests / 32 assertions PASS. Final candidate rerun pending.

Staging baseline smoke stopped at an exact-SHA mismatch (main c201b41; deployed f3509f8), so no candidate staging acceptance is claimed. Near-limit 20-MB medical uploads require multipart headroom in PHP and the existing staging nginx limit; exact-limit runtime proof is pending. The original source requests S3, but accepted architecture uses the existing private Storage authority: no parallel file subsystem or S3 acceptance claim is made.

Owner answers: original videos unavailable; temporary QA media permitted, with managed CRM uploading required. Telegram QA identity: Murlock Warlock. No concrete family consent/guardianship policy was supplied. Adult medical delegation and minor representative access must not be inferred from relation or an invitation.

Every criterion requires visible trigger → real click/input → authorization → Application action → PostgreSQL state → CRM/client projection → relevant provider effect → reload → permitted change/cancel/correction. Negative evidence includes foreign tenant/client, unauthorized actor, invalid input/state, duplicate submit, stale UI and provider failure where applicable. Browser widths: 320, 360, 390, 768, 1024, 1440. Check full-page overflow; desktop Chromium and mobile WebKit. No destructive testing on staging, real medical data, real payments or bulk delivery. Owner acceptance is separate.

### Follow-up implementation and preservation checkpoint

LIST 2 №14 now separates organization-wide Scheduling Configuration from specialist-specific Working Intervals, preserving the existing SetSpecialistWorkingHours command, impact preview, explicit-clear control and viewer-timezone preference. General settings are last in Bookings; Work Schedule heading is «График работы специалиста». Missing working-hours snapshots are rejected. Focused schedule/profile/medical tests: 54 tests / 470 assertions PASS. Unified unavailable-period UI, multi-specialist Journal selection and specialist-bound travel days remain outstanding; №14 is PARTIAL, not complete.

Second candidate `43293a5131e53e3fc975a8b3b7ea406010c9f289`: [browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38398585589) PASS and [Docker/runtime](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38398580325) PASS. Browser checks exercise profile/clinical correction/reload, two-file upload/download, medical/non-medical booking and overflow at 320/360/390/768/1024/1440px in Chromium/WebKit. This is isolated hosted browser evidence, not staging or a complete visual design audit.

[Quality](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38398575059) remains FAIL: the same 10 feature failures as Starting SHA, existing format failures, PHPStan findings and Composer advisories. No failures are hidden or declared PASS. Owner authorized narrow dependency security updates: `45103f5` changes Laravel 13.25.0→13.30.0, Filament 5.7.6→5.8.2 with required synchronized packages, CommonMark 2.10.0→2.10.2 and Flysystem 3.35.2→3.35.3. Local locked audit PASS; hosted final-SHA audit pending.

Read-only staging REVISION is `f3509f8ce957082045f3e140cd4a08809d1c3751`, which is not an ancestor of Starting SHA. Owner explicitly authorized selective transfer of necessary PR #59 runtime fixes/regressions, including Telegram internal client CTA buttons, without merging/closing #59 or importing its proof/audit artifacts. Staging deployment remains pending integration and affected verification; it must not silently roll back this delta.


### Selective preservation of deployed PR #59 code

Source delta is Starting main `c201b41` → deployed `f3509f8`, not PR #59's later un-deployed HEAD. No merge or blind commit cherry-pick was used. Unchanged files were checked against Starting main; overlapping files received selected hunks or functional integration.

Source commits represented: `f3509f8` (internal Mini App CTA), `de1b246` (sanitized staff reply), `1030a50` (meeting-link replay), `65b89e2` / `a57b443` (visible payment/barter validation and payment-history refresh), `2d16ac3` (gift-claim error continuation), `570f2da` / `4b4c9fd` (substantive regressions and valid PostgreSQL/test contracts), `ed9f423` / `aeaa182` (compatible typed CRM/date contracts), `c79ebac` (Compose preflight drains output). This identifies source contributions, not a claim that entire commits were imported.

Selected files transferred so far:

- `app/Filament/Pages/WorkSchedule.php`
- `app/Filament/Resources/Clients/Schemas/ClientWorkspaceInfolist.php`
- `app/Filament/Resources/ContentSections/Schemas/ContentSectionForm.php`
- `app/Filament/Resources/FinancialObligations/FinancialPaymentsRelationManager.php`
- `app/Filament/Resources/PaymentGatewayReconciliation/Tables/PaymentGatewayReconciliationTable.php`
- `app/Filament/Support/FinancePaymentActions.php`
- `app/Filament/Support/LocalizedCreateRecord.php`
- `app/Filament/Support/LocalizedDashboard.php`
- `app/Filament/Support/LocalizedEditRecord.php`
- `app/Filament/Support/LocalizedListRecords.php`
- `app/Filament/Support/LocalizedManageRelatedRecords.php`
- `app/Filament/Support/LocalizedPage.php`
- `app/Filament/Support/LocalizedResource.php`
- `app/Filament/Support/LocalizedViewRecord.php`
- `app/Http/Controllers/Portal/GiftCertificateClaimController.php`
- `app/Modules/AI/Application/Actions/StartClinicalCourseReport.php`
- `app/Modules/AI/Application/Actions/StartClinicalSynthesis.php`
- `app/Modules/Analytics/Application/AcquisitionAnalytics.php`
- `app/Modules/Channels/Application/ResolveTelegramMiniAppEntry.php`
- `app/Modules/Channels/Application/SendTelegramContentSection.php`
- `app/Modules/Channels/Application/TelegramMessagePreview.php`
- `app/Modules/Channels/Domain/ValueObjects/NotificationActionButton.php`
- `app/Modules/Channels/Infrastructure/Telegram/TelegramMessagingChannel.php`
- `app/Modules/Channels/Infrastructure/Telegram/TelegramNotificationChannel.php`
- `app/Modules/ClientCompanion/Application/Services/ReadCompanionConversation.php`
- `app/Modules/Finance/Application/FailFakePayment.php`
- `app/Modules/Finance/Application/ProcessPaymentGatewayEvent.php`
- `app/Modules/Finance/Application/ReconcileFakeGatewayTransaction.php`
- `app/Modules/Finance/Application/RefundFakePayment.php`
- `app/Modules/Finance/Application/SettleFakePayment.php`
- `app/Modules/Finance/Application/SimulateClientFakePayment.php`
- `app/Modules/Knowledge/Application/ProcessKnowledgeIngestion.php`
- `app/Modules/Knowledge/Application/ReprocessKnowledgeForSearch.php`
- `app/Modules/Knowledge/Application/RetryKnowledgeIngestion.php`
- `app/Modules/Knowledge/Application/StartPendingKnowledgeIngestion.php`
- `app/Modules/Organizations/Domain/Models/Organization.php`
- `app/Modules/Scenarios/Application/ExecuteScenarioAction.php`
- `app/Modules/Scenarios/Domain/Exceptions/TelegramMiniAppConfigurationException.php`
- `app/Modules/Scheduling/Application/BookingLocationResolver.php`
- `app/Modules/Scheduling/Application/CalculateAvailability.php`
- `app/Modules/Scheduling/Application/ResolveSpecialistWorkingHours.php`
- `app/Modules/Scheduling/Application/ScheduleMutationImpactCalculator.php`
- `app/Modules/Scheduling/Application/SetOnlineMeetingUrl.php`
- `app/Modules/Scheduling/Domain/Models/SpecialistWorkingHour.php`
- `app/Modules/Scheduling/Domain/ValueObjects/SpecialistScheduleDefinition.php`
- `app/Modules/Services/Domain/Models/Service.php`
- `config/portal.php`
- `resources/js/Pages/Portal/Companion.vue`
- `scripts/deploy-staging.sh`
- `tests/Feature/AI/AiAsyncCandidateSnapshotTest.php`
- `tests/Feature/AI/AiEvaluationQualityTest.php`
- `tests/Feature/Analytics/AnalyticsProjectionTest.php`
- `tests/Feature/ClientCompanion/StaffReplyPortalFormattingTest.php`
- `tests/Feature/CrmEntityClickThroughTest.php`
- `tests/Feature/CustomizableCopySafetyTest.php`
- `tests/Feature/FinanceCrmUxTest.php`
- `tests/Feature/GiftCertificateTest.php`
- `tests/Feature/MilestoneFiveBScenarioFamiliesTest.php`
- `tests/Feature/MilestoneFourFinalLifecycleTest.php`
- `tests/Feature/MilestoneTwoTelegramBotTest.php`
- `tests/Feature/PaymentGatewayReconciliationCrmTest.php`
- `tests/Feature/PaymentScenarioNotificationsTest.php`
- `tests/Feature/PlatformHealthExperienceTest.php`
- `tests/Feature/ReferralPayoutNotificationTest.php`
- `tests/Feature/ReferralRewardsTest.php`
- `tests/Feature/SchedulingJournalProductionPassTest.php`
- `tests/Feature/TelegramMiniAppLaunchTest.php`
- `tests/Integration/MilestoneFourConcurrencyTest.php`

Conflict integration: WorkingIntervals receives date projection fixes from the old SchedulingConfiguration without restoring combined settings; WorkSchedule keeps the new heading; Service keeps requires_medical_profile and format buffers; ClientWorkspace keeps demographics/chronic conditions; managed video fields and single-button /start remain intact.

Intentionally excluded: PR #59 proof/audit documents; source inventories; system-proof inventory scripts/tests; staging-proof-client/integrations/evidence helpers; the Finance test that only exercises those evidence helpers; browser diagnostic/editor-selection iteration changes; PR #59 CI inventory step. The approved security lockfile change was implemented separately as `45103f5`, not overwritten from the audit branch. Remaining static-only PR #59 hunks are not silently considered transferred or verified.

Preservation focused checks PASS: Telegram/Scenario/Companion 105 tests / 677 assertions; Finance/gifts/analytics/manual-link 78 tests / 686 assertions; schema-compatible UI/schedule regressions 46 tests / 466 assertions; scheduling/payment processing 47 tests / 306 assertions. PostgreSQL race test for same meeting-link saves is transferred but NOT RUN LOCALLY; hosted candidate gate pending. Real Telegram CTA/client click and exact-SHA staging smoke are NOT VERIFIED pending deploy.

## Summary

| Requirement | Before | After | Evidence |
| --- | --- | --- | --- |
| [CR-01](#cr-01) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-02](#cr-02) | NOT IMPLEMENTED | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-03](#cr-03) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-04](#cr-04) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-05](#cr-05) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-06](#cr-06) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-07](#cr-07) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-08](#cr-08) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-09](#cr-09) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-10](#cr-10) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-11](#cr-11) | NOT IMPLEMENTED | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-12](#cr-12) | BLOCKED BY OWNER/LEGAL DECISION | NOT VERIFIED | Pending exact-SHA acceptance |
| [CR-13](#cr-13) | NOT IMPLEMENTED | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-01](#l2-01) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-02](#l2-02) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-03](#l2-03) | NOT IMPLEMENTED | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-04](#l2-04) | BLOCKED BY OWNER/LEGAL DECISION | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-05](#l2-05) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-06](#l2-06) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-07](#l2-07) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-08](#l2-08) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-09](#l2-09) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-10](#l2-10) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-11](#l2-11) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-12](#l2-12) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-13](#l2-13) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-14](#l2-14) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-15](#l2-15) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |
| [L2-16](#l2-16) | PARTIAL | NOT VERIFIED | Pending exact-SHA acceptance |

## CR-01

Сокращение главного меню бота в чате Telegram

Source: LIST 1, §5 CR-01.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: GetTelegramMenu; existing verified linking/referral/launch paths.
Missing or pending proof: One launch button, welcome video, menu button.

### Source and individual criteria

- CR-01.1: source: «В обработчике команды /start удалить генерацию всех вертикальных инлайн-кнопок («Об авторе», «Метод», «Сообщества», «Партнёры», «Пригласить друга» и т.д.).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-01.2: source: «Оставить ровно одну инлайн-кнопку: [ 🚀 Открыть приложение ], вызывающую WebApp (тип web_app: { url }).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-01.3: source: «К приветственному сообщению прикреплять видеоприветствие (или video note / кружочек) Евгения Чуклова длительностью до 60 секунд.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-01.4: source: «Системную кнопку Menu Button (в нижнем левом углу строки ввода) перевести в режим прямого вызова WebApp.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Сокращение главного меню бота в чате Telegram, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-02

Горизонтальный Stories-компонент на Главной с точными видео Евгения

Source: LIST 1, §5 CR-02.
Before: **NOT IMPLEMENTED**. After: **NOT VERIFIED**.
Preserve: Home.vue.
Missing or pending proof: Managed four Stories, approved media, fullscreen/subtitles/swipes.

### Source and individual criteria

- CR-02.1: source: «Реализовать компонент карусели Stories над блоком приветствия со следующими точными названиями:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-02.2: source: «Кружок 1: «Кто такой Евгений Чуклов (видео)»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-02.3: source: «Кружок 2: «История мастера (видео)»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-02.4: source: «Кружок 3: «Авторский метод (видео)»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-02.5: source: «Кружок 4: «Подробно о методике (видео)»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-02.6: source: «По тапу на кружок открывается полноэкранный модальный плеер с вертикальным видео (формат 9:16), поддержкой субтитров, кнопкой закрытия крестиком и свайп-навигацией между роликами.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-02.7: source: «Высота компонента на экране не должна превышать 90px.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Горизонтальный Stories-компонент на Главной с точными видео Евгения, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-03

Fallback-логика приветствия в шапке (Header Greeting)

Source: LIST 1, §5 CR-03.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: HandleInertiaRequests currently exposes full_name; Home.vue.
Missing or pending proof: Confirmed given name and exact fallback.

### Source and individual criteria

- CR-03.1: source: «Выполнять проверку верификации профиля в БД (users.first_name):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-03.2: source: «Условие 1 (Анкета не заполнена / имя не подтверждено): выводить статичный текст: «Добро пожаловать в пространство здоровья!».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-03.3: source: «Условие 2 (Клиент заполнил профиль/анкету, указав подтвержденное имя): выводить: «Добро пожаловать, {first_name}!».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-03.4: source: «Любые модальные окна с запросом имени при входе в приложение исключить.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Fallback-логика приветствия в шапке (Header Greeting), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-04

Smart Empty State для виджета бронирования

Source: LIST 1, §5 CR-04.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: ListClientBookings; BookingCard.vue.
Missing or pending proof: Exact empty card; single integrated CTA; automatic update.

### Source and individual criteria

- CR-04.1: source: «Выполнять проверку наличия активных записей в таблице bookings со статусами CONFIRMED или PENDING_REVIEW для текущего user_id:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-04.2: source: «Если запись ЕСТЬ: отображать информационную карточку сеанса (Дата, время, формат, адрес/ссылка, статус оплаты) с кнопкой перехода в детали визита.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-04.3: source: «Если записи НЕТ: отображать вовлекающую карточку:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-04.4: source: «Заголовок: «📅 Ваш график восстановления»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-04.5: source: «Подзаголовок: «У вас пока нет запланированных сеансов. Самое время разгрузить тело и снять накопленное напряжение.»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-04.6: source: «Встроенная кнопка действия: [ Выбрать удобное время → ] (событие: программный переход на таб нижнего бара Записаться).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-04.7: source: «Отдельную кнопку «Записаться на приём» под виджетом в состоянии Empty State скрывать, избегая дублирования интерфейса.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Smart Empty State для виджета бронирования, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-05

Редизайн и динамика виджета Health Action

Source: LIST 1, §5 CR-05.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: HomeController; Tracker and Surveys authorities.
Missing or pending proof: Three source-backed states and inline completion; 9 systems source missing.

### Source and individual criteria

- CR-05.1: source: «Форм-фактор: выпуклая карточка со скруглением border-radius: 16px, фоновой пастельной заливкой/градиентом, цветной иконкой в кружке слева и статусным бейджем справа вверху. Текст «ДЕЙСТВИЕ ПО ЗДОРОВЬЮ» полностью удалить.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.2: source: «Состояние 1 (Новый пользователь / тест здоровья не сдан):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.3: source: «Заголовок: «Персональная диагностика»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.4: source: «Описание: «Экспресс-скрининг 9 систем организма. ИИ выявит первопричины боли за 3 минуты.»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.5: source: «Бейдж: [Новое]». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.6: source: «Кнопка: [ Пройти тест → ] (переход к модулю тестов)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.7: source: «Состояние 2 (Запись подтверждена / сеанс завершен):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.8: source: «Заголовок: «Ваша Дорожная карта»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.9: source: «Описание: «Индивидуальные рекомендации и план восстановления готовы к просмотру.»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.10: source: «Бейдж: [Готово]». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.11: source: «Кнопка: [ Открыть план → ] (переход в ИИ-отчет)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.12: source: «Состояние 3 (Активная платная подписка на ИИ-трекер привычек):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.13: source: «Заголовок: «Фокус дня»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.14: source: «Описание: динамический текст текущей практики (например: «5 минут лимфодренажной разгрузки шеи + питьевой режим»)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-05.15: source: «Интерактив: чекбокс прямо на карточке [ Выполнено ✅ ]». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Редизайн и динамика виджета Health Action, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-06

Редизайн кнопки коммуникации (Чат)

Source: LIST 1, §5 CR-06.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Home.vue uses canonical companion URL.
Missing or pending proof: Exact copy/description; actual history and return journey.

### Source and individual criteria

- CR-06.1: source: «Заменить текст плашки «Открыть чат» на Задать вопрос.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-06.2: source: «Добавить поясняющую подпись: «ИИ-ассистент ответит на вопросы по симптомам и поможет подобрать подходящий формат сеанса».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-06.3: source: «Клик по блоку триггерит программное переключение на 4-й таб нижнего бара (Чат).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Редизайн кнопки коммуникации (Чат), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-07

Актуализация полей профиля в «Личных данных» (Mini App и CRM)

Source: LIST 1, §5 CR-07.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Client; GetClientProfile; Profile.vue; ClientForm.
Missing or pending proof: Separate names, gender, birth date, country/city, booking fields, cabinet entries.

### Source and individual criteria

- CR-07.1: source: «Доступ к вкладке Кабинет предоставляется без блокирующих модальных окон авторизации.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.2: source: «В разделе «Личные данные» (таб Кабинет) и на Этапе 1 бронирования реализовать поля профиля строго отдельными инпутами:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.3: source: «Фамилия (Text Input, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.4: source: «Имя (Text Input, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.5: source: «Отчество (Text Input, необязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.6: source: «Пол (Segmented Control: Мужской / Женский, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.7: source: «Дата рождения (Date Picker, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.8: source: «Номер телефона (Phone Input с валидацией по маске страны, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.9: source: «Страна (Select / Text Autocomplete, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.10: source: «Город (Select / Text Autocomplete, обязательное)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.11: source: «E-mail (Email Input, необязательное, подсказка: «Для отправки чеков и отчетов»)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.12: source: «Поле «Ник в Telegram» полностью исключить из формы ввода: значение @username перехватывается бэкендом из initData и записывается в таблицу users автоматически.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.13: source: «Структура вложенных пунктов меню во вкладке Кабинет:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.14: source: «Мои записи». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.15: source: «Личные данные». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.16: source: «Медицинские данные (см. CR-08)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.17: source: «Партнёрская программа (реферальная ссылка, статистика переходов, баланс бонусов)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.18: source: «Для мастеров и бизнеса (форма заявки на франшизу бота и запись на Zoom с Евгением)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.19: source: «Наши сообщества (список официальных Telegram-каналов Евгения)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-07.20: source: «Обратная связь». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Актуализация полей профиля в «Личных данных» (Mini App и CRM), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-08

Кнопка «Медицинские данные» в Кабинете и логика визарда после записи

Source: LIST 1, §5 CR-08.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: GetClientMedicalProfile; MedicalProfile authority; Health.vue.
Missing or pending proof: Complete questionnaire, preview/edit, booking prefill/return.

### Source and individual criteria

- CR-08.1: source: «В меню таба Кабинет добавить пункт «Медицинские данные»:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.2: source: «По клику открывается экран просмотра клинического профиля (поля из Раздела 2 данного документа).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.3: source: «Внизу экрана разместить кнопку [ Редактировать данные ], открывающую форму изменения полей с последующим UPDATE в таблице medical_profiles.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.4: source: «Поведение визарда на Этапе 2 бронирования:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.5: source: «Сразу после выбора слота/оплаты услуги с флагом requires_medical_profile открывается экран медицинской анкеты.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.6: source: «Если медицинские данные отсутствуют: визард открывается пустым для первичного ввода.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.7: source: «Если медицинские данные уже заполнены: визард открывается предзаполненным текущими значениями из базы с уведомлением:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.8: source: ««Проверьте актуальность данных о вашем здоровье перед приёмом. Если что-то изменилось — скорректируйте информацию».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-08.9: source: «При нажатии кнопки «Подтвердить данные» выполняется обновление записи в БД.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Кнопка «Медицинские данные» в Кабинете и логика визарда после записи, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-09

Синхронизация полей в карточке клиента в Web-CRM

Source: LIST 1, §5 CR-09.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: ClientWorkspaceInfolist; encrypted split clinical fields.
Missing or pending proof: Extended demographics/chronic conditions; CRM create/edit/session projections.

### Source and individual criteria

- CR-09.1: source: «В блоке «Клиент» (левая колонка):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.2: source: «Вынести поле Фамилия отдельной строкой (рядом с Именем).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.3: source: «Добавить отображение полей: Отчество, Пол, Дата рождения, Страна, Город.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.4: source: «В блоке «Клинический профиль» (правая колонка):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.5: source: «Разбить поле «Жалобы, ВАШ и цели» на два самостоятельных блока:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.6: source: ««Жалобы и шкала боли (ВАШ)» — перечень симптомов + зафиксированный балл боли от 0 до 10.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.7: source: ««Задачи по здоровью» — текстовые задачи и потребности пациента на курс терапии.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.8: source: «Добавить отдельное поле «Хронические заболевания» (Textarea).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-09.9: source: «Сохранить поля: «Операции и травмы», «Лекарственные препараты», «Нутрицевтики и БАДы».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Синхронизация полей в карточке клиента в Web-CRM, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-10

Разграничение прав доступа к медицинской карте (Клиент vs Мастер)

Source: LIST 1, §5 CR-10.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Medical authorization; existing encrypted profile/session authorities.
Missing or pending proof: Exact public/private field audit and negative browser/API proof.

### Source and individual criteria

- CR-10.1: source: «Публичный контур API (доступен клиенту в WebApp и Евгению в CRM):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.2: source: «Контактные данные, Дата рождения, Пол, Город, Страна.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.3: source: «Жалобы, Шкала боли (ВАШ), Задачи по здоровью, Хронические заболевания.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.4: source: «Операции, Травмы, Препараты, Нутрицевтики/БАДы.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.5: source: «Рекомендации мастера («Дорожная карта»), Сравнительная таблица До/После.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.6: source: «Закрытый контур CRM (доступен ТОЛЬКО Евгению в админ-панели, исключен из API-ответов для клиента):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.7: source: «root_cause_hypothesis (Гипотеза первопричины поражения).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.8: source: «kinesiotherapy_data (Данные мышечного и фасциального тестирования).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.9: source: «session_protocol (Внутренние заметки мастера по ходу сеанса).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.10: source: «ai_summary_json (Клинический экспресс-доклад ИИ перед приёмом).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-10.11: source: «Служебные маркетинговые и B2B-теги клиента.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Разграничение прав доступа к медицинской карте (Клиент vs Мастер), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-11

Системный флаг услуги requires_medical_profile (Каталог Услуг)

Source: LIST 1, §5 CR-11.
Before: **NOT IMPLEMENTED**. After: **NOT VERIFIED**.
Preserve: Service has no requires_medical_profile field.
Missing or pending proof: Flag, CRM action, server flow and compatible true default.

### Source and individual criteria

- CR-11.1: source: «В таблицу services и в форму создания/редактирования услуги в Admin CRM добавить булево поле:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-11.2: source: «requires_medical_profile (чекбокс: «Требуется медицинская анкета», значение по умолчанию true).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-11.3: source: «Во фронтенде WebApp: после выбора слота/оплаты проверять флаг выбранной услуги:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-11.4: source: «Если true ➔ открывать визард медицинской анкеты (Этап 2).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-11.5: source: «Если false ➔ пропускать Этап 2 и сразу перенаправлять на экран «Запись подтверждена».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Системный флаг услуги requires_medical_profile (Каталог Услуг), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-12

Архитектурная поддержка семейной и групповой записи

Source: LIST 1, §5 CR-12.
Before: **BLOCKED BY OWNER/LEGAL DECISION**. After: **NOT VERIFIED**.
Preserve: Existing party_size only; not family identity.
Missing or pending proof: Separate patient identity and booking/invite/unlink; no inferred medical delegation.

### Source and individual criteria

- CR-12.1: source: «Схема базы данных (PostgreSQL):». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.2: source: «Создать таблицу patient_profiles:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.3: source: «SQL». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.4: source: «CREATE TABLE patient_profiles (». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.5: source: «id BIGSERIAL PRIMARY KEY,». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.6: source: «user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.7: source: «first_name VARCHAR(100) NOT NULL,». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.8: source: «last_name VARCHAR(100) NOT NULL,». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.9: source: «middle_name VARCHAR(100),». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.10: source: «relationship VARCHAR(50) NOT NULL, -- 'SELF', 'SPOUSE', 'CHILD', 'PARENT'». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.11: source: «gender VARCHAR(10) NOT NULL,        -- 'MALE', 'FEMALE'». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.12: source: «birth_date DATE NOT NULL,». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.13: source: «created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.14: source: «);». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.15: source: «В таблице bookings добавить внешний ключ patient_profile_id (FK на конкретного пациента).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.16: source: «В таблице medical_profiles привязать клинические записи к patient_profile_id.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.17: source: «Пользовательский интерфейс в WebApp:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.18: source: «При начале записи добавить выбор: Кого записываем? (Себя / Члена семьи).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.19: source: «При выборе члена семьи запрашивать: Фамилию, Имя, Отчество, Пол, Статус родства и Дату рождения.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.20: source: «Отображение в CRM Мастера:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.21: source: «В карточке бронирования выводить: «Пациент: Михаил Солдатов (Сын, 7 лет) • Заказчик: Денис Солдатов».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-12.22: source: «В карточке клиента выводить связанный список всех членов семьи.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Архитектурная поддержка семейной и групповой записи, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## CR-13

Каскад напоминаний о заполнении карты здоровья (Notification Engine)

Source: LIST 1, §5 CR-13.
Before: **NOT IMPLEMENTED**. After: **NOT VERIFIED**.
Preserve: Existing Scenarios and Notification authorities.
Missing or pending proof: Health-card cascade with live suppression, schedule/retry delivery.

### Source and individual criteria

- CR-13.1: source: «Триггер #1 (Моментальный): Сразу после оформления бронирования бот отправляет в Telegram сообщение:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.2: source: ««Бронирование подтверждено! Чтобы Евгений Чуклов заранее ознакомился с вашей ситуацией и подготовил индивидуальный план — заполните короткую карту здоровья: [Заполнить карту здоровья]».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.3: source: «Триггер #2 (За 24 часа до сеанса): Фоновый воркер (Cron / Queue) проверяет статус medical_profiles:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.4: source: «Если данные заполнены: отправляется стандартное подтверждение с временем и локацией встречи.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.5: source: «Если данные НЕ заполнены: отправляется напоминание:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.6: source: ««До вашего сеанса осталось 24 часа! Пожалуйста, уделите 3 минуты на заполнение жалоб, чтобы мастер встретил вас с готовой стратегией: [Заполнить карту здоровья]».». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.7: source: «Триггер #3 (За 2 часа до сеанса): Финальное сервисное пуш-напоминание, если поля всё ещё не заполнены.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- CR-13.8: source: «Fallback в CRM: Если пациент не заполнил данные онлайн, Евгений нажимает кнопку «Изменить медицинский профиль» в CRM и вносит параметры вручную со слов клиента во время приёма.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Каскад напоминаний о заполнении карты здоровья (Notification Engine), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-01

Поле «Примечание» в карте клиента

Source: LIST 2, №1.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: ClientNotesRelationManager and ClientNote.
Missing or pending proof: Real create/edit/read persistence regression.

### Source and individual criteria

- L2-01.1: source: «11.08.26 (1)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-01.2: source: «**Поле «Примечание» в карте клиента**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-01.3: source: «В карточке клиента нужно поле Примечание (можно писать разные особенности)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Поле «Примечание» в карте клиента, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-02

Чёрный список (раздел в клиентах)

Source: LIST 2, №2.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: ClientRestrictionType::Blacklist and ClientsTable.
Missing or pending proof: Reason, filter, direct booking denial and reversal regression.

### Source and individual criteria

- L2-02.1: source: «11.08.26 (2)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-02.2: source: «**Чёрный список (раздел в клиентах)**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-02.3: source: «Возможность поместить клиента в чёрный список, с указанием причины в поле Примечание \- были случаи когда люди просто в не адеквате бывали или не заплатили и убежали и прочее.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-02.4: source: «Добавить статус «Чёрный список» в фильтре списка клиентов». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Чёрный список (раздел в клиентах), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-03

Быстрая запись на несколько сеансов

Source: LIST 2, №3.
Before: **NOT IMPLEMENTED**. After: **NOT VERIFIED**.
Preserve: Existing CRM multi-booking; single Portal flow.
Missing or pending proof: Portal + sessions before clinical data, explicit atomic semantics.

### Source and individual criteria

- L2-03.1: source: «26.09.26 (3)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-03.2: source: «**Быстрая запись на несколько сеансов**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-03.3: source: «Возможность записи сразу на несколько сеансов (до ввода данных)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-03.4: source: «После выбора даты/времени кнопка «+» добавить ещё сеанс». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-03.5: source: «Уже после выбора всех временных сеансов переходить к запросу мед данных». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Быстрая запись на несколько сеансов, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-04

Запись нескольких членов семьи одним пользователем

Source: LIST 2, №4.
Before: **BLOCKED BY OWNER/LEGAL DECISION**. After: **NOT VERIFIED**.
Preserve: Party size is separate group-booking feature.
Missing or pending proof: Invitation/account linking/revocation; owner/legal adult/minor permissions.

### Source and individual criteria

- L2-04.1: source: «26.09.26 (4)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.2: source: «**Запись нескольких членов семьи одним пользователем**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.3: source: «При записи своих членов семьи пользователем:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.4: source: «Кнопку "Выслать приглашение" на сеанс члену семьи можно сделать в виде реф ссылки на бота, и когда пользователь зайдёт, ему придёт уведомление о предстоящей записи и уведомление для заполнения анкеты с мед данными.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.5: source: «Если пользователь привязал члена своей семьи по ссылке, то он может выбрать его, чтобы не вводить все его данные.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.6: source: «Нужно тогда в кабинете в личных данных отображать привязанных членов семьи и возможность отвязать / привязать новых.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.7: source: «А также при записи показывать и выбирать уже привязанных.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.8: source: «**Вопрос 1:** высылать уведомления о записи и конечному клиенту и тому, кто его записал». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.9: source: «**Вопрос 2 (серьёзный): безопасность и доверительное право\!** Анкету по здоровью заполняет только тот, кому пришла ссылка, если это взрослый (а не тот, кто записывает). А если ребёнок \- то пользователь может заполнить анкету за него\! Нужно изучить, что по этому поводу говорит Российское и международное право\! Возможно кто-то из взрослых также может дать согласие на доступ к своей мед карте другому. Я слышал, что такое допускается (у нас через госуслуги), актуально, например, если взрослый доверяет своему члену семьи, часто родители детям или муж жене и пр. Но эту механику нужно продумать также технически\!». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-04.10: source: «**Ответ:** Женя изучит и скажет как». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Запись нескольких членов семьи одним пользователем, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-05

Визуализация шкалы боли

Source: LIST 2, №5.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing encrypted numeric VAS.
Missing or pending proof: Face/color progression 0..10/null and CRM session dynamics.

### Source and individual criteria

- L2-05.1: source: «26.09.26 (5)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-05.2: source: «**Визуализация шкалы боли**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-05.3: source: «**при оценке боли при заполнении мед данных пользователем**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-05.4: source: «**Шкала интенсивности боли (ВАШ):**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-05.5: source: «* Формат: Интерактивный слайдер от 0 до 10 (где 0 — «нет боли», 10 — «невыносимая боль»)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-05.6: source: «Дополнение Жени по визуализации:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-05.7: source: «Кстати одной из решения это создать либо с скроллинг то есть ноль это когда счастливый человечек счастливый смайлик и померить движения к десятке можно с визуализировать что смайлик-то начинает что то есть как бы Мень улыбаться потом начнёт грустить и потом он уже становится грустной и красного цвета то есть можно и так делать». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Визуализация шкалы боли, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-06

Работа с бонусами

Source: LIST 2, №6.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing ServiceCredit and FX authority.
Missing or pending proof: Top-right balance, currency display, click explanation/catalog.

### Source and individual criteria

- L2-06.1: source: «26.09.26 (6)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.2: source: «**Работа с бонусами**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.3: source: «**Выделять накопленные бонусы, чтобы их было видно (в правом верхнем углу экрана)**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.4: source: «Если есть бонусы, то можно просто при нажатии выводить "вы можете частично или полностью оплатить ими любую услугу" и переводить на список услуг.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.5: source: «**Конвертация бонусного баланса**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.6: source: «Так как у нас система мультивалютная, встал вопрос, в чём отображать бонусы?». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.7: source: «**Вариант:**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.8: source: «**Для пользователя:**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.9: source: «В той валюте, которая в настройках выбрана основной.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.10: source: «И при оплате в другой валюте конвертировать в ту валюту, которая у пользователя выбрана основной.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.11: source: «Например, пользователь оплачивает в рублях, и у него основа \- рубли. Тогда бонусы в рублях. А если у него основа \- доллары, то в долларах.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.12: source: «**Для администратора:**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.13: source: «Администратор в CRM бонусы видит в той валюте, которая выбрана как основная.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-06.14: source: «**ВАЖНО:** при оплате конвертировать бонусы в нужную валюту для списания, чтобы не было такого, что пользователь оплатит рублевыми бонусам долларовый счёт, а специалист угорит на этом )». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Работа с бонусами, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-07

Работа с сертификатами:

Source: LIST 2, №7.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing gift certificate implementation merged before starting SHA.
Missing or pending proof: Purchase/issue/claim/residual spend/correction real two-actor regression.

### Source and individual criteria

- L2-07.1: source: «26.09.26 (7)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-07.2: source: «**Работа с сертификатами:**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-07.3: source: «смотри можно сделать сертификаты либо стандартные как бы да там тысячу пять ну 5 1 начинать нас 5000 там 5000 рублей там 10000 рублей то есть либо в долларах там к тому они могут конвертироваться либо сделать гибкую систему то есть человек сам и бывает сколько он хотел бы допустим подарить когда человеку и система искусственный интеллект сам создает сертификат на вот эту же сумму вот то есть как создается генерация вот но я думаю это будет универсальный сертификат и на обучение и на оздоровление и соответственно он выбирает то есть на какую сумму он хочет купить покупает его попадает на платежную страницу покупают его ему выдается какое-то.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-07.4: source: «После покупки ему выдается сгенерированный сертификат на определенную сумму которую он может переслать уже своему знакомому то есть его поздравить там и так далее и вот в этом сертификате там будучи ссылочка вот человек нажимая на эту ссылку переходит наш бот то задача его перетянуть в бота проходит регистрацию выбирает на что он хочет потратить там на обучение на оздоровление там и так далее то есть проходит регистрацию (в ссылке заложен реферер и сертификат), привязывает свой аккаунт к нашему боту и уже там оставляет заявку то есть уже на выбор вот здесь искусственный интеллект может еще его вести подсказывать его так скажем приветствовать там до подсказывать что не так то есть как бы есть какой-то вопрос он может задать ну и так далее». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Работа с сертификатами:  , with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-08

Статистика по рекомендациям, чтобы оценить эффективность в целом и конкретными пользователями

Source: LIST 2, №8.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing referral statistics.
Missing or pending proof: Overall and client-specific counts/authoritative outcomes regression.

### Source and individual criteria

- L2-08.1: source: «26.09.26 (8)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-08.2: source: «Статистика по рекомендациям, чтобы оценить эффективность в целом и конкретными пользователями». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Статистика по рекомендациям, чтобы оценить эффективность в целом и конкретными пользователями, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-09

При создании новой записи на приём

Source: LIST 2, №9.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing backdated confirmation in BookingForm.
Missing or pending proof: Exact red warning and mandatory confirmation direct/UI proof.

### Source and individual criteria

- L2-09.1: source: «26.09.26 (9)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-09.2: source: «При создании новой записи на приём». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-09.3: source: ««Внимание\! Вы создаёте запись задним числом: 30.09.2026 04:30.»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-09.4: source: «Надпись сделать красным шрифтом». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: При создании новой записи на приём  , with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-10

Добавить никнейм телеграм в карточке клиента (сейчас есть только telegram id) со ссылкой на тг акк по id или никнейму.

Source: LIST 2, №10.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Verified Telegram presentation in client workspace.
Missing or pending proof: Username/id link updating from verified identity regression.

### Source and individual criteria

- L2-10.1: source: «26.09.26 (10)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-10.2: source: «Добавить никнейм телеграм в карточке клиента (сейчас есть только telegram id) со ссылкой на тг акк по id или никнейму.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Добавить никнейм телеграм в карточке клиента (сейчас есть только telegram id) со ссылкой на тг акк по id или никнейму., with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-11

Переименовать везде кнопки с «Создать» и «Создать и создать ещё» в «Сохранить»и «Сохранить и создать ещё»

Source: LIST 2, №11.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing customized create-page labels.
Missing or pending proof: Every create/create-another label inventory and submit behavior.

### Source and individual criteria

- L2-11.1: source: «26.09.26 (11)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-11.2: source: «Переименовать везде кнопки с «Создать» и «Создать и создать ещё» в «Сохранить»и «Сохранить и создать ещё»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Переименовать везде кнопки с «Создать» и «Создать и создать ещё» в «Сохранить»и «Сохранить и создать ещё», with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-12

В каждой услуге добавить «время перерыва после записи». Для каждого формата услуги этот период может быть свой.

Source: LIST 2, №12.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Service.buffer_minutes; CalculateAvailability; occupancy.
Missing or pending proof: Per-format authoritative buffers and legacy fallback; race proof.

### Source and individual criteria

- L2-12.1: source: «26.09.26 (12)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-12.2: source: «В каждой услуге добавить «время перерыва после записи». Для каждого формата услуги этот период может быть свой.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: В каждой услуге добавить «время перерыва после записи». Для каждого формата услуги этот период может быть свой., with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-13

«Адрес по умолчанию» в настройках расписания \- переименовать в «Адрес приёма по умолчанию»

Source: LIST 2, №13.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: SchedulingConfiguration address label already corrected.
Missing or pending proof: Exact label and save/reload default-address regression.

### Source and individual criteria

- L2-13.1: source: «26.09.26 (13)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-13.2: source: ««Адрес по умолчанию» в настройках расписания \- переименовать в «Адрес приёма по умолчанию»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: «Адрес по умолчанию» в настройках расписания \- переименовать в «Адрес приёма по умолчанию», with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-14

Настройки расписания

Source: LIST 2, №14.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: SchedulingConfiguration mixes global and weekly intervals; UnavailablePeriods separate.
Missing or pending proof: Exact source-specific navigation, intervals, journal multiselect, exceptions/location days.

### Source and individual criteria

- L2-14.1: source: «26.09.26 (14)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.2: source: «**Настройки расписания**». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.3: source: «1\. Перенести в самый вниз раздела «Записи»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.4: source: «2\. В раздел «настройки расписания» попадают как блоки с общими параметрами для всего расписания, так и частные для конкретного специалиста (рабочие интервалы) \- всё на одну страницу, что создаёт путаницу. Нужно разделить на «Настройки расписания» и «Рабочие интервалы» (отдельный раздел).». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.5: source: «3\. Также надпись вверху скорректировать:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.6: source: ««Здесь настраиваются B2B-разговоры и рабочее время специалиста. Исключения и недоступное время — в разделе «Записи»" \==\> «Здесь настраиваются общие параметры для всего расписания» — для общих настроек и соответственно для других.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.7: source: «Журнал записей \- добавить по умолчанию просмотр журнала для всех доступных специалистов, а выбор специалистов \- сделать фильтром выпадающим списком с чек-боксами, чтобы можно было просмотреть журнал сразу для нескольких.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.8: source: «Раздел «График работы» \- надпись вверху «График работы специалиста»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.9: source: «Раздел перенести сразу после «». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.10: source: «Недоступное время \- раздел убрать.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.11: source: «Перенести в раздел «Изменения расписания» как один из типов изменений с датами начала и окончания.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.12: source: «Дни выезда.». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.13: source: «Заголовок «Дни выезда специалистов»». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-14.14: source: «И привязать дни выезда к выбранному специалисту». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Настройки расписания  , with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-15

В карточке «Медицинский профиль» пользователя разделить поля:

Source: LIST 2, №15.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing encrypted complaints/goals/operations/injuries.
Missing or pending proof: Preserve split fields, legacy history and update/session regression.

### Source and individual criteria

- L2-15.1: source: «26.09.26 (15)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-15.2: source: «В карточке «Медицинский профиль» пользователя разделить поля:». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-15.3: source: «\- Жалобы и цели (см. доп файл с изменениями)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-15.4: source: «\- Разделить операции и травмы». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: В карточке «Медицинский профиль» пользователя разделить поля:  , with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-16

Редактировать шаблон сообщения для рассылки. Не закрепляется фото/видео в сообщении

Source: LIST 2, №16.
Before: **PARTIAL**. After: **NOT VERIFIED**.
Preserve: Existing broadcast/template media authority.
Missing or pending proof: Attach photo/video, save, reopen, actual delivery and preview.

### Source and individual criteria

- L2-16.1: source: «26.09.26 (16)». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.
- L2-16.2: source: «Редактировать шаблон сообщения для рассылки. Не закрепляется фото/видео в сообщении». Acceptance: reproduce this exact stated outcome in the applicable client/CRM flow; record visible result and authoritative state. **NOT VERIFIED**.

Client outcome: Редактировать шаблон сообщения для рассылки. Не закрепляется фото/видео в сообщении, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## Additional LIST 1 §1/§2 criteria

The fast-booking flow and clinical field specification apply jointly to CR-07/08/09/11/13. Private storage follows the accepted repository storage architecture; the source's S3 wording does not authorize replacing it.

- CLINICAL.1: «2. Спецификация полей: Клинический профиль Пациента (Этап 2)». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.2: «Данные поля формируют медицинскую карту пациента, собираются на Этапе 2 бронирования и доступны для просмотра/редактирования в Личном Кабинете в разделе «Медицинские данные»:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.3: «•	Главные жалобы (Симптомы):». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.4: «•	Элемент интерфейса: Мульти-селект чипсов (Головные боли, Боль в шее, Боль в пояснице, Суставы, Отечность, Проблемы ЖКТ, Сонливость/Слабость, Бессонница, Стресс/Тревожность) + текстовое многострочное поле для детализации жалоб в свободной форме.». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.5: «•	Шкала интенсивности боли (ВАШ):». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.6: «•	Элемент интерфейса: Интерактивный ползунок-слайдер со значениями от 0 до 10 (где 0 — «нет боли», 10 — «нестерпимая боль»).». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.7: «•	Задачи по здоровью:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.8: «•	Элемент интерфейса: Многострочное текстовое поле (Textarea).». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.9: «•	Плейсхолдер: «Опишите конкретные результаты и задачи, которые вы хотите решить с Евгением (например: 1. Убрать тянущую боль в пояснице при наклонах. 2. Избавиться от утренней скованности и отеков)».». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.10: «•	Хронические заболевания:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.11: «•	Элемент интерфейса: Текстовое поле (Textarea) для фиксации установленных диагнозов. Предусмотреть чекбокс «Хронических заболеваний нет».». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.12: «•	Перенесенные операции:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.13: «•	Элемент интерфейса: Переключатель (Radio: Да / Нет). При выборе Да раскрываются вложенные поля: Год операции (Number) и Описание операции/рубца (Text: где находится шрам).». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.14: «•	Перенесенные травмы и переломы:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.15: «•	Элемент интерфейса: Переключатель (Radio: Да / Нет). При выборе Да раскрываются вложенные поля: Год травмы (Number) и Характер травмы (Text).». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.16: «•	Принимаемые лекарственные препараты:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.17: «•	Элемент интерфейса: Текстовое поле (Textarea) — постоянные аптечные медикаменты, дозировки и частота приёма.». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.18: «•	Нутрицевтики и БАДы:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.19: «•	Элемент интерфейса: Текстовое поле (Textarea) — витамины, биологически активные добавки, сборы, микроэлементы.». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.20: «•	Медицинские документы и обследования (Опционально):». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.21: «•	Элемент интерфейса: Мульти-загрузчик файлов (до 5 файлов: JPG, PNG, PDF до 20 МБ каждый). Заключения МРТ, КТ, УЗИ или врачебные выписки. Загрузка производится в защищенный приватный S3-бакет с генерацией внутреннего UUID.». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
