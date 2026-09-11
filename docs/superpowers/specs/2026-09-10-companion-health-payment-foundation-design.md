# Companion, Health Experience, and Payment Foundation

## Status

Design approved by the owner on 2026-09-10. Implementation is pending final specification review.

## Goal

Deliver a demonstrable, production-quality client flow using the existing modular-monolith boundaries:

- a useful Companion v4;
- two platform-default draft health questionnaires;
- deterministic, user-friendly reports, Road Map snapshots, and repeat comparison;
- a provider-neutral Fake/Demo payment lifecycle over Finance;
- a compact Portal Cabinet replacing the visible «Ещё» destination.

Production deployment, merge, real payment providers, official MSQ content, and unapproved Chuklov-authored claims remain out of scope.

## Existing authorities to extend

The implementation extends, rather than duplicates:

- `Surveys`: versioned definitions, immutable attempt snapshots, deterministic scoring, reports, comparisons, Scenario completion events, and Portal flow;
- `AI` and `ClientCompanion`: Prompt Studio versions, `AiWorkflowEngine`, bounded context assembly, protected traces, private attachment references, deterministic handoff controls, and existing evaluation assertions;
- `Finance`: Money, obligations, append-only ledger, gateway transactions/events, idempotency keys, reconciliation, settlement evidence, and the existing `PaymentGateway` / `FakePaymentGateway`;
- `ClientPortal`: server-derived client context, Inertia pages, shared AppShell, responsive mobile navigation, and existing routes;
- `Tracker` and `Scenarios`: entitlement authority and existing follow-up/event infrastructure.

No second survey engine, prompt engine, payment ledger, entitlement subsystem, or Telegram-specific product flow will be introduced.

## 1. Companion v4 usefulness

### Runtime policy

Create an immutable Prompt Studio version labelled `Companion v4 — usefulness` for the `client_companion` capability. The prompt explicitly separates:

- general health education available from model knowledge;
- verified, bounded client context supplied by the server;
- organization-approved Chuklov content supplied by authorized Knowledge retrieval.

The prompt permits plain-language explanations of general health questions, named diagnoses, medical terminology, reports available to the model, recovery education, sleep, gradual activity, self-observation, rehabilitation topics, product features, tests, Road Map, Tracker, Cabinet, and booking navigation.

The prompt prohibits diagnosing a new condition as established, prescribing medication or dosage, inventing client facts or test values, presenting unsupported Chuklov methodology, and replacing a treating team. A short qualification is allowed only when relevant and is followed by useful content.

### Deterministic handoff boundary

The application remains authoritative for handoff. The classifier recognizes only:

- an explicit request for a person/specialist;
- an urgent safety signal;
- an explicitly unsupported technical operation or confirmed processing failure.

Negated requests such as «привет, не надо специалиста» never become `human_requested`. A serious diagnosis, surgery, oncology term, image attachment, or medical vocabulary alone never triggers handoff. The model response is validated against this boundary before an escalation is persisted.

### Health context

Extend the bounded Companion context with a server-generated, organization/client-scoped health projection containing only the latest authorized survey report and comparison/Road Map facts needed to answer the current question. The projection contains definition/version references, neutral metric labels and levels, selected answer evidence, safe observations, Road Map items, and comparison direction. It never exposes encrypted ciphertext, internal keys, unrestricted medical profile data, raw answers outside the selected evidence, or another client’s records.

Image questions use the existing private attachment path. If the runtime has no extracted image content, the Companion states that the concrete values are unavailable and provides useful next steps without fabricating findings or issuing a generic refusal.

### Executable eval suite

Add a source-controlled, synthetic Companion v4 suite materialized through the existing AI evaluation framework. Cases cover the required A–G scenarios plus casual health, musculoskeletal symptoms, sleep/fatigue, vague input, medication safety, emergency red flags, and product questions. Assertions are semantic properties rather than exact full-response strings, including decision, handoff reason, forbidden claims, minimum useful content, long-response paragraph count, no invented client values, and truthful unavailable-attachment behavior.

Normal PHPUnit/CI execution uses the existing AI fake boundary and prevents external provider calls. A separately runnable command/test path can execute the same suite against an explicitly selected configured release without making paid execution a normal CI prerequisite.

## 2. Health Experience

### Content provenance

Add explicit version metadata to Survey versions for `source = platform_default` and `approval_status = draft`. Operational publication status remains separate from content approval status so a staging/demo definition can be available without being represented as Chuklov-approved. Future `source = chuklov_approved` content can use the same engine and version snapshots.

The platform-default definitions are not labelled official MSQ or Chuklov-authored material. The extended questionnaire uses `methodology = platform_extended_symptom_questionnaire` in its definition metadata and retains the same platform-default/draft provenance.

### Default definitions

Seed two idempotent, versioned definitions for the default organization:

1. `Скрининг здоровья: 9 систем` — nine sections, five short frequency questions per section, 45 questions total, and the supported five-point scale: Никогда 0, Редко 1, Иногда 2, Часто 3, Почти постоянно 4.
2. `Расширенный опрос симптомов` — a multi-section platform questionnaire with several dozen short symptom items, the same bounded severity/frequency scale, domain grouping, and a separate metric schema key.

Question wording is neutral and non-diagnostic. Each version retains stable question/option/metric/threshold identities. Seeders never overwrite an existing definition, version, or owner edit.

### Scoring and report materialization

