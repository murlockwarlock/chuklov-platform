# Project Status

## Current snapshot — 2026-11-04

This file is the active status snapshot. Historical milestone narratives and
old candidate metadata are in
`docs/archive/PROJECT_STATUS-legacy.md`; they are evidence only.

| Item | Current value |
| --- | --- |
| Latest owner-accepted product candidate/runtime | `63cb0a51e1c4897dca794144e70374a8e1e395a2` |
| Latest product merge | PR #72 merged to `main` as `4b08330bf8f0732ed6a68d1018a401a9d1de31ef` |
| Latest exact staging runtime | `63cb0a51e1c4897dca794144e70374a8e1e395a2` |
| Product phase | Phase 1 |
| Active milestone stream | M11 `DONE`; M15 `NOT_STARTED` and deferred until bounded production-readiness/cutover work before M16 |
| M11 partner/reward closeout | CRM-only partner assignment/cabinet and reward/manual payout lifecycle are `OWNER ACCEPTED / DONE` on deployed SHA `de3252eb682d55d668e01852537d911ccdea364c`; deactivation disables new partner activity while preserving accrued rewards and payout eligibility |
| Communities | Configurable delivery mechanism verified; owner-managed links, text and media remain `CONTENT REQUIRED`, not a development blocker |
| M8 authored content | Mechanism/configuration ready; OQ-015 tracks approved-content replacement/activation |
| M12 | Core `OWNER ACCEPTED / MERGED`; recurring billing remains deferred |
| M13 | `BLOCKED` pending owner payment-provider selection; manual payment is usable without M13 |
| First-client readiness | `CHUKLOV CAN START REAL ONBOARDING`; `READY FOR CHUKLOV DATA ENTRY`; staging/product readiness only, not production readiness |
| First-client onboarding | `OWNER ACCEPTED / READY`; organization, services, specialists, schedule, client, booking, manual payment, Zoom, and AI owner setup/lifecycle are verified; owner supplies business values through CRM |
| AI provider execution | Real provider executions and AI owner setup/lifecycle are `OWNER ACCEPTED / VERIFIED`; Telegram runtime is `PASS`, while the real client Telegram Companion/notification path remains a separate pre-use check |
| Staging QA procedure | Repository-owned direct staging QA path: `docs/operations/staging-qa.md` |
| PR #71 | Closed, superseded, and unmerged |
| Blocking | `0` |
| REAL BUG | `0` after completed fixes |
| Owner acceptance | PR #72 owner accepted and merged; first-client onboarding and AI acceptance are complete; future product candidates require separate acceptance |
| Production | No production deployment claim from this governance cleanup |

## Current working rules

Missing authored business content is not a development blocker when the safe,
owner-configurable mechanism exists. Do not invent medical, legal, formula, or
authored content. See `AGENTS.md` and `.ai/rules/product-ui.md`.

This file is a snapshot, not an instruction source. Do not infer current
implementation status from its archived history, chat memory, or code presence
alone. Use current owner/task/requirements authority and explicit evidence
status for acceptance decisions.

## First-client readiness

`CHUKLOV CAN START REAL ONBOARDING` and `READY FOR CHUKLOV DATA ENTRY` are the
current staging/product readiness verdicts. Chuklov can configure business data
through the CRM without a developer. Human-readable illustrative/demo values
remain examples and are not business rules. First-client onboarding, manual
partial/full payment, OFFICE / ONLINE / HOME VISIT booking, Zoom, real AI
provider executions, AI owner setup/lifecycle, and first-time usability are
owner accepted or verified. This is not a production-readiness verdict.

Surveys/MSQ, authored Medical dictionaries/content, and Communities authored
materials remain `CONTENT REQUIRED`, not development blockers. OQ-005 remains
an owner payment-provider decision before M13. The real client Telegram
Companion/notification path remains a separate check before using that channel.

## M11 closeout

- Partner assignment/cabinet: `OWNER ACCEPTED / DONE`. CRM assigns
  an existing Client as Partner; the Partner cabinet exposes campaign links and
  statistics, with tenant authorization and reload-safe state. Deactivation
  removes new partner activity but keeps earned rewards and payout requests
  available.
- Reward/manual payout: `OWNER ACCEPTED / DONE`. Settlement-to-reward,
  reservation, cancel/reject, approve, externally-paid, balance/history,
  currency and idempotency behavior are covered by the existing focused
  evidence and current regression suite.
- Communities: the configurable delivery mechanism is closed. Missing links,
  copy and media are owner-configurable content only.
- The deactivated-partner path is deployed at exact SHA
  `de3252eb682d55d668e01852537d911ccdea364c`; staging smoke passed. Owner
  accepted the bounded M11 closeout behavior on 2026-11-04; M11 is `DONE`.

## Active blockers and decisions

- Future product candidates require owner acceptance and an explicit owner
  command before they are marked Ready or merged.
- M13 needs an owner-selected payment provider.
- OQ-015 needs approved authored content before activation; implementation of
  the configurable mechanism is not blocked.
- OQ-003, OQ-004, OQ-005, OQ-008, OQ-013, OQ-014, and OQ-015 remain open.
  OQ-005 is the M13 provider decision, OQ-013 is needed before production,
  OQ-015 is authored content, and OQ-014 does not block the first client.
  The other open questions are not current first-client blockers.
- Ops issues #62–66 remain open and are outside current product readiness.
- Product-specific acceptance or runtime blockers must be recorded in the
  current requirements/evidence documents, not by expanding this snapshot for
  every bounded fix.
