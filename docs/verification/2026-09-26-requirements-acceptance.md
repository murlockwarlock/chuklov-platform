# 26.09.2026 — requirements and staging acceptance

Starting main SHA: `c201b41a14d91c57c1890e62737f9e2001a231f1`.
Branch: `codex/26-09-full-implementation`. Current PR: #60.

On 2026-10-23, `/Users/ivankorakin/Downloads/Изменения_в_CRM_Чуклов_бот_от_26_09_26_СПИСОК_1.docx` and `/Users/ivankorakin/Downloads/Изменения_в_CRM_Чуклов_бот_от_26_09_26_СПИСОК_2.md` were re-read. LIST 1 SHA-256: `d421c99855a149a4435a590d6d6dd306c51087290cc41760db0ec75395eb2b02`; LIST 2 SHA-256: `20a869450b7c5bac032eecee389d5a240012f434d9a2e462a4c2637926f24e0d`. The ledger contains 29 parent requirements (CR-01–CR-13 and LIST 2 items 1–16) and 226 numbered leaf criteria: 124 CR, 81 L2, and 21 clinical. Parent headings are not double-counted as leaf criteria. The remaining gap is criterion-to-evidence reconciliation, not the denominator.

## Owner-directed final pass — 2026-10-23

**Overall result: FAIL — the full acceptance pass is not complete. PR #60 remains Draft/Open and unmerged.** This section supersedes every earlier status in this file. The denominator remains 29 parent requirements / 226 numbered leaf criteria. L2-03.1–L2-03.5 are `VERIFIED`; the four behavior leaves have direct staging, PostgreSQL, Chromium and WebKit evidence using synthetic Portal identities and normal email verification. L2-04.1–L2-04.3 and L2-04.5–L2-04.7 are `VERIFIED`; L2-04.4 is externally `BLOCKED` only for independent recipient-visible Telegram notification, and L2-04.8–L2-04.10 await explicit owner/business/legal decisions. L2-14.1–L2-14.8, L2-15.1–L2-15.4, and L2-16.1 are `VERIFIED`; L2-14.9 and the recipient-visible portion of L2-16.2 remain objectively blocked. CR-01 was exercised at the transport/test and staging-runtime levels, but all four leaves remain objectively `BLOCKED` for a real Telegram-client journey; approved welcome video is also absent. CR-02.1–CR-02.5 and CR-02.7 are now `VERIFIED`; CR-02.6 is `BLOCKED` only for the missing approved Eugene video/subtitle assets, after its playback, subtitle, navigation, close, retry and failure-copy mechanics were exercised with synthetic QA media. At entry to the CR-02 continuation, staging runtime and PR code head were `80dfcbc9bab353ca4aa034c2c17b6c0f6fa85932`; deployment and repository-owned staging smoke passed. Current-pass outcome: 65 `VERIFIED`, zero `FAIL`, eleven objective `BLOCKED`, 150 machine-verifiable leaves remaining without direct evidence, zero `PARTIAL`. No older IMPLEMENTED, PARTIAL, or NOT VERIFIED label upgrades a current requirement.

For this checkpoint, CR-01.1–CR-01.4 are `BLOCKED` only for the real recipient-visible Telegram-client journey; the saved QA profile has no authenticated Telegram client/chat-history session. CR-01.3 additionally lacks approved author welcome media. L2-04.4 is blocked only for viewing the booking and medical-questionnaire notifications in the invited QA recipient's Telegram chat. L2-04.8 is an unresolved recipient policy question, L2-04.9 requires an owner/legal consent decision, and L2-04.10 explicitly defers the decision to the owner. L2-14.9 remains blocked because LIST 2 ends its navigation instruction after «Раздел перенести сразу после «» without a destination anchor. L2-16.2 remains blocked only for independent recipient-side observation. CR-02.6 is blocked only because its exact authored video and matching subtitles await approved owner content; synthetic media proved the mechanics, not the final content. The expired saved client-2 state initially returned HTTP 401; this was resolved for L2-03 using fresh synthetic Portal identities through normal email verification and staging Mailpit, with no owner interaction or authentication bypass. The active result is 65 `VERIFIED`, zero `FAIL`, eleven `BLOCKED`, 150 machine-verifiable leaves still without direct evidence, and zero `PARTIAL` claims. Historical sections below are retained for provenance and are not current acceptance status.

### CR-01 continuation checkpoint — 2026-10-23

The exact LIST 1 source is §5 CR-01; source hashes are recorded at the top of this ledger. The launch CTA must be exactly «🚀 Открыть приложение», be the sole inline button, and use Telegram `web_app: { url }`. `/start` should present the welcome video/video note (Eugene, no longer than 60 seconds), concise greeting and launch CTA; the system Menu Button must open the WebApp directly.

All four leaves remain **BLOCKED**, not VERIFIED: transport and app behavior have regression evidence, but the QA profile contains no authenticated Telegram client/chat-history session, so the actual `/start` message, system Menu Button and recipient-visible result cannot be inspected independently. CR-01.3 also has no approved Eugene welcome video published in staging. No substitute author material was used and no duplicate recipient-visible message was sent.

- CR-01.1 — **BLOCKED**. `MilestoneTwoTelegramBotTest::test_start_renders_the_localized_menu_through_fake_transport` asserts one inline WebApp action and absence of legacy vertical actions. Live `/start` message inspection remains blocked by the missing Telegram client session.
- CR-01.2 — **BLOCKED**. `MilestoneTwoTelegramBotTest::test_start_renders_the_localized_menu_through_fake_transport` and `September26ContentVideoTest::test_uploaded_video_is_persisted_and_welcome_uses_video_and_one_web_app_button` assert the exact Russian CTA label, sole button and `web_app.url`. The pre-fix assertion reproduced the missing 🚀; commit `3809ece3df9275bbf78afdce81839af8be2a2e08` corrected it. Recipient-visible rendering after the fix was not observed.
- CR-01.3 — **BLOCKED**. A read-only PostgreSQL staging query found no published `telegram_welcome` video. The source-approved author asset and duration cannot be verified. `September26ContentVideoTest::test_missing_welcome_video_shows_recovery_copy_and_logs_safe_diagnostics` proves the missing-media case gives a localized recovery message and structured safe diagnostic; no test clip was substituted as final content.
- CR-01.4 — **BLOCKED**. Transport tests verify the direct WebApp Menu Button payload and localized fallback for an unconfirmed update. The actual Telegram system button cannot be observed without a Telegram client session.

The localized Telegram failure/recovery and diagnostic cases are protected by `September26ContentVideoTest::test_video_and_menu_provider_failures_show_recovery_copy_and_log_safe_diagnostics`, `::test_unconfirmed_menu_button_update_shows_recovery_copy_and_logs_unknown_outcome`, `::test_unconfirmed_greeting_delivery_is_not_reported_as_success`, and `::test_greeting_delivery_failure_is_not_reported_as_a_success`. They assert structured event/error/provider/outcome/attempt/organization/correlation/time context, actionable Russian copy, and no raw exception, token or media payload. `MilestoneTwoTelegramBotTest.php` passed locally (34 tests, 180 assertions); `September26ContentVideoTest.php` passed locally (9 tests, 125 assertions); PHP syntax, Pint and `git diff --check` passed. No GitHub Actions run exists for SHA `3809ece3` (`gh run list --commit 3809ece3...` returned no runs); no full CI result is implied.

Commit `3809ece3df9275bbf78afdce81839af8be2a2e08` deployed successfully to PostgreSQL 18 staging. The deployment health checks and `./scripts/staging-smoke.sh` passed on that exact runtime, including app, Horizon, scheduler, Telegram, CRM and Portal checks. This is runtime/health evidence, not a real Telegram-client acceptance journey; no Telegram recipient message was sent by this smoke. The code candidate and staging runtime matched at `3809ece3`; this checkpoint changes evidence documents only. PR #60 remains Draft/Open and unmerged.

- Starting SHA: c201b41a14d91c57c1890e62737f9e2001a231f1.
- Branch / PR: codex/26-09-full-implementation / #60; not merged.
- At the L2-16 checkpoint, staging was `140a5b0bf3fea2daf908b0e85c33fd992008c985`; L2-14, L2-15 and L2-16 evidence was collected against that runtime or code-equivalent paths. The later L2-03 fix was deployed first to `f198c763aae5624ce3d6dd07b8aea31130f5b644`, then to `035319c003e2e36c68a79d74136483b6cdbf4d54`; both runtimes are now superseded by CR-01 runtime `3809ece3df9275bbf78afdce81839af8be2a2e08`. L2-16 itself made no staging write or external send; its thirteen focused fake-provider tests passed on code-equivalent branch HEAD `0a169d5c` (60 assertions). PR #60 remains Draft/Open.
- L2-16.2’s earlier staging proof remains: the authorized CRM test-send targeted exactly one QA client; saved media reopened, Preview returned the signed private-media URL successfully, and the Telegram provider recorded delivery attempt 1 with a provider reference. The local QA profile contains authenticated CRM user 19 and Portal client 2 states, but no Telegram client/browser session or message-history access. Provider acceptance does not prove independent rendering in the recipient chat, so that external observation stays BLOCKED. No staging data or recipient was touched during this continuation.
- The staging deployment from `29d5b49f355566a108d5a355ed3e23f901dd0ff4` to `140a5b0bf3fea2daf908b0e85c33fd992008c985` created and validated a PostgreSQL backup, reported “Nothing to migrate,” refreshed the application/workers, passed health checks and preserved unrelated host/routing state. `scripts/staging-smoke.sh` then passed on the exact `140a5b0b` runtime.
- The earlier L2-03 checkpoint code candidate `04b48ceeaf4e38c08166aca3cb517fc8eb95f7ce` cleared stale slot/idempotency validation when the user changes or removes a selected time and emitted `booking.multiple.slot_unavailable` through Laravel's existing logger with safe organization/actor/client/specialist/service/slot context. That checkpoint runtime was `f198c763aae5624ce3d6dd07b8aea31130f5b644`, deployed from `140a5b0bf3fea2daf908b0e85c33fd992008c985`; the subsequent L2-03 runtime was `035319c003e2e36c68a79d74136483b6cdbf4d54`. Both are historical and superseded by current runtime `3809ece3df9275bbf78afdce81839af8be2a2e08`. The earlier deployment created and validated a PostgreSQL backup, ran no migrations, refreshed app/Horizon/scheduler/Telegram, passed health and protected-host comparisons, and the repository-owned smoke passed. Its `PORTAL OK` used an in-process Laravel session assignment and is health evidence only, not authenticated browser acceptance.
- At the start of the L2-03 work, the saved Portal browser state for client 2 returned HTTP 401 at `/portal/bookings`. That state was not reused. Fresh synthetic `@qa.invalid` identities were verified through the ordinary email flow and staging Mailpit, so L2-03 staging browser acceptance did not depend on owner participation, a Telegram auth bypass or a changed working account.
- L2-04 family acceptance ran against the deployed runtime `0dff56d70c11a731d49c508415dbce2616262cef` (previous runtime `f198c763aae5624ce3d6dd07b8aea31130f5b644`; no migrations); the repository-owned staging smoke passed on `0dff56d`. The exact current PR test head is `e3da771817a161fa2353f96cdcbdd4e34299a26b`, with only `tests/e2e/portal.spec.ts` beyond staging. On staging, a synthetic Portal owner/client 134 created, edited, reloaded, invited and selected a family profile; an injected missing-Telegram configuration returned a specific Russian error, kept retry available and logged `portal.family.invitation.failed`; the same idempotency key then retried successfully and the share control invoked `Telegram.WebApp.openTelegramLink` with the expected `t.me/share/url` payload. No real Telegram message was sent. Chromium and WebKit checked the family list, selection, deselect/reselect, reload/back/forward and unlink/reopen behavior; CRM user 19 saw the owner/patient projection and opened the linked clinical client card. Staging PostgreSQL recorded the expected creation/revocation and cleanup audit events; final read-only checks found no `@qa.invalid` client, client 134/135, patient profile, invitation, auth request, booking or financial obligation. The reset actions retained their audit history. The exact run and per-leaf proof are recorded in L2-04 below.
- L2-04 invitation acceptance/linked-booking E2E is run `39919591187` on exact SHA `e3da771817a161fa2353f96cdcbdd4e34299a26b`: isolated PostgreSQL migration/build succeeded, and the target Playwright flow passed in desktop Chromium and mobile WebKit (2/2). It clicked the real invite-acceptance UI, showed the localized missing-consent error, corrected the checkbox, accepted and reloaded, then selected the linked «Дочь» identity for booking and retained that patient through reload and Back/Forward. CI full-candidate run `39914579774` on code SHA `030c17790da4115d173cf24200ee1e2e882506e1` is overall FAILURE: Docker, privacy, integration foundation, RAG and concurrency jobs passed; Quality failed repository-wide Pint (two style findings outside family code) and PHPStan. Do not call the full CI run green.
- L2-04 E2E harness attempts were both recorded and corrected: run `39915137543` failed because it observed `window.open` but the product correctly used the Telegram WebApp bridge; test-only commit `44bcdeb2f67f658844f3e7a0e67dfd273188dd7c` captures either transport and checks the payload without printing an invitation token. Run `39919112393` then failed strict locator selection because the synthetic recipient had distinct SELF («Вы») and linked CHILD («Дочь») identities with the same name; test-only commit `e3da771817a161fa2353f96cdcbdd4e34299a26b` selects the exact relationship and asserts one matching profile. Neither failure was a product defect.
- Multi-session recovery regression: `BookingCreate.vue` now clears only slot/idempotency errors after date/slot correction or removal. The root cause was that `bookingError` continued reading stale Inertia validation errors after local selection state changed. The feature regression `test_multi_booking_conflict_rolls_back_the_whole_batch_and_identifies_the_slot` and new `test_portal_multi_booking_conflict_logs_safe_client_context` protect rollback and safe structured context for CRM and Portal actors. New E2E `September 26 multi-session booking explains a stale slot and succeeds after replacement` exercises the Russian denial, retained selection, recovery, retry and final PostgreSQL booking count.
- Logging regression found during direct staging failure verification: the existing `RedactSensitiveLogData` key matcher treated `operation` as sensitive and redacted `booking.create_multiple` because it was missing from the safe operation-code allowlist. The staging booking denial therefore had all other safe context but lost its operation name. Commit `035319c003e2e36c68a79d74136483b6cdbf4d54` adds only this known-safe operation code; `RedactSensitiveLogDataTest` failed before the change (1 test, 2 assertions) and passes afterward (6 tests, 49 assertions). On the exact 035 runtime a real stale-slot rejection retained `operation=booking.create_multiple`, `event=booking.multiple.slot_unavailable`, organization/actor/client/specialist/service IDs, slot position/count, safe error/outcome and UTC time; no sensitive-key fields or medical data were present. No new logging subsystem was introduced.
- Focused hosted E2E run [39898778987](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39898778987) passed on exact SHA `29d5b49f355566a108d5a355ed3e23f901dd0ff4` (desktop/mobile, 2/2) before browser-history coverage was added. Run [39899651759](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39899651759) on exact SHA `35892f70af7947968e8b46699d85e23e0fe861a0` completed FAIL: desktop passed; mobile timed out at test line 749 because the newly extended test clicked a checkbox while its dropdown was already closed after back/forward. This was a test-harness state error, corrected in `140a5b0b` by opening the filter only when hidden. Final focused E2E run [39900043330](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39900043330) passed on exact SHA `140a5b0bf3fea2daf908b0e85c33fd992008c985`: the Playwright suite ran 2 tests, desktop and mobile both passed (52.1 s).
- Direct staging browser acceptance on `140a5b0b` used the saved QA admin identity `user_id=19`; no owner login, confirmation, data write or cleanup was needed. Chromium and WebKit both completed `all → A+B → B → all → reload → back(B) → forward(all)`. The button's visible text and accessible name matched each selection count; the dropdown stayed open while selecting; URL values matched the checked specialists (the single-selection URL retains both the compatibility `specialist_id` alias and `specialist_ids[0]` array value); reset removed both filter parameters; reload retained All; browser Back restored B in the URL, label and checkbox, and Forward restored All. No page errors or HTTP 5xx responses occurred. At stable widths 320/360/390/768/1024/1440, both engines had `scrollWidth == clientWidth`; in WebKit an immediate 320px resize once measured 326px before layout settled to 320px within 300 ms, while opening directly at 320px fit the viewport. This was transient resize measurement, not persistent overflow.
- On the unchanged staging runtime `140a5b0b`, Chromium and WebKit clicked the actual «Записи» navigation through «Настройки расписания», «Рабочие интервалы» and «График работы»; exact headings and page-specific explanatory copy matched LIST 2, the settings entry appeared last, and reload/reopen plus Back/Forward kept the destination and title coherent. The settings form contained general schedule fields and no weekday interval field; Working Intervals exposed the interval action and no general lead-time field. At widths 320/360/390/768/1024/1440 there was no persistent horizontal overflow. The browser journeys were read-only; PostgreSQL writes and audit events were not expected. Feature test `MilestoneFourSchedulingTest::test_schedule_pages_show_the_accepted_labels_copy_and_separated_fields` passed on test commit `2724d6bb` (1 test, 16 assertions); navigation grouping and protected-surface checks also passed (`FilamentFoundationTest`: 1/34; `MilestoneFourSchedulingTest`: 1/6). This commit only added a PHPUnit regression test, so no staging deploy or browser E2E rerun was needed.
- L2-15.1–L2-15.4 reuse the already completed clinical pass on staging runtime `140a5b0b`; no synthetic account was recreated. Portal showed distinct complaint-detail/goals controls and separate operation/injury yes/no groups with conditional years/details. Existing synthetic clients saved complaints, goals, an operation and an injury independently; Portal reload/reopen and the authorized CRM clinical-profile tab showed the corresponding fields, including corrections and distinct validation for missing/out-of-range operation and injury years. PostgreSQL staging stored the profile under encrypted medical fields; the existing split-field test preserves legacy combined text while asserting four independent current values. The focused tests passed on code-equivalent branch HEAD `813283e6`: `MedicalProfiles\\MedicalProfileTest::test_split_medical_fields_are_saved_independently_without_losing_legacy_text` and `September26MedicalProfileTest::test_crm_medical_form_saves_reopens_and_projects_all_clinical_answers` (2 tests, 76 assertions). Existing clinical browser evidence is recorded in CLINICAL.3–9 and CLINICAL.12–15; it is not repeated for a new timestamp. Safe field-name audit and redaction evidence are in those rows; no medical plaintext was added to logs. Synthetic profiles were reset after the pass.
- Diagnostic observation during Journal → schedule page SPA visit → reload → Back → Forward: Chromium raised two `column is not defined` and one `direction is not defined` Alpine `pageerror`s; WebKit raised one of each. These expressions belong to hidden Filament sorting/column-manager controls in the detached Journal DOM. At exception time the active DOM contained only the destination page; its heading and 37 visible controls remained usable, there was no visible alert, and a subsequent navigation to Working Intervals succeeded. The same detached-cleanup exception occurred when Forward returned to Work Schedule or Working Intervals; it did not occur on the equivalent Clients-table path. No action failed, no database state changed, and no backend audit/log event is applicable. This is recorded as a browser-console lifecycle observation, not a failed L2 label/navigation outcome; no SPA behavior was changed to hide it.
- `scripts/staging-smoke.sh` passed on code-equivalent SHA `79150df496a7d41b699ffa529ae965f11f493ce0`; after the exact revert to `47e229a`, deployment health checks passed and app, Horizon, scheduler, Telegram, PostgreSQL, pgvector, Redis and private storage were healthy. Pre-deploy host/runtime preservation checks passed on the smoke deployment.
- Hosted clinical Portal E2E: PASS, run [39851853569](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39851853569) on exact SHA `ae19e2924d52d21b2bdc0ec1d586d5d6e7b2e679`, 2/2 across Chromium desktop and WebKit mobile. It checks the exact VAS label, keyboard transitions 0→8→10, missing and out-of-range operation/injury years, correction, save/reopen and responsive behavior. Earlier attempts to simulate a pointer drag in hosted Chromium produced 0; staging Chromium independently exercised an actual drag and set VAS to 8, so the regression now asserts the stable keyboard contract while the staging journey records pointer behavior.
- Hosted full-candidate CI run 39854316504 on older SHA `5decdaf9c9cc0e853a57ddc8520031ec82b11cc6` is FAILURE: PostgreSQL unit/feature tests, privacy scan, Docker runtime and integration concurrency/foundation/RAG jobs passed; Quality failed repository-wide Pint (two unchanged files) and PHPStan. Later full-candidate CI run 39914579774 on `030c17790da4115d173cf24200ee1e2e882506e1` is also FAILURE: all active jobs except Quality passed. Neither run is on the previous `035319c0` staging runtime nor current runtime `3809ece3`. Focused L2-04 E2E run 39919591187 passed on exact PR test SHA `e3da771817a161fa2353f96cdcbdd4e34299a26b`; it is not a full CI gate.
- Full hosted Playwright on SHA `5decdaf9c9cc0e853a57ddc8520031ec82b11cc6`: run [39854315944](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39854315944) completed 44 passed / 3 skipped / 1 failed. The mobile group-booking test timed out waiting for the booking-table «Изменить время занятости» modal after the same action succeeded on the detail page. Focused rerun on prior branch SHA `6763ef3aaf0fd96a12e53e3d32c59215e9dccae3` passed desktop and mobile 2/2 in run [39855937877](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39855937877); the three-booking-scenario sequence including its two preceding tests passed 6/6 in run [39856452524](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39856452524). Full rerun [39857044870](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39857044870) was cancelled while still installing browsers, before any tests ran. Targeted final-candidate run [39860960154](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39860960154) failed in the test assertion because HTML returns `novalidate="novalidate"`, not an empty attribute; the Playwright expectation is corrected in `f3cd1ccc`. Follow-up [39862055933](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39862055933) was cancelled while Chromium/WebKit installation remained in progress; Playwright tests did not start and the run published zero artifacts. Hosted E2E for this candidate is therefore NOT RUN; the separate staging browser journey passed in both engines, and the PHP regression passed.
- On the preceding staging runtime SHA 2c0264421cd6133f2ac91305e69aedab3095debe, two full CRM E2E runs failed identically (runs 39820470585 and 39822594732: 44 passed, 3 skipped, 1 mobile group-booking/table-action timeout). The isolated scenario passed on desktop and mobile (run 39822054327), as did the preceding CRM scenario subset (run 39824156943).
- An earlier full CRM E2E run on SHA 3b59c4e62e86503d9541445775048b165f92d72a passed 45 / skipped 3; it is not an exact-candidate result. The previous CI run 39818168768 was on SHA `2c0264421cd6133f2ac91305e69aedab3095debe`; it also had Quality failures and is not evidence for this candidate.
- Owner acceptance: NOT RUN. No owner participation was requested for routine QA.
- Source reacquisition: both original Downloads files were re-read and hashed above. Their complete criterion-to-evidence reconciliation remains in progress; historical quotations alone do not close any criterion.
- QA cleanup: synthetic Portal client 124 was reset through the staging CRM action. PostgreSQL confirms the client and all FK references are gone; audit event 3284 remains with actor 19 and three deleted records. Task screenshots, the specialist-26 snapshot, and the synthetic Portal browser state were removed from local private artifacts. No clients 121/122/124 or QA-named specialists remain. Service 29 was deactivated through the CRM after confirming it had a confirmed booking for a non-synthetic-labeled client and immutable financial history; it was retained to protect those records.
- Clinical Portal/CRM pass used short-lived synthetic `qa.invalid` clients through the real email-code flow. Client 125 exercised empty-required validation, symptom deselect/reselect, VAS keyboard bounds, operation Yes→No, injury No→Yes, none-of-chronic-conditions, invalid MIME/size/sixth file, five accepted files including exact 20 MiB, reload, private download, anonymous denial and expired-signature denial. Client 126 saved complaint detail, goals, VAS=10, no-chronic state, operation/year/details, injury=No, medicines and supplements; Portal reload preserved them, and the QA-admin CRM clinical-profile tab displayed each saved value. The CRM «Файлы и МРТ» tab displayed its uploaded PDF. Chromium exercised the editable medical form at 390px; WebKit medical-profile route had no page overflow at 320/360/390/768/1024/1440. PostgreSQL showed encrypted profile columns, private-disk attachment rows and safe field-name/file-metadata audit; clinical marker text was absent from app logs.
- Follow-up Portal/CRM staging journey on the deployed app code with a fresh synthetic client exercised all nine complaint chips through select/deselect/reselect, confirmed all nine persisted after reload and appeared in CRM, asserted the exact LIST 1 goals placeholder and exact VAS heading «Шкала интенсивности боли (ВАШ):», and set VAS to 8 by Chromium pointer drag. Empty operation and injury years each showed «Укажите год.» with visible, associated field errors; 1899 and 2027 each showed «Укажите год с 1900 по 2026.». Correcting to 2020/2021 saved, survived Portal reload/reopen, and projected the operation and injury Yes details into CRM. Chromium and WebKit had no horizontal overflow at 320/360/390/768/1024/1440; no page errors were observed.
- Attachment run on PostgreSQL/staging: the real Portal uploader rejected invalid `.txt`, 20 MiB + 1 byte, and six files with specific messages, then uploaded five JPG/PNG/PDF files including an exact 20 MiB PNG. Reload retained all five. PostgreSQL recorded 3 PDFs, 1 JPEG, 1 PNG on the private disk with all objects present; download returned 200 with `private, no-store`; no-session access returned 401; a tampered signature returned 403. A different authenticated client following another client's signed link received 404 and `Файл не найден. Откройте медицинские данные и попробуйте снова.`; the same request was correlated with `attachment_not_found_or_unavailable` in structured logs. Seven `attachment.uploaded` audit events and the denial log classes were present, metadata held no medical text, and the clinical values were absent from application logs. The CRM clinical-profile and attachment counterparts were visible to the authorized QA admin. Laravel private Storage is the accepted architecture, not literal S3 verification.
- QA cleanup: synthetic Portal clients were reset through the staging CRM action. PostgreSQL now reports zero active `@qa.invalid` clients and auth challenges; the exact Mailpit messages/challenges used by this pass were removed. Eleven `client.staging_account.reset` audit events and seven attachment-upload audit events remain. All current Story rows 9–12 are absent; their only remaining unreferenced test MP4 was deleted after matching its exact size, timestamp and SHA-256 with no database references. Two referenced public content-media objects and six referenced private medical attachment objects were left untouched. The unrelated older unreferenced content JPG (2026-09-03) and older private medical attachment orphan were also left untouched. No new medical attachments were created in the final clinical journey; the reusable QA-admin session was preserved.

