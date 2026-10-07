# Owner Feedback Backlog

Updated: 2026-10-07

This is the working backlog for owner acceptance findings, owner-confirmed product changes, and new Change Requests that are not yet fully normalized into `docs/product/requirements.md`.

It exists so acceptance feedback is not lost between chats or implementation passes.

## Source priority

When sources conflict, use:

1. latest explicit owner decision;
2. current task / owner acceptance finding;
3. owner-accepted product decisions already implemented;
4. normalized requirements;
5. older v2.2 / historical planning documents.

Do not silently turn every item from later change-request documents into an accepted requirement.

Status vocabulary:

- `IMPLEMENTED_PENDING_ACCEPTANCE` — code exists, but owner has not completed the real browser/Telegram acceptance journey.
- `TO_IMPLEMENT` — accepted change/fix still missing.
- `REAL_BUG` — current behavior is functionally broken.
- `UX_CONFUSION` — current behavior may be technically correct but is unclear to a normal manager/specialist.
- `DIAGNOSED / NO_CURRENT_REAL_BUG_REPRODUCED` — the focused valid-state path works; the reported failure is not reproducible in the current implementation.
- `NEEDS_OWNER_DECISION` — product semantics are not yet authoritative enough to implement safely.
- `NEW_SCOPE` — not part of the original Phase 1 v2.2 scope in its current detailed form.
- `DEFERRED_INFRA` — confirmed infrastructure issue intentionally separated from ordinary product fixes.

---

# 1. Current baseline

Current Draft PR:

- PR: #53 — Phase 1 functional closeout
- Branch: `codex/chuklov-phase1-functional-closeout`
- Current remediation-pass starting SHA: `b349ce68749408a07f76465fce51a54352fcf401`
- Code candidate for this pass: `c6469f4eb80ca9dabd9ed82e8b1b559fd118e8f2`
- PR remains Draft / not merged.

The current branch already contains the ServiceCredit Base Currency remediation, Client Companion retry/handoff remediation, and the booking/Telegram/Work Schedule acceptance pass.

Do not reopen already accepted architecture without a concrete defect.

---

# 2. Testing rule exposed by owner acceptance

## Attachment button regression

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

In CRM Messages the paperclip attachment button is visible but a real browser click does not open the file chooser.

Existing tests were insufficient: they asserted that the trigger component, hidden upload and Alpine event wiring existed, but they did not prove the user journey:

`click paperclip -> native/file picker opens -> selected file appears -> send succeeds or human-readable validation error appears`.

This is a test-gap, not an acceptable implementation.

Required remediation:

- fix the real browser interaction;
- add a browser/E2E regression for the click path;
- do not accept DOM-presence/event-registration tests as sufficient for clickable UI;
- if a button is user-facing, acceptance must assert a visible result or human-readable error.

The FilePond browser path now opens the native chooser, keeps the selected file visible in the composer, and sends it through the existing protected attachment action. The focused Playwright regression covers the full click → selection → composer → send/history journey and passed on both desktop and mobile in hosted E2E. The exact candidate was deployed and passed staging smoke; owner acceptance remains pending.

---

# 3. Client / CRM workspace

## 3.1 Telegram contact presentation

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Client card should show:

- Telegram connection state;
- `@username` when available;
- clickable `https://t.me/<username>` for validated usernames;
- Telegram ID as secondary fallback/technical identifier, not the only human-facing contact.

Investigate `tg://user?id=<telegram_id>` in the actual supported browser/Desktop Telegram environment before relying on it. Do not make it the only contact action because browser support is not guaranteed.

If the deep link is unreliable:

- primary external action = `@username` link when username exists;
- Telegram ID remains visible/copyable as a fallback where useful.

Implemented with verified identity state and a validated `https://t.me/<username>` primary contact link. `tg://user?id=...` is not used as the primary action.

## 3.2 "Написать клиенту"

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

From CRM notifications / booking actions, the primary action `Написать клиенту` should open the CRM conversation with that client.

It should not immediately force-open the Telegram application.

