# Project Status

## Current snapshot — 2026-11-04

This file is the active status snapshot. Historical milestone narratives and
old candidate metadata are in
`docs/archive/PROJECT_STATUS-legacy.md`; they are evidence only.

| Item | Current value |
| --- | --- |
| Product base | `origin/main` at `8b85efb65ee5d7cf80940cbec29c26a004871f56` |
| Product phase | Phase 1 |
| Active milestone stream | M11 `IN_PROGRESS`; M15 `IN_PROGRESS` |
| M8 authored content | Mechanism/configuration ready; OQ-015 tracks approved-content replacement/activation |
| M12 | Core `OWNER ACCEPTED / MERGED`; recurring billing remains deferred |
| M13 | `BLOCKED` pending payment-provider selection |
| Last documented staging runtime | `9e4f78c61731a9714dddbb35beea7d1e13643df8` in historical acceptance evidence; this cleanup does not redeploy or reverify it |
| Owner acceptance | Required for active product candidates; this governance branch has no product acceptance claim |
| Production | Not deployed by this cleanup |

## Current working rules

Missing authored business content is not a development blocker when the safe,
owner-configurable mechanism exists. Do not invent medical, legal, formula, or
authored content. See `AGENTS.md` and `.ai/rules/product-ui.md`.

This file is a snapshot, not an instruction source. Do not infer current
implementation status from its archived history, chat memory, or code presence
alone. Use current owner/task/requirements authority and explicit evidence
status for acceptance decisions.

## Active blockers and decisions

- Owner acceptance and the explicit owner command remain required before a
  product candidate is marked Ready or merged.
- M13 needs an owner-selected payment provider.
- OQ-015 needs approved authored content before activation; implementation of
  the configurable mechanism is not blocked.
- Product-specific acceptance or runtime blockers must be recorded in the
  current requirements/evidence documents, not by expanding this snapshot for
  every bounded fix.

## Governance cleanup

This instruction cleanup is isolated on
`codex/governance-instruction-cleanup` from `origin/main`. It changes
instructions, skills, and evidence labeling only; it does not claim product,
staging, browser, PostgreSQL, or CI verification.