- Post-deploy cleanup reconciliation used read-only PostgreSQL queries: no remaining auth challenges or `@qa.invalid` clients; no staging QA client IDs 95, 100–104, 119 or preview fixtures; no QA specialists 21–24; and no Story rows 9–12. Service 29 remains inactive because it has three booking records, one financial obligation and nine immutable ledger rows, including a booking attached to a non-synthetic-labeled client. It was not deleted or rewritten.

- Audit defect found while cleaning synthetic service 29: staging audit event 3344 recorded `catalog_type` and `payment_requirement` as changed during an `is_active`-only update, although PostgreSQL values remained `service` and `postpay`. Eloquent enum casts were compared strictly with backing-value strings. `UpdateService` now compares the backed value, and `MilestoneThreeCrmTest::test_service_update_audit_omits_unchanged_enum_cast_fields` asserts the exact audit field list and persisted state. All 28 tests in `MilestoneThreeCrmTest.php` and all 20 `ServicePricingMediaTest.php` tests pass locally. The fix from `6e706a71` was present in staging at the earlier runtime `47e229a` and is included in current runtime `3809ece3`; the exact PostgreSQL feature test passed in hosted CI run 39854316504 on SHA `5decdaf9`. Event 3344 remains immutable history; service 29 is inactive and its three bookings and nine ledger rows remain intact.

- New REAL BUG: on staging, an empty Location Day create form was stopped by browser-native required validation (English message in a Russian CRM), and allowing submit exposed raw `validation.required`. Root cause was native validation preventing Filament's server validation from running, plus no field-specific messages. `CreateLocationDay` and `EditLocationDay` now submit with native UI suppressed while retaining required semantics; `LocationDayForm` supplies five actionable Russian messages. Regression coverage: `PhaseOneBookingLocationsTest::test_location_day_create_shows_actionable_required_errors_without_creating_a_record` (1/1, 18 assertions), and Playwright `staff gets actionable validation and can save a location day after correction` (test added, ESLint passed; hosted execution not completed because the CI runner stalled during browser installation). The first hosted browser attempt revealed an incorrect assertion about HTML's boolean attribute value; the test-only expectation was corrected. Pint and ESLint passed. On staging d55dd6bd, Chromium and WebKit both showed all five specific errors with no raw key and no write; Chromium created a corrected inactive QA day, redirected to its edit page, and preserved fields across reload; WebKit reopened the same row and also preserved state. Create and edit forms had no horizontal overflow at 320/360/390/768/1024/1440 in both browsers; the edit failure path left the original PostgreSQL value unchanged. PostgreSQL recorded the selected active specialist (ID 20), zero booking references, and one create audit. The exact synthetic row (ID 15) was then deleted in a bounded transaction after verified staging backup; one `location_day.qa_cleanup` audit remains and PostgreSQL reports zero matching rows. No QA specialist or other staging entity was added.

### Defect register carried into the checkpoint

