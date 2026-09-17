# Phase 1 Source Reconciliation — 2026-08-24

## Purpose

This document records a source-reconciliation pass against the private client source package available on 2026-08-24:

- `11.08.26 ТЗ на CRM-бота v.2_2 detailed.docx`
- `11.08.26 Лог изменений ТЗ changelog v2_2.docx`
- owner-confirmed clarifications from 2026-08-24

The private source files remain local-only under the repository source-requirements policy and are not copied here. This file captures only durable requirement conclusions and gaps that must not be lost before Phase 1 closeout.

This reconciliation does not supersede `docs/product/requirements.md`; it identifies normalization changes that must be carried into executable REQ rows / roadmap before the dependent work is implemented or closed.

## Confirmed: existing architecture is still valid

No completed M0–M10.2 implementation is invalidated by this reconciliation.

The following normalization choices remain intentional and correct:

- `organization_id`, not client-source `master_id`, remains the tenant/security boundary.
- Laravel/Horizon modular-monolith architecture remains authoritative; source suggestions such as Celery are not business requirements.
- Booking and payment lifecycle states remain separate.
- Money/finance remains ledger-based and provider confirmation remains server-side.
- Raw DICOM remains excluded; supported medical inputs are ordinary text/PDF/image documents and posture photos.
- AI output remains reviewable/provenanced and must not silently become a confirmed clinical fact.
- Phase 2 white-label/SaaS runtime must not be pulled into Phase 1 merely because the source document proposed future technical architecture.

## Phase 1 commitments that are source-backed and must remain explicit

### 1. Referral / partner funnel is Phase 1

Client v2.2 explicitly includes the B2C `Партнёрство & Сарафанный маркетинг` funnel:

- client-facing `Стань партнёром` entry;
- one-click unique referral link;
- prepared promotional text/banner concept;
- automatic tracking of referral traffic / registrations;
- client-visible bonus balance concept.

Existing `REQ-ATTRIBUTION-001` and `REQ-REFERRAL-001` correctly keep this in M11 / Phase 1.

Owner-confirmed 2026-08-24 clarification adds:

- referral relation must also be creatable manually by authorized CRM staff;
- the CRM relation `referrer -> referred client` is the operational source of truth for later reward accounting and payouts;
- the same referral foundation is intended to be reusable across current paid practitioner services and future subscription/course/bot-sale contexts.

This clarification does **not** define reward economics.

OQ-007 remains OPEN for, at minimum:

- qualification/earning rule per program/product;
- amount / percentage / points / reward currency;
- redemption;
- expiry;
- refund reversal;
- cash-out / payout semantics.

Do not invent these rules.

### 2. Organic recommendation claim must not be lost

When automatic ref/UTM attribution is absent, client v2.2 allows `Рекомендация знакомых` with a free/structured hint such as recommender Telegram nickname, name, or phone.

This is not verified identity evidence.

Required normalization:

- retain it only as an unverified referral/recommendation claim or CRM matching hint;
- never auto-merge or auto-link clients by name, nickname, or phone similarity;
- authorized staff may establish the canonical referral relation manually after verification;
- existing first-touch attribution must not be silently rewritten.

### 3. B2B bot-sales lead funnel is Phase 1

Client v2.2 explicitly includes a Phase 1 B2B funnel for selling the bot/system:

- identify whether the user is a massage/bodywork specialist;
- B2B segment/tag concept;
- `Хочешь себе такого бота? / Развить бизнес` CTA;
- B2B lead form;
- Zoom-call booking / lead handoff to Evgeny.

The client Phase 1 roadmap explicitly includes the B2B Zoom lead form.

This is separate from Phase 2 white-label runtime.

Required normalization:

- add an explicit executable B2B lead requirement rather than relying only on the generic Telegram menu/Broadcast segment rows;
- CRM must be able to see/manage the resulting B2B lead state;
- no Phase 2 tenant onboarding, self-service bot provisioning, white-label runtime, tenant billing, or external-master merchant setup is implied.

### 4. Bot referral commissions are a new owner clarification, not an old detailed rule

The client source includes both the B2B bot-sales funnel and the B2C referral program, but does not explicitly define a commission formula for a partner who refers a bot buyer.

Owner-confirmed 2026-08-24 clarification states that future referral rewards may also apply to bot sales.

Required normalization:

- preserve product-neutral referral/commercial evidence architecture so future bot-sale evidence can participate;
- do not treat this clarification as a resolved payout rule;
- keep OQ-007 open.

### 5. DIKIDI-style CRM calendar and location-day behavior must not disappear silently

The client changelog/source explicitly includes:

- custom web master calendar in DIKIDI-style;
- drag-and-drop interaction;
- colored/status-aware operational calendar concept;
- home-visit logistics with a roughly 4.5-hour operational block in the source proposal;
- `Локационные дни` / area-based home-visit planning (example: Bang Tao).

Current M4 correctly implemented the safe scheduling domain, availability, buffers, home-visit review and transactional conflict controls. This reconciliation does not require undoing M4.

However the richer CRM interaction/location-day behavior is not clearly represented as a remaining executable requirement.

Before Phase 1 closeout, one of the following must happen:

1. normalize and implement the still-required CRM calendar/location-day behavior on top of the existing safe M4 domain; or
2. record a later owner-confirmed superseding decision that removes/replaces it.

Do not silently mark it satisfied merely because base scheduling exists.

### 6. Physical / online product storefront is Phase 1 source scope, but commerce semantics are unresolved

Client v2.2 includes a storefront concept for additional physical and online products.

Current `REQ-PRODUCT-001` correctly distinguishes catalog types, while `REQ-PRODUCT-002` keeps checkout/quantity/inventory/delivery/refunds/variants open.

Required normalization:

- keep the storefront/product-sale outcome visible in the Phase 1 plan;
- do not invent cart, stock, shipping, refund, or variant behavior;
- before implementation, resolve the missing commerce semantics through OQ-003 or a replacement explicit product decision;
- do not allow this requirement to disappear merely because catalog typing already exists.

### 7. Telegram community routing is Phase 1 source scope

Client v2.2/changelog includes post-service routing/invitations to configured Telegram communities/channels.

Required normalization:

- add an explicit requirement or trace it to an existing Content + Scenario/Notification capability;
- channel/community links must be managed/configured content, not hardcoded production copy;
- delivery must respect the existing communication/consent boundary where applicable.

### 8. Specialized AI workflows remain Phase 1 product scope

Client v2.2 includes specialized AI workflows for:

- clinical/document extraction from supported conclusions/documents;
- posture-photo analysis;
- clinical synthesis / master-facing case summary;
- AI Companion.

M10/M10.1/10.2 correctly built the controlled AI platform and Companion foundation. The specialized capabilities exist architecturally, but Phase 1 must not be considered complete solely because the capability registry/control plane exists.

Required normalization:

- retain an explicit productization slice before M15 for supported document extraction, posture analysis and master-facing synthesis;
- use source-backed/approved prompts and medical constraints only;
- do not fabricate Appendix 1/2 content that is missing under OQ-015;
- no AI draft silently becomes a specialist-confirmed clinical fact.

### 9. B2C subscription / Health Tracker and Zoom upsell remain Phase 1

Client v2.2 and changelog explicitly include:

- paid B2C AI Health Tracker subscription;
- recurring tracker/coaching behavior;
- paid 15-minute Zoom mentoring upsell.

Current M12 direction remains correct.

Billing renewal/cancel/failure/grace/entitlement-end semantics remain blocked by the corresponding open billing question and must not be guessed.

## Phase 2 boundary reaffirmed

The following remain Phase 2 / future and must not be pulled into Phase 1 by accident:

- Multi-Bot Engine / white-label bot provisioning for independent masters;
- self-service SaaS tenant onboarding;
- tenant subscription plans / tenant billing / quotas;
- individual merchant/gateway setup for independent masters;
- generalized dynamic cockpit builder for other professions;
- two-way Google Calendar / iCal synchronization unless separately re-scoped;
- expanded SaaS RBAC required specifically by the multi-master product.

Phase 1 may preserve extension points for these, but does not implement the SaaS runtime.

## Required follow-up normalization

After the active M11A implementation/remediation branch is stable, normalize these conclusions into `docs/product/requirements.md`, `requirements-changelog.md`, `ROADMAP.md`, and `open-questions.md` where applicable.

At minimum the executable requirement set should explicitly cover:

- M11 referral CRM manual relation + unverified organic recommendation claim handling;
- Phase 1 B2B bot-sales/Zoom lead funnel and CRM lead handling;
- owner-confirmed future referral applicability to bot sales without inventing rewards;
- remaining DIKIDI-style calendar/location-day product work or an explicit superseding owner decision;
- Phase 1 product storefront outcome subject to commerce-rule resolution;
- Telegram community routing;
- specialized AI workflow productization before M15;
- existing M12 B2C subscription/Zoom upsell scope.

## Guardrail

Do not use this reconciliation to retroactively reopen completed milestones whose accepted architecture already satisfies the normalized business requirement. New source-backed gaps should be scheduled as bounded follow-up slices on top of the existing domain foundations.
