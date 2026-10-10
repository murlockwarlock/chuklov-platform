# Full system proof report

This report is the A-to-Z acceptance audit for the current implementation on
branch codex/full-system-proof. It separates source coverage, canonical
capabilities, automated outcomes, browser behavior, staging effects and
unavailable external identities.

## Scope and source of truth

- Starting SHA: c201b41a14d91c57c1890e62737f9e2001a231f1
- Branch: codex/full-system-proof
- PR: #59, Draft/Open, not merged
- Current hosted candidate: 4b4c9fd9c9a4d18dd607f52963d0ef1eddd141c5
- Final SHA: the exact commit containing this report and the regenerated matrix; the final handoff prints and deploys it.

Current owner-accepted behavior, requirements/changelog and backlog override
historical material. Planned 9-systems/MSQ scoring, a clinical Road Map
methodology and a new Portal Companion file-opening feature were not invented.

## Coverage accounting

### Source inventory

The machine inventory contains 1,410 declarations. It is source coverage, not
the acceptance denominator.

| Category | Count | Reconciliation |
| --- | ---: | --- |
| CRM actions/filters | 267 | duplicate/internal representation mapped to a canonical flow |
| Blade controls | 80 | duplicate/internal representation |
| inherited CRUD controls | 84 | duplicate framework representation |
| navigation labels | 43 | duplicate screen representation |
| resource routes | 91 | duplicate capability representation |
| screens | 124 | mapped to page/action capability |
| resources | 32 | mapped to CRUD/domain capability |
| custom submits | 11 | mapped to action |
| Portal controls | 156 | mapped to client action |
| HTTP declarations | 82 | route representation |
| Telegram handlers/keyboards/menus | 36 | channel representation |
| jobs/commands/scheduler | 42 | durable-workflow/internal representation |
| adapters/bindings | 28 | integration/internal representation |
| metrics | 21 | dashboard representation |
| notification catalog events | 32 | notification contracts below |
| enum references | 281 | internal state vocabulary, not capabilities |
| Total | 1,410 | 1,410 reconciled, 0 unmapped |

The executable guard is:

    node scripts/system-proof-inventory.mjs --update
    node scripts/system-proof-inventory.mjs --check

The latest run reports 1,410 source declarations, 157 canonical capabilities
and zero mapping errors. The detailed appendix is
docs/verification/system-source-inventory.md. The primary matrix contains only
the 157 deduplicated canonical rows.

### Canonical status

| Status | Count |
| --- | ---: |
| Canonical capabilities | 157 |
| Implemented and testable denominator | 154 |
| VERIFIED | 149 |
| NOT VERIFIED | 5 |
| NOT IMPLEMENTED | 2 |
| NEEDS OWNER DECISION | 1 |

Coverage is 149 / 154 = 96.75%. It is intentionally not reported as 100%.
The five NOT VERIFIED rows are unavailable external executions; their
application adapters, validation, authorization, errors, retries and fake
provider paths are separately verified.

## Quality and database gates

| Gate | Evidence | Result |
| --- | --- | --- |
| Local PHPUnit | php artisan test --compact: 2,325 tests, 15,174 assertions, 2,324 passed, 1 skipped | PASS |
| Local focused waves | 336 tests, 2,286 assertions | PASS |
| Local Pint | vendor/bin/pint --test | PASS |
| Local PHPStan | vendor/bin/phpstan analyse --memory-limit=1G | PASS, 0 errors |
| Hosted Quality | run 38057199422, exact SHA 4b4c9fd | PASS; 211 unit + 2,113 feature tests, 1 skipped; formatting, static analysis, frontend lint/types/build and audits pass |
| PostgreSQL foundation | run 38057327354 | PASS: 139 tests / 539 assertions |
| PostgreSQL RAG | run 38057327354 | PASS: 8 tests / 89 assertions |
| PostgreSQL concurrency | run 38057327354 | PASS: 102 tests / 622 assertions |
| SQLite | local suites above | PASS, one intentional compatibility skip |

