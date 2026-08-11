# ADR-003: Responsive Inertia Client Portal

- Status: Accepted
- Date: 2026-08-12

## Context

Clients require desktop/mobile web and Telegram Mini App without divergent systems.

## Decision

Use Vue 3, Inertia 3, TypeScript, Tailwind, and Vite. Telegram-specific APIs are a runtime adapter around the same routes and application core.

## Consequences

Portal behavior remains server-authorized and reusable; platform-specific UX stays isolated.
