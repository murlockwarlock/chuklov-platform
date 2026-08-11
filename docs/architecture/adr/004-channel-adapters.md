# ADR-004: Capability-Aware Channel Adapters

- Status: Accepted
- Date: 2026-08-12

## Context

Telegram is first, while MAX/Instagram may differ in proactive sends, buttons, files, threads, and webapps.

## Decision

Define a channel-neutral `MessagingChannel` port with explicit capability snapshots. Adapters verify/normalize inbound events and deliver application outputs; they contain no business logic.

## Consequences

Core conversations/scenarios do not assume Telegram features. Optional channels require confirmed scope.