The PostgreSQL jobs cover FK/check/unique/exclusion constraints, JSON/JSONB,
pgvector, tenant scope, ledger arithmetic, transactions/savepoints, locks,
leases, idempotency, queue contracts and concurrent writers. No destructive
staging reset was used.

## Browser evidence

The full Playwright run at exact candidate SHA is run 38057210596. It runs
Chromium and WebKit against CRM and Portal projects and completed with 40
passed and 2 skipped in 8.4 minutes. Communities' mobile
saved-preview replacement was a synchronization defect in the test: the
regression now waits for editor hydration/network idle and invokes the native
mobile action. Targeted desktop/mobile run 38055781665 also passed. There is
no unexplained browser product failure in the current candidate.

Portal acceptance probes exercised 320, 360, 390, 768, 1024 and 1440px and
asserted document scroll width. Hosted browser evidence is authoritative:
local browser startup could not authenticate because the local PostgreSQL role
was absent. No local browser PASS is claimed.

## Subsystem proof

Every row in docs/verification/system-proof-matrix.md is an observable
capability with preconditions, visible/hidden conditions, variants, state and
database effects, notification, UI refresh, reverse/correction, retry,
concurrency and tenant/security columns. The following records the actual
cross-system result.

### Identity, tenant and security

Email OTP and Telegram initData/deep-link authentication are independent.
OTP is normalized, hashed, expiring, single-use and replay-protected; session
rotation follows successful auth. Telegram signature/freshness/replay,
allowlisted destinations and browser binding are tested. Policies and
application actions enforce organization scope; direct foreign IDs are denied
server-side. PostgreSQL tests cover cross-organization reads/mutations,
protected medical data, private storage, signed URLs, audit events, masked
credentials and webhook replay. Staging direct probes returned 404/403 without
mutation. A second staging organization was not present, so the cross-tenant
claim uses authoritative PostgreSQL integration evidence.

### CRM clients, catalog and locations

Client CRUD/search, notes, blacklist/self-booking restriction, contact and
Telegram identity, attribution/referrals, medical profile, services,
specialists, assignments, working locations, days, exceptions and unavailable
periods are canonical rows. Valid-state actions are visible; inactive records,
invalid selectors, malformed input and insufficient permissions are rejected.
Feature tests, hosted CRM screens and PostgreSQL constraints prove persisted
reload and organization ownership. Staff membership is not treated as a
Manager role; Owner/Administrator panel access follows the actual enum.

### Booking and scheduling

Office, Home Visit, Online, group party_size, multi-booking, availability,
regular schedule, days off, exceptions, unavailable periods, buffers, lead
time, timezone and extended blocking are covered. Portal creation and CRM
confirmation see one authoritative record. Confirm/reject/cancel/complete/
no-show/reschedule are state-gated; invalid/repeated transitions preserve
history and do not duplicate effects. PostgreSQL exclusion/version/idempotency
tests cover overlap, touching intervals, stale versions and concurrent
writers. Identical manual Online links are unchanged/idempotent; a different
link creates a replacement history item. Current group semantics are one
booking/finance lifecycle. Home review and Online ready/failure are covered in
application/browser suites; external Zoom status is listed below.

### Finance and analytics

Cash, card, bank transfer, barter and Other cover unpaid/partial/settled/debt,
overpayment rejection, description validation, multiple partial payments,
corrections and idempotent retries. Ledger entries are append-only and
corrections retain the original. Booking/purchase amount, paid/outstanding,
status, receipts, debt, average receipt and LTV projections are reconciled to
minor-unit PostgreSQL ledgers by analytics tests. Staging synthetic evidence
reconciled all five manual methods and gift redemption; no real transfer or
charge was made.

### Gift certificates, referrals and partners

