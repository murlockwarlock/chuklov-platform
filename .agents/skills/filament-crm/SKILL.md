---
name: filament-crm
description: Build or change the Chuklov Filament CRM while preserving authorization and application boundaries. Use for Filament resources, pages, forms, tables, or admin workflows.
---

# Filament CRM

1. Read the affected `REQ-*`, `docs/architecture/overview.md`, and current Filament resource tests.
2. Consult Laravel Boost for the installed Filament and Laravel APIs before version-sensitive changes.
3. Keep resources and pages as adapters. Invoke Application actions for writes and query objects/actions for reads.
4. Scope queries from server-resolved `OrganizationContext`; never accept tenant scope from form or request data.
5. Enforce authorization independently of whether a UI action is hidden.
6. Split schemas, tables, and real workflow concepts when a resource becomes broad.
7. Add focused regression coverage only where the changed behavior is likely to regress or the defect lacks sufficient coverage.
8. For bounded UX fixes, verify the exact staging user journey first and run only checks affected by the change. Do not require `make lint`, `make static`, or full browser suites before owner-visible staging verification unless the changed risk requires them.

## Primary User Task First

The primary user task must be visible and obvious. Put the primary user task before the internal data model: if a user opens a Prompt, show the prompt itself first; if a user opens a Partner, show partner actions first; if a user opens a Booking, show booking actions and status first.

Avoid “CRM quest” UX, hidden primary actions, unnecessary horizontal scrolling, and nested scroll mazes for core tasks.