| Defect | Root cause → code fix SHA | Regression | PostgreSQL / browser / staging evidence | Current status |
| --- | --- | --- | --- | --- |
| Telegram launch CTA omitted the source-required rocket; missing media and uncertain provider outcomes did not always give the user a useful next step | The launch CTA reused the system-menu label; missing-media and provider-null/error branches were silent or generic → `3809ece3df9275bbf78afdce81839af8be2a2e08` | `MilestoneTwoTelegramBotTest::test_start_renders_the_localized_menu_through_fake_transport`; `September26ContentVideoTest::test_uploaded_video_is_persisted_and_welcome_uses_video_and_one_web_app_button`; missing-media/video-menu/greeting failure tests named in the CR-01 evidence block | Read-only staging PostgreSQL check found no published welcome video; Chromium/WebKit actual Telegram client journeys were unavailable. Deployed runtime `3809ece3`; health and repository-owned staging smoke passed. No live `/start` was sent after the fix | Fixed in app/test layers. CR-01 leaves remain BLOCKED for the genuine client observation; .3 also awaits approved Eugene media |
| Telegram welcome/menu failures lacked safe structured correlation and user-visible recovery | Failure paths logged generic warning text or omitted a status when provider outcome was null/unknown → safe `telegram_welcome_degraded` event and localized recovery in `3809ece3df9275bbf78afdce81839af8be2a2e08` | `September26ContentVideoTest::test_missing_welcome_video_shows_recovery_copy_and_logs_safe_diagnostics`; `::test_video_and_menu_provider_failures_show_recovery_copy_and_log_safe_diagnostics`; `::test_unconfirmed_menu_button_update_shows_recovery_copy_and_logs_unknown_outcome`; `::test_unconfirmed_greeting_delivery_is_not_reported_as_success`; `::test_greeting_delivery_failure_is_not_reported_as_a_success` | PostgreSQL evidence is limited to missing-published-video state; no live provider failure was injected. Staging deploy and smoke passed on exact runtime `3809ece3`; no Telegram recipient send occurred | Fixed and regression-protected through the existing logger; real Telegram client status remains within CR-01 external blocker |
| Unavailable-period required fields used browser-native English validation; reversed intervals escaped as technical behavior | Native constraint validation stopped Livewire; the application interval exception was not mapped to field validation → `79150df4` | `MilestoneFourFinalRemediationTest::test_unavailable_time_form_shows_actionable_required_errors_without_writing`; `test_unavailable_time_form_rejects_an_end_before_its_start_without_writing`; Playwright unavailable-time journey | Chromium and WebKit empty/reversed/corrected form; no PostgreSQL row on invalid submits; valid periods persisted then UI-deleted; audit 3347–3350 | Fixed in current runtime; staging journey passed |
| Location-day form showed English native errors / raw `validation.required` | Native validation blocked Filament server validation and fields lacked specific messages → `d55dd6bd` | `PhaseOneBookingLocationsTest::test_location_day_create_shows_actionable_required_errors_without_creating_a_record`; Playwright location-day journey | Chromium and WebKit showed five Russian messages; corrected specialist-20 day persisted/reopened and then was UI-deleted; audit retained | Fixed in current runtime; staging journey passed |
| Blacklist booking denial disappeared during city lookup/redirect | An unfocused city request overlapped redirect and consumed Livewire's one-request validation error → `4ce6e5a0` | `PortalBookingRemediationTest::test_blacklist_denies_portal_booking_with_a_specific_error_then_allows_an_idempotent_retry_after_unblock`; `tests/e2e/portal.spec.ts` | Chromium and WebKit showed loading/disabled state and actionable denial; PostgreSQL had no denied booking/idempotency row; `portal.booking.denied` recorded reason `blacklist` | Fixed; staging success/denial/correction journey passed |
| Service audit falsely listed unchanged enum-backed fields | Strict comparison compared Eloquent enum objects with backing strings → `6e706a71` | `MilestoneThreeCrmTest::test_service_update_audit_omits_unchanged_enum_cast_fields` | Event 3344 retained the historical false-positive; PostgreSQL values were unchanged; regression passed in local feature suite and hosted CI run 39854316504; fix was present in staging `47e229a` and is included in current runtime `3809ece3` | Fixed; immutable old audit event retained |
| Journal desktop weekly grid/mobile-only cards regressed across breakpoints | Responsive view selection failed to preserve distinct desktop and mobile representations → `da836cea` | `SchedulingJournalProductionPassTest`; `tests/e2e/crm.spec.ts::multi-specialist Journal keeps the accepted layout and slot ownership` | Chromium/WebKit staging at 320/360/390/768/1024/1440; desktop grid at 1024/1440 and mobile cards only through 768; focused hosted run 39643361069 passed on SHA 85fd9b9 | Fix present; prior exact browser evidence retained; no automatic rerun unless code-diff review requires it |
| Journal slot ownership was lost when multiple/all specialists were shown | Projection flattened specialist intervals/bookings and create links omitted `specialist_id` → `da836cea` | `SchedulingJournalProductionPassTest::test_multi_specialist_journal_keeps_schedule_booking_and_slot_ownership`; E2E test above | PostgreSQL/staging bookings retained specialists 20 and 1 through reload/cancel; Chromium/WebKit showed correctly owned lanes and no unbound create links | Fixed; prior staging evidence retained |
| Journal specialist multiselect closed after each checkbox | Livewire update discarded popover open state → `812a4f83` | `SchedulingJournalProductionPassTest`; E2E test above | Chromium/WebKit staging selected A+B and deselected/reselected without closing; earlier screenshot set under local artifacts | Fixed in earlier staging pass; current label-reset issue is separate |
| Journal specialist dropdown collided with viewport on mobile | Dropdown placement did not shift within narrow viewport → `07793519` | `SchedulingJournalProductionPassTest`; E2E geometry assertions | Chromium/WebKit staging widths 320–1440; no page overflow or control collision in recorded screenshots | Fixed in earlier staging pass |
| Cancelled bookings continued to block slots; stale Journal prefill could submit an unavailable time | Availability projection counted cancelled state and prefilled time was not revalidated against current slot → `71fcaa94`, `836ce315`, `a2386e17` | `SchedulingJournalProductionPassTest::test_cancelled_booking_remains_visible_without_blocking_its_journal_slot`; `tests/e2e/crm.spec.ts::stale Journal time explains the conflict and allows another slot` | PostgreSQL staging cancellation freed the same slot; stale prefill showed conflict and allowed correction; reload preserved final state | Fixed; prior staging/browser evidence retained |
| Midnight Journal projection risked double conversion or dropped end-of-day boundary | Specialist/organization timezone projection flattened a midnight split → `da836cea` | `SchedulingJournalProductionPassTest::test_schedule_projection_splits_midnight_once_and_keeps_end_minute_at_day_boundary` | PostgreSQL staging inspected UTC/Asia-Almaty values; Chromium/WebKit showed 23:00–00:00 and next-day 00:00–10:00 without double conversion; no data changed | Fixed and verified in earlier staging pass |
| CRM login could miss browser-autofilled credentials on submit | Browser autofill values were not synchronized into Livewire submit state → `4d1956fc` | `PrivilegedAuthenticationTest::test_login_form_keeps_browser_autofill_values_available_to_livewire_on_submit` | Feature regression passed; end-to-end autofill/login/logout journey is not mapped to an exact run in this ledger | Fix present; retain browser journey as a verification item if later diff review finds auth code changed |
| Inactive membership could be treated as absent for protected attachment access | Organization resolver did not distinguish inactive membership from no membership → `e9dafd30` | `MedicalProfileTest::test_inactive_membership_cannot_read_or_update_medical_profile`; `MilestoneThreeCrmTest::test_specialist_rejects_an_inactive_same_organization_membership` | Authorization tests passed; staging attachment negative paths returned 401/403/404 with safe diagnostics; no medical plaintext logged | Fixed; evidence exists, exact membership/browser mapping should be checked before rerun |
| Journal filter reset exposed a misleading accessible name | Static `aria-label="Специалисты"` overrode the dynamic visible «Все специалисты» / count text → removed the static label in `29d5b49f` so the accessible name follows the visible state | `tests/e2e/crm.spec.ts::multi-specialist Journal keeps the accepted layout and slot ownership` asserts the accessible name at all/default and selected counts; hosted runs 39898778987 and 39900043330 | Staging Chromium and WebKit: default, 1, 2, 1 and reset states exposed matching visible and accessible names; reset/reload remained All; no write, page error or 5xx | Fixed and VERIFIED as L2-14.7 |
| Journal browser Back restored the URL but left the specialist checkbox state at All | The legacy scalar `selectedSpecialistId` and multi-value `selectedSpecialistIds` are both URL-backed; during history restoration a null scalar update could clear the restored array → guard the legacy hook in `35892f70` | The same E2E test now exercises reload → Back(B) → Forward(All); hosted run 39900043330 passes desktop/mobile | Staging Chromium and WebKit: URL, label and exact checkbox selection stayed aligned through all → A+B → B → all → reload → Back(B) → Forward(All); filter caused no database writes | Fixed and VERIFIED as L2-14.7 |
| Follow-up mobile E2E timed out after the new Back/Forward coverage | The test clicked the second specialist checkbox after history navigation had left the popover open/closed differently across browser projects → conditionally reopen only when hidden in `140a5b0b` | Exact failure run 39899651759: desktop passed, mobile timed out at line 749 on a hidden checkbox; final exact-SHA run 39900043330 passed 2/2 | This was test harness behavior, not a staging product failure. Direct staging Chromium/WebKit journey passed on runtime `140a5b0b` | Harness correction verified; no product defect |
| Stale multi-session slot error stayed visible after the user changed the selection | Inertia `useForm` errors remained in `bookingForm.errors` while the user corrected local slot state; `BookingCreate.vue` now clears only `starts_at`, `additional_starts_at`, and `idempotency_key` errors when date/slot changes or a queued slot is removed → `04b48cee` | `tests/e2e/portal.spec.ts::September 26 multi-session booking explains a stale slot and succeeds after replacement`; `MilestoneFourCrmBookingTest::test_multi_booking_conflict_rolls_back_the_whole_batch_and_identifies_the_slot`; `PortalBookingRemediationTest::test_multi_session_conflict_rolls_back_bookings_consents_intents_and_events` | Hosted PostgreSQL E2E run 39907031745: rejected stale batch leaves no partial booking; corrected retry creates exactly two Portal bookings for the QA client. Existing PG concurrency job 39854316504 passed on older SHA 5decdaf9; booking transaction/locking code has no diff from that run. Direct staging PostgreSQL on runtime 035 confirmed zero partial primary bookings after a conflicting multi-session submit and cleanup of companion bookings 162/163 | Chromium desktop and WebKit mobile on staging: select two sessions → submit stale conflict → see the Russian slot-specific reason and remain in booking → confirm no medical continuation/partial write → return and correct selection → retry → continue to medical profile → reload/back/forward/reopen → cancel and confirm the slot returns to availability. Hosted run 39907031745 already covers matching recovery in both engines; no E2E rerun was needed | Safe failure event `booking.multiple.slot_unavailable` records organization/actor/client/specialist/service/UTC slot, slot position/count, safe error/outcome/time and no intent key or medical payload. The operation code was initially redacted by the existing sensitive-key filter; the separate fix in `035319c0` is verified by a focused regression and a real staging denial on that runtime | Fixed and VERIFIED for L2-03.2–L2-03.5; direct staging, PostgreSQL and Chromium/WebKit evidence complete |
| Structured multi-session error redacted its safe operation name | `RedactSensitiveLogData` treated `operation` as sensitive and `booking.create_multiple` was absent from `SAFE_OPERATION_CODES` → allowlisted this exact non-secret code in `035319c003e2e36c68a79d74136483b6cdbf4d54` | `RedactSensitiveLogDataTest::test_known_safe_operation_codes_remain_visible`; before-fix regression failed (1 test/2 assertions), after-fix suite passed (6 tests/49 assertions) | Direct PostgreSQL staging rejection on exact runtime 035 emitted expected safe context and no sensitive fields | Chromium stale-slot rejection on exact runtime 035 showed the actionable Russian error and wrote no partial primary booking; read-only structured-log lookup correlated it to `booking.multiple.slot_unavailable` | Existing logger/redactor retained; safe operation/event/entity/actor/slot/error/outcome/UTC fields are queryable after the fact; no medical plaintext or token was present | Fixed; regression and exact-runtime staging diagnostic verification PASS |
| Multi-session recovery E2E initially expected the wrong control label and queue shape | Test used nonexistent «Изменить время» instead of accepted «Изменить дату и время», then counted the selected calendar slot as a queued item; corrected in test-only commits `85506786` and `a06b76fc` | Exact regression is `tests/e2e/portal.spec.ts::September 26 multi-session booking explains a stale slot and succeeds after replacement` | Not a database or application defect; failure run 39906384999 reached the correct stale-slot message before timing out on the wrong label; 39906746599 showed one queued item plus one selected calendar slot, which is the actual state model | Final run 39907031745 passed desktop and mobile at code SHA a06b76fc; branch-to-run diff after that SHA is test-only (`MilestoneFourCrmBookingTest.php`) | Both failed runs are recorded as harness/assertion failures, not PASS and not product defects; corrected run proves the actual recovery behavior | Test harness corrected; current behavior PASS |

| Long Russian Story labels clipped inside the 90px Home carousel | The first CSS adjustment raised the strip to its 90px limit and increased the title clamp to three lines, but the 76px card still needed four lines for the longest label → set the existing title typography to 9.5px/11px in `80dfcbc9bab353ca4aa034c2c17b6c0f6fa85932` | `tests/e2e/portal.spec.ts::portrait Story previews stay inside their frames and player navigation works` asserts exact labels, title fit and strip height at 320/360/390/768/1024/1440. Run 39972204762 failed desktop/mobile on the fit assertion before the final typography adjustment; exact-current run 39972914121 passed the new fit assertions on both projects before a separate mobile subtitle-fixture assertion failed | Fixed on staging runtime `80dfcbc9`; Chromium and WebKit direct staging checks showed all four titles fully visible and the strip within 90px at six widths |

The earlier global-search/table-search interpretation was a QA mistake, not a product defect: the temporary `searchable()` change (`f7ba1d70`) was reverted in `47e229a4`; current runtime has no added table-search controls. No QA-auth isolation defect was found: CRM actions used dedicated staging `user_id=19`; user2 was not modified. L2-03 used fresh synthetic email identities and temporary staffless QA specialist 51; identities, specialist, schedule and bookings were removed and independently verified absent. No QA specialist remains.

### Current-pass criteria with direct evidence

