# Full system proof

Starting SHA: `c201b41a14d91c57c1890e62737f9e2001a231f1`.
Branch: `codex/full-system-proof`. Merge is prohibited.

## Evidence policy

**SYSTEM NOT FULLY VERIFIED**. This is an active audit. Source declarations, existing tests, executed assertions, browser interactions, staging acceptance and real provider effects are separate evidence classes. No inherited PASS from a previous candidate counts. The matrix initially records declaration-level inventory with explicit missing contracts. Those rows must be reconciled before a complete capability count or coverage percentage can be claimed.

The inventory guard detects new and stale mapped source declarations. It does not prove runtime reachability, vendor-inherited buttons, arbitrary dynamic action factories, state transitions, notification delivery or business correctness. No complete-inventory claim is made until those have been reconciled.

## Current source reconciliation

Current code and the owner backlog/changelog override old milestone statements. `PROJECT_STATUS.md` contains historical entries, including deferred payment statements, that do not override the currently wired Lava adapter. Roles are Owner, Administrator and Staff; there is no Manager enum. Staff has some application permissions but `OrganizationRole::canAccessAdminPanel()` allows only Owner and Administrator. Browser fixtures named “staff” must be checked for their actual membership role.

Attachment quarantine was removed from the accepted current flow (ADR-021); private validated attachments are immediately usable by authorized actors. Quarantine is not an implemented acceptance target.

## Unresolved scope

| Classification | Capability | Exact reason |
| --- | --- | --- |
| NOT IMPLEMENTED | Source-backed 9-systems and MSQ questionnaires/scoring | Authoritative question/scoring material is missing; existing survey builder is not that methodology. |
| NEEDS OWNER DECISION | Source-backed clinical Road Map/Tracker methodology | Current entitlement/check-in/configurable task code is implemented and is in the audit; missing clinical methodology must not be invented. |
| NOT IMPLEMENTED | Direct file opening/downloading from the Portal Companion message bubble | The reader returns file name/type metadata, but the current bubble renders only attachment count. Signed medical-file access exists separately. The audit does not add a new chat file-download feature. |
| OUT OF SCOPE | Family/dependent accounts | Current party size represents one booking; separate dependent identity/account architecture is not the accepted implementation. |
| OUT OF SCOPE | MAX/Instagram | Future channel adapters. |
| OUT OF SCOPE | Production deployment and merge | Explicitly prohibited by task and repository phase. |

## Execution record

## Inventory accounting

The current source guard maps 1,410 declarations: 267 CRM actions/filters, 80 Blade controls, 84 inherited CRUD form controls, 43 explicit navigation labels, 91 resource routes, 124 screens, 32 resources, 11 custom submit methods, 156 Portal controls, 82 HTTP declarations, 9 Telegram handlers, 11 keyboard factories, 16 menu entries, 16 jobs, 14 commands, 12 scheduler entries, 5 adapter declarations, 23 bindings, 21 metric declarations, 32 notification catalog events and 281 enum references. The enum references are not user capabilities. Navigation, routes and their controls can represent the same capability, so 1,410 is not a unique implemented-capability count. Dynamic/inherited controls and notification delivery contracts remain to be reconciled. The mapping guard passes with zero unmapped/stale declarations within its documented scanner boundary.

## Executed candidate evidence