External Telegram contact can exist as a separate secondary action.

Reuse the existing authoritative CRM Messages/Companion conversation.

Booking and internal notification actions now use the CRM Messages URL with the selected Client. Verified Telegram remains a separate secondary external action.

## 3.3 "Новый сеанс"

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Move `Новый сеанс` from `Дополнительные действия` to the primary action layer on the Client page.

Reason: this is a common specialist workflow, not an exceptional/technical action.

Preserve existing action semantics and authorization.

The action is now in the Client page primary header action layer.

## 3.4 Client search in Booking form

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Current problem: clients with identical/similar names are difficult to distinguish.

Booking client selection should:

- search by full name;
- phone;
- Telegram username;
- email;
- optionally Telegram ID as a secondary exact-match path if useful;
- remain tenant-scoped.

Search result labels should include available identifying fields, for example:

`Анна Иванова · @anna · +7... · anna@example.com`

Missing fields are omitted rather than rendered as empty separators.

Use the same human-readable pattern already used in referral-person search: search text plus rich option label.

Do not expose internal Client IDs.

The existing tenant-scoped ClientSearch abstraction now supplies rich labels and searches full name, phone, verified Telegram username, and email. Telegram ID remains an exact-match capability of the shared search abstraction but is not displayed in option labels.

---

# 4. Referral / attribution UX and owner decision

## 4.1 Human wording

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Replace user-facing technical wording:

- `Реферер` -> `Кто пригласил`;
- `Первая атрибуция` -> human wording such as `Первый источник`, preserving exact semantics;
- source-detail UI must not look like it creates a referral relationship.

`Уточнение источника` is free-text attribution provenance and does **not** create a canonical `ReferralRelationship`.

Its helper text must say this clearly.

Implemented as `Уточнить источник` with `Комментарий к источнику`; the helper explicitly separates attribution text from `Кто пригласил`.

## 4.2 Existing referrer must be shown before change

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

If a Client already has a canonical referrer, opening the "who invited" action must immediately show:

- current referrer;
- relationship source/method when available;
- warning that the existing relationship will be replaced.

Do not let the user fill the whole form and only then return:

`У клиента уже указан пригласивший`.

The existing relationship and its method are now shown when the action opens, before submit.

## 4.3 Admin may forcibly replace referrer

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Latest owner decision supersedes the earlier "canonical referrer cannot be changed" ordinary-rule for authorized CRM admin operations.

Authorized CRM admin may explicitly replace an existing referrer.

Requirements:

- explicit privileged action/confirmation;
- current referrer displayed before change;
- select new referrer;
- self-referral forbidden;
- tenant isolation preserved;
- authorization preserved;
- immutable audit: who changed, when, old referrer, new referrer;
- UI updates immediately after success.

Historical reward/Finance entries must **not** be rewritten retrospectively.

Already qualified/paid/redeemed reward evidence stays attached to its historical recipient.

The new referrer applies prospectively unless the owner later explicitly defines a separate historical correction workflow.

Do not silently mutate append-only financial/reward history.

The privileged application action uses the `manage_referral_relationships` permission, keeps the old relationship as superseded evidence, creates a new active manual relationship, records old/new IDs in audit, and applies the new relationship only to future qualification.

---

# 5. Specialist / CRM identity

Status of the previous pass: `IMPLEMENTED_PENDING_ACCEPTANCE`

Already implemented on the current branch:

- `Сотрудник CRM` renamed to a clearer CRM-account concept;
- Telegram `Подключён / Не подключён`;
- verified `@username` presentation;
- raw Telegram ID removed from primary Specialist infolist;
- staff Telegram start route no longer overlaps generic client token route;
- booking-confirm callback no longer arbitrarily picks the first Specialist of a CRM user.

Real owner click acceptance is still required for:

- fresh staff Telegram link;
- username display;
- booking confirm for a CRM user linked to multiple Specialists.

---

# 6. Scheduling configuration information architecture

## 6.1 B2B meeting duration

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

