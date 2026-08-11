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
7. Add feature tests for access, validation, scoping, and the intended workflow.
8. Run `make lint`, `make static`, and the affected tests.