CRM sale, unpaid/partial/full payment, exactly-once issue, holder/balance/
history, transfer replace/cancel, claim/auth handoff/replay/self/wrong-user/
wrong-org denial, partial/full redemption, same-currency and correction are
verified. PostgreSQL concurrency proves one issue/claim and no overspend.
Hosted gift E2E proves desktop/mobile transfer/claim/replay/apply/self-denial.
Referral attribution, one-level ServiceCredit, balance redemption, Partner
assignment, campaigns, PartnerCash and payout lifecycle have feature and
PostgreSQL evidence; ServiceCredit is not mislabeled cash payout.

### Medical, sessions, surveys and attachments

Medical fields, pain/VAS splits, photos/documents, private attachments, session
cockpit, notes/results/completion and survey definition/attempt/progress/
required validation/scoring/report/repeat/history are covered. Completed
attempts and session history remain immutable. Direct private file access is
authorization and tenant checked. CRM attachment upload/send passes; Portal
Companion direct file opening is explicitly NOT IMPLEMENTED. The authoritative
9-systems/MSQ source is absent, so the generic survey builder is not claimed
as that methodology.

### Messages, Companion, AI and Knowledge

Client text appears in CRM; staff replies render sanitized trusted HTML in
Portal; client/AI content remains untrusted Markdown. Upload/send, encryption,
private storage and CRM authorization pass. Companion useful/long/RAG/retry/
technical failure/safety/human request/staff join/reply/resume paths are
covered. Technical AI failure never creates automatic handoff. PostgreSQL
tests prove active-execution and retry/takeover fencing. OpenAI and DeepSeek
real staging probes pass. Groq is the owner-confirmed invalid credential and
is only an external NOT VERIFIED row. Knowledge source, ingest/failure/retry/
retirement, pgvector retrieval/provenance and inspector scope pass.

### Notifications, scenarios and broadcasts

The 32-event contract table below is derived from the current catalog. Booking,
payment, reminder, survey, Companion, Zoom/B2B and tracker events are
transactional/service events and do not require marketing consent. Broadcast
marketing requires current consent. Durable scenario actions claim/lease/
retry/dedupe and delivery history retain recipient/channel/template/outcome/
destination. Provider failure classifications do not create unsafe duplicates.
Broadcast preview/audience/bounded recipient send/partial failure/retry were
tested without mass traffic.

### Feedback, Tracker, B2B and content

Feedback rating/comment/empty-comment/routing/review destinations/idempotency
pass. Tracker plan/version, enrollment, check-in, task/history and scheduler
dedupe pass; source-backed clinical Road Map methodology is NEEDS OWNER
DECISION. B2B lead/segmentation/timezone/CRM/Zoom failure paths pass.
RU/EN About/Method/content section order/inactive/missing behavior and rich
link replacement pass; internal keys are not rendered.

### Operations and integrations

Queue workers, Horizon Redis identity, scheduler, private storage, webhook
signature/replay, reconciliation and bounded cleanup pass staging harnesses.
Zoom configured adapter create/read/update/cancel passes. Email application/
OTP behavior passes through the array sink; external SMTP is unavailable.
Payment domain/webhook/idempotency passes with the fake gateway; Lava external
is unavailable. Staff Telegram outbound passes; no safe client inbound account
was discovered, so only that client external row remains NOT VERIFIED.

## Notification contract matrix

All rows below are real catalog events. Durable delivery history uses the
event/action/recipient/channel identity for dedupe; adapter results classify
delivery as delivered, suppressed, retryable or failed. Links are the
organization-scoped route carried by the event payload (CRM/Portal/booking/
payment/payout/attempt/source/tracker as applicable).

