# Companion, Health Experience, and Payment Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the existing CHUKLOV Portal demonstrable end to end through useful Companion v4, platform-default health surveys and Road Map dynamics, a provider-neutral Fake payment lifecycle, and Cabinet navigation.

**Architecture:** Extend the existing Surveys, AI/ClientCompanion, Finance, Tracker/Scenarios, and ClientPortal application boundaries. Add only the metadata, report projection, payment lifecycle fields, and application actions needed to make the existing authorities usable; preserve organization scope, immutable survey snapshots, append-only Finance, authoritative payment verification, and protected-data boundaries.

**Tech Stack:** PHP 8.5, Laravel 13.25, PHPUnit 12, PostgreSQL/pgvector, Laravel AI 0.10.3, Inertia 3.3.1, Vue 3, TypeScript, Tailwind 4, Filament 5.7.6, Nutgram 4.49.1.

**Spec:** `docs/superpowers/specs/2026-09-10-companion-health-payment-foundation-design.md`

## Global constraints

- Start from `d168240818ac3e4997506581f586954c3aff1666` on `codex/companion-health-payment-foundation`.
- Use existing Application/Domain/Infrastructure boundaries and existing Survey Engine, Prompt Studio, Finance, Tracker entitlement, Scenario, and Portal routes.
- Keep all new health content explicitly `platform_default` and `draft`; never call it Chuklov-authored or official IFM MSQ.
- Do not add a real payment provider, merchant credentials, Cashier, Payum, a second ledger, a second survey engine, or a second prompt engine.
- Do not deploy production or merge. Use staging only if an already-authorized environment can be verified without deployment; otherwise report it not run.
- Do not expose protected health/payment payloads, organization IDs, internal keys, or provider status enums to the client.
- Add tests before implementation for each bounded slice, run focused checks, and run PostgreSQL verification for all changed persistence/transaction behavior.

## Task 1: Capture baseline and repository conventions

- [ ] Confirm the branch, starting SHA, clean baseline, package versions, matching `.ai/rules`, and relevant existing Survey/Companion/Finance/Portal tests.
- [ ] Inspect the exact method contracts for `SurveyScorer`, `CompleteSurveyAttempt`, `CompareSurveyAttempts`, `AssembleCompanionContext`, `CompanionTurnProcessor`, `PaymentGateway`, `FakePaymentGateway`, `InitiateFakePayment`, `SettleFakePayment`, `ReconcileFakeGatewayTransaction`, and Cabinet/Health controllers and pages.
- [ ] Record only material product/architecture assumptions in the already-existing product assumptions/open-questions documents; retain the unresolved need for Chuklov-approved content and official MSQ provenance.
- [ ] Commit and push the design plus this plan before application changes.

## Task 2: Versioned platform-default survey catalog

- [ ] Add the minimum survey-version metadata needed to distinguish `platform_default` from future `chuklov_approved` content and `draft` from operational approval, while preserving existing `source_reference`, published immutability, and old attempt snapshots.
- [ ] Add an idempotent catalog/seeder through the existing survey application boundary for the published operational version of `Скрининг здоровья: 9 систем`, with metadata still marked `source=platform_default`, `approval_status=draft`.
- [ ] Seed 45 short, non-diagnostic questions: five each for the nine agreed domains, using the existing single-choice scale `Никогда=0`, `Редко=1`, `Иногда=2`, `Часто=3`, `Почти постоянно=4`.
- [ ] Add an idempotent separate `Расширенный опрос симптомов` definition with `methodology=platform_extended_symptom_questionnaire`, the same provenance/approval metadata, several dozen grouped symptom items, severity/frequency scoring, and no claim of official MSQ/IFM content.
- [ ] Encode scoring metric labels, question membership, maximums, neutral thresholds, and comparison direction in the existing version scoring JSON so future content can replace definitions without changing the engine.
- [ ] Add seed/import/versioning tests, including immutable completed snapshots and the ability to activate a later version for new attempts.

## Task 3: Scoring, friendly results, Road Map, and dynamics

- [ ] Extend the existing scorer/projector to calculate bounded raw domain values, normalized 0–100 symptom-burden values, neutral threshold labels, top three domains, and evidence references derived only from the completed answers.
- [ ] Keep existing scoring definitions compatible; do not break boolean, numeric, text, or existing metric operators.
- [ ] Extend report materialization so each completed attempt has a user-facing summary, top attention areas, answer-based reasons, observation prompts, safe general steps, specialist discussion prompts, Road Map items, and CTA state, while keeping raw values secondary and snapshots encrypted.
- [ ] Build Road Map v1 from the existing survey completion pipeline and persist it in the existing report/version snapshot; recommendations remain educational and non-prescriptive.
- [ ] Extend existing comparison logic to compare normalized domain burden across compatible attempts, expose `Было → Стало → Изменение`, and retain neutral `no_decrease`/stagnation behavior without blame or fabricated health percentages.
- [ ] Add focused tests for thresholds, top domains, evidence, Road Map source/version, repeated attempts, improvement, no-decrease/stagnation, and organization/client isolation.

## Task 4: Health Portal experience

- [ ] Extend existing Health/Survey controllers and Inertia payloads rather than adding duplicate routes or pages.
- [ ] Present available platform-default tests with plain user-facing copy and a provenance-neutral draft/demo indication where appropriate; keep internal source/methodology keys out of the UI.
- [ ] Replace the technical-only report view with a mobile-first result layout containing summary, three attention areas, answer-based reasons, next observations, safe steps, specialist questions, Road Map CTA, Companion CTA, and repeat CTA; keep raw technical details secondary.
- [ ] Add a comparison section for repeat attempts and a friendly stagnation/no-decrease message when applicable.
- [ ] Reuse existing shell, icons, typography, translations, buttons, and routes; verify at 320/360/390/768/1024/1440px with no horizontal overflow or clipped long text.
- [ ] Add focused controller/component/type tests where the project setup supports them.