`Длительность вводной B2B-встречи (минуты)` is not an ordinary client service duration.

It controls the dedicated B2B sales-call flow and its availability/Zoom duration.

It should not visually look like a generic schedule setting.

Preferred placement:

- separate section `B2B-встречи`;
- hide/collapse the section when the B2B feature is not used, if this can be done with existing feature/config state without inventing a new subsystem.

Keep the helper explicit:

ordinary consultation duration is defined in the Service itself.

The fields are grouped under `B2B-встречи`.

## 6.2 Consultation after test

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

`Консультация после результатов теста` selects an existing online Service that is proposed after test/survey results.

It belongs conceptually to test/Road Map client flow, not ordinary specialist scheduling.

Preferred placement:

- section `После результатов теста`, or
- the existing tests/Road Map settings surface if there is a natural existing home.

Do not change its existing eligibility rules merely to move the setting.

The field is grouped under `После результатов теста` with human helper text; eligibility rules are unchanged.

## 6.3 Zoom Meetings license

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

The setting `У Zoom-хоста есть лицензия Meetings` is related to automatic Zoom/B2B meeting capability.

Group it with the B2B/Zoom settings rather than mixing it into generic schedule fields.

The license capability is now in `B2B-встречи`.

---

# 7. Work Schedule

Status of requested UX pass: `IMPLEMENTED_PENDING_ACCEPTANCE`

Expected accepted behavior:

- `Не работает`;
- `Вернуть по графику`;
- `Изменить рабочее время`;
- no duplicated dropdown choices for day-off / regular schedule;
- visible border on `Причина`;
- Save button below editable controls;
- exact explanatory copy:

`Внесенные здесь изменения не повлияют на установленный регулярный график.`

Underlying regular schedule / local override semantics must remain unchanged.

---

# 8. Booking / Portal acceptance items

Status of previous pass: mostly `IMPLEMENTED_PENDING_ACCEPTANCE`

Implemented:

- Booking required validation is human Russian copy, not `validation.required`;
- Portal home uses `Записаться ещё` when an upcoming booking is already shown;
- Telegram specialist booking confirmation supports one CRM user linked to multiple Specialists.

Still perform owner click acceptance on staging.

---

# 9. Finance / manual payment UX

## 9.1 Current architecture

Current Finance uses an authoritative `FinancialObligation` plus append-only payment ledger.

Payment status must stay derived from recorded operations:

- unpaid;
- partial;
- paid.

Do **not** add a manual editable `Статус оплаты` that bypasses the ledger.

## 9.2 Payment entry on Booking page

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Manual payment exists but is too hidden.

When a Booking has a financial obligation, the Booking view should visibly show a payment block:

- total;
- paid;
- remaining;
- derived payment status;
- primary `Записать оплату` action when an outstanding amount exists.

Do not require a specialist to discover payment entry in an opaque `Действия` dropdown.

Reuse the existing Finance payment action/application authority.

The Booking infolist now renders `Оплата` with total, currency, paid, remaining, derived status, and visible `Записать оплату` when outstanding exists.

## 9.3 Unpaid sessions in "Оплаты"

Status: `DIAGNOSED / NO_CURRENT_REAL_BUG_REPRODUCED`

The Payments/Financial Obligations list should show existing unpaid obligations, including `paid = 0`.

If a completed post-pay Booking with a positive configured price has no obligation and therefore does not appear in Payments, diagnose why obligation materialization failed.

Do not fix by inventing a second payment table.

A focused lifecycle test completes a valid positive-price post-pay Booking, observes a zero-paid FinancialObligation in `Оплаты`, and confirms it remains listed after a partial payment. Hosted PostgreSQL integration/concurrency and the exact staging smoke passed. No current A/B materialization or list-filter bug reproduced; the owner screenshot is consistent with a pre-completion/configuration/data state (C/D). No fake debt or second payment table was added. Owner acceptance remains pending.

## 9.4 Manual payment methods

Current known methods include money payment methods and `Другое`.

Owner questions introduce explicit barter semantics.

Status: `NEEDS_OWNER_DECISION`