Extend the existing `SurveyScorer` scoring metadata so each domain has raw score, maximum score, and a normalized 0–100 burden value. Thresholds remain neutral: `низкая симптомная нагрузка`, `стоит обратить внимание`, and `выраженные жалобы`. No illness, organ-failure, diagnosis, or health-percentage claims are emitted.

Completion continues to run in the existing transaction and pins the exact version. The materialized report snapshot adds:

- a concise summary;
- top three attention domains ordered deterministically;
- answer-backed evidence for each selected domain;
- observations to monitor;
- general safe next steps;
- topics to discuss with a specialist;
- Road Map items derived from the same report/version;
- links/actions for Road Map, Companion discussion, and later retest.

Raw metrics remain available as secondary detail data. Presentation maps keys and enums to Russian/English labels at the Application boundary.

### Road Map

Road Map v1 is a deterministic platform-default projection produced during survey report materialization. It uses only the completed attempt/version and its normalized domain results. Its recommendation categories are limited to sleep/routine, gradual activity, recovery after load, moderate hydration observation, symptom tracking, relaxation/stress, following existing specialist recommendations, questions for a clinician, and retesting.

The Road Map is stored inside the immutable encrypted report snapshot with a schema/version marker. This reuses the current report history and avoids creating a second roadmap store. If later AI prose is added, it receives the same fact/score/general-recommendation/approved-content separation and its output remains a suggestion, not a confirmed clinical fact.

### Repeat and dynamics

Reuse `SurveyComparison` and its compatible metric schema. Extend the comparison projection to expose `Было`, `Стало`, and `Изменение` per domain and a neutral positive/stable/needs-attention message. Lower symptom burden is an improvement; unchanged or higher burden never receives blame. Incompatible survey versions remain `not_comparable` and do not emit stagnation events.

## 3. Provider-neutral Payment Foundation

### Existing Finance boundary

Keep Finance’s obligation and append-only ledger authoritative. Extend the current `PaymentGateway` contract and Fake implementation only where needed for checkout status, failure, pending, refund, and reconciliation. Provider payloads remain outside Domain values.

The persisted transaction continues to snapshot organization, obligation, amount, currency, gateway, provider reference, request hash, and idempotency identity. Gateway events remain organization-scoped and unique by provider event identity. Refunds are represented by an explicit gateway/ledger transition and never erase the original settlement.

### Lifecycle

The application flow is:

1. derive and authorize the organization/client/obligation server-side;
2. lock and reconcile the obligation;
3. claim a stable idempotency key and snapshot amount/currency;
4. commit the pending gateway transaction before any external boundary;
5. let the Fake gateway return pending, succeeded, or failed evidence;
6. verify settlement evidence through the gateway and finalize the ledger in a short transaction;
7. accept refund evidence through the same verified, deduplicated boundary;
8. reconcile safely and preserve uncertainty when an outcome is not authoritative.

A redirect or success URL never settles an obligation. Only verified evidence or authoritative reconciliation can do so. Duplicate events replay the original result, cannot double-credit the ledger, and reject mismatched amount/currency or tenant identity.

### Demo access

The staging/local demo entry point is guarded by configuration and environment so the Fake gateway cannot be selected by an ordinary production client. The UI clearly says that it is a demonstration with no real charge. Demo controls exercise the same application actions and persistence lifecycle as a future provider; they do not write directly to Finance tables.

Entitlements remain separate. A verified payment event may be consumed by existing entitlement policy, but no gateway adapter directly grants Tracker access and no recurring subscription lifecycle is invented.

## 4. Cabinet UX

Keep the existing `portal.more` route and page boundary while changing all client-visible terminology and navigation keys to `Кабинет` / `Cabinet`. The Cabinet list contains exactly the existing destinations:

- Личные данные / Profile;
- Мои записи / Bookings;
- Оплата / Finance;
- Партнёрство / Referrals;
- Обратная связь / Feedback.

The unrelated B2B destination is not placed in this compact client list. Existing routes/pages remain authoritative. Shared AppShell and bottom navigation keep the primary destinations clear, use the existing icon library, and retain a mobile-first layout without horizontal overflow.

## 5. Verification and delivery

Focused verification will cover:

- Companion classifier, response contract, context isolation, eval assertions, and fake execution;
- survey seed/import, provenance, scoring, thresholds, reports, Road Map, version snapshots, repeat comparison, stagnation behavior, and tenant isolation;
- payment success/pending/failure/refund, duplicate event/idempotency, redirect non-settlement, mismatch rejection, reconciliation, tenant isolation, and meaningful PostgreSQL races;
- Portal feature responses and focused frontend assertions for Health, report, Cabinet, and mobile navigation.

The implementation will run targeted PHPUnit, PHP syntax, Pint, Larastan where configured, ESLint, `vue-tsc`, Vite build, and `git diff --check`. PostgreSQL integration will run against the repository’s production-equivalent service rather than relying on SQLite. No broad unrelated suite or production deployment is part of this design.

The finished branch will be committed and pushed as a Draft PR. Merge, production deployment, real provider selection, owner acceptance, and official/Chuklov questionnaire replacement remain separate decisions.

## Explicit non-goals

- no real merchant/provider integration or credentials;
- no Cashier, Payum, SaaS billing, recurring billing, or provider subscription lifecycle;
- no official IFM MSQ claim or copied licensed content without provenance;
- no Chuklov methodology/scoring claim;
- no new survey, prompt, Road Map, payment ledger, websocket, or entitlement subsystem;
- no automatic diagnosis, prescription, dosage, or personal treatment protocol;
- no production deploy or merge.