| Requirement ID | Exact source expectation | Implemented path and regression test | PostgreSQL evidence | Browser/staging journey | Error, audit and diagnostic evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| CLINICAL.1 | «2. Спецификация полей: Клинический профиль Пациента (Этап 2)» | Portal MedicalProfile; `tests/Feature/September26MedicalProfileTest.php`; `tests/Feature/September26MedicalAttachmentTest.php`; `tests/e2e/portal.spec.ts` | Encrypted profile fields and private attachment records persisted on PostgreSQL; synthetic accounts were reset | All literal fields are verified in CLINICAL.3–21; booking continuation and Portal/CRM counterpart are evidenced by CLINICAL.2 | Audit contains safe field names/file metadata only; clinical plaintext absent from logs | VERIFIED — every child criterion CLINICAL.3–21 has direct evidence below |
| CLINICAL.2 | «Данные поля формируют медицинскую карту пациента, собираются на Этапе 2 бронирования и доступны для просмотра/редактирования в Личном Кабинете в разделе «Медицинские данные»:» | Portal medical onboarding; `tests/Feature/PortalBookingRemediationTest.php`; `tests/Feature/September26MedicalProfileTest.php`; staging journey L2-02.4 | Booking onboarding saved the profile; encrypted fields survived reload and synthetic-account reset | Staging: medical booking → profile onboarding/save → booking confirmation/reload; Portal medical editor reopened with saved values and returned to booking; CRM clinical-profile showed the same saved values | Safe profile audit; no clinical plaintext in logs | VERIFIED |
| CLINICAL.3 | «Главные жалобы (Симптомы):» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted profile persisted, then was reset | Complaint detail saved/reopened in Portal and appeared in CRM clinical profile | Empty complaint yielded a specific validation message | VERIFIED |
| CLINICAL.4 | «Мульти-селект чипсов (Головные боли, Боль в шее, Боль в пояснице, Суставы, Отечность, Проблемы ЖКТ, Сонливость/Слабость, Бессонница, Стресс/Тревожность) + текстовое многострочное поле для детализации жалоб в свободной форме» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted state persisted; account reset removed it | Chromium toggled all nine chips on/off and reselected all; all nine plus complaint detail survived reload and appeared in CRM | Empty complaint produced a specific validation message; no clinical plaintext in logs | VERIFIED |
| CLINICAL.5 | «Шкала интенсивности боли (ВАШ):» | Portal MedicalProfile; September26MedicalProfileTest.php; passing hosted `portal.spec.ts` on ae19e292 | Encrypted clinical_answers persisted on PostgreSQL and synthetic client was reset | Staging Chromium showed the exact label, pointer set VAS to 8, and keyboard moved 0→8→10; prior Portal reload/CRM projection displayed saved 10 / 10 | Clinical values are absent from audit/log output | VERIFIED |
| CLINICAL.6 | «Интерактивный ползунок-слайдер со значениями от 0 до 10 (где 0 — «нет боли», 10 — «нестерпимая боль»)» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted range-edge answers persisted | Chromium Home/End/Arrow keys exercised 0–10 and intermediate values; save/reopen; CRM displayed 10 / 10 | Feature regression rejects 11; no plaintext in audit/log | VERIFIED |
| CLINICAL.7 | «Задачи по здоровью:» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted goals persisted and were reset | Goal text saved/reopened in Portal and shown in CRM | Empty goal submission showed a specific message | VERIFIED |
| CLINICAL.8 | «Многострочное текстовое поле (Textarea)» for health goals | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted goals persisted | Portal textarea saved/reopened; CRM displayed the same value | Empty state rejected with actionable validation | VERIFIED |
| CLINICAL.9 | Exact requested goals placeholder from LIST 1, including the two example outcomes | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted goals persisted | Staging DOM placeholder exactly matched both requested examples; saved goal reopened in Portal and appeared in CRM | Required-empty error checked | VERIFIED |
| CLINICAL.10 | «Хронические заболевания:» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted chronic text and none-state persisted before reset | Text condition then explicit none-state saved/reopened; CRM showed the none-state | Audit stores field names only; plaintext absent from logs | VERIFIED |
| CLINICAL.11 | «Текстовое поле (Textarea) для фиксации установленных диагнозов. Предусмотреть чекбокс «Хронических заболеваний нет».» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted profile persisted the none-state; `medical.profile.updated` audit contained field names only | Chromium: select none → save → reload/reopen; CRM clinical-profile tab displayed the explicit none-state | No clinical plaintext in audit metadata or application logs | VERIFIED |
| CLINICAL.12 | «Перенесенные операции:» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted operation data persisted before reset | Yes/year/details saved and displayed in CRM; correction to No exercised in Portal | Empty required Yes/No state had a specific validation message | VERIFIED |
| CLINICAL.13 | «Переключатель (Radio: Да / Нет). При выборе Да раскрываются вложенные поля: Год операции (Number) и Описание операции/рубца (Text: где находится шрам)» | Portal MedicalProfile; September26MedicalProfileTest.php; passing hosted `portal.spec.ts` on ae19e292 | Yes/year/description persisted encrypted in PostgreSQL, then synthetic account was reset | Chromium: Yes → missing year error → 1899 range error → correct year; save/reload/reopen and CRM displayed operation Yes details | Error copy is distinct and actionable; `aria-describedby` resolves to visible error; audit stores safe field metadata | VERIFIED |
| CLINICAL.14 | «Перенесенные травмы и переломы:» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Encrypted injury state persisted before reset | No→Yes and Yes→No states exercised in Portal; CRM displayed the current No state | Empty required Yes/No state had a specific validation message | VERIFIED |
| CLINICAL.15 | «Переключатель (Radio: Да / Нет). При выборе Да раскрываются вложенные поля: Год травмы (Number) и Характер травмы (Text)» | Portal MedicalProfile; September26MedicalProfileTest.php; passing hosted `portal.spec.ts` on ae19e292 | Yes/year/details persisted encrypted in PostgreSQL | Chromium: No→Yes → missing year error → 2027 range error → correct year; save/reload/reopen and CRM displayed injury Yes details | Error copy is distinct and actionable; `aria-describedby` resolves to visible error; audit stores safe field metadata | VERIFIED |
| CLINICAL.16 | «Принимаемые лекарственные препараты:» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Medication field encrypted at rest | Synthetic medication text saved/reopened in Portal and displayed in CRM | No clinical plaintext in audit or logs | VERIFIED |
| CLINICAL.17 | «Текстовое поле (Textarea) — постоянные аптечные медикаменты, дозировки и частота приёма» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Medication field encrypted at rest | Portal medication textarea saved/reopened; CRM displayed the same value | Sensitive text absent from audit/logs | VERIFIED |
| CLINICAL.18 | «Нутрицевтики и БАДы:» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Supplements field encrypted at rest | Synthetic supplement text saved/reopened in Portal and displayed in CRM | No clinical plaintext in audit or logs | VERIFIED |
| CLINICAL.19 | «Текстовое поле (Textarea) — витамины, биологически активные добавки, сборы, микроэлементы» | Portal MedicalProfile; September26MedicalProfileTest.php; portal.spec.ts | Supplements field encrypted at rest | Portal supplement textarea saved/reopened; CRM displayed the same value | Sensitive text absent from audit/logs | VERIFIED |
| CLINICAL.20 | «Медицинские документы и обследования (Опционально)» | Portal MedicalProfile attachment section; September26MedicalAttachmentTest.php; portal.spec.ts | Five private attachment rows existed in first boundary run; one PDF row in CRM run; all synthetic rows removed | Optional section accepted files without blocking form completion; CRM «Файлы и МРТ» tab showed the uploaded PDF | Upload audit recorded safe file metadata | VERIFIED |
| CLINICAL.21 | Up to five JPG/PNG/PDF files, up to 20 MiB each; private access and authorized CRM counterpart | MedicalAttachment; tests/Feature/September26MedicalAttachmentTest.php, tests/Feature/Attachments/ClientAttachmentUploadLimitTest.php, tests/Integration/MedicalAttachmentLimitPostgresTest.php | Five-file and exact 20 MiB boundaries; private storage; synthetic account reset removed related rows and QA-created objects | Invalid type, >20 MiB, and six files showed specific messages; five accepted files survived reload; authorized download 200/private/no-store, no-session 401, tampered signature 403; wrong-client signed link returned 404 with actionable copy; CRM attachment tab displayed PDF | Seven upload audit events; denial reason correlated to the request; no clinical plaintext in logs. Laravel private Storage is accepted architecture, not literal S3 verification. | VERIFIED |
| L2-01.1–L2-01.3 | «Поле «Примечание» в карте клиента»; «В карточке клиента нужно поле Примечание (можно писать разные особенности)» | ClientNotesRelationManager; tests/Feature/ClientFinishingPackTest.php | Synthetic client 120 note create/edit state persisted; client reset removed the test row | CRM create → save → reload/reopen → edit → save → reload; visible success; empty input rejected | client.note.created / client.note.updated audit events retain body_present only; tenant and inactive-membership negative cases covered by ClientFinishingPackTest | VERIFIED |
| L2-02.1 | «Возможность поместить клиента в чёрный список, с указанием причины в поле Примечание» | ClientResource blacklist action → BlockClientBlacklist; tests/Feature/ClientFinishingPackTest.php | QA client 122 had a blacklist restriction with reason; the state remained active until explicit unblock/reset | Chromium: CRM add with reason → status visible after reload | Audit 3157 records `client.blacklist.added` and `reason_present=true`; the reason text is not copied into audit metadata | VERIFIED |
| L2-02.2 | «Добавить статус «Чёрный список» в фильтре списка клиентов» | ClientResource blacklist status/filter; tests/Feature/ClientFinishingPackTest.php | Filtered synthetic client matched the active restriction; removal changed the persisted status | Chromium: select filter → find client → unblock → reload and confirm status/filter result | Audit 3178 records `client.blacklist.removed`, `old_blacklist_state=true`, `new_blacklist_state=false`; no reason text | VERIFIED |
| L2-02.3 (owner-directed denial subcriterion) | Source: «Возможность поместить клиента в чёрный список, с указанием причины в поле Примечание». Acceptance subcriterion from the owner’s final-pass instruction: deny self-booking for that profile with an understandable next step | PortalBookingController / PortalBookingErrorMessages; tests/Feature/PortalBookingRemediationTest.php; Playwright regression in `tests/e2e/portal.spec.ts` asserts no passive city lookup, busy/disabled submit state, actionable denial and no persisted booking/idempotency row | Staging PostgreSQL after the denied attempt: client 124 had zero bookings and zero idempotency rows; after reset, client 124 and all foreign-key references are absent | Chromium and WebKit at 390×844: confirmation mount sends no city lookup; focusing the field starts lookup; CTA reports loading and disables; after lookup, submit shows «Для этого профиля самостоятельная запись недоступна. Свяжитесь с нами, и мы поможем подобрать вариант.» Responsive overflow check passed at 320/360/390/768/1024/1440 in both engines | 15 structured `portal.booking.denied` records for client 124 have `reason_code=blacklist`, `outcome=denied`, organization/actor/client IDs and UTC timestamps; sensitive-key scan found no password, token, contact or medical fields. Reset audit event 3284 remains after account cleanup | VERIFIED |
| L2-02.4 | Allow booking after unblocking; preserve result through reload and release the slot after cancellation | UnblockClientBlacklist / CreateBooking / CancelBooking; tests/Feature/PortalBookingRemediationTest.php | PostgreSQL booking 132 became `requested`, then `cancelled`; the same slot became available; reset removed the synthetic client, booking, profile and restriction | Chromium: CRM unblock/reload → same-slot booking → medical-profile onboarding/save → confirmation/reload → cancel → same slot available; CRM booking card showed cancellation | Audit 3183/3186 records create/cancel; cleanup audit 3187/3189. No medical plaintext in audit | VERIFIED |
| L2-13.1–L2-13.2 | Rename to exact label «Адрес приёма по умолчанию» and use the saved default in new bookings | SchedulingConfiguration; tests/Feature/PhaseOneBookingLocationsTest.php::test_default_address_setting_updates_future_office_bookings_and_preserves_existing_snapshot | PostgreSQL setting and default location matched the UI; five pre-test booking snapshots agreed; existing snapshots remained unchanged | Chromium on staging: edit → save success → reload persisted value → CRM booking form prefilled; final clean reload matched PostgreSQL. Final viewport 1440px. The earlier QA selector error was corrected; no application defect | working_location.updated audit retained entity ID, without address text. Test marker and QA state restored. | VERIFIED |
| L2-14.7 | «Журнал записей — добавить по умолчанию просмотр журнала для всех доступных специалистов, а выбор специалистов — сделать фильтром выпадающим списком с чек-боксами, чтобы можно было просмотреть журнал сразу для нескольких» | `ListBookings.php`; `list-bookings.blade.php`; `tests/Feature/SchedulingJournalProductionPassTest.php`; `tests/e2e/crm.spec.ts::multi-specialist Journal keeps the accepted layout and slot ownership` | Presentation/query-only filter; no database mutation. Starting SHA used a single-specialist `<select>` and therefore did not satisfy LIST 2; current candidate retains the explicit default-all/multi-checkbox contract | Hosted E2E 39900043330 on exact SHA `140a5b0b`: desktop/mobile 2/2. Staging Chromium/WebKit on exact runtime `140a5b0b`: all → A+B → B → all → reload → Back(B) → Forward(All); visible/accessibility label, URL and checked state stayed consistent; reset label needed no reopen. At stable 320/360/390/768/1024/1440, `scrollWidth == clientWidth`; no page errors or HTTP 5xx. A local query assertion was normalized for the single-selection legacy scalar plus array alias; no product issue | Root causes and fixes are in the two Journal defect rows above. The hosted E2E regression covers the exact browser-history invariant; PostgreSQL-specific mutation semantics and audit are not applicable to this read-only filter. No staging data changed; read-only filtering intentionally produced no audit event | VERIFIED |
| L2-14.1–L2-14.6; L2-14.8 | LIST 2 §14 date marker; exact «Настройки расписания» label; move it to the bottom of «Записи»; split general schedule settings from specialist regular intervals; replace the top copy with «Здесь настраиваются общие параметры для всего расписания» and corresponding page-specific copy; Work Schedule heading «График работы специалиста» | `SchedulingConfiguration`, `WorkingIntervals`, `WorkSchedule`; `MilestoneFourSchedulingTest::test_schedule_pages_show_the_accepted_labels_copy_and_separated_fields`; `FilamentFoundationTest::test_admin_navigation_is_grouped_by_business_task`; `MilestoneFourSchedulingTest::test_crm_scheduling_surfaces_are_organization_scoped_and_manage_protected` | N/A: these leaves specify page labels, copy, separation and navigation; the read-only journey made no persisted mutation. PostgreSQL is not the authority for these outcomes | Staging runtime `140a5b0b`, QA admin user 19: Chromium and WebKit opened each page from actual navigation, checked exact headings/copy and order `Журнал записей → График работы → Рабочие интервалы → Дни выезда специалистов → Изменения расписания → Настройки расписания`, then reloaded, reopened and used Back/Forward. Settings showed general fields without weekday intervals; Working Intervals exposed «Добавить интервал» without the general lead-time field. Widths 320/360/390/768/1024/1440 had no persistent horizontal overflow. PHPUnit commands passed with 16, 34 and 6 assertions on test commit `2724d6bb` | No action failed, no user-visible error/alert appeared, and no audit event is expected for read-only page navigation. Browser `pageerror`s from detached Journal Alpine `column`/`direction` expressions occurred on reload → Back → Forward cleanup; the active page remained interactive. Details and limits are recorded above; no backend failure/state mutation occurred | VERIFIED |
| L2-14.9 | LIST 2 literal: «Раздел перенести сразу после «» | No accepted navigation anchor can be implemented or evaluated from this incomplete sentence | N/A: source omits the destination item, so there is no objective expected placement to compare | Original LIST 2 line 91 ends after the opening quotation mark. The following line «Недоступное время — раздел убрать» is a separate instruction and does not complete the quote. No other source or owner decision supplies the anchor; current navigation order is observed but cannot be treated as the intended outcome | Needs the owner to name the item after which «График работы» should be placed. Inferring it would invent the requested navigation behavior | BLOCKED — incomplete owner source |
| L2-14.10–L2-14.11 | «Недоступное время — раздел убрать»; «Перенести в раздел „Изменения расписания“ как один из типов изменений с датами начала и окончания.» | ScheduleExceptionResource, ScheduleExceptionForm, CreateScheduleException, ScheduleExceptionsTable; CreateUnavailablePeriod / CreateScheduleException; `MilestoneFourFinalRemediationTest::test_unavailable_time_form_shows_actionable_required_errors_without_writing`, `test_unavailable_time_form_rejects_an_end_before_its_start_without_writing`; `tests/e2e/crm.spec.ts` regression for actionable unavailable-time errors and corrected save | PostgreSQL staging persisted QA periods 6 and 7 under organization 1 / specialist 20 with the expected UTC interval; after UI deletion, query returned zero QA periods. No migration was needed | Chromium and WebKit staging: «Записи» → «Изменения расписания» → «Недоступное время»; no separate unavailable-time section was used. Empty submit reached Livewire and showed specific Russian required errors; reversed interval showed a specific date error and wrote no row; corrected interval showed «Создано», appeared after pagination and survived reload; visible delete + confirmation removed it and reload remained empty. Form widths 320/360/390/768/1024/1440 had no horizontal overflow | Audit events 3347/3349 (`specialist.unavailable_period.created`) and 3348/3350 (`specialist.unavailable_period.deleted`) identify organization 1, actor 19, entity and UTC time. The browser-native validation defect was fixed with Livewire validation and localized messages; no raw key or sensitive data appeared. Both temporary QA rows were removed through the CRM UI | VERIFIED |
| L2-14.12–L2-14.14 | «Дни выезда.»; exact heading «Дни выезда специалистов»; «И привязать дни выезда к выбранному специалисту» | LocationDayResource; LocationDayForm; CreateLocationDay; EditLocationDay; passing `PhaseOneBookingLocationsTest::test_location_day_create_shows_actionable_required_errors_without_creating_a_record`; Playwright regression exists in `tests/e2e/crm.spec.ts` but hosted execution did not start (run 39862055933 cancelled during browser installation) | PostgreSQL staging row id 15 persisted as inactive with weekday/times/timezone and specialist_id 20; zero booking references; exact row removed after verification | Chromium and WebKit staging: page/create/edit routes, specialist 20 explicitly selected for the travel day, five specific Russian validation messages, corrected save, edit redirect, reload and reopen; create/edit CTA visible without horizontal overflow at 320/360/390/768/1024/1440 | `location_day.created` and `location_day.qa_cleanup` audit events retained; no raw `validation.required`; cleanup query returned zero matching rows | VERIFIED |
| L2-15.1 | LIST 2 §15 heading «26.09.26 (15)» | Source inventory/date marker only; it requests no screen label, interaction or state change | N/A: no product behavior or persisted state is requested by the heading | LIST 2 structure was reconciled against the numbered requirements; no UI behavior was inferred from the date marker | No action/failure/audit is applicable to a source heading | VERIFIED — source metadata, no separate product behavior |
| L2-15.2 | «В карточке „Медицинский профиль“ пользователя разделить поля:» | Portal `MedicalProfile.vue`; CRM `MedicalProfileForm` and `ClinicalProfileInfolist`; `September26MedicalProfileTest::test_crm_medical_form_saves_reopens_and_projects_all_clinical_answers`; `MedicalProfiles\MedicalProfileTest::test_split_medical_fields_are_saved_independently_without_losing_legacy_text` | Existing PostgreSQL staging profile was persisted in encrypted medical fields and read back through authorized Portal/CRM flows; split-field regression asserts distinct values and preservation of legacy combined text | Existing staging journey opened the Portal profile and CRM clinical-profile tab; separate complaint/goals and operation/injury fields remained visible after save/reload/reopen. Client/session projections remain separately covered by CLINICAL.2 and the clinical rows below | Distinct required/year errors and correction evidence are in CLINICAL.3–9 and CLINICAL.12–15; safe field-name audit only; medical plaintext was absent from logs; synthetic records were reset | VERIFIED |
| L2-15.3 | «Жалобы и цели (см. доп файл с изменениями)»; LIST 1 clinical specification provides symptom/detail controls and a separate goals textarea | Portal `MedicalProfile.vue`; `MedicalProfileTest::test_split_medical_fields_are_saved_independently_without_losing_legacy_text`; CLINICAL.3–9 regressions | Encrypted complaint and goal fields were present in the PostgreSQL-backed staging profile; current values remained independently readable after reload; legacy combined history was retained by the split-field regression | Nine symptom chips, complaint detail and goals were separately edited; save/reload/reopen preserved them in Portal and the authorized CRM counterpart showed the saved values. Exact goals placeholder and CLINICAL.3–9 evidence are recorded above | Empty complaint/goals errors and recoverable correction are recorded in CLINICAL.3–9; audits contain safe field names, not text; logs contained no clinical plaintext | VERIFIED |
| L2-15.4 | «Разделить операции и травмы» | Portal `MedicalProfile.vue` independent operations/injuries field groups; CRM clinical form/projection; `September26MedicalProfileTest::test_crm_medical_form_saves_reopens_and_projects_all_clinical_answers`; `MedicalProfiles\MedicalProfileTest::test_split_medical_fields_are_saved_independently_without_losing_legacy_text`; operation/injury validation regressions | PostgreSQL-backed staging saved the profile and read the distinct current fields through the app; encrypted data and safe field-name audit were checked; model regression covers independent values and legacy history | Portal independently changed operations Yes→No and injuries No→Yes; year and description inputs appeared under the corresponding choice. Missing and out-of-range years showed field-specific errors; correcting both saved, survived reload/reopen and appeared as separate CRM values | Tests assert invalid direct states do not persist; audit metadata uses field names only; operation/injury detail was not exposed in logs. Existing negative journeys are recorded in CLINICAL.12–15 | VERIFIED |

| L2-16.1 | LIST 2 §16 heading «26.09.26 (16)» | Source inventory/date marker only; it requests no product action or label | N/A: no product state is requested by this heading | LIST 2 structure was reconciled without inventing a screen element | No action, error or audit applies to source metadata | VERIFIED — source metadata, no separate product behavior |
| L2-16.2 | «Редактировать шаблон сообщения для рассылки. Не закрепляется фото/видео в сообщении» | `BroadcastCampaignResource`, `BroadcastCampaignMedia`, `PreviewBroadcastCampaign`, `TestBroadcastCampaign`; `MilestoneElevenBBroadcastTest` media save/reopen/preview, video, replacement/removal, explicit-recipient, provider rejection, validation, audit and replay tests | Existing PostgreSQL staging campaign retained media metadata and reopened it; signed private-media preview returned 200; a test-send to exactly one QA client persisted delivered attempt 1 and a provider reference. Prior PostgreSQL integration concurrency evidence remains applicable because the broadcast runtime and concurrency tests have no code diff between SHA `5decdaf9` and staging SHA `140a5b0b` | Earlier staging CRM journey: reopen template → Preview → signed private-media load → test-send to one QA client → reload/reopen. Media delivery was accepted by Telegram. The actual recipient's Telegram chat was not independently opened; the approved QA profile contains CRM and Portal states but no Telegram client session or message-history access | Thirteen focused fake-provider feature tests passed on code-equivalent HEAD `0a169d5c` (60 assertions), covering save/reopen/preview, video, replacement/removal, single-recipient routing, upload/consent/action validation, safe provider rejection reference, sanitized provider failure, bounded retry, failed-send audit semantics and replay without duplicate. No secret or media contents were logged by the tested paths. The user-visible client chat remains unobserved | BLOCKED — only independent Telegram recipient-side observation remains; source behavior, staging provider path and failure diagnostics are evidenced |

The full evidence ledger for 29 parent requirements and 226 leaf criteria has not yet been completed. Accordingly this is a failed acceptance result, not a release-ready evidence report. The historical sections below preserve prior investigations only and do not change the FAIL status of any unlisted requirement.

The current 2026-10-23 replay corrected the earlier stale-bundle diagnosis: the denial message disappeared when an unfocused city-autocomplete request overlapped the booking redirect and consumed the one-request validation error. The fix is deployed on `4ce6e5a051dd9e00f038172aa73cd6549c1f75f6`; post-fix Chromium and WebKit staging journeys passed. The focus-triggered city request displays loading and disables submit until completion, then a blacklisted attempt displays the specific recovery message and creates no booking or idempotency row.

## Historical checkpoint evidence — 2026-10-21 (superseded)

Historical checkpoint only. Its statuses and scope are superseded by the owner-directed 2026-10-22 section above and are not current acceptance claims.

- Starting main: `c201b41a14d91c57c1890e62737f9e2001a231f1`.
- Reviewed branch candidate: `85fd9b9dacbc871032990711be1a5055df3fbe93`. Exact staging runtime: `e9dafd30ab4eb83a470dba92a20211fdbe88f0e6`. Draft [PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60) remains open and unmerged. Commits after the staging runtime are browser-test synchronization and evidence assertions; no later runtime code was deployed.
- Exact-SHA `e9dafd30ab4eb83a470dba92a20211fdbe88f0e6` staging deployment and `scripts/staging-smoke.sh` returned **PASS** with the dedicated staging QA admin `user_id=19`; shared user 2 was not used for automated QA credential or MFA operations. No staging data was repaired or rewritten as part of the Journal diagnosis.
- Staging Journal responsive checks passed in both Chromium and WebKit at 320, 360, 390, 768, 1024 and 1440px. The open specialist dropdown had no page overflow, remained open during sequential checkbox selection, desktop weekly grid was present at 1024/1440px, and mobile cards were present only through 768px. Focused hosted browser run [39643361069](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39643361069) passed the current Journal flow in Chromium desktop and WebKit mobile.
- Hosted full CRM browser run [39638454462](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39638454462) completed 38 passed / 2 skipped / 2 failed. The current Journal flow passed; the two failures were pre-existing mobile baselines in group-booking action-menu and partner-recommendation menu scenarios. Therefore the full suite is not reported as green.
- Final hosted CI [39643849378](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39643849378) completed with Quality **FAIL / BASELINE** from existing PHPStan/Pint and dependency-audit findings. Integration foundation, concurrency, RAG, Docker/runtime health and privacy/secret scan **PASS**. No current Journal or authentication feature failure was reported by the final candidate feature tests.
- Focused local feature verification passed: 62 tests / 424 assertions for authentication, authorization, medical attachment access and Journal flows; the broader affected set passed 77 tests / 514 assertions. PHP syntax, Pint on the changed middleware, `git diff --check`, ESLint and TypeScript checks passed.
- Read-only PostgreSQL staging evidence confirmed organization timezone `Asia/Almaty`, DB session timezone `Etc/UTC`, specialist-bound schedule values and no overlapping exception/unavailable records for the inspected week. The `23:00–00:00` and `00:00–10:00` ranges are valid midnight projection segments from real schedule values; conversion is applied once from stored UTC/specialist timezone into the CRM organization display timezone. No double conversion and no schedule-data correction were performed.
- Real QA booking journeys created and then cancelled a Denis slot and an Evgeniy slot. The persisted records retained the clicked specialist IDs (`20` and `1`) through detail, reload and cancellation; booking events recorded both transitions. This was safe QA data only.
- Owner-independent defects found and fixed: mobile dropdown collision overflow; cancelled bookings incorrectly occupying Journal availability; stale Journal time prefill entering an unavailable time; and inactive membership being treated as absent during signed medical attachment access. Each has focused regression coverage or an existing regression that caught the defect after the middleware correction.
- The strict 29-entry matrix remains: L2-14 is **VERIFIED — BOUNDED JOURNAL SLICE**; L2-16 is **VERIFIED — BOUNDED QA MEDIA SLICE**; CR-02 and CR-05 are **BLOCKED** by approved external/owner content; CR-01, L2-01, L2-02 and L2-13 are **NOT VERIFIED**; the other entries remain **PARTIALLY VERIFIED** where a protected Portal, recipient-visible provider, legal or complete CRUD boundary was unavailable.
- Owner acceptance is **NOT RUN**. Remaining objective boundaries are approved Evgeny media, authoritative nine-systems/MSQ content, adult/minor medical-delegation rules, and fresh recipient-visible Telegram/Mini App proof where the external activation/session is unavailable. No payments, mass sends, destructive staging tests or production changes were performed.

