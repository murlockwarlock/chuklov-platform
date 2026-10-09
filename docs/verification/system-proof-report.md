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

### Companion and staff reply

Synthetic `Привет, ты кто?` completed through Redis and real AI with conversation active, not automatic human handoff. Client UI submitted `Хочу поговорить с человеком`; timeline showed specialist requested and the accepted notice that AI continues until staff joins. CRM `Подключиться к диалогу` exposed the reply form; client timeline showed deliberate staff takeover. CRM reply was visible to the client; CRM resume restored the join action. Exact message/provider-delivery/state counts remain to be reconciled from scoped synthetic evidence.

REAL BUG: Portal displayed literal `<p>…</p>` around the staff reply. Existing CRM composer stores canonical HTML; the Portal Markdown renderer did not receive its already-supported sanitized-HTML prop. Regression failed on missing `contentHtml`. Minimal remediation adds sanitized HTML only for Staff using existing `RichTextDocument` and `SafeRichText`; AI/client content remains untrusted Markdown/text. Regression proves encrypted storage, preserved emphasis/link, stripped script, and null trusted-HTML projection for client/AI. New actual CRM-button → client browser regression was added. Hosted PostgreSQL/browser and exact-SHA staging recheck remain mandatory before calling the fix accepted.

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
2. E2E 34 failures must be classified and rerun after correcting stale tests or real defects; two clinical provider cases are skipped.
3. Quality gate is failing on baseline formatting; independent later checks must execute and report their own outcome, without suppressing that failure or editing deployed migrations for cosmetics.
4. Staff reply formatting fix needs hosted PostgreSQL/E2E and staging exact candidate acceptance.
5. Final staging revision, smoke, scheduler, private storage and all eight cross-system stories are not yet fully proved.
6. External gaps are the configured Groq HTTP 401, absent external email mailer, absent Lava sandbox credential, and unexecuted real Telegram incoming/Mini App auth. Adapter fakes are not real provider PASS.

Conclusion remains **SYSTEM NOT FULLY VERIFIED**. Audit work continues; this report is not a final gate or an owner-acceptance assertion.