## Task 5: Companion v4 usefulness, context, and executable evaluations

- [ ] Add a new versioned Prompt Studio source/bundle labeled `Companion v4 — usefulness`, preserving the prior version and clearly separating model general health education, bounded client health projections, and approved Chuklov content.
- [ ] Update only the existing orchestration/classifier seams so a serious diagnosis or medical term alone does not hand off; explicit human request, urgent red flags, unsupported technical action, and confirmed processing failure remain the only automatic handoff classes.
- [ ] Preserve negation handling so `привет, не надо специалиста` is not `human_requested`; preserve explicit `хочу поговорить со специалистом` handoff behavior; put useful educational content after any necessary short caveat.
- [ ] Allow long-answer intent to produce a genuinely detailed educational framework without medications/dosages, invented client facts, unsupported Chuklov claims, or generic refusal.
- [ ] Extend the existing health context builder with the minimum authorized current client survey/report/comparison projection needed for questions about test results, red blocks, Road Map, and dynamics; enforce organization/client authorization and bounded payloads.
- [ ] Add executable Companion evaluation cases (not markdown-only) covering the seven supplied cases plus casual health, musculoskeletal complaint, sleep/fatigue, vague message, medication safety, emergency red flag, and product questions. Assertions must check properties such as decision, handoff class, useful content, prohibited claims, context use, and long-answer shape rather than exact prose.
- [ ] Add focused classifier/orchestration/context/evaluation tests using the existing AI fake boundary and existing evaluation assertion registry.

## Task 6: Provider-neutral Payment Foundation and Fake lifecycle

- [ ] Extend the existing Finance payment tables/models/enums/contracts only where needed for explicit pending/succeeded/failed/refunded semantics, amount/currency snapshots, provider references, idempotency, provider events, refunds, and reconciliation; avoid a parallel ledger.
- [ ] Refactor the existing fake initiation path to claim idempotency and durable transaction state before external side effects, keeping provider calls outside long-lived row locks where the contract requires it.
- [ ] Add the smallest provider-neutral lifecycle operations for status verification/refund/event handling supported by current Finance architecture; do not add recurring subscriptions without a present product need.
- [ ] Make Fake/Demo Gateway run through the same verified-event/API application lifecycle for success, pending, failed, and refund. A redirect or success URL must never settle a payment.
- [ ] Add verified provider-event dedupe, replay-safe settlement/refund/reconciliation, amount/currency mismatch rejection, and no double-credit behavior under duplicate delivery/concurrency.
- [ ] Keep Tracker entitlement authoritative and separate: only verified Finance business decisions may grant/extend/revoke according to existing policy; no gateway adapter may directly own Tracker access.
- [ ] Add an explicit Russian/English demo-payment presentation in the existing Finance page only where the environment/configuration permits it, and fail closed so Fake cannot be accidentally exposed as a production customer payment path.
- [ ] Add focused tests for success, pending, failed, refund, redirect non-settlement, duplicate events, reconciliation replay, mismatch handling, tenant scope, and meaningful concurrency behavior.

## Task 7: Cabinet UX

- [ ] Replace the client-facing bottom-navigation label and key from `Ещё` to `Кабинет`, preserving the existing `/portal/more` route boundary where changing the URL would create unnecessary risk.
- [ ] Keep only the existing links `Личные данные`, `Мои записи`, `Оплата`, `Партнёрство`, and `Обратная связь`; remove the B2B item from the compact Cabinet list without deleting its existing route/page.
- [ ] Keep `Записаться`, `Здоровье`, and `Кабинет` visually clear and usable in the existing mobile shell; update translations, types, active-state handling, and tests.
- [ ] Verify the Cabinet at required mobile/desktop widths, with long labels and touch-sized controls, and check document scroll width.

## Task 8: Cross-boundary security and quality review

- [ ] Run focused PHP syntax, Pint on changed files, PHPUnit feature/unit tests, relevant frontend lint/type/build checks, and `git diff --check`; do not repair unrelated failures.
- [ ] Run the changed migrations and DB-sensitive tests against isolated PostgreSQL, including JSON/constraints/FKs, UTC timestamps, encrypted snapshots, idempotency/event uniqueness, upserts, transactions, repeat/version semantics, tenant isolation, and payment replay/concurrency.
- [ ] Run a focused browser/manual local or already-available staging path for Health start/complete/result/Road Map/repeat, Companion exact cases, Cabinet, and Fake lifecycle if an accessible runtime exists; otherwise report Staging `NOT RUN`.
- [ ] Perform a security review of authorization, protected health context, payment event verification, production Fake gating, and entitlement separation before finalizing.

## Task 9: Candidate, Draft PR, review, and handoff

- [ ] Commit the coherent implementation and push the feature branch.
- [ ] Create a Draft PR against `main` with the exact starting/final SHA, scope, verification evidence, explicit non-goals, and no merge/deploy action.
- [ ] Perform a substantive review of the actual diff and PR, classify findings as `BLOCKING`, `REAL BUG`, `WORTHWHILE`, or `COSMETIC`, and fix all BLOCKING/REAL BUG findings.
- [ ] Re-run only checks affected by review fixes, push the final SHA, and update the Draft PR.
- [ ] Report exact statuses for SQLite, PostgreSQL, Staging, and Owner acceptance; state `Merge: NOT PERFORMED` and `Production: NOT DEPLOYED`.