Initial statuses describe bounded source inspection, not final staging proof. Every criterion still requires its named observable journey; a green test or a route response alone is not VERIFIED.

## Historical shared evidence contract — superseded by 2026-10-23 pass

At that earlier checkpoint, the reviewed branch candidate was `85fd9b9dacbc871032990711be1a5055df3fbe93` and staging runtime was `e9dafd30ab4eb83a470dba92a20211fdbe88f0e6`. Its 29-item status table is retained as history only; the 2026-10-23 owner-directed pass above is the current source of truth.

- CR-01/02: managed web-app/story media controls and temporary QA media are implemented; approved author media and recipient-visible real Telegram/WebView launch/playback remain blocked/not verified.
- CR-03/04/06/07/08/09/10/11/13: implementation and focused regressions are complete; full browser/provider proof is limited where the Portal session expired or a future-due delivery was not observed.
- CR-05: the three-state Health Action engine is implemented; authoritative nine-systems/MSQ content remains an owner asset blocker.
- CR-12 and LIST 2 №4: patient identities, booking-for-whom, separate medical-history ownership, invitation acceptance/replay protection/revocation and tenant/client guards are implemented. Adult/minor medical delegation remains blocked pending owner/legal rules.
- L2-06: header, referral/bonus cabinet and applicable booking/payment projections use the selected display currency through the existing Finance FX authority; ServiceCredit accounting and immutable evidence remain in organization base currency.
- L2-14: unified unavailable time, Journal specialist filters, specialist-bound travel/location days, regular intervals and schedule settings are implemented. The current Journal evidence below covers the restored accepted desktop weekly grid, specialist ownership and the multi-select interaction.
- L2-16: image/video upload, private persistence, replacement/removal, preview, reload and QA-only test-send delivery are implemented and exercised on staging; real recipient-visible Telegram delivery remains not independently observed.

Final static comparison: [Quality run 39643849378](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39643849378) is **FAIL / BASELINE** for existing PHPStan/Pint and dependency-audit findings. Integration foundation/concurrency/RAG, Docker/runtime health and privacy/secret scan PASS. Focused Journal browser run [39643361069](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39643361069) PASSes on the reviewed branch in Chromium desktop and WebKit mobile; the full CRM run [39638454462](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39638454462) has two unrelated baseline mobile failures. No current Journal assertion was removed or weakened.

First candidate: `676c4da3436e30f0268aff2245db61958edb3a6f`, [Draft PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60), no merge. [Hosted CI](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38389322189): unit, integration foundation, integration concurrency, RAG, Docker/runtime and privacy gates PASS; Quality FAIL (13 feature failures, remaining static/build gates not reached). [Focused browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38389447699): 4 PASS / 2 FAIL across Chromium/WebKit. Medical and non-medical booking continuation passed; the profile/medical journey found symptom-row overflow. This is not staging acceptance.

Follow-up changes address wrapping, confirmed submit waiting, missing Mini App configuration, mandatory profile fields, feature-disabled self writes, private-file uploads and PHP multipart limits. Quality steps retain all original checks but execute independently after schema readiness, so a feature failure cannot conceal type/build/security outcomes. A single [starting-main Quality comparison](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38397769054) distinguishes baseline failures from candidate defects. Focused attachment/profile/health/Telegram/CRM tests: 70 tests / 661 assertions PASS; new upload replay/private download/forged-key checks: 3 tests / 32 assertions PASS. At that early candidate checkpoint, the final candidate rerun was pending.

Historical checkpoint note: the earlier staging baseline smoke stopped at an exact-SHA mismatch (main c201b41; deployed f3509f8), and at that time exact-limit upload runtime proof was pending. The owner-directed pass above later exercised the five-file / exact 20 MiB boundary on staging PostgreSQL. The original source requests S3, but accepted architecture uses the existing private Storage authority; no literal S3 verification is claimed.

Owner answers: original videos unavailable; temporary QA media permitted, with managed CRM uploading required. Telegram QA identity: Murlock Warlock. No concrete family consent/guardianship policy was supplied. Adult medical delegation and minor representative access must not be inferred from relation or an invitation.

Every criterion requires visible trigger → real click/input → authorization → Application action → PostgreSQL state → CRM/client projection → relevant provider effect → reload → permitted change/cancel/correction. Negative evidence includes foreign tenant/client, unauthorized actor, invalid input/state, duplicate submit, stale UI and provider failure where applicable. Browser widths: 320, 360, 390, 768, 1024, 1440. Check full-page overflow; desktop Chromium and mobile WebKit. No destructive testing on staging, real medical data, real payments or bulk delivery. Owner acceptance is separate.

### Historical follow-up implementation and preservation checkpoint

Latest native staging candidate: `c0d6beea97e398fa13322e61d29c0ceea3c61763`, deployed over 4ad1a9c. Build, runtime, protected host baseline and default repository staging smoke PASS; no new migrations. [Exact-SHA PostgreSQL focused gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38449111788): 22 tests / 183 assertions PASS; overall Quality FAIL remains three baseline Pint files and 194 PHPStan findings. [Exact-SHA browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38449116772): six Chromium/WebKit cases PASS, covering enrolled MFA, portrait Stories/navigation labels and paid Focus/card padding. No blanket CI or whole-product PASS is claimed.

Following the owner's explicit approval, enrolled CRM MFA now uses the installed framework challenge with organization-scoped active authorization. Registration remains optional. Real native QA-user-2 staging browser checks PASS: password alone denied; invalid six-digit TOTP denied; valid TOTP accepted; reload remains authenticated. The verification includes missing/malformed codes, replay and membership/tenant denial in isolated tests. Existing sessions were not globally revoked and other users' rights/passwords/secrets were not changed. Earlier password-only browser login evidence must not be described as MFA evidence.

The owner requested durable QA access reuse. Genuine Portal state, enrolled CRM state, credentials and MFA provisioning are retained outside Git in mode-0600 files under a mode-0700 private directory, with a globally discoverable local skill and AGENTS pointer. Server expiry/revocation is unchanged; old login links and fabricated sessions are not substitutes. Following this later instruction, the stored QA credential is retained rather than automatically restored. The encrypted prior-hash recovery snapshot remains available; any later restoration/rotation requires explicit direction.

Native Portal login was confirmed in the real bot; subsequent checks reused the genuine saved browser state without further owner prompts. On c0d6bee, all five bottom-navigation destinations were actually clicked, Stories decoded playback/switch/close and idle Companion banner absence PASS. Seventeen safe Portal page destinations returned HTTP 200 and were checked at 320/360/390/768/1024/1440px with no document overflow. The one clipped Story control at 320px is inside the intentional horizontal carousel, not page overflow. This is geometry/navigation evidence for QA client 2, not visual acceptance of every screenshot, all roles/dialogs/states or actual Telegram WebView. All 29 source requirements remain PARTIAL overall, with missing implementations and external/owner blockers still listed below.

Code candidate `ec32044` ([focused hosted gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38426895784)): 96 PostgreSQL feature tests / 839 assertions PASS. All frontend and dependency checks PASS. Overall CI remains FAIL: 3 baseline Pint files and 194 PHPStan findings. A newly added expiry fixture initially violated PostgreSQL's ends-after-start constraint; it now advances time past a valid entitlement's end instead of constructing an impossible period. The constraint and expired-access assertion are preserved. The authoritative medical update now validates complete clinical answers before confirmation, including complaints/symptoms and operation/injury details; invalid direct commands create no profile or success audit.

[Browser check on f8fb6f3](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38426096708): 8 Chromium/WebKit cases PASS, including actual Focus completion/correction/reload, empty Focus → Tracker, profile/medical editing and multi-booking cancellation. [Profile follow-up on 7a846fc](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38426723150): 2 PASS, including native two-segment gender selection with pointer and arrow keys. [Exact ec32044 browser rerun](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38427783078): all 8 targeted cases PASS. These are isolated hosted checks, not live staging, real Telegram or owner acceptance.

#### Authorized staging checkpoint — 13.10.2026

Owner explicitly approved the intermediate CI-red QA deployment. Exact `ec320440e2cefabc1fa8820806234edb3c120763` deployed successfully over `f3509f8ce957082045f3e140cd4a08809d1c3751`. PostgreSQL backup archive was created and its table of contents validated; no isolated restore drill was performed. Three additive migrations applied; PostgreSQL, Redis, private storage, app, Horizon, scheduler and Telegram runtime checks PASS. Existing Redis physical volume and protected host configuration were preserved. Application rollback target remains f3509f8; destructive database rollback is not authorized.

Default `scripts/staging-smoke.sh` initially stopped because QA user 2 lacked MFA. Following the owner's specific instruction to enable it, MFA was enrolled through the existing audited provider for that account only. Encrypted secret and hashed recovery codes were verified; rights/password were unchanged. Provisioning material is in a private local artifact, never Git or report content. The subsequent default staging smoke PASS includes CRM Home, Clients/card, Partners/payouts/finance, Sessions, Surveys, Knowledge and Portal HTTP checks. These harness checks use their existing server-side actor setup: they do not prove a real browser login or user clicks.

One safe real Telegram CTA was sent to the verified Murlock Warlock QA identity, client 2. Actual Bot API response: HTTP 200, `ok=true`, message 520, correct recipient, one «Открыть приложение» button containing canonical `web_app.url` and no ordinary `url`. This is direct adapter/provider evidence, not Scenario → queue → worker → delivery-attempt evidence. No replay or duplicate send was attempted. The owner's screenshot shows the authenticated Stories area, but does not establish all launch/auth steps or playback.

The owner identified four non-clickable Story placeholders. Inspection showed zero corresponding content records, so all four were disabled by missing video URLs. Using the approved temporary-media permission, four ru Mini App content slots were created through existing authorized/audited CreateContentSection and AttachContentVideo actions: IDs 9–12, keys story_author/story_history/story_method/story_details. Each has a preview and the same five-second diagnostic color-pattern MP4; content explicitly identifies it as temporary test material, not Eugene's approved footage. All four media URLs return HTTP 200, video/mp4, 889684 bytes and byte-range support. CRM FilePicker upload, actual Stories click/play/switch/close and mobile Telegram WebView playback remain NOT VERIFIED; owner recheck requested. Welcome media is still absent. CR-01/02 are not complete or VERIFIED.

Public staging login and unauthenticated launch destination were inspected in Chromium and narrow-viewport WebKit at 320/360/390/768/1024/1440px with no observed overflow or page errors. This does not verify authenticated screens, mobile device emulation or the real Telegram WebView. Overall CI remains FAIL; full 29-requirement acceptance remains PARTIAL.

Owner subsequently exposed a real portrait-preview overlap: the image escaped the Story circle and covered its label. Previous source/backend tests and empty-preview browser checks were insufficient. `b1c783c5571d834ca97098214d1d57ed4e85ebd1` contains the bounded fix: positioned/clipped image inside the existing circular frame and natural row height for complete labels. It also removes the owner's rejected idle «Помощник отвечает» panel, while retaining pending typing feedback, human takeover, escalation and actionable failure/retry notices. That exact code SHA is deployed; build/runtime/default staging smoke PASS. Real authenticated browser visual recheck remains pending.

New portrait-image geometry and actual player open/next/previous/close/reopen regression was added. Its first [hosted run](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38435452556) had Companion checks PASS (desktop/mobile), but the new Stories test navigated to nonexistent /portal instead of canonical Home /. Test-only commit `30e05b8` corrects the route without reducing assertions; [targeted rerun](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38435983233): four Chromium/WebKit cases PASS. The image fixture is synthetic; this hosted test does not prove real video playback. Owner requested checks of frames/overflow throughout Portal and CRM, not sampled-screen acceptance. [Broader hosted run](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38436084414) actually selected CRM only because the workflow restored its default test path: 21 PASS, 15 FAIL, two existing clinical-AI environment skips. It was not a full-product browser gate. `3c82f23` corrects the isolated login limiter's actual installed class key and the existing «Описание боли» textarea locator. [Focused follow-up](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38442441745): sidebar navigation PASS on both engines; Session create/edit FAIL because the test requested «Создать» while the accepted create action has always been «Сохранить». Persistence and history assertions are retained in the follow-up correction. Staging remains b1c783c, not these test-only SHAs.

Owner explicitly authorized a temporary random password for QA staff user 2 after the original password could not be found. The prior hash is encrypted in private recovery storage; no credentials or MFA material are published. Native password/MFA browser login subsequently succeeded. Chromium visited all 44 discovered CRM sidebar destinations with HTTP 200, no page JavaScript errors and no document horizontal overflow at 320/360/390/768/1024/1440px. This is route/list geometry evidence, not proof of every CRUD action, modal, role or scrollable table. WebKit captures still require error classification and settled-drawer visual review. Original password restoration remains pending; MFA is intentionally retained.

Actual CRM content flow on record 9: native login → «Загрузить видео» → visible modal → native file chooser → completed upload → «Отправить» → «Видео сохранено» → reload → persisted replacement video URL. Upload HTTP 200; authorized server projection returns the new URL. Subsequent native video controls started decoded playback. Content edit document overflow checks PASS at all six widths. This proves one existing slot's replacement/upload/playback, not subtitles, all media limits or approved footage. Private artifacts are under `/Users/ivankorakin/.codex/local-artifacts/chuklov-platform/2026-09-26/deploy-ec32044.EmaiT4/`; none are committed.

First native Portal authentication challenge timed out before owner confirmation; its old link was rejected and that attempt is NOT VERIFIED. A newly generated browser-bound challenge was confirmed by the owner in the real Telegram bot. The open Chromium browser authenticated normally, clicked the first Story, observed decoded playback with advancing currentTime, switched to the next Story and closed it. Home document overflow checks PASS at all six required widths. Idle Companion banner absence PASS. Captures exist for all widths; visual review of 320/390/1440 Home and 390 Companion exposed two additional issues: isolated final-letter wrapping in the 320px «Записаться» navigation label and missing horizontal Health Action padding. Follow-up code and regressions are in progress. This is ordinary browser authentication and playback, not actual Telegram WebView playback or full CR-02 acceptance. Approved media remain missing; all-role/all-action UI acceptance remains PARTIAL.

Candidate `35f2cb0`: [browser check](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38423845609) PASS (4 Chromium/WebKit cases). Profile country pointer/keyboard selection, open-list overflow at six widths, medical persistence/correction/private attachments and multi-session selection/medical return/independent cancellation execute in the isolated hosted environment. This is not staging acceptance or a complete visual inspection. Welcome-video and menu provider-failure fallback preserve the auth-continuation web_app button, without logging provider text/URLs; greeting delivery failures still propagate. Telegram/content regressions: 40 tests / 208 assertions PASS locally, fake adapter only. Real video playback and Telegram receipt remain NOT VERIFIED.

Candidate `7a5db0c`: [focused hosted check](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38413647275) has 256 PostgreSQL feature tests / 2231 assertions PASS; frontend and dependency checks PASS. Pint (3 baseline files) and PHPStan (198 findings) remain FAIL. This is a focused check, not the full gate or staging acceptance. Subsequent [browser check on e0a9bf2](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38418779308) found the batch fixture selected the first available day, not its intended date: the query anchors the displayed month. The test now clicks the actual intended calendar day before selecting a slot; cancellation policy and assertions are preserved. Country search adds real pointer/keyboard selection and open-dropdown overflow checks at the six required widths; rerun pending. No staging deployment is claimed.

Multi-session candidate `ed0cc38e09534cc4eb25695bf857c2b3b6de90f8`: [PostgreSQL foundation](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38411045281) and [concurrency](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38411051526) PASS, including the Portal batch process race. [Browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38411057033): 6 PASS / 2 FAIL; actual creation persisted two rows, but the medical-screen «Мои записи» button returned to one booking. This is a real navigation defect, not a count assertion to weaken. Follow-up returns a batch to the complete list, labels a single-booking destination «К записи», and preserves cabinet return. New server coverage checks medical save → all bookings, single return and rejected external return targets.

