# 26.09.2026 — requirements and staging acceptance

Starting main SHA: `c201b41a14d91c57c1890e62737f9e2001a231f1`.
Branch: `codex/26-09-full-implementation`. PR #59 is separate.

Sources: original Downloads LIST 1 DOCX (v2.4, 26.09.2026) and original Downloads LIST 2 Markdown. Quotations below were extracted directly from those files; no prior proof matrix is accepted as implementation evidence.

## Final candidate evidence — 2026-10-15

This block is authoritative for the current checkpoint. The older checkpoint notes below are retained as history and are not current status claims.

- Runtime code candidate: `94e6f534de9c8e5a7be210e4419b2270cfdc7ab6`; final docs-only candidate is the exact branch SHA reported in the Git handoff and was deployed/smoke-checked; Draft [PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60) remains unmerged.
- Exact-SHA staging code deployment `94e6f534de9c8e5a7be210e4419b2270cfdc7ab6` and the final docs-only deployment both passed `scripts/staging-smoke.sh` and `--deep`; app, PostgreSQL, Redis, Horizon, scheduler, Telegram runtime and queue identity are healthy.
- Technical implementation included in this candidate: patient identities/profiles with booking-for-whom, invitation acceptance/replay protection/revocation and separate medical-history ownership; client booking personal data with country-scoped city lookup and phone normalization; clinical CRM projections; per-format scheduling buffers; unified unavailable-time and specialist schedule projections; display-currency bonus projections through existing Finance FX; and managed broadcast media.
- Hosted PostgreSQL/Concurrency: integration foundation `141 passed / 547 assertions`, concurrency `106 / 642`, RAG `8 / 89`; no candidate-induced PHPStan additions relative to checkpoint `2828d5184fc39716ec979cb7d5c73d0bf59a7820` (194 remaining baseline diagnostics, one baseline diagnostic removed). Hosted Quality run [38625353168](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38625353168) ran against the unchanged runtime code candidate `94e6f534` and is **FAIL** only because the same three baseline feature failures, two unchanged Pint files and baseline PHPStan diagnostics remain; frontend lint/typecheck/build and Composer/npm audits PASS. The final documentation-only changes do not alter runtime code. No rules or assertions were weakened.
- Exact staging CRM browser proof on runtime code SHA `94e6f534`: actual click → navigation succeeded for `/admin/bookings`, `/admin/work-schedule`, `/admin/working-intervals`, `/admin/location-days`, `/admin/schedule-exceptions`, `/admin/scheduling-configuration` and `/admin/broadcast-campaigns`; Chromium and WebKit geometry at 320/360/390/768/1024/1440 had no page overflow, outside controls or page errors. The final docs-only deployment was redeployed and smoke-checked.
- Exact staging broadcast proof: campaign 15 was reopened; actual Preview rendered one media item through an authorized signed endpoint (HTTP 200, no private path leak), actual test-send to the single QA client displayed delivery confirmation, and reload retained the campaign. Read-only PostgreSQL evidence showed `draft`, media present, test recipient `delivered`, attempt 1, completion and provider reference, with no error. The campaign is explicitly QA-only and does not target production recipients.
- Hosted browser [38625359316](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38625359316) was dispatched against this exact SHA and finished **FAIL**: 26 passed, 12 failed and 2 skipped across the desktop/mobile CRM suite. The failures are existing CRM selector/fixture issues (including a strict duplicate `Закрыть` selector and missing expected fixture heading), so this is not full browser acceptance. The genuine Portal browser state has expired, so authenticated Portal/Telegram-WebView recipient-visible journeys remain **NOT VERIFIED**. Provider-level Telegram payload/delivery evidence exists, but recipient-visible Telegram UI was not independently observed.
- Objective blockers only: approved Evgeny welcome/Stories media, authoritative nine-systems/MSQ content, and owner/legal adult/minor medical-delegation rules. These do not block the implemented technical family identity/invitation/revocation infrastructure, but they do block the corresponding full acceptance claims.

Initial statuses describe bounded source inspection, not final staging proof. Every criterion still requires its named observable journey; a green test or a route response alone is not VERIFIED.

## Shared evidence contract

The authoritative current candidate is `94e6f534de9c8e5a7be210e4419b2270cfdc7ab6`, deployed to staging. The implementation is complete for every unblocked technical slice in the requested scope; acceptance remains open where the evidence or owner decision is explicitly marked below. The current 29-item status table is the source of truth. Historical checkpoint notes follow this section and are retained only to explain earlier gaps.

- CR-01/02: managed web-app/story media controls and temporary QA media are implemented; approved author media and recipient-visible real Telegram/WebView launch/playback remain blocked/not verified.
- CR-03/04/06/07/08/09/10/11/13: implementation and focused regressions are complete; full browser/provider proof is limited where the Portal session expired or a future-due delivery was not observed.
- CR-05: the three-state Health Action engine is implemented; authoritative nine-systems/MSQ content remains an owner asset blocker.
- CR-12 and LIST 2 №4: patient identities, booking-for-whom, separate medical-history ownership, invitation acceptance/replay protection/revocation and tenant/client guards are implemented. Adult/minor medical delegation remains blocked pending owner/legal rules.
- L2-06: header, referral/bonus cabinet and applicable booking/payment projections use the selected display currency through the existing Finance FX authority; ServiceCredit accounting and immutable evidence remain in organization base currency.
- L2-14: unified unavailable time, Journal specialist filters, specialist-bound travel/location days, regular intervals and schedule settings are implemented and route/click/geometry checked on staging.
- L2-16: image/video upload, private persistence, replacement/removal, preview, reload and QA-only test-send delivery are implemented and exercised on staging; real recipient-visible Telegram delivery remains not independently observed.

