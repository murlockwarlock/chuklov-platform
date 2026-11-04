---
name: filament-crm
description: Build or change the Chuklov Filament CRM while preserving authorization and application boundaries. Use for Filament resources, pages, forms, tables, or admin workflows.
---

# Filament CRM

1. Read the affected `REQ-*`, `docs/architecture/overview.md`, the matching
   `.ai/rules/product-ui.md`, and current resource tests.
2. Consult Laravel Boost for installed Filament/Laravel APIs before
   version-sensitive changes.
3. Keep resources and pages as adapters. Invoke Application actions for writes
   and query objects/actions for reads. Scope through server-resolved
   `OrganizationContext`; never accept tenant scope from form/request data.
4. Enforce authorization independently of whether a UI action is hidden.
5. Apply the canonical first-time usability gate: put the primary user task
   before the internal data model, avoid CRM quest UX and nested scroll mazes,
   and make 0/1/many states and 10–30 object collections understandable.
   A giant repeater/backend-shaped form, raw technical values, numeric
   ordering without reorder interaction, dead-end empty state, or inconsistent
   CTA is a `REAL BUG`.
6. Split schemas, tables, and real workflow concepts when a resource becomes
   broad. Add focused regression coverage where the changed behavior can
   regress.
7. For bounded UX fixes, verify the exact staging user journey first and run
   only affected checks. Do not require full lint, static analysis, or browser
   suites before staging unless the changed risk requires them.