[Quality on ed0cc38](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38411039299) remains FAIL: 2 legacy single-booking tests, 3 baseline Pint files, 199 PHPStan errors; frontend and both dependency audits PASS. The new UUID validation wrongly rejected a previously ignored client-controlled key on a single booking. Follow-up restores the existing server-generated single-booking intent and applies the new request UUID only to multi-session requests; old assertions are unchanged. The new typed list error is corrected at the validated HTTP boundary. A focused hosted mode now runs an explicitly named regression filter with all original static/frontend/audit steps; full/quality scopes remain unchanged. This mode is not full-candidate acceptance.

Identity-switch hardening also clears previous-client booking receipts, outgoing certificate links and Telegram-link output while preserving incoming claim/auth continuation. Follow-up scheduling/Portal/history regressions: 59 tests / 544 assertions PASS on SQLite. No new staging acceptance is asserted by these local checks.

LIST 2 №3 adds a Portal «+ / Добавить ещё сеанс» control, removable session list, multi-date/month selection, full confirmation list and a single medical continuation after all selected bookings commit. It extends CreateMultipleBookings/CreateBooking, with no alternate Booking engine. Portal batch intent is bound through the existing booking_idempotency_keys authority before child creation; the aggregate binding is locked, organization/client scoped, and rolls back with all bookings, consents and events on conflict. Retrying a used key with a changed entire set is rejected, including a set with no shared slots. Existing CRM batch keys and behavior are preserved. Successful batches support independent cancellation; receipt IDs are client/organization scoped. Focused batch/secret/smoke checks: 11 tests / 125 assertions PASS on SQLite. New PostgreSQL process-race and actual browser add/remove/confirm/medical/reload/cancel tests are NOT RUN at this checkpoint; real queues/delivery and CRM browser counterpart remain NOT VERIFIED. The pre-existing large BookingCreate view is retained to preserve its accepted state/choice orchestration; new basket responsibility is extracted into useBookingSessions and BookingSessionSelection rather than introducing a second booking UI.

Candidate `6c6b515ba169601ca350ac84167c11ff66c9ebff`: [focused browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38407070226) PASS (6 Chromium/WebKit cases, including encrypted history/storage and expired-session back navigation). [Full hosted gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38407063516): concurrency, RAG, privacy, Docker/runtime and all frontend/dependency checks PASS. Quality FAIL: 2 feature failures, 3 pre-existing Pint files and 198 PHPStan errors. Foundation FAIL: the old combined-settings test still posted viewer timezone into the general-settings page. Follow-up moves its timezone interaction to WorkingIntervals and retains the subsequent general-settings save plus unchanged timezone/hour assertions. Zoom secret regression now uses an invalid general lead-time field, retaining secret scrubbing assertions after the schedule split. Staging smoke fails closed for a QA actor without enrolled MFA; no enrollment or bypass is manufactured. These follow-up fixes still need exact-SHA hosted verification.

Selective deployed-PR-59 bridge is committed as `1fe546b0b41b03e84fdfb5741c73782599a7ec29`; its source commit/file inventory and intentional exclusions follow below. [Hosted static gate on that exact SHA](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38405301227): ESLint, Vue typecheck, Vite build and Composer audit PASS; Pint FAIL (3 baseline files), PHPStan FAIL (208 errors, including candidate type contracts), npm audit FAIL (new advisories). This dispatch intentionally did not execute unit/feature/integration/browser tests. No staging deploy or real Telegram delivery is claimed.

Owner approved compatible npm security updates separately: `77ed266` changes only package-lock.json, preserving package.json and major versions. Vue/compiler/runtime packages update together; Babel parser/PostCSS/nanoid are required compatible dependencies, not independent upgrades. Unrelated optional-platform lockfile additions were excluded. Lockfile audit and npm ci dry-run PASS; hosted installation/build/browser checks at the next SHA remain required.

CR-10 follow-up encrypts all Portal history, rotates the history key on a server-derived organization/client subject change (including guest/invalid identity), clears request context and marks responses private/no-store. Two server regressions PASS; actual browser back-navigation after expired authentication remains NOT VERIFIED until the new browser test executes. Referral-payout CTA copy now names the partner cabinet rather than a meeting, without changing its web_app transport. Focused profile/medical/schedule/payout/history checks: 46 tests / 398 assertions PASS (SQLite), not PostgreSQL or staging proof.

LIST 2 №14 now separates organization-wide Scheduling Configuration from specialist-specific Working Intervals, preserving the existing SetSpecialistWorkingHours command, impact preview, explicit-clear control and viewer-timezone preference. General settings are last in Bookings; Work Schedule heading is «График работы специалиста». Missing working-hours snapshots are rejected. Focused schedule/profile/medical tests: 54 tests / 470 assertions PASS. Unified unavailable-period UI, multi-specialist Journal selection and specialist-bound travel days remain outstanding; №14 is PARTIAL, not complete.

Second candidate `43293a5131e53e3fc975a8b3b7ea406010c9f289`: [browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38398585589) PASS and [Docker/runtime](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38398580325) PASS. Browser checks exercise profile/clinical correction/reload, two-file upload/download, medical/non-medical booking and overflow at 320/360/390/768/1024/1440px in Chromium/WebKit. This is isolated hosted browser evidence, not staging or a complete visual design audit.

[Quality](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38398575059) remains FAIL: the same 10 feature failures as Starting SHA, existing format failures, PHPStan findings and Composer advisories. No failures are hidden or declared PASS. Owner authorized narrow dependency security updates: `45103f5` changes Laravel 13.25.0→13.30.0, Filament 5.7.6→5.8.2 with required synchronized packages, CommonMark 2.10.0→2.10.2 and Flysystem 3.35.2→3.35.3. Local locked audit PASS; hosted final-SHA audit pending.

Read-only staging REVISION is `f3509f8ce957082045f3e140cd4a08809d1c3751`, which is not an ancestor of Starting SHA. Owner explicitly authorized selective transfer of necessary PR #59 runtime fixes/regressions, including Telegram internal client CTA buttons, without merging/closing #59 or importing its proof/audit artifacts. At that earlier checkpoint, staging deployment remained pending integration and affected verification; it must not silently roll back this delta.


### Selective preservation of deployed PR #59 code

Source delta is Starting main `c201b41` → deployed `f3509f8`, not PR #59's later un-deployed HEAD. No merge or blind commit cherry-pick was used. Unchanged files were checked against Starting main; overlapping files received selected hunks or functional integration.

Source commits represented: `f3509f8` (internal Mini App CTA), `de1b246` (sanitized staff reply), `1030a50` (meeting-link replay), `65b89e2` / `a57b443` (visible payment/barter validation and payment-history refresh), `2d16ac3` (gift-claim error continuation), `570f2da` / `4b4c9fd` (substantive regressions and valid PostgreSQL/test contracts), `ed9f423` / `aeaa182` (compatible typed CRM/date contracts), `c79ebac` (Compose preflight drains output). This identifies source contributions, not a claim that entire commits were imported.

Selected files transferred so far:

- `app/Filament/Auth/Login.php` — source `ed9f423`; missing panel fails closed instead of passing null to user authorization. New missing-panel regression and existing privileged-auth suite PASS locally.
- `app/Filament/Resources/B2bLeads/Schemas/B2bLeadInfolist.php` — only the two backed-enum fallback fixes from `4b4c9fd`; existing nullable sales-call guards intentionally retained.
- `app/Modules/Finance/Domain/Models/PaymentGatewayEvent.php` — only accurate Carbon/null retry and lease property types from `4b4c9fd`; existing runtime casts and state machine unchanged.

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

## Historical implementation summary — superseded by owner-directed final pass

`IMPLEMENTED` means the code and focused regression contract are present; it is not a claim that every external/client-visible journey was observed. `STAGING VERIFIED` is limited to the exact observable slice named in its evidence. `BLOCKED` is reserved for the three objective source blockers listed above.