| Candidate / run | Result | Limit |
| --- | --- | --- |
| Starting-main-derived `1a1434af28ee5c04cd64eb7bcdea9d26ab0bbb0e`, CI [37968485171](https://github.com/murlockwarlock/chuklov-platform/actions/runs/37968485171) | PostgreSQL foundation, RAG, concurrency, Docker runtime and privacy passed; quality failed on ten stale fixture/expectation contracts | Not a product acceptance PASS; failed quality prevented later gates |
| `1a1434a`, E2E [37968489768](https://github.com/murlockwarlock/chuklov-platform/actions/runs/37968489768) | 21 passed, 15 failed, 2 skipped | CRM file only; empty workflow input selected its default, not all files |
| `6c7d7d6a1e52931b7a2503d452dcabce847296b4`, CI [37974428024](https://github.com/murlockwarlock/chuklov-platform/actions/runs/37974428024) | 211 unit / 1,062 assertions; 2,102 feature / 14,015 assertions; one intentional SQLite migration-compatibility skip. PostgreSQL foundation, RAG and independent-process concurrency passed; Docker runtime and privacy passed | Overall FAIL: four baseline Pint issues stopped quality before static analysis/types/build/dependency audits |
| `6c7d7d6`, all current E2E files [37974431894](https://github.com/murlockwarlock/chuklov-platform/actions/runs/37974431894) | 68 passed, 34 failed, 2 skipped, 104 cases | FAIL, not complete browser proof. Clinical real-provider journeys were skipped. Each failed path needs reconciliation and rerun |
| Working candidate: focused new security + Booking transition + AI/scheduling regressions | 88 passed / 856 assertions on SQLite | PostgreSQL confirmation is the candidate CI above; future edits require their own relevant evidence |
| Working candidate: staff reply formatting, Companion readers/workspace and staging guards | 36 passed / 388 assertions on SQLite | New HTML projection and guard need hosted exact-candidate and staging verification |
| `de1b2465b9c3305ca001ef8ed56334af03087152`, CI [38020022970](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38020022970) | 211 unit / 1,062 assertions; 2,107 feature / 14,052 assertions; one intentional compatibility skip. PostgreSQL foundation, RAG, concurrency, Docker/runtime, privacy, frontend lint and build passed | Overall FAIL: baseline Pint, 304 static-analysis errors, HTML-prop type mismatch introduced by this candidate, and dependency advisories. Each gate executed independently |
| `1030a504a4459c11b5b1d3acca7bfefcbdd20808`, CI concurrency [38020322738](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38020322738) | PASS, including independent-process identical-manual-link save race | Final candidate quality/browser/staging still pending |
| `de1b246`, complete E2E [38020025060](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38020025060) | 86 passed, 18 failed, 2 skipped; 106 cases. The real CRM reply → Portal rendering regression passed on desktop and mobile | Remaining failures include messages composer/attachment, medical files, community editor, Portal navigation/referral/payout/scroll/legal and scenario-history assertions; each needs classification |
| `5b7e13894bce6e2a362432d314a4ed3a4c5a9dc0`, CI [38020898822](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38020898822) | 211 unit / 1,062 assertions; 2,108 feature / 14,062 assertions; one compatibility skip. PostgreSQL foundation 139 / 539, RAG 8 / 89, concurrency 102 / 622 passed. Docker/privacy, frontend lint/types/build and both dependency audits passed | Overall FAIL: four baseline Pint issues and 304 static-analysis errors remain; no suppression added |
| `45caad8e9cb3b4755b3f08c594c46a5145382579`, complete E2E [38021115244](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38021115244) | 94 passed, 12 failed, 2 skipped; 108 cases | Six failing families on desktop/mobile: compact composer, attachment upload timing, repeated-link toast, service-card navigation, payout-history tab and Companion polling/history. Targeted corrected checks run on `55ef9ef`; result pending |

Raw diagnostics, JUnit and screenshots stay outside Git under `~/.codex/local-artifacts/chuklov-platform/full-system-proof/`. They are execution aids, not a substitute for the per-action matrix. Only the requested matrix/report, tests and repository-owned proof infrastructure are published.

## Local wave ledger

These are focused subsystem signals, not complete acceptance rows. Tests exercise business outcomes, but passing a file does not verify every UI action or real provider.

| Wave | Local execution | Outstanding reconciliation |
| --- | --- | --- |
| 1 identity/auth/tenant | 59 tests, 388 assertions; route-wide security guard later passed | Full per-action permission/foreign-selector/attachment boundary mapping |
| 2 clients/catalog/specialists/locations | 92 tests, 732 assertions | Every CRUD/filter/inactive/correction button in real browser |
| 3 scheduling | Initial 141 of 143 passed; two journal fixtures remediated and focused rerun passed; new status/format matrix 35 cases passed | Full Office/Home/Online, extended block and all boundary browser journeys |
| 4 finance/analytics | 93 tests, 534 assertions | Independent expected-value proof for every displayed metric; full browser correction/FX paths |
| 5 gifts/referrals/partners | 64 tests, 443 assertions | End-to-end transfer/auth/claim/redeem/correction and all cabinet actions |
| 6 medical/session/surveys | 120 tests, 733 assertions | All private-file/browser actions and state transitions; source-backed methodologies unavailable |
| 7 Companion/AI/Knowledge | Initial 122 of 124 passed; two stale AI expectations remediated; focused rerun passed | Real failure/retry, long answer/RAG, ingestion and privileged control-plane journeys |
| 8 scenarios/broadcast/feedback/tracker/B2B/content | 274 tests, 1,792 assertions | Event-by-event notification contracts and every current UI family |
| 9 cross-system/staging | Bounded provider and browser paths below | Eight complete mega journeys and exact final SHA still pending |

## Staging targets inspected and actual effects

Candidate `45caad8e9cb3b4755b3f08c594c46a5145382579` was deployed successfully after the streamed-probe repair. `staging-smoke.sh --deep` passed on that exact SHA: health, scheduler container, Telegram API reachability, matching app/Horizon Redis identity, CRM/Portal paths, private storage, RAG ingest/retrieve/provenance, revision-history rendering and supported retirement of the synthetic source. This is candidate evidence; the final audit SHA is not yet fixed.

The scoped PostgreSQL evidence for synthetic client 77 confirms booking 112 has `party_size=2`, status Cancelled and event_version 4, with Created/client → StatusChanged/user → Rescheduled/client → Cancelled/client history. Required consent evidence is version `2026-09-03-default-v1`, marketing is false. Booking scenario events are processed; internal actions include delivered outcomes, and client actions include suppressed/cancelled outcomes. There is no financial obligation for this unpriced service. The two Companion turns are Completed, the human-request escalation is resolved, and message bodies are encrypted with plaintext fields null. Client suppression is not proof of successful external delivery.

Initial remote revision: `66a7edc3e04c5b5028d292e4a4201b0e628cb54f`, not a final audit candidate. Repository and staging configuration, smoke identities, provider credentials/bindings, fixtures and existing scripts were inspected before target selection. No secret values are included here.

| Integration | Current wiring and real evidence | Remaining gap |
| --- | --- | --- |
| PostgreSQL / Redis | Configured `pgsql` and Redis queues; synthetic Companion turn processed by a real worker | Exact final-SHA runtime/scheduler/storage smoke and full per-row reconciliation |
| Telegram | Existing smoke client binding returned permanent `telegram_chat_not_found`; existing verified staff acceptance binding delivered one controlled message with Mini App button and provider reference | Incoming command/web authentication/Mini App button click not established by outgoing send. No safe synthetic client Telegram binding found |
| Email/auth | Configured auth transport is `array`, not SMTP. One synthetic OTP captured by actual sink; authentication succeeded; replay rejected. Real Portal request/verify/profile UI exercised | External mailbox delivery is NOT VERIFIED because staging has no external auth mailer, not because owner failed to provide a mailbox |
| Zoom | Configured account/host and active credential. Created synthetic meeting `79783690311`; identity/correlation/start/duration/timezone matched; updated +1 hour / 20 minutes; cancelled that meeting and verified absent | Booking/B2B UI-to-job meeting lifecycle and reminder link not yet complete; adapter success is not that journey |
| Payments | Gateway selects `fake`, enabled; no Lava organization credential. Current Lava adapter has no sandbox-mode switch | Real Lava initiation/webhook/sandbox charge NOT VERIFIED. No real monetary charge was made |
| AI | DeepSeek/OpenAI authenticated provider probes passed; Groq rejected its bound credential with HTTP 401. Synthetic greeting completed via queue without handoff; replay returned same turn | Groq operational failure unresolved; probes are not inference proof for every model/capability. RAG/clinical/evaluation real-provider paths incomplete |
| Storage | Configured private local disk | Full private download/signed-URL/retirement/cleanup staging journeys still pending |

Synthetic client `77` and temporary synthetic CRM operator `17` were created. The operator has Administrator membership; this does not prove Staff can access CRM. No mass broadcast, real charge, destructive reset, customer-data deletion or production action was performed. The temporary operator access must be revoked after acceptance; historical synthetic audit data is retained.

## Actual two-sided staging journeys

### Profile, consents and group booking

Portal email form request/verification entered the authenticated shell. Profile save set `Synthetic acceptance 20261009`; CRM client detail showed the same name. Required legal consents were saved with optional marketing unchecked. Portal selected service, Office format, working location, date and slot, set `party_size=2`, accepted required documents and submitted. Booking `112` appeared in CRM with confirm action and in client detail with reschedule/cancel. CRM confirmation opened its modal and submitted a synthetic comment; confirm disappeared after success. Client reschedule selected the next slot; both sides showed the same record/history. Client cancellation removed further actions; CRM showed the terminal record. Refresh was exercised through repeated real navigation.

Accepted group semantics are one Booking and one unchanged Finance lifecycle. Party size does not automatically multiply service duration or price; an extended blocking interval is staff-controlled. This particular service was unpriced, so the journey is not evidence for priced group Finance invariants. Extended blocking and concurrent conflicts require separate proof.

Booking detail was captured at 320, 360, 390, 768, 1024 and 1440px; document `scrollWidth <= clientWidth` held at each width. That inequality alone is not full visual acceptance. Screenshots must additionally be inspected for sticky-header scroll positioning, clipping and action accessibility. It does not verify every Portal/CRM screen.

### Online lifecycle and finance

On staging `45caad8`, synthetic service 29 was created through CRM with all three formats, 60 minutes, 100 EUR and AfterSession payment, then assigned to specialist 1 through the actual assignment form. Portal created Online booking 113 at 12 October 11:00 Asia/Bangkok; CRM confirmation produced an automatic Zoom link and the client's Join button. Premature completion was rejected with the explicit planned-end-time message; the booking remained Confirmed. CRM cancellation removed client reschedule/cancel controls. Provider cancellation outcome still needs scoped evidence; absence of the client button alone is not provider cleanup proof.

CRM backdated Office creation first rejected missing explicit backdate confirmation, then created booking 114 after the checkbox was selected. Confirmation and completion exposed manual payment and created the 100 EUR obligation. Portal showed unpaid 100, then cash 25 / outstanding 75, then cash 25 + transfer 30 / outstanding 45. Correcting cash restored outstanding 70 while the original payment and correction remained in Portal history. These are synthetic ledger entries, not real monetary transfers. Independent ledger/audit/analytics counts and the remaining methods still need reconciliation.

REAL BUG: manual overpayment 76 against remaining 75 kept the modal open without a visible message. Application errors used bare `amount`, while Filament rendered `mountedActions.<index>.data.amount`. The new regression failed on the missing mounted-field error; the minimal adapter change maps business validation to the mounted state path. REAL BUG: after transfer payment the summary showed paid 55 but the child history still displayed only cash 25; only navigation refreshed it. The existing refresh event had no history listener. A regression reproduced the missing listener; the child now invalidates its table records on that event. Focused regressions pass (2 tests / 16 assertions); targeted PostgreSQL/browser and exact-SHA staging verification are pending.

Candidate `65b89e2c13b0bd99a055cbd1ad620b60f6129394` deployed successfully. On that exact staging revision, the same obligation rejected 71 against remaining 70 with the human error visibly attached to the amount field. Correcting the form to manual-card 20 succeeded without reopening it. The history immediately displayed all four entries (cash 25, transfer 30, cash correction -25, card 20) and the summary agreed at paid 50 / outstanding 50; no browser refresh/navigation was used between submit and history capture. Screenshots at desktop 1440 were visually inspected. Focused Finance CRM/barter tests passed 47 / 379 assertions locally; hosted PostgreSQL and extended desktop/mobile browser results are still pending.

The next actual staging negative path exposed a raw `validation.required` key for missing barter description. The existing form required the value but supplied no localized required-message override. The regression also found non-Russian framework fallback locally. The minimal fix reuses existing RU/EN copy “Опишите, что получено взамен.”; focused Finance CRM/barter tests pass 47 / 380 assertions. Exact candidate staging/browser verification is pending. Targeted `65b89e2` E2E passed the partner payout request/cancel on both viewports; Finance tests stopped at a wrong table-container selector before exercising the new assertions (2 passed / 2 failed). The selector is corrected to the actual relation-manager wrapper, without weakening the outcome assertions.

All five current manual methods were exercised on the synthetic obligation: cash, bank transfer, card in clinic, barter with description and Other. After the original cash correction, net paid reached 100 EUR and outstanding 0; Portal showed Paid and no payment-initiation button, and CRM hid manual payment/reminder. This is not external payment settlement proof or independent dashboard validation.

Deploy of `a57b443` stopped before activation at the Compose-service preflight; staging remained `65b89e2` with Horizon running. The exact preflight fragment reproduced Broken pipe against a controlled, delayed valid service stream because `grep -q` closed the producer early under pipefail. Removing early-exit mode preserves required-service matching and consumes the complete stream. The new regression proves valid inventory succeeds and missing Telegram still fails; staging harness/deploy tests pass 18 / 257 locally. A successful new deploy remains required.

The preflight repair deployed `c79ebac157194dac031aef232e562670009177c9` successfully. On that exact revision, missing barter description now visibly shows “Опишите, что получено взамен.”; desktop screenshot inspected. Hosted Quality `65b89e2` run [38022879861](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38022879861) passed 211 unit / 1,062 assertions and 2,110 feature / 14,078 assertions with one compatibility skip, including the two new Finance regressions on PostgreSQL. Overall FAIL remains four baseline Pint issues / 304 static errors; frontend checks and dependency audits passed. Expanded Finance browser on `834b989` run [38023651813](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38023651813) passed desktop and mobile, including overpayment, retry with corrected amount, live history, correction and empty/valid barter description. The preceding mobile run exhausted the old 30-second whole-story budget after correction; the expanded multi-action story uses 90 seconds, not longer per-element waits.

The read-only streamed repository harness on staging PostgreSQL confirms obligation18 = 10,000 EUR minor units and obligation19 = 5,000 EUR minor units. Eleven exact ledger entries include all five manual methods, cash correction -2,500→entry22, Other correction -3,000→entry27, gift redemption +1,000→entry31 and its correction -1,000→entry31. Independently adding obligation18 entries gives 7,000 paid / 3,000 outstanding; obligation19 gives 5,000 paid / 0 outstanding. Those amounts agree with both current UI projections. Booking114 is Completed/version3; booking113 is Cancelled/version3, no meeting URL, provider synchronization NotRequired. Direct Zoom GET of this cancelled booking's meeting and final analytics reconciliation remain unverified. The new bounded evidence reader passed cross-organization/read-only/no-note projection regression; focused Finance tests pass 48 / 387 locally.

### Gift sale, transfer and redemption

On staging `c79ebac`, the actual CRM sale modal selected the existing test offering (50 EUR) and synthetic client77, creating purchase3 / obligation19. Unpaid and partially paid (25 EUR) sale left the client's certificate list empty. Completing payment with a second 25 EUR exposed one 50 EUR certificate. Client Gift created a pending transfer, hid redemption, Copy visibly changed to Copied, Replace created another link, and Cancel restored availability.

The same staging browser session authenticated a synthetic recipient through the configured email sink, followed the fragment-only claim link, previewed the 50 EUR certificate and received it. Reopening the used link showed the invalid/used-link message and no second movement. A self-transfer by the current holder initially reproduced a 405 Method Not Allowed. The minimal fix in `2d16ac3e82969bd08cc29f730b644214fcb9433d` redirects validation failures to canonical GET `/gift-certificates/claim`, preserves the token in the session and renders the existing human message. The exact staging click after deployment stayed on the claim page and visibly showed “Текущий владелец не может получить этот сертификат повторно.”; screenshot `gift-self-claim-fixed` was inspected at desktop 1440. The regression passed locally and the expanded browser case in hosted run [38044066149](https://github.com/murlockwarlock/chuklov-platform/actions/runs/38044066149) passed on desktop and mobile.

Read-only `synthetic-evidence` on PostgreSQL staging exact `a24a827639db2103bd6db64f9ee1da223e05a375` scoped certificate3 to the synthetic client: original/balance 5,000 EUR minor units, current holder synthetic recipient, claims 2–5 revoked, claim6 claimed and claim7 pending, with issued/transferred/claimed/redeemed/redemption-reversed movements. No token, contact, credential or private body was emitted. The projection is read-only and the local regression proves organization scope and unchanged row counts.

Portal applied 10 EUR to obligation18: certificate balance40 / debt20. CRM corrected the redemption through the actual history action; Portal then showed certificate50 / debt30. Opening certificate history preserved Issued50, transfer history, Redeemed10 and CreditBack10. CRM/client transfer, receive, replay, self-denial, correction, and the hosted desktop/mobile gift E2E are now evidenced. Full redemption, unrelated-currency UI denial, direct foreign-ID browser probes, and independent dashboard/LTV reconciliation remain open.

### Companion and staff reply

On deployed `45caad8`, real email request/OTP verification authenticated the synthetic client. The existing staff reply now renders readable text without literal HTML tags. Portal chat geometry passed at 320, 360, 390, 768, 1024 and 1440px; desktop and 320px screenshots were visually inspected. Initial capture before paint was blank and was excluded from visual acceptance; a settled capture showed the actual reply. This does not verify all other screens at those widths.

The actual CRM attachment button opened the native chooser, selected a synthetic PDF, showed its filename, completed upload and enabled Send. Submission produced the success notification and a new staff message on the client's refreshed Portal. The current bubble labels the PDF as “Изображений: 1” and has no file-opening action. The misleading image label and metadata-only client experience are explicit limitations, not a claimed file-download PASS. The CRM was returned to AI mode through its actual Resume button.

Synthetic `Привет, ты кто?` completed through Redis and real AI with conversation active, not automatic human handoff. Client UI submitted `Хочу поговорить с человеком`; timeline showed specialist requested and the accepted notice that AI continues until staff joins. CRM `Подключиться к диалогу` exposed the reply form; client timeline showed deliberate staff takeover. CRM reply was visible to the client; CRM resume restored the join action. Exact message/provider-delivery/state counts remain to be reconciled from scoped synthetic evidence.

REAL BUG: Portal displayed literal `<p>…</p>` around the staff reply. Existing CRM composer stores canonical HTML; the Portal Markdown renderer did not receive its already-supported sanitized-HTML prop. Regression failed on missing `contentHtml`. Minimal remediation adds sanitized HTML only for Staff using existing `RichTextDocument` and `SafeRichText`; AI/client content remains untrusted Markdown/text. Regression proves encrypted storage, preserved emphasis/link, stripped script, and null trusted-HTML projection for client/AI. New actual CRM-button → client browser regression was added. Hosted PostgreSQL/browser and exact-SHA staging recheck remain mandatory before calling the fix accepted.

REAL BUG: repeated save of the same manual Online meeting URL incremented event version and created another history/confirmation event. Regression observed version 3 becoming 4 on replay. The application now returns the locked unchanged booking for an identical URL, after authorization/status validation. Saving a different URL still records a replacement. The lifecycle file passes 18 tests / 111 assertions on SQLite; a new independent-process PostgreSQL race test asserts one update for concurrent identical saves. Hosted PostgreSQL and actual modal/staging proof remain pending.

Inventory infrastructure regression: nested modal-field visibility could be recorded as the action visibility. The scanner now reads only top-level fluent calls, with a failing-then-passing regression. Eight inventory tests pass. Reviewed contracts live in the matrix and are preserved by regeneration; the generator no longer overwrites them with hardcoded review data.

Candidate-induced deployment regression: the streamed preflight probe tried to require new helper files from the previous release. Deployment `1030a50` stopped before activation. The old staging revision remained active. Streaming the committed probe into the old release reproduced the missing-file fatal error; streaming the corrected probe returned the actual Redis snapshot with zero pending/delayed/reserved B2B jobs. Helper loading is now deferred to new proof commands. A subprocess regression boots the probe without those helper files; the focused staging test batch passes 17 tests / 250 assertions. A successful redeploy is still required.

Security dependency remediation: hosted audit found five PHP advisories (Filament MFA password reauthentication, Laravel debug-page XSS, two CommonMark issues, Flysystem malformed UTF-8 paths) and eight reported JS vulnerabilities. The bounded update uses Filament 5.8.2, Laravel 13.30.0, CommonMark 2.10.3, Flysystem 3.36.0 and compatible JS security fixes without new packages or major-version upgrades. Local Composer and npm audits report zero advisories/vulnerabilities. Hosted tests and staging on the resulting SHA remain pending. The HTML prop now uses `undefined`, matching the existing component contract; local typecheck and ESLint completed successfully. The previously claimed local static/type/build batch lacked retained completion evidence and must not be used as a PASS.

## State-machine proof boundaries

Booking code and the added exhaustive visibility/backend tests establish confirmation only for Requested Office/Online, completion only from Confirmed after the visit, and no-show from Requested/Confirmed after start. Invalid/repeated actions leave entity/event/audit counts unchanged. Existing PostgreSQL process tests cover same-key booking creation, overlap exclusion, stale-version reschedule and block-extension races. Other Booking paths (Home approval/rejection, cutoff cancellation, reschedule, party-size and block update) require row-specific reconciliation; no unsupported terminal reopening is invented.

Financial status is Outstanding / PartiallyPaid / Settled; payment/booking states are separate. Corrections append history. Purchase enum is PendingPayment / Paid / Refunded; fulfillment is Pending / Processing / Fulfilled / Failed. An enum member alone does not prove an exposed refund transition. Gift issue/claim/transfer/redemption invariants are distinct, not a fabricated single status machine. Partner Active/Inactive and payout Requested/Approved/Rejected/Paid/Cancelled are separate from ordinary ServiceCredit. Survey InProgress/Completed references pinned versions; completed history is not an editable draft.

Scenario actions and deliveries have different scheduled/processing/retry/delivery/suppression/failure states. Broadcast campaign Draft/Scheduled/Dispatching/Completed/Cancelled differs from recipient Pending/Suppressed/Claimed/Delivered/Failed. AI, Companion, ingestion/extraction/revision/cleanup and video-provider synchronization each have independent state enums and fencing rules. No all-transition PASS is inferred from a job being alive or a page rendering. Feedback, Session, tracker entitlement and attachments must be derived from their actual fields/actions rather than inventing a common status enum.

## Subsystem acceptance gaps

The following table is an explicit work ledger. Subsystem test PASS is not full capability VERIFIED.

| Subsystem | Client / CRM current surface | Negative/reverse/permission/concurrency signal | Exact remaining proof |
| --- | --- | --- | --- |
| Identity / security | Email, Telegram web/Mini App linking, profile; privileged CRM login | Executed auth/replay/rate/session/tenant tests and route-wide denied direct requests | Every authorized counterpart, foreign selector/search/download and real Telegram inbound |
| Clients / catalog / locations | Client profile; CRM clients, restrictions, notes, contacts, catalog, specialists, assignments, working locations/days | Executed typed forms, archive/inactive, stale schedule, tenant tests | Real CRUD/button/filter coverage and cross-side catalog render |
| Booking / scheduling | Guided Portal selection, my visits/detail; CRM journal/actions/calendar | Executed PostgreSQL exclusion/version/idempotency races; actual group forward/confirm/reschedule/cancel | Home approve/reject/deposit, Online UI, multi-booking, explicit extension, DST, error/back/double-submit and notifications |
| Finance / analytics | Portal debts/receipts; CRM obligations/manual methods/correction/settings/metrics | Executed ledger/FX/rounding/barter/gateway/projection tests and PostgreSQL money races | Every displayed metric independently calculated; all browser methods/correction/debt and exact staging amounts |
| Gifts | Client holder/transfer/claim/apply; CRM catalog sale/issue/history | Executed partial/full issue, claim/replacement/correction and PostgreSQL overspend tests | Complete gift mega journey with auth handoff, both UI perspectives and delivery |
| Referrals / partners | Ordinary link/bonus; partner links/stats/payout; CRM assignment/replacement/rewards | Executed base-currency/history/cross-tenant and attribution/reward/payout races | Each cabinet tab/action, replacement stale winner and complete qualifying activity story |
| Medical / session / surveys | Health, private files, questionnaire/results; CRM clinical cockpit/session/definitions/attempts | Executed privacy/encryption/IDOR/history/report tests | Actual session/attachment/upload/download/correction UI and each available questionnaire type; 9-systems/MSQ not implemented |
| Messages / Companion / AI | Portal chat/safe actions; CRM takeover/reply/resume, provider/prompt/run/evaluation admin | Executed failure-no-auto-handoff, retry, safety, paused backlog, encryption and PostgreSQL fence tests | Staff HTML defect exact-SHA acceptance; long/RAG/failure/retry/attachments and all AI admin actions |
| Knowledge / RAG | Connected AI answers; CRM source/revision/ingestion/inspector | Executed PostgreSQL retrieval scope/timeouts/claim/cleanup tests | Real upload→ingest→retrieval→answer provenance, private access and failure/retry browser evidence |
| Notifications / scenarios | Transactional/client messages; CRM rules/templates/catalog/history | Executed consent/dedup/retry/outcome/concurrent materialization tests | Every event's recipient/channel/template/conditions/consent/link and actual delivery row mapping |
| Broadcasts | Controlled recipients; CRM audience/preview/test/start/history | Executed consent/tenant/crash/claim/dedup tests on isolated DB | Bounded synthetic-recipient UI journey and partial failure/retry; never mass-send |
| Legal / feedback | Versioned documents/required vs optional consents; rating/comment; CRM definitions/submissions/settings | Executed immutable history/tenant/idempotent feedback tests; actual optional-marketing-off booking | Every legal publication/version refresh and feedback positive/negative/internal-comment UI path |
| Tracker | Current plan/entitlement/task/check-in/history; CRM settings | Executed entitlement/task/scenario/privacy tests | Actual enrollment/daily/periodic UI and scheduler notification; no invented clinical methodology |
| B2B / content | Lead/professional segmentation, RU/EN sections; CRM leads/Zoom/content | Executed funnel/timezone/provider failure/content tests; real Zoom adapter lifecycle | Complete lead→CRM→meeting notification journey and every active/inactive/order/translation section |

## Unresolved execution blockers

1. Matrix contract/test/evidence reconciliation is unfinished; source declarations still contain NOT DERIVED/NOT MAPPED fields. Therefore no complete implemented-capability count or VERIFIED percentage is claimed.
2. Complete E2E on `45caad8` has 12 failures / 94 passes / 2 clinical-provider skips. Targeted reconciliation on `55ef9ef` passed 10 of 12; the remaining two payout-history assertions expected the success message instead of the actual Requested status label and were corrected. A fresh targeted run includes that correction and the newly reproduced Finance UI regressions; no complete-suite PASS is claimed.
3. Quality gate is failing on baseline formatting; independent later checks must execute and report their own outcome, without suppressing that failure or editing deployed migrations for cosmetics.
4. Staff reply formatting has hosted logic/browser and actual staging acceptance evidence, but the wider Messages/Companion/attachment/AI action inventory is not fully verified. The two Finance UI fixes have local regression and exact-SHA desktop staging evidence; hosted PostgreSQL/mobile evidence remains pending.
5. Final staging revision, smoke, scheduler, private storage and all eight cross-system stories are not yet fully proved.
6. External gaps are the configured Groq HTTP 401, absent external email mailer, absent Lava sandbox credential, and unexecuted real Telegram incoming/Mini App auth. Adapter fakes are not real provider PASS.

Conclusion remains **SYSTEM NOT FULLY VERIFIED**. Audit work continues; this report is not a final gate or an owner-acceptance assertion.