Potential desired methods:

- cash;
- bank transfer / cashless;
- card;
- barter;
- mixed payment (represented as multiple ledger entries, not one ambiguous status).

Recommended model if owner accepts barter:

- add `Бартер` as an explicit payment method;
- require a note describing consideration received;
- mixed money + barter = separate append-only entries against the same obligation;
- payment status remains derived from the obligation balance.

Do not implement barter from the question alone until owner explicitly accepts it as a real accounting method.

## 9.5 Ordinary partial deposit

Status: `NEEDS_OWNER_DECISION`

Current ordinary service modes are post-pay or full prepayment.

A generic partial deposit for every service is not the same as the existing Home Visit transport deposit.

If required, owner must define at least:

- fixed amount vs percentage;
- whether deposit is mandatory;
- when it becomes due;
- refundable/non-refundable rules;
- how cancellation/reschedule affects it;
- how the remainder is collected.

Do not reuse Home Visit transport-deposit semantics as a generic service deposit without a product decision.

---

# 10. CRM Messages / Client Companion

## 10.1 Attachment paperclip

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

See testing rule above.

The real browser regression is present and passed on desktop and mobile in hosted E2E, including chooser opening, selected-file visibility, first-message send, and history visibility. Local browser execution was blocked by the unavailable local PostgreSQL role. The exact candidate passed staging smoke; owner acceptance remains pending.

## 10.2 Internal feedback copy

Status: `IMPLEMENTED_PENDING_ACCEPTANCE`

Current copy:

`Текст доступен только в авторизованном просмотре клиента и не участвует в поиске или аудите.`

This describes a security implementation detail rather than helping a normal CRM user.

Preferred human presentation:

- title: `Комментарий клиента` (or equivalent matching the real field semantics);
- helper: `Комментарий виден только сотрудникам с доступом к клиенту.`

Keep the existing privacy/security boundary:

- protected text is not exposed to unauthorized staff;
- no broad search indexing if current security policy excludes it;
- do not copy protected body text into audit logs.

This is a wording cleanup, not permission weakening.

Implemented as `Комментарий клиента` with the human helper text while preserving protected-text authorization and audit boundaries.

---

# 11. Deployment continuity

Status: `DEFERRED_INFRA`

Observed 502 during booking creation was traced to the deployment window where host nginx temporarily had no healthy app listener on the configured upstream.

The request did not reach Laravel.

Do not "fix Booking" for this symptom.

A production-quality fix needs deployment continuity, for example a ready candidate listener plus health-check and atomic switch, but the exact solution must extend the existing deployment abstraction rather than introducing a large new orchestration platform.

Handle as a separate infrastructure task.

Until fixed, do not claim zero-downtime deployment.

---

# 12. New documents received on 2026-09-26

Inputs:

- `Изменения_в_CRM_Чуклов_бот_от_26_09_26_СПИСОК_1.docx`
- `Изменения_в_CRM_Чуклов_бот_от_26_09_26_СПИСОК_2.md`

These documents are Change Request inputs, not automatically authoritative replacements for v2.2 or later owner decisions.

Before implementing items from them, reconcile them against:

- latest owner decisions;
- already accepted UX;
- current normalized requirements;
- current implementation.

## 12.1 Gift certificates

Status: `NEW_SCOPE`

Gift certificates were not a Phase 1 v2.2 requirement.

The v2.2 Phase 3 mention of "certification" is expert accreditation/marketplace certification, not gift certificates.

The new CR describes a real new product subsystem:

- certificate amount/value;
- purchase;
- issuance;
- transfer to another person;
- recipient deep-link / registration;
- redemption against eligible services/products;
- balance/redeem history.

Do not fold this into referral ServiceCredit.

Do not implement inside Phase 1 closeout / PR #53 unless the owner explicitly reprioritizes it.

When implemented, extend the existing Finance/FinancialObligation ledger architecture rather than inventing a parallel payment engine.

## 12.2 Family / group booking

