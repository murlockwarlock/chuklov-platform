# Chuklov Behavioral Proof Standard

This document is the detailed Chuklov extension of the global Behavioral
Verification / Proof Standard. The global rule and the root `AGENTS.md` are
mandatory; this document does not replace existing delivery, security,
PostgreSQL, staging, or UX rules.

## Owner acceptance is not technical QA

Do not ask the owner to manually discover:

- HTTP 500;
- broken button wiring;
- wrong navigation;
- missing modal;
- failed import;
- stale async state;
- incorrect notification CTA;
- wrong status transition;
- tenant/auth regression;
- duplicate side effect.

Automation must catch these wherever technically feasible. Owner acceptance is
for product and UX judgment after technical behavior is already proven.

## CRM and Portal two-sided proof

For workflows shared by CRM and the client Portal/Mini App, verify both sides.

Examples:

- Portal booking → CRM record, state, and action;
- CRM confirmation, reschedule, cancel, or payment → client-visible state.

Do not call a cross-surface workflow VERIFIED from one surface alone.

## Telegram internal navigation invariant

For CLIENT-FACING Telegram controls, any destination inside Chuklov
Portal/Mini App MUST use the existing Telegram Mini App `web_app` launch/auth
architecture. It MUST NOT be serialized as an ordinary external `url` button.

Internal destinations include, when implemented:

- Portal home;
- Health;
- Surveys;
- Feedback;
- Referrals;
- Tracker;
- Finance;
- Gift certificates;
- Companion;
- Communities/content;
- booking/client-cabinet destinations.

Genuinely external destinations remain ordinary URL buttons, including Zoom
meeting links and explicitly external websites.

Tests must assert the actual Telegram keyboard payload:

- internal: `web_app.url` exists and ordinary `url` does not;
- external: ordinary `url` exists and `web_app` does not.

A correct textual URL alone is not sufficient proof. Every new client Telegram
CTA must be explicitly classified as `INTERNAL_MINI_APP`, `EXTERNAL_URL`, or
`CALLBACK`.

## Notifications

Current notification flows require evidence for:

trigger → correct recipient → marketing/transactional consent rule → rendered
message → CTA label → CTA transport type → destination → dedupe/retry →
recipient-visible result.

Transactional booking and reminder messages must not be blocked by missing
marketing consent.

## Async UI

CRM and Portal async actions must update through the existing automatic
mechanism or bounded polling. An async flow is not VERIFIED when the user must
manually refresh to discover completion unless manual refresh is explicitly the
accepted UX.

## PostgreSQL and staging

SQLite is only a quick signal. DB-sensitive Chuklov behavior is not VERIFIED
until PostgreSQL evidence exists. If PostgreSQL has not been checked, report:

`POSTGRESQL NOT VERIFIED`

Staging is production-equivalent acceptance for production-visible behavior.
Use its real queues, workers, scheduler, and safely configured integrations as
appropriate. Never destructively reset the staging acceptance database.

## Browser proof

Critical CRM and Portal user journeys must use browser tests that click the
real controls when the defect class can exist only in rendered UI, JavaScript,
routing, button wiring, modal wiring, responsive layout, or browser
navigation.

Component existence, HTTP 200, route existence, and Livewire method tests are
not substitutes for browser proof in those cases.

## Bug-class remediation

When an owner manually finds a bug after green tests:

1. classify why the previous evidence falsely allowed VERIFIED status;
2. fix the exact bug;
3. add the exact regression;
4. add a systemic invariant when the bug represents a reusable class;
5. inspect sibling paths;
6. downgrade any over-broad VERIFIED claim until re-proven.

## Evidence language

Use the global meanings strictly:

- `PASS` means the named test or check passed;
- `VERIFIED` means the observable capability contract has sufficient evidence
  across the necessary layers;
- `PARTIALLY VERIFIED` means meaningful evidence exists but an important part
  remains unexercised;
- `NOT VERIFIED` means evidence is insufficient;
- `NOT IMPLEMENTED` means the capability does not exist.

Do not silently upgrade PASS to VERIFIED, and do not describe the product as
fully working while an observable path remains unproven.
