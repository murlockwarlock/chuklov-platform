# Project Status

## Current snapshot — 2026-11-04

This file is the active status snapshot. Historical milestone narratives and
old candidate metadata are in
`docs/archive/PROJECT_STATUS-legacy.md`; they are evidence only.

| Item | Current value |
| --- | --- |
| Latest owner-accepted product runtime | `de3252eb682d55d668e01852537d911ccdea364c` |
| Latest product merge | PR #69 merged to `main` as `e038fd8d3bc321ae0ce468333d1bbb1d26d943ee` |
| Latest exact staging runtime | `de3252eb682d55d668e01852537d911ccdea364c` |
| Product phase | Phase 1 |
| Active milestone stream | M11 `DONE`; M15 `IN_PROGRESS` |
| M11 partner/reward closeout | CRM-only partner assignment/cabinet and reward/manual payout lifecycle are `OWNER ACCEPTED / DONE` on deployed SHA `de3252eb682d55d668e01852537d911ccdea364c`; deactivation disables new partner activity while preserving accrued rewards and payout eligibility |
| Communities | Configurable delivery mechanism verified; owner-managed links, text and media remain `CONTENT REQUIRED`, not a development blocker |
| M8 authored content | Mechanism/configuration ready; OQ-015 tracks approved-content replacement/activation |
| M12 | Core `OWNER ACCEPTED / MERGED`; recurring billing remains deferred |
| M13 | `BLOCKED` pending payment-provider selection |
| Owner acceptance | PR #61 owner acceptance completed and merged; M11 partner assignment/cabinet and reward/manual payout are owner accepted; future product candidates require separate acceptance |
| Production | No production deployment claim from this governance cleanup |

## Current working rules

Missing authored business content is not a development blocker when the safe,
owner-configurable mechanism exists. Do not invent medical, legal, formula, or
authored content. See `AGENTS.md` and `.ai/rules/product-ui.md`.

This file is a snapshot, not an instruction source. Do not infer current
implementation status from its archived history, chat memory, or code presence
alone. Use current owner/task/requirements authority and explicit evidence
status for acceptance decisions.

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
- Product-specific acceptance or runtime blockers must be recorded in the
  current requirements/evidence documents, not by expanding this snapshot for
  every bounded fix.