Status: `PARTIAL_OLD_CONCEPT + NEW_SUBSTANTIAL_SCOPE`

v2.2 contained a brief requirement that the interactive calendar account for family/group visits.

It did **not** define the detailed family architecture now proposed.

The newer CR expands this into:

- family members / dependents;
- separate patient/medical profiles;
- booking "for whom";
- invitations;
- adult/minor access/delegation;
- consent/privacy semantics;
- possible `patient_profiles` persistence and Booking/MedicalProfile references.

This detailed architecture is substantial new scope.

Do not pretend the one-line v2.2 calendar mention already specified all of this.

Before implementation, owner/legal/product decisions are required for:

- adult family-member access;
- minors/guardian authority;
- medical-data consent/delegation;
- who can view/edit whose protected profile;
- revocation and audit.

Do not implement this in the Phase 1 closeout PR by default.

## 12.3 Other CR items

The 26.09 lists also contain additional proposed scope such as:

- client notes;
- blacklist;
- booking multiple sessions in one operation;
- visual pain scale;
- referral statistics;
- service-format buffers;
- schedule/settings reorganization;
- media behavior in marketing templates;
- broader Mini App / onboarding redesign.

Each item must be classified before implementation as:

- already implemented;
- owner accepted / implement now;
- conflict with later owner decision;
- needs owner decision;
- later/new scope.

Do not execute the full documents as a bulk redesign instruction.

## 12.4 Known conflict: referral bonus currency

Any wording in the 26.09 CRs suggesting an independent "client main/accounting currency" for referral ServiceCredit is superseded by the later accepted architecture:

`ServiceCredit authoritative accounting currency = Organization Base Currency`.

Cross-currency redemption uses configured organization FX with immutable evidence.

Do not regress this.

---

# 13. Phase 1 boundary

Original Phase 1 v2.2 explicitly focused on the MVP B2C/B2B contour, master web panel, kinesiology/3D, tests/AI, home visits, NPS, Tracker, Zoom and multi-currency/debt.

Gift certificates are outside that original scope.

Family/group visits were mentioned conceptually, but the detailed family/patient-profile architecture in the later CR is not a fully specified original Phase 1 requirement.

Phase 1 closeout should therefore prioritize:

- concrete acceptance bugs;
- missing v2.2 behavior that is already defined;
- latest accepted owner decisions;
- production readiness.

Do not allow large new CR subsystems to prevent closing the already-built Phase 1 unless the owner explicitly reprioritizes them.

---

# 14. Next implementation sequencing

Recommended order after owner finishes collecting feedback:

1. **REAL BUGS / direct acceptance failures**
   - CRM Messages attachment click;
   - any reproducible missing FinancialObligation/payment materialization;
   - real Telegram/browser broken journeys.

2. **Small accepted CRM UX pass**
   - Client Telegram contact;
   - `Написать клиенту` -> CRM dialog;
   - primary `Новый сеанс`;
   - rich Client search in Booking;
   - referral wording/current-referrer warning/admin replacement;
   - Finance block on Booking;
   - scheduling settings grouping.

3. **PostgreSQL/staging acceptance**
   - DB-sensitive referral/Finance changes on PostgreSQL;
   - browser click journeys, not only component tests.

4. **Separate infrastructure task**
   - deployment continuity / transient 502.

5. **Separate post-Phase-1 product work**
   - gift certificates;
   - detailed family/dependent architecture;
   - other large 26.09 CRs after explicit owner prioritization.

---

# 15. Acceptance test standard

For user-facing controls, unit/component existence is insufficient.

Examples:

- attachment: `click -> chooser/upload -> visible attachment -> send`;
- Telegram callback: `real callback -> state change -> visible confirmation`;
- referral replacement: `open -> see current referrer -> confirm replacement -> audit -> future attribution uses new relationship`;
- payment: `record payment -> ledger -> balance/status -> Booking + Payments projections`;
- client search: `search by phone/@username/email -> identify correct client -> select -> create Booking`.

Tests should verify business journey and observable result, not merely that a button/component/event listener exists.