| Event | Recipient | Channels | Template | Consent / condition | Dedupe, retry, history |
| --- | --- | --- | --- | --- | --- |
| companion.requested_specialist | permitted CRM staff | CRM, Telegram | specialist request | transactional | durable permission filter, retry and history |
| companion.specialist_attention | permitted CRM staff | CRM, Telegram | attention required | transactional | durable retry/history |
| companion.fallback_failed | handling CRM staff | CRM | AI failure diagnosis | technical | failure classification/history |
| broadcast.delivery_failed | responsible staff | CRM, Telegram | delivery failure | operational | failed history and bounded retry |
| booking.created | client, specialist | CRM, Telegram | new booking | transactional, no marketing consent | dedupe/retry/history |
| booking.confirmed | client, specialist | CRM, Telegram | confirmation | transactional | dedupe/retry/history |
| booking.rescheduled | client, specialist | CRM, Telegram | moved booking | transactional | dedupe/retry/history |
| booking.rejected | client | Telegram | rejected booking | transactional, disabled by default | history if enabled |
| booking.cancelled | client, specialist | CRM, Telegram | cancelled booking | transactional | dedupe/retry/history |
| booking.home_visit.changed | client, responsible staff | CRM, Telegram | home visit changed | transactional, disabled by default | history if enabled |
| booking.completed | client | Telegram | after visit | transactional | dedupe/retry/history |
| feedback.submitted | responsible staff | CRM | new feedback | transactional, disabled by default | CRM history if enabled |
| referral.payout.requested | finance staff | CRM | payout request | transactional | dedupe/retry/history |
| referral.payout.status_changed | finance staff, partner | CRM, Telegram | payout status | transactional | dedupe/retry/history |
| survey.completed | test staff | CRM | survey completed | transactional | dedupe/retry/history |
| TEST_STAGNATION_DETECTED | test staff | CRM | repeat-test review | transactional | dedupe/retry/history |
| b2b.lead.submitted | B2B staff | CRM | new B2B request | transactional | dedupe/retry/history |
| b2b.sales_call.ready | prospect, B2B staff | CRM, Telegram | B2B call ready | transactional | dedupe/retry/history |
| ai.evaluation.failed | AI staff | CRM, Telegram | evaluation failure | operational, disabled by default | failure history |
| knowledge.ingestion.failed | knowledge staff | CRM | source processing failure | operational | retry/retirement history |
| referral.link.visited | none by default | none | do not send | attribution only | attribution history |
| payment.provider.event.prepared | none | none | unused | internal compatibility | no delivery |
| finance.payment.succeeded | client | Telegram | payment received | transactional | dedupe/retry/history |
| finance.obligation.reminder_requested | client | Telegram | payment reminder | transactional; marketing optional | dedupe/retry/history |
| finance.payment.failed | client | Telegram | payment failed | transactional | dedupe/retry/history |
| finance.payment.initiation_unavailable | finance staff | CRM, Telegram | payment configuration | operational | dedupe/retry/history |
| finance.payment.reconciliation_required | finance staff | CRM, Telegram | reconciliation | operational | dedupe/retry/history |
| commerce.fulfillment.failed | client, finance staff | CRM, Telegram | access not issued | transactional/operational | durable failure/retry history |
| commerce.fulfillment.completed | client | Telegram | access ready | transactional | dedupe/retry/history |
| referral.reward.earned | client | Telegram | referral reward | transactional | dedupe/retry/history |
| tracker.task.daily_assigned | eligible tracker client | Telegram | daily task | service/transactional | scheduler dedupe/retry/history |
| tracker.task.weekly_assigned | eligible tracker client | Telegram | weekly task | service/transactional | scheduler dedupe/retry/history |

Evidence: NotificationCatalogTest, OperationalNotificationDeliveryTest,
PaymentScenarioNotificationsTest, MilestoneFiveDeliveryRemediationTest,
MilestoneElevenBBroadcastTest, MilestoneTwelveTrackerPostgresTest, hosted
CRM/Portal/scenario E2E and run 38057327354.

## Mega-journey ledger

