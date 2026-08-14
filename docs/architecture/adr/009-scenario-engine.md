# ADR-009: Configurable Scenario Engine

- Status: Accepted
- Date: 2026-08-12

## Context

Follow-up intervals, conditions, content, and channels change operationally.

## Decision

Represent typed triggers/actions, delays, conditions, templates/prompts, scheduled actions, deliveries, and dedupe keys as organization configuration.

## Consequences

v2.2 timings are seed data. Arbitrary code execution and security-invariant feature switches are prohibited. M5B confirms that repeatable scenario families are generic action sequences: maximum occurrences, interval, condition snapshot, recipient, channel order, render context, and template revision are persisted configuration/snapshots, while event-family behavior is provided only through registered typed conditions and event boundaries.