Final static comparison: [Quality run 38625353168](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38625353168) has zero candidate-induced PHPStan diagnostics relative to checkpoint `2828d5184fc39716ec979cb7d5c73d0bf59a7820`; 194 diagnostics remain baseline, and Pint still reports two unchanged baseline files. No static-analysis rules, exclusions or assertions were weakened. Frontend lint/typecheck/build and dependency audits PASS. Hosted full browser run [38625359316](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38625359316) is FAIL (26 passed, 12 failed, 2 skipped) from existing CRM selectors/fixtures, so it is not claimed as full acceptance.

First candidate: `676c4da3436e30f0268aff2245db61958edb3a6f`, [Draft PR #60](https://github.com/murlockwarlock/chuklov-platform/pull/60), no merge. [Hosted CI](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38389322189): unit, integration foundation, integration concurrency, RAG, Docker/runtime and privacy gates PASS; Quality FAIL (13 feature failures, remaining static/build gates not reached). [Focused browser gate](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38389447699): 4 PASS / 2 FAIL across Chromium/WebKit. Medical and non-medical booking continuation passed; the profile/medical journey found symptom-row overflow. This is not staging acceptance.

Follow-up changes address wrapping, confirmed submit waiting, missing Mini App configuration, mandatory profile fields, feature-disabled self writes, private-file uploads and PHP multipart limits. Quality steps retain all original checks but execute independently after schema readiness, so a feature failure cannot conceal type/build/security outcomes. A single [starting-main Quality comparison](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38397769054) distinguishes baseline failures from candidate defects. Focused attachment/profile/health/Telegram/CRM tests: 70 tests / 661 assertions PASS; new upload replay/private download/forged-key checks: 3 tests / 32 assertions PASS. Final candidate rerun pending.

Staging baseline smoke stopped at an exact-SHA mismatch (main c201b41; deployed f3509f8), so no candidate staging acceptance is claimed. Near-limit 20-MB medical uploads require multipart headroom in PHP and the existing staging nginx limit; exact-limit runtime proof is pending. The original source requests S3, but accepted architecture uses the existing private Storage authority: no parallel file subsystem or S3 acceptance claim is made.

Owner answers: original videos unavailable; temporary QA media permitted, with managed CRM uploading required. Telegram QA identity: Murlock Warlock. No concrete family consent/guardianship policy was supplied. Adult medical delegation and minor representative access must not be inferred from relation or an invitation.

Every criterion requires visible trigger → real click/input → authorization → Application action → PostgreSQL state → CRM/client projection → relevant provider effect → reload → permitted change/cancel/correction. Negative evidence includes foreign tenant/client, unauthorized actor, invalid input/state, duplicate submit, stale UI and provider failure where applicable. Browser widths: 320, 360, 390, 768, 1024, 1440. Check full-page overflow; desktop Chromium and mobile WebKit. No destructive testing on staging, real medical data, real payments or bulk delivery. Owner acceptance is separate.

### Follow-up implementation and preservation checkpoint

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

Read-only staging REVISION is `f3509f8ce957082045f3e140cd4a08809d1c3751`, which is not an ancestor of Starting SHA. Owner explicitly authorized selective transfer of necessary PR #59 runtime fixes/regressions, including Telegram internal client CTA buttons, without merging/closing #59 or importing its proof/audit artifacts. Staging deployment remains pending integration and affected verification; it must not silently roll back this delta.


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

## Summary — current candidate

`IMPLEMENTED` means the code and focused regression contract are present; it is not a claim that every external/client-visible journey was observed. `STAGING VERIFIED` is limited to the exact observable slice named in its evidence. `BLOCKED` is reserved for the three objective source blockers listed above.

| Requirement | Before | Current status | Evidence |
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
| [L2-04](#l2-04) | BLOCKED BY OWNER/LEGAL DECISION | BLOCKED | Technical family identity/invitation/revoke infrastructure is present; protected adult/minor delegation rules are not inferred. |
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
Before: **BLOCKED BY OWNER/LEGAL DECISION**. After: **IMPLEMENTED** for technical identity/invitation/revocation; protected medical delegation remains **BLOCKED BY OWNER/LEGAL DECISION**.
Preserve: Party size is separate group-booking feature.
Implemented technical scope: patient selection in booking, account/owner relationship, invitation issue and acceptance, idempotent replay rejection, unlink/revoke, separate patient history and tenant/client guards. Missing: only the legal permission/consent matrix for adult/minor protected medical data.

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
Before: **PARTIAL**. After: **STAGING VERIFIED** for the exact CRM route/control slice.
Preserve: Scheduling commands, impact preview and timezone authority.
Implemented and clicked on exact staging SHA: unified unavailable-time control, regular working intervals, specialist-bound travel days, all-specialist/multi-specialist Journal filters, separate schedule settings and no duplicate misleading controls. PostgreSQL schema/concurrency tests pass; complete mutation lifecycle is documented separately where not exercised with the QA user.

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
Before: **PARTIAL**. After: **IMPLEMENTED**.
Preserve: Existing encrypted complaints/goals/operations/injuries.
Implemented: split complaints/goals and operations/injuries fields, legacy-history compatibility and Client/Session projection regressions. Full all-role browser acceptance remains separate.

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
Before: **PARTIAL**. After: **STAGING VERIFIED** for the QA-only media lifecycle.
Preserve: Existing broadcast/template media authority.
Exact staging proof: reopen → Preview → signed media HTTP 200 → test-send to the single QA client → delivered provider attempt 1/reference → reload. Local 75-test media suite covers video, replacement/removal, invalid MIME/size, provider failure/retry and no duplicate. Production broadcast is untouched; recipient-visible Telegram client observation remains NOT VERIFIED.

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