| Journey | Client sees | CRM sees | DB/effect | Notification/reverse | Result |
| --- | --- | --- | --- | --- | --- |
| A New client | OTP, profile, consent, booking, feedback | client, booking, session, feedback | identity/booking/consent/finance/session suites | transactional booking/reminder; cancellation/correction history | VERIFIED by hosted Portal/CRM and staging synthetic path |
| B Returning client | existing profile, repeat booking, partial pay, reschedule | same history and corrected ledger | PostgreSQL booking/finance/concurrency | payment/reminder, correction retained | VERIFIED |
| C Home Visit | request, pending, approved/rejected | review/location/block | Home lifecycle/location/PostgreSQL | configured event, rejection removes client action | VERIFIED |
| D Online | ready join link, cancelled removal | confirmation/provider/cancel | Zoom/B2B and booking lifecycle | ready/reminder/cancel link | VERIFIED application path; provider limits listed |
| E Group | party_size and one booking | one CRM record and block/conflict | exclusion/version/group tests | normal booking reverse path | VERIFIED current one-booking semantics |
| F Gift | sale/pay/transfer/claim/partial spend/correction | purchase/obligation/certificate/history | gift PostgreSQL concurrency + hosted E2E | fulfillment/transfer history/replay denial | VERIFIED |
| G Referral | attribution, reward/balance | referral/partner/payout | referral/partner PostgreSQL + feature | reward/payout/retry | VERIFIED |
| H Companion | AI/retry/human/staff/resume | handoff/join/reply/resume | Companion PostgreSQL races/encrypted body | human/safety/technical events distinct | VERIFIED |

Every journey has application-level negative and reverse/correction assertions.
Staging records were synthetic; no mass broadcast, live charge, destructive
reset or production action was performed.

## External verification and unresolved classifications

| Integration | Status | Exact blocker/evidence |
| --- | --- | --- |
| Telegram | NOT VERIFIED only for client inbound/Mini App click; staff outbound PASS | Bot API reachability and bounded staff delivery pass; no safe client chat/account |
| Email | OTP/application through array sink PASS; SMTP NOT VERIFIED | sink captured OTP and replay denial; no external mailbox transport |
| Zoom | PASS for configured adapter and bounded lifecycle | staging account create/read/update/cancel passes |
| Payments | fake gateway/domain/webhook/idempotency PASS; Lava NOT VERIFIED | no Lava sandbox credential; no real charge attempted |
| AI | OpenAI/DeepSeek PASS; Groq NOT VERIFIED | owner-confirmed intentionally invalid Groq key, HTTP 401; adapter error handling tested |
| Queues/workers | PASS | Redis/Horizon identity, real worker Companion turn and PostgreSQL queue contracts |
| Scheduler | PASS | running scheduler and bounded smoke commands |
| Storage | PASS for private disk/RAG cleanup | protected access/retirement pass; Companion direct file open is not implemented |
| Staging exact Final SHA | pending final report commit deployment | deploy exact SHA then staging-smoke --deep; no wipe/truncate |

### NOT VERIFIED

FINANCE-LAVA-EXTERNAL, PAYMENT-LAVA-EXTERNAL, AI-GROQ-EXTERNAL,
EMAIL-SMTP-EXTERNAL and TELEGRAM-CLIENT-EXTERNAL. Each blocker is an
unavailable or intentionally invalid external staging credential/identity,
not an unverified internal application path.

### NOT IMPLEMENTED

SURVEY-9SYSTEMS (authoritative questionnaire/scoring source absent) and
COMPANION-FILE-OPEN (Portal bubble exposes metadata only).

### NEEDS OWNER DECISION

TRACKER-CLINICAL-METHODOLOGY (clinical Road Map methodology absent).

### BLOCKING and REAL BUG

No unresolved BLOCKING or REAL BUG remains. Regression proof remains for the
Portal staff HTML literal-tag fix, duplicate Online URL replay, Finance
overpayment, barter validation, Gift self-claim 405, staging broken-pipe
preflight and Communities mobile editor synchronization.

## Final gate

The full browser gate is green on the exact code candidate. The final report
commit itself must still be deployed and checked with staging-smoke --deep;
until that exact SHA smoke is recorded, the correct conclusion is:

SYSTEM NOT FULLY VERIFIED

The final handoff must repeat the counts, exact remaining external blockers,
hosted run URLs/results, staging SHA, branch and PR state. It must not claim
ALL IMPLEMENTED SYSTEM CAPABILITIES VERIFIED while any external row remains
unavailable.