| Requirement | Before | Snapshot status at that time | Evidence |
| --- | --- | --- | --- |
| [CR-01](#cr-01) | PARTIAL | BLOCKED | Single web_app/menu-button implementation and provider payload coverage; approved welcome video and recipient-visible launch remain unavailable. |
| [CR-02](#cr-02) | NOT IMPLEMENTED | BLOCKED | Four managed story slots, upload/player/fallback and temporary QA MP4 playback code; approved Eugene footage/subtitles missing. |
| [CR-03](#cr-03) | PARTIAL | IMPLEMENTED | Name/fallback projection and legacy identity preservation covered by focused profile/greeting tests. |
| [CR-04](#cr-04) | PARTIAL | IMPLEMENTED | Recovery schedule card and booking-state projection implemented with refresh/polling coverage. |
| [CR-05](#cr-05) | PARTIAL | BLOCKED | Three-state Health Action engine is wired to existing Tracker/Survey; authoritative nine-systems/MSQ content is missing. |
| [CR-06](#cr-06) | PARTIAL | IMPLEMENTED | «Задать вопрос» routes through the existing Companion and preserves shared conversation authority. |
| [CR-07](#cr-07) | PARTIAL | IMPLEMENTED | Booking personal-data persistence, country-scoped city autocomplete and E.164 phone normalization; PostgreSQL feature coverage in CI. |
| [CR-08](#cr-08) | PARTIAL | IMPLEMENTED | Encrypted medical continuation/prefill, private multi-file attachments and rollback/idempotency regressions. |
| [CR-09](#cr-09) | PARTIAL | IMPLEMENTED | CRM client/session create/edit/view projections include structured clinical fields and encrypted history; focused native tests pass. |
| [CR-10](#cr-10) | PARTIAL | IMPLEMENTED | Tenant/client authorization, private storage, redaction and direct-ID negative tests; exhaustive all-role browser proof remains separate. |
| [CR-11](#cr-11) | NOT IMPLEMENTED | IMPLEMENTED | Backend `requires_medical_profile` branching is enforced for medical and non-medical booking. |
| [CR-12](#cr-12) | NOT IMPLEMENTED | BLOCKED | Patient identities, invitations, acceptance/replay protection, unlink/revoke and separate histories are implemented; adult/minor medical delegation remains owner/legal blocked. |
| [CR-13](#cr-13) | NOT IMPLEMENTED | IMPLEMENTED | Immediate/24h/2h reminder cascade, suppression and cancellation/retry semantics covered by focused PostgreSQL/staging evidence; recipient-visible future delivery remains not independently observed. |
| [L2-01](#l2-01) | PARTIAL | IMPLEMENTED | Client notes are retained in CRM projections and lifecycle tests. |
| [L2-02](#l2-02) | PARTIAL | IMPLEMENTED | Blacklist controls and backend booking rejection remain tenant-scoped. |
| [L2-03](#l2-03) | NOT IMPLEMENTED | IMPLEMENTED | Portal add/remove/multi-session confirmation and atomic replay-safe batch command; hosted feature/concurrency coverage. |
| [L2-04](#l2-04) | MIXED — 6 VERIFIED / 4 BLOCKED | BLOCKED | Family profile, invitation acceptance, booking selection and unlink are directly verified. Remaining blocks are recipient-visible Telegram notification, notification-recipient policy, legal medical delegation, and the source's deferred owner answer. |
| [L2-05](#l2-05) | PARTIAL | IMPLEMENTED | Existing encrypted 0–10/null value preserved with emoji/color presentation and CRM session mapping. |
| [L2-06](#l2-06) | PARTIAL | IMPLEMENTED | Header/referral/booking bonus display uses selected client currency through Finance FX; ServiceCredit ledger/evidence remains base-currency immutable. |
| [L2-07](#l2-07) | PARTIAL | IMPLEMENTED | Gift certificate sale/claim/use/correction implementation preserved; focused regression and staging smoke coverage. |
| [L2-08](#l2-08) | PARTIAL | IMPLEMENTED | Referral attribution and cabinet projections remain on the existing ledger/identity authority. |
| [L2-09](#l2-09) | PARTIAL | IMPLEMENTED | Backdated booking requires confirmation and now exposes the required danger/red presentation. |
| [L2-10](#l2-10) | PARTIAL | IMPLEMENTED | Blacklist and referral/notes regressions retained with authorization checks. |
| [L2-11](#l2-11) | PARTIAL | IMPLEMENTED | Existing service/location and client-facing schedule flows retained with focused lifecycle tests. |
| [L2-12](#l2-12) | PARTIAL | IMPLEMENTED | Independent Office/Home/Online buffers, legacy fallback, availability/conflict/occupancy and PostgreSQL race coverage. |
| [L2-13](#l2-13) | PARTIAL | IMPLEMENTED | Existing scheduling and client session lifecycle retained; affected regression suites pass. |
| [L2-14](#l2-14) | PARTIAL | STAGING VERIFIED | Literal controls now align: unified unavailable time, regular working intervals, specialist-bound travel days, all-specialist/multi-specialist Journal filters and separate schedule settings; exact staging route clicks/geometry pass. |
| [L2-15](#l2-15) | PARTIAL | IMPLEMENTED | Medical Profile complaints/goals remain separate from operations/injuries; encrypted legacy history and Client/Session projections are preserved. |
| [L2-16](#l2-16) | PARTIAL | STAGING VERIFIED | Exact staging upload/save/reopen/preview/test-send/reload; provider attempt 1 delivered with reference; local invalid/replacement/retry/no-duplicate suite passes. |

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
Before: **NOT IMPLEMENTED**. Current status: six leaves `VERIFIED`, one author-content leaf `BLOCKED`, zero `FAIL`.
Preserve: Home.vue.
Missing or pending proof: approved Eugene video and matching author subtitles. Synthetic QA media was used only to verify mechanics; it is not accepted as final authored content.

### Source and individual criteria

| Requirement ID | Exact source expectation | Implementation / regression | PostgreSQL / authoritative state | Browser and staging journey | Error, audit and diagnostic evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| CR-02.1 | «Реализовать компонент карусели Stories над блоком приветствия со следующими точными названиями:» | `Home.vue`; `resources/js/Components/Portal/Stories.vue`; `tests/e2e/portal.spec.ts::portrait Story previews stay inside their frames and player navigation works` | Four visible `ContentSection` rows with story keys `story_author`, `story_history`, `story_method`, `story_details` persisted; reload projection read them back. Rows/files were removed after acceptance and cleanup audit retained. | Chromium/WebKit on staging: 0 stories → first story → four stories; actual carousel appeared above greeting and survived reload/reopen. | Read-only display has no state-changing audit. CRM create/update and cleanup actions produced safe audit records; no media payload was included. | VERIFIED |
| CR-02.2 | «Кружок 1: «Кто такой Евгений Чуклов (видео)»» | `Stories.vue`; exact-label/fit regression in `tests/e2e/portal.spec.ts::portrait Story previews stay inside their frames and player navigation works` | First published QA `ContentSection` row was read from PostgreSQL and removed after the journey. | Exact text, accessibility name and first position matched in Chromium and WebKit; reload/reopen preserved the first Story. | No audit expected for read-only display; content management audit stores safe metadata. | VERIFIED |
| CR-02.3 | «Кружок 2: «История мастера (видео)»» | `Stories.vue`; same exact-label/fit regression | Second published QA `ContentSection` row was read from PostgreSQL and removed after the journey. | Exact text, accessibility name and second position matched in Chromium and WebKit; reload/reopen preserved the second Story. | No audit expected for read-only display; content management audit stores safe metadata. | VERIFIED |
| CR-02.4 | «Кружок 3: «Авторский метод (видео)»» | `Stories.vue`; same exact-label/fit regression | Third published QA `ContentSection` row was read from PostgreSQL and removed after the journey. | Exact text, accessibility name and third position matched in Chromium and WebKit; reload/reopen preserved the third Story. | No audit expected for read-only display; content management audit stores safe metadata. | VERIFIED |
| CR-02.5 | «Кружок 4: «Подробно о методике (видео)»» | `Stories.vue`; same exact-label/fit regression | Fourth published QA `ContentSection` row was read from PostgreSQL and removed after the journey. | Exact text, accessibility name and fourth position matched in Chromium and WebKit; reload/reopen preserved the fourth Story. | No audit expected for read-only display; content management audit stores safe metadata. | VERIFIED |
| CR-02.6 | «По тапу на кружок открывается полноэкранный модальный плеер с вертикальным видео (формат 9:16), поддержкой субтитров, кнопкой закрытия крестиком и свайп-навигацией между роликами.» | `Stories.vue`; E2E player-navigation test above. Exact-current hosted run 39972914121 remains FAILURE on its mobile subtitle-fixture assertion, after title assertions passed. | QA player referenced synthetic private media only; four QA rows/media files were cleaned with audit history retained. No booking, patient, payment or author content changed. | On staging Chromium/WebKit with a valid synthetic 360×640 MP4/VTT: open, play, cue, swipe A→B→C→B→A, Next/Previous, close, reopen, reload; inject media failure, see localized error, retry and clear error. | User-visible Russian failure/retry was verified; playback itself is read-only and no DB audit is expected. Approved Eugene video and matching subtitles are absent, so the literal author-content portion is objectively blocked. | BLOCKED — approved author media/subtitles |
| CR-02.7 | «Высота компонента на экране не должна превышать 90px.» | `Stories.vue`; E2E regression asserts carousel height and title fit | PostgreSQL state unchanged by this display rule. | Chromium/WebKit at 320/360/390/768/1024/1440: strip ≤90px, all four labels fully visible, no page horizontal overflow. | No mutation or audit expected. Hosted run 39972914121 passed this assertion on both projects before its later subtitle-fixture failure. | VERIFIED |

Staging PostgreSQL on exact runtime `80dfcbc9bab353ca4aa034c2c17b6c0f6fa85932` held four temporary `ContentSection` rows (IDs 23–26; story keys `story_author`, `story_history`, `story_method`, `story_details`) and their synthetic private media. The create/update actions retained eight audit records. Cleanup removed only those marker-matched rows/files and recorded four cleanup audit events; final read-only checks found zero matching story rows/files, zero synthetic clients 194–198 and zero verification challenges 118–122. All three temporary clients were reset through CRM; targeted Mailpit messages and temporary Portal state files were removed. Audit history was retained. No worker/staff specialist, payment, real broadcast or owner account was used.

The exact-current hosted E2E run 39972914121 on SHA `80dfcbc9bab353ca4aa034c2c17b6c0f6fa85932` completed `failure`: desktop passed, mobile reached a subtitle-cue assertion with an empty cue. The new title-fit assertions passed on both projects before that failure. The test fixture points to a nonexistent synthetic MP4 path; direct staging Chromium and WebKit used a valid synthetic 9:16 MP4/VTT and proved the subtitle behavior. Earlier run 39972204762 on `4a41a9b70807e5574c1cc2a7230f4653d94f83c2` completed `failure` in desktop and mobile at the title-fit assertion, exposing the real clipping defect fixed in `80dfcbc9`. Neither run is reported as PASS. The WebKit failure injection was initially matched by Playwright resource type; WebKit did not classify its MP4 request as `media`. The QA probe was corrected to match the `.mp4` URL and the direct WebKit failure/retry journey then passed; this was a harness correction, not a product defect.

Local browser scripts, result JSON, fixture manifests and synthetic screenshots remain outside Git at `/Users/ivankorakin/.codex/local-artifacts/chuklov-platform/2026-10-23-cr02-stories-attempt-14/` through `attempt-17/`; the manifest records hashes, purpose and sensitivity. No browser storage state, trace, video, password, token, MFA data, medical plaintext or protected file content was committed. Screenshots and outputs use synthetic QA data; scripts reference the private QA profile path and must remain private. No migration or destructive database action was run.

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
Before: **NOT IMPLEMENTED**. After: **IMPLEMENTED**.
Preserve: Existing Service authority and a true-compatible default for legacy rows.
Missing or pending proof: Full authenticated Portal medical/non-medical browser journey; backend branching and CRM configuration are implemented and covered by focused tests.

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
Before: **NOT IMPLEMENTED**. After: **IMPLEMENTED** for the unblocked technical model; **BLOCKED BY OWNER/LEGAL DECISION** only for protected adult/minor delegation.
Preserve: Existing party_size remains a separate group-booking concept.
Implemented technical scope: separate patient identities/profiles, owner/account relationships, booking-for-whom, patient-scoped medical history, invitation issue/acceptance/replay fencing, unlink/revoke, CRM Booking/Journal/Client context projections, existing-client migration and tenant/client authorization. Neither family membership nor invitation acceptance grants medical access.
Missing or pending proof: owner/legal rules for guardian/delegate/parent access to protected adult or minor data and the corresponding consent workflow.

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
Before: **NOT IMPLEMENTED**. After: **IMPLEMENTED** (recipient-visible due-time delivery remains not independently observed).
Preserve: Existing Scenarios and Notification authorities.
Missing or pending proof: Repeated real provider delivery at due time and authenticated client CTA observation; cascade, suppression, retry and cancellation semantics are implemented.

Implementation candidate: existing BookingCreated Scenario rules send the immediate prompt; existing appointment-reminder rows control the 24h/2h prompts, with the medical choice snapshotted to an immutable template version. Completion is rechecked before delivery without decrypting clinical fields; completed confirmed bookings retain the normal time/location reminder. Nonmedical, cancelled, past and completed-profile pending bookings do not receive a medical prompt. The CRM checkbox can disable the medical variant independently; the immediate rule retains the existing enabled control. Pending medical bookings receive the configured medical reminders without changing their Booking state. Existing unsent cancelled reminder actions receive deduplicated replacements on settings rebuild; delivered actions are not replayed. Native CRM manual completion, real worker/provider delivery, timezone/concurrent rebuilding and all staging reverse paths remain **NOT VERIFIED** for this candidate.

Latest direct owner decision: **«Да, текст по фактическому статусу»**. CR-13.2 keeps its original quotation below for provenance, but pending bookings use «Запись создана!», and only actually confirmed bookings use «Бронирование подтверждено!». Completion metadata `completed_at` is separate from `client_confirmed_at`: valid staff-entered data can stop prompts without fabricating a client attestation; removing required data clears the completion state. Historical client-confirmed profiles remain recognized without bulk decryption/backfill. The additive migration preserves reminder enabled states and customized offsets; published versions and cancellation history are retained, not destructively rolled back.

Implementation commits: `22a15d8`, `ff1a450`, `0c91645`, `8443e9c`. Ordinary and medical creation rules at the same instant share one canonical action/delivery; the ordinary variant survives profile completion before delivery. Medical alternative conditions/template/rule references are snapshotted; enabled controls and completion remain live. Delivery attempts record the selected immutable template version before provider I/O. A late result cannot finalize a newer attempt. Local focused checks on 0c91645: 71 tests / 581 assertions PASS. [Hosted PostgreSQL feature checks on exact 8443e9c](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38473115343): 71 tests / 581 assertions PASS; overall Quality FAIL (two earlier Pint issues and 194 earlier PHPStan findings; not all are independently classified against main). [PostgreSQL concurrency on exact 8443e9c](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38473118394): 106 tests / 642 assertions PASS, including concurrent reminder replacement and one medical creation notice. The reschedule retry regression advances to the persisted due time rather than changing it through a stale model. Staging deploy of exact `8443e9c1797847623f4520c7edb137581342d20b` succeeded with backup and four additive migrations; default staging smoke PASS. The complete medical cascade remains PARTIAL, not VERIFIED ON STAGING.

Native CRM evidence on 8443e9c: reused genuine enrolled-MFA session for QA user 2. Scheduling configuration geometry checked at 320/360/390/768/1024/1440px without root overflow or out-of-frame controls; 320px full-page screenshot visually reviewed. This is not proof of checkbox save/rebuild behavior. Created exactly one synthetic QA booking 116 for approved client 2 through service/client/format/date/available-slot controls and Save; reload preserved Requested status and 2026-10-20 14:42 CRM time (09:42 UTC). Scenario event 179 was processed by the real runtime. One immediate medical-profile delivery (action/delivery 325) has one successful attempt, immutable template version 476 (`medical-profile-after-booking`) and actual provider message reference 529. Client-side visible text, actual CTA payload and CTA click/authentication are NOT VERIFIED by these database records alone.

Reverse evidence: opened Actions → Cancel → confirmation dialog, entered a synthetic QA cleanup reason and confirmed. Reload displayed Cancelled. PostgreSQL shows both future medical actions 323/324 cancelled, and their deliveries suppressed with `booking_changed`, zero attempts. They were scheduled at exactly 24h/2h before the UTC visit. The delivered immediate attempt remains unchanged; history was not deleted. Reopening the real create form showed the same 14:42–15:42 slot available again. This proves creation/cancellation/availability restoration for this QA path, not real due-time delivery, rescheduling, completion suppression or all roles. Local screenshots/helpers are under `/Users/ivankorakin/.codex/local-artifacts/chuklov-platform/2026-09-26/deploy-ec32044.EmaiT4/` and are not published.

Saved genuine Portal access was attempted through the protected medical-profile page and returned HTTP 401: the normal session expired. No replacement session was forged, lifetime was not weakened and no repeated challenge was generated. Private reusable access remains stored; CRM native state reuse previously succeeded without another owner login. Portal browser acceptance of this new candidate needs normal reauthentication, independently of implementation and CRM/worker checks.

Cancellation event 180 subsequently reached Processed through the real runtime: client Telegram delivery has message reference 530, specialist Telegram delivery 531, and CRM database notification Delivered; each has exactly one successful attempt with the recorded immutable version. Recipient-visible cancellation text/CTA clicks remain NOT VERIFIED. The synthetic booking is left Cancelled, with its two unsent medical reminders suppressed and its audit/delivery history retained.

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
Historical checkpoint status: **VERIFIED** based on earlier acceptance work. Current-pass result is recorded in the owner-directed section above.
Preserve: ClientNotesRelationManager and ClientNote.
Evidence: synthetic client create/edit/reload, PostgreSQL persistence, tenant authorization and safe note audit metadata.

### Source and individual criteria

- L2-01.1: source: «11.08.26 (1)». Historical checkpoint claim: CRM create/edit/reopen and PostgreSQL persistence on a synthetic client; current-pass evidence is recorded above.
- L2-01.2: source: «**Поле «Примечание» в карте клиента**». Historical checkpoint claim: the note field remained visible with its saved value after reload/reopen.
- L2-01.3: source: «В карточке клиента нужно поле Примечание (можно писать разные особенности)». Historical checkpoint claim: create, edit, empty-input validation, persistence and tenant denial were exercised.

Client outcome: Поле «Примечание» в карте клиента, with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-02

Чёрный список (раздел в клиентах)

Source: LIST 2, №2.
Historical checkpoint status: **VERIFIED** in the earlier acceptance notes. Current-pass status is **FAIL** until the successful Portal booking after unblock is directly captured; see the owner-directed section above.
Preserve: ClientRestrictionType::Blacklist and ClientsTable.
Evidence: reason, status/filter, actual Portal denial, unblock, subsequent booking and reload.

### Source and individual criteria

- L2-02.1: source: «11.08.26 (2)». Historical checkpoint claim — blacklist add/remove lifecycle completed in the CRM and Portal.
- L2-02.2: source: «**Чёрный список (раздел в клиентах)**». Historical checkpoint claim — blacklist controls are present and state survives reload.
- L2-02.3: source: «Возможность поместить клиента в чёрный список, с указанием причины в поле Примечание \- были случаи когда люди просто в не адеквате бывали или не заплатили и убежали и прочее.». Historical checkpoint claim — reason was entered; authorized booking was denied with an actionable message; historical notes claimed booking after unblock, not reproduced in the current pass.
- L2-02.4: source: «Добавить статус «Чёрный список» в фильтре списка клиентов». Historical checkpoint claim — CRM filter returned the blocked client and reflected removal after unblock.

Client outcome: Чёрный список (раздел в клиентах), with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-03

Быстрая запись на несколько сеансов

Source: LIST 2, №3.
Before: **NOT IMPLEMENTED**. Current implementation remains the existing Portal/CRM multi-session booking flow.
Current status: one source-metadata leaf `VERIFIED`; four behavior leaves have direct hosted PostgreSQL + Chromium/WebKit evidence, but their authenticated staging-browser slice is `BLOCKED` because the approved Portal QA session is expired and no genuine Telegram client session is available.
Preserve: Existing CRM multi-booking; Portal flow; medical data is requested only after the selected sessions are confirmed.

### Source and individual criteria

| Requirement ID | Exact source expectation | Implemented path and regression test | PostgreSQL evidence | Browser/staging journey | Error, audit and diagnostic evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| L2-03.1 | LIST 2 §3 heading «26.09.26 (3)» | Source inventory/date marker only; no product component or action is requested | N/A: the date marker requests no product state | The original LIST 2 section/date marker was reconciled against its recorded hash; no UI label was invented | No action, error or audit applies | VERIFIED — source metadata only |
| L2-03.2 | «Быстрая запись на несколько сеансов» | Portal `BookingCreate.vue`, `BookingSessionSelection.vue`, `useBookingSessions.ts`; shared `CreateMultipleBookings`; `tests/Feature/MilestoneFourCrmBookingTest.php`; `tests/e2e/portal.spec.ts` | PostgreSQL 18 staging: companion bookings 162/163 persisted; primary stale batch left zero rows; companion rows were canceled and verified absent. Hosted run 39907031745 independently exercised rollback and corrected persistence | Synthetic Portal identities used normal email verification. Chromium desktop + WebKit mobile: choose two dates, add/remove/reselect, submit a stale conflict, read the specific Russian denial, correct and retry; continue to medical profile, reload/back/forward/reopen, cancel and confirm the slot returns to availability | `booking.multiple.slot_unavailable` retained event/operation/actor/entity/slot/error/outcome/time context after the redaction fix; no partial booking or medical data | VERIFIED |
| L2-03.3 | «Возможность записи сразу на несколько сеансов (до ввода данных)» | `BookingCreate.vue`, `useBookingSessions.ts`, `CreateMultipleBookings`; `MilestoneFourCrmBookingTest::test_multi_booking_conflict_rolls_back_the_whole_batch_and_identifies_the_slot`; `PortalBookingRemediationTest::test_multi_session_conflict_rolls_back_bookings_consents_intents_and_events`; `tests/e2e/portal.spec.ts` | Stale batch rejection was atomic on staging PostgreSQL; corrected two-session create persisted before any medical data; read-only state showed no primary booking after failure | Chromium/WebKit reached medical onboarding only after selected times were confirmed; conflict retained booking context and correctable selection; reload/reopen and Back/Forward retained expected result | No false success; structured event correlated the denial to the safe actor/entity context and omitted protected payload | VERIFIED |
| L2-03.4 | «После выбора даты/времени кнопка «+» добавить ещё сеанс» | `BookingSessionSelection.vue` plus `useBookingSessions`; E2E `September 26 multi-session booking explains a stale slot and succeeds after replacement` | Persisted corrected sessions matched the selection; cleanup removed synthetic rows and retained audit history | Actual add action, date switch, remove/reselect, return to schedule, correction and retry were exercised in both engines; hosted responsive widths 320/360/390/768/1024/1440 passed in run 39907031745; direct staging used Chromium desktop and WebKit mobile | Stale error cleared after correction; the final submit showed completion without manual refresh | VERIFIED |
| L2-03.5 | «Уже после выбора всех временных сеансов переходить к запросу мед данных» | Portal confirmation and medical-profile continuation; `BookingController`, `CreateMultipleBookings`; `tests/e2e/portal.spec.ts` | Exactly two corrected Portal bookings persisted before medical continuation; cleanup verified zero residual bookings | Chromium/WebKit opened medical data only after confirmation; reload/reopen/Back/Forward preserved result; cancellation freed availability. The unchanged full success journey from run 39904968997 was reused rather than repeated | Failure stayed in booking with actionable Russian reason; success followed backend completion; diagnostic event contains no medical plaintext | VERIFIED |

Runs 39906384999 (FAIL: the test used the wrong Russian CTA name) and 39906746599 (FAIL: the test incorrectly counted a selected calendar slot as a queued item) are harness/assertion failures, not product defects. Both browser projects reached the stale-slot message before the bad assertions. Corrected run 39907031745 passed both projects on exact app SHA `a06b76fc`. Supplemental run 39933996677 is now `completed/success` on older SHA `3db382f81e2f0a04b21084cb1096c921614c9422` (GitHub job `Playwright desktop and mobile`: success); it is not exact-current-head evidence. The logging fix `035319c0` was independently covered by a focused regression and direct failure verification on the then-current staging runtime; current runtime is `3809ece3`.

The hosted runner used an isolated PostgreSQL database; synthetic fixture state was reset with the runner. Direct staging acceptance used separate normal-email-verified synthetic identities, not a server-side injected Portal session. It completed on then-current L2-03 runtime `035319c003e2e36c68a79d74136483b6cdbf4d54`; deployment from `3db382f81e2f0a04b21084cb1096c921614c9422` created and validated a PostgreSQL backup, ran no migrations, passed protected-host/health checks, and `scripts/staging-smoke.sh` passed on that exact runtime. CR-01 deployment later superseded it with current runtime `3809ece3df9275bbf78afdce81839af8be2a2e08`. L2-03.2–L2-03.5 are closed; no L2-03 staging replay is needed.

## L2-04

Запись нескольких членов семьи одним пользователем

Source: LIST 2, №4.
Current pass: 6 leaves `VERIFIED`; 4 leaves `BLOCKED`; 0 `FAIL`; 0 remaining without classification. Parent status is incomplete because the source explicitly leaves recipient, legal and business decisions open.
Preserve: party size is a separate group-booking feature. Family-member booking and protected medical delegation are different decisions.

### Source and individual criteria

| Requirement ID | Exact source expectation | Implementation and regression protection | PostgreSQL / authoritative state | Browser/staging journey | Error, audit and diagnostic evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| L2-04.1 | «26.09.26 (4)» | LIST 2 date marker only; no user action or separate UI label is requested | N/A | Reconciled to the original LIST 2 section and recorded source hash | No action, error or audit applies | VERIFIED — source metadata only |
| L2-04.2 | «Запись нескольких членов семьи одним пользователем» | LIST 2 heading; concrete behavior is specified by .3–.7 | N/A | Source heading reconciled; no product behavior inferred from the heading | No independent action or audit applies | VERIFIED — source metadata only |
| L2-04.3 | «При записи своих членов семьи пользователем:» | LIST 2 introduction; concrete behavior is specified by .4–.7 | N/A | Source introduction reconciled; no separate UI step is requested | No independent action or audit applies | VERIFIED — source metadata only |
| L2-04.4 | «Кнопку "Выслать приглашение" на сеанс члену семьи можно сделать в виде реф ссылки на бота, и когда пользователь зайдёт, ему придёт уведомление о предстоящей записи и уведомление для заполнения анкеты с мед данными.» | `Family.vue`, `FamilyController::invite`, `IssuePatientInvitation`, `AcceptPatientInvitation`, `MaterializeScenarioEvent`; `PatientProfileTest::test_missing_telegram_configuration_does_not_create_or_audit_an_invitation_and_retry_is_safe`; `test_accepting_invitation_materializes_upcoming_booking_notice_and_reminders_once_for_linked_account` | Staging created/replaced/revoked the expected invitations; hosted PostgreSQL test materialized the upcoming-booking notice/reminders once. No invited patient accepted during the synthetic Portal owner-account journey. | On staging, clicked «Выслать приглашение»; injected missing-bot configuration returned a localized 503 and enabled retry; retry with the same request key succeeded. The actual «Отправить в Telegram» click called `Telegram.WebApp.openTelegramLink` with the expected share payload. No Telegram message was sent and no recipient chat was available. | `portal.family.invitation.failed` records operation, organization, actor, patient entity, provider, safe error code, outcome and UTC time; the test asserts no invitation/audit row on missing configuration and safe retry. No token or medical text is logged. Recipient-visible booking/questionnaire delivery remains unavailable without the QA Telegram client. | BLOCKED — external recipient-visible notification |
| L2-04.5 | «Если пользователь привязал члена своей семьи по ссылке, то он может выбрать его, чтобы не вводить все его данные.» | `BindPatientInvitationToAccount`, `AcceptPatientInvitation`, `ListPatientProfiles`, `PatientProfileProjection`; `PatientProfileTest::test_invitation_binds_accepts_replays_and_revokes_without_transferring_or_deleting_history`; family invitation E2E | Isolated PostgreSQL E2E persisted `accepted_at`, `accepted_by_client_id` and the linked patient account; replay and wrong-account denial are covered by feature tests | Hosted run 39919591187 clicked the invitation confirmation UI, showed the missing-consent message, corrected it, accepted, reloaded and opened the linked «Дочь» identity. The booking form selected the persisted patient ID and retained it after reload/Back/Forward. Chromium desktop and WebKit mobile both passed. Staging also proved owner-side family-profile selection; recipient binding was exercised in the isolated PostgreSQL browser run. | Missing confirmation showed a localized field error; expired/revoked/wrong-account and replay paths have distinct feature assertions and safe diagnostics. Acceptance audit records the patient profile and accepted account, not medical contents. | VERIFIED |
| L2-04.6 | «Нужно тогда в кабинете в личных данных отображать привязанных членов семьи и возможность отвязать / привязать новых.» | `ListPatientProfiles`, `PatientProfileProjection`, `FamilyController`, `IssuePatientInvitation`, `RevokePatientRelationship`; `PatientProfileTest` and staging-reset feature tests | Staging PostgreSQL showed the owned family profile and three invitation attempts with two replaced/revoked, then final unlink. QA clients 134/135 and temporary family/invitation rows were reset; invitation and reset audit history remains. | Staging Chromium/WebKit opened the Portal family-data screen, created, edited, reloaded and reopened the profile, then unlinked through confirmation. Reload showed the revoked state, retained reinvite/add-member actions and removed the booking action. CRM user 19 saw the owner-family row and opened the patient CRM card. No horizontal overflow at 320/360/390/768/1024/1440; WebKit interaction was checked at 390px. | Unlink confirmation and success state were visible; patient relationship, invitation, profile and staging-reset audit events remained after synthetic QA cleanup. No invitation token or protected content was logged. | VERIFIED |
| L2-04.7 | «А также при записи показывать и выбирать уже привязанных.» | `ListPatientProfiles::forBooking`, `SelectBookingPatient`, `BookingPatientAccounts`; `PatientProfileTest` and Portal booking feature/E2E tests | PostgreSQL E2E persisted selected `patient_profile_id`; staging selection/reload read-back used the family-profile projection. No booking was submitted during staging QA. | Staging Chromium/WebKit selected a family profile, deselected/reselected it, reloaded and traversed Back/Forward with the patient choice preserved. Hosted run 39919591187 repeated selection for the accepted linked identity and retained it through reload/history. | Invalid/missing patient selection is field-validated; foreign account and tenant use are denied by feature tests. No medical plaintext is exposed in the booking projection or audit. | VERIFIED |
| L2-04.8 | «Вопрос 1: высылать уведомления о записи и конечному клиенту и тому, кто его записал» | Source explicitly asks a question; current recipient resolver is not treated as accepted business policy | N/A until owner decides recipient policy | No recipient behavior can be accepted against an unresolved expected outcome | No policy-specific success/failure or recipient audit claim is made | BLOCKED — owner business decision |
| L2-04.9 | «Вопрос 2 (серьёзный): безопасность и доверительное право! Анкету по здоровью заполняет только тот, кому пришла ссылка, если это взрослый (а не тот, кто записывает). А если ребёнок — то пользователь может заполнить анкету за него! Нужно изучить, что по этому поводу говорит Российское и международное право! Возможно кто-то из взрослых также может дать согласие на доступ к своей мед карте другому. Я слышал, что такое допускается (у нас через госуслуги), актуально, например, если взрослый доверяет своему члену семьи, часто родители детям или муж жене и пр. Но эту механику нужно продумать также технически!» | Source assigns the adult/minor and delegated-medical-access policy to a legal/owner decision; implementation must not invent it | N/A until approved policy and consent rules exist | No legal consent scenario was fabricated or exercised with protected medical data | Current feature tests prove invitation identity/tenant constraints only; they do not claim legal authorization to delegate a medical chart | BLOCKED — owner/legal decision |
| L2-04.10 | «Ответ: Женя изучит и скажет как» | Explicitly deferred by the source to the owner | N/A | No product behavior can be inferred from the deferred answer | No audit or user status is applicable until the decision exists | BLOCKED — owner business decision |

**Current L2-04 totals:** 6 VERIFIED, 0 FAIL, 4 objectively BLOCKED, 0 unresolved machine-verifiable leaves. The blocked slices are recipient-visible Telegram delivery (.4), recipient policy (.8), legal medical delegation (.9), and the explicitly deferred owner answer (.10).

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
Before: **PARTIAL**. After: **IMPLEMENTED**.
Preserve: Existing ServiceCredit and FX authority.
Implemented: selected client display currency is projected consistently in header, referral cabinet and applicable booking/payment surfaces through existing Finance FX; immutable base-currency accounting and FX evidence are unchanged. Authenticated Portal reload and external redemption observation remain NOT VERIFIED.

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
Historical checkpoint status: **VERIFIED** based on earlier acceptance work. Current-pass result is recorded in the owner-directed section above.
Preserve: SchedulingConfiguration address label already corrected.
Evidence: exact label, CRM save success, reload persistence, new-booking address prefill and PostgreSQL snapshot preservation.

### Source and individual criteria

- L2-13.1: source: «26.09.26 (13)». Historical checkpoint claim — save/reload, downstream CRM prefill and PostgreSQL state were directly checked.
- L2-13.2: source: ««Адрес по умолчанию» в настройках расписания \- переименовать в «Адрес приёма по умолчанию»». Historical checkpoint claim — exact visible label matches the source text.

Client outcome: «Адрес по умолчанию» в настройках расписания \- переименовать в «Адрес приёма по умолчанию», with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-14

Настройки расписания

Source: LIST 2, №14.
Before: **PARTIAL**. Current final pass: L2-14.7 and 14.10–14.14 are VERIFIED; 14.1–14.6 and 14.8–14.9 still require direct evidence.
Preserve: Scheduling commands, impact preview and timezone authority.
At the L2-14 checkpoint, staging ran `140a5b0bf3fea2daf908b0e85c33fd992008c985`. The L2-14.7 journal filter/reset/history journey and the previously verified unavailable-time and travel-day journeys are recorded in the current-pass table. Staging later advanced through L2-03 fixes to `035319c003e2e36c68a79d74136483b6cdbf4d54`, then through CR-01 to `3809ece3df9275bbf78afdce81839af8be2a2e08`. Do not replay leaves 14.10–14.14 solely for fresh timestamps.

Historical Journal layout, ownership and timezone evidence (current L2-14.7 label/history evidence is recorded in the owner-directed table above):

- Root cause: the previous multi-specialist projection flattened per-specialist intervals and bookings into shared day arrays, dropping specialist ownership. The create URL also omitted `specialist_id` in multi/all mode, allowing the create form to fall back to another specialist. Equal clock times were valid separate slots but rendered as indistinguishable duplicates; different schedules were visually merged.
- The projection now keeps specialist groups and lane ownership through intervals, free slots, bookings and applicable schedule changes. Every create link carries the owning `specialist_id`; the create/reschedule path validates it against the organization and preserves it after reload. The existing desktop weekly grid remains visible at `lg+` for one, several and all specialists; mobile cards remain mobile-only.
- The `23:00–00:00` and `00:00–10:00` ranges came from real cross-timezone schedule values and a midnight segment boundary. Stored DB values, DB timezone `Etc/UTC`, organization timezone `Asia/Almaty` and specialist timezone projections were inspected. The authoritative conversion is applied once; no double conversion was found. The exact midnight boundary ends at display minute 1440 instead of 0. No schedule data was changed.
- Hosted PostgreSQL feature tests and the focused local Journal suite passed, including ownership, different schedules, overlap, midnight projection, booking binding, cancelled-slot availability and tenant filtering. The invalid Journal prefill regression also passes.
- Chromium and WebKit staging browser runs passed at 320, 360, 390, 768, 1024 and 1440px. The all-specialist and A+B views had no page overflow, retained the desktop grid at 1024/1440px, kept only selected lanes, kept the dropdown open while selecting checkboxes, and produced zero unbound create links. Focused hosted run [39643361069](https://github.com/murlockwarlock/chuklov-platform/actions/runs/39643361069) passed the current branch's Journal scenario in both browser projects.
- The earlier Journal layout/ownership slice ran on staging SHA `e9dafd30ab4eb83a470dba92a20211fdbe88f0e6`; its geometry and booking-ownership evidence remains reusable because that L2-14 continuation changed only the filter accessible name/history synchronization. The exact `140a5b0b` deployment, smoke and L2-14.7 browser proof were current at that checkpoint; the later L2-03 runtime `035319c0` was superseded by current runtime `3809ece3`. Owner acceptance remains **NOT RUN**.
### Source and individual criteria

- L2-14.1: source heading «26.09.26 (14)» is the LIST 2 grouping marker, not a requested CRM label or action. It was reconciled to the numbered source leaves without inventing a screen element. **VERIFIED — no separate product behavior is requested by this marker.**
- L2-14.2: source label «Настройки расписания» is the visible title and navigation label; the current page displays it after reload/reopen. **VERIFIED**.
- L2-14.3: the «Настройки расписания» navigation item is last within «Записи»; actual sidebar order was clicked and recorded in Chromium/WebKit. **VERIFIED**.
- L2-14.4: general settings and specialist regular intervals are distinct routes/screens; the settings form has general fields and no weekday interval, while Working Intervals provides its interval action and no general lead-time field. Browser and focused regression test verified the split. **VERIFIED**.
- L2-14.5: the heading and copy at the top of the general settings page were checked in the rendered CRM page and after reload. **VERIFIED**.
- L2-14.6: the exact general-settings copy is «Здесь настраиваются общие параметры для всего расписания»; Working Intervals and Work Schedule show their corresponding specialist-specific/calendar copy. Chromium/WebKit and the focused feature regression checked it. **VERIFIED**.
- L2-14.7: source: «Журнал записей \- добавить по умолчанию просмотр журнала для всех доступных специалистов, а выбор специалистов \- сделать фильтром выпадающим списком с чек-боксами, чтобы можно было просмотреть журнал сразу для нескольких.». Starting SHA had a single-specialist `<select>`, while this source criterion requires default-all and multi-checkbox behavior. Current candidate runtime `140a5b0b` visibly and accessibly shows «Все специалисты» when reset, retains simultaneous checkbox selection, and restores filter URL/state together on browser history. Exact hosted and staging evidence is in the current-pass row above. **VERIFIED.**
- L2-14.8: Work Schedule top heading is «График работы специалиста», preserved after reload/reopen and protected by the focused schedule-page test. **VERIFIED**.
- L2-14.9: source text is incomplete: «Раздел перенести сразу после «» does not identify the anchor. The next line is a separate unavailable-time instruction; no source or owner decision supplies the missing item. **BLOCKED — owner must identify the navigation anchor.**
- L2-14.10–14.11: exact CRM placement, removal of the standalone section and date-range mutation are evidenced in the current-pass table above. **VERIFIED**.
- L2-14.12–14.14: exact heading and selected-specialist relationship are evidenced by staging PostgreSQL row 15 and the Chromium/WebKit create-edit-reload journey in the current-pass table above. **VERIFIED**.

Client outcome: Настройки расписания  , with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: candidate SHA `4d1956fc3e79da3e43676455a5d9eff31842d954`, hosted run `39617748548`, local artifacts under `/Users/ivankorakin/.codex/local-artifacts/CRM_Evgeniy/`, screenshots kept outside Git.

## L2-15

В карточке «Медицинский профиль» пользователя разделить поля:

Source: LIST 2, №15.
Before: **PARTIAL**. After: **IMPLEMENTED**.
Preserve: Existing encrypted complaints/goals/operations/injuries.
Implemented: split complaints/goals and operations/injuries fields, legacy-history compatibility and Client/Session projection regressions. Full all-role browser acceptance remains separate.

### Source and individual criteria

- L2-15.1: «26.09.26 (15)» is the LIST 2 heading/date marker and asks for no separate screen behavior. **VERIFIED — source metadata.**
- L2-15.2: the Portal Medical Profile and authorized CRM clinical-profile card display complaints/goals separately from operations/injuries; saved fields remain distinct after reopening. **VERIFIED.**
- L2-15.3: complaints (symptom chips and free-text detail) and health goals (separate textarea) were edited independently, saved, reloaded, reopened and projected to CRM; the supplement is reconciled to LIST 1 clinical specification. **VERIFIED.**
- L2-15.4: operations and injuries have independent Yes/No controls and conditional details; correction and field-specific year validation were exercised, and the saved distinct values survived reload and CRM projection. **VERIFIED.**

Client outcome: В карточке «Медицинский профиль» пользователя разделить поля:  , with exact source wording, fields, order and action destination above.
CRM outcome: same authorized business facts, persisted after reopening; no hidden duplicate or divergent authority.
Backend/DB/notification outcome: existing organization-scoped authority; atomic mutation/audit where required, UTC instants and explicit notification transport. Clinical plaintext excluded from general logs/audit/storage.
Positive staging: real action, persisted result, counterpart view and reload for every numbered criterion above.
Negative staging: invalid/missing input, wrong actor/tenant/client, duplicate and stale operation; cancel/correct and provider retry/failure for stateful flows.
Evidence: pending candidate SHA, test/run ID, synthetic record references, screenshots kept outside Git.

## L2-16

Редактировать шаблон сообщения для рассылки. Не закрепляется фото/видео в сообщении

Source: LIST 2, №16.
Existing staging success is preserved: reopen → Preview → signed media HTTP 200 → test-send to exactly one QA client → provider attempt 1 recorded as delivered with reference → reload/reopen. The local media suite covers video, replacement/removal, invalid MIME/size, provider failure/retry and no duplicate; thirteen focused tests passed on branch HEAD `0a169d5c` (60 assertions). No production broadcast was sent.
Current leaf status: L2-16.1 VERIFIED as source date metadata. L2-16.2 BLOCKED only for independent recipient-visible Telegram chat observation. The current approved profile has a QA CRM session and Portal client session but no Telegram client session or chat-history access. The staging provider acknowledgement is not treated as proof that the recipient UI was independently observed.
Preserve: Existing broadcast/template media authority.

### Source and individual criteria

- L2-16.1: «26.09.26 (16)» is a LIST 2 grouping/date marker; it does not request separate UI behavior. **VERIFIED — source metadata.**
- L2-16.2: the media-template edit, save, reopen, preview and one-recipient QA test-send are directly evidenced on staging, with photo/video persistence and safe provider failure/replay tests. Independent client-side rendering in the Telegram recipient chat is **BLOCKED** because no authorized QA Telegram client session is available; the provider's `delivered` result is not substituted for that observation.

The user-facing template, private media and provider-attempt behavior are evidenced in the current-pass row above. The exact provider failure presentation includes a safe attempt reference in the CRM; failed test-send does not create a success audit. Sanitized provider failures, bounded retry and minimized suppressed-recipient context passed focused tests. No content, token or recipient PII is copied into the public audit/log fields. This continuation sent no message and made no staging write. Remaining external evidence is the QA recipient's actual Telegram chat view.

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
- CLINICAL.11: «•	Элемент интерфейса: Текстовое поле (Textarea) для фиксации установленных диагнозов. Предусмотреть чекбокс «Хронических заболеваний нет».». **VERIFIED** — explicit none state saves, survives reload/reopen and projects to CRM; browser and regression evidence are listed in the owner-directed pass ledger above.
- CLINICAL.12: «•	Перенесенные операции:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.13: «•	Элемент интерфейса: Переключатель (Radio: Да / Нет). При выборе Да раскрываются вложенные поля: Год операции (Number) и Описание операции/рубца (Text: где находится шрам).». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.14: «•	Перенесенные травмы и переломы:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.15: «•	Элемент интерфейса: Переключатель (Radio: Да / Нет). При выборе Да раскрываются вложенные поля: Год травмы (Number) и Характер травмы (Text).». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.16: «•	Принимаемые лекарственные препараты:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.17: «•	Элемент интерфейса: Текстовое поле (Textarea) — постоянные аптечные медикаменты, дозировки и частота приёма.». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.18: «•	Нутрицевтики и БАДы:». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.19: «•	Элемент интерфейса: Текстовое поле (Textarea) — витамины, биологически активные добавки, сборы, микроэлементы.». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.20: «•	Медицинские документы и обследования (Опционально):». Acceptance: exact field/control, validation and authorized save/read/reopen in Portal and CRM. NOT VERIFIED.
- CLINICAL.21: «•	Элемент интерфейса: Мульти-загрузчик файлов (до 5 файлов: JPG, PNG, PDF до 20 МБ каждый). Заключения МРТ, КТ, УЗИ или врачебные выписки. Загрузка производится в защищенный приватный S3-бакет с генерацией внутреннего UUID.». **VERIFIED for the required private-storage security outcome** — five-file, format and size limits, invalid uploads, private authorization, reload and CRM counterpart were exercised using the accepted Laravel private Storage architecture; no literal S3 claim is made.
