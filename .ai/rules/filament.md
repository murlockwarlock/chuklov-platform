---
paths:
  - 'app/Filament/**'
---

# Filament

## CRM copy is operational
CRM labels, help text, notifications, and errors must help organization owners or staff perform an action, understand state, provide required input, or take an actionable next step. Never show developer/architecture terminology, implementation details, demo copy, or empty AI/SaaS marketing language; Russian-facing CRM text must not contain English developer/demo copy.

## Bound per-row work in list/table/resource views
Filament list/table/resource planning must identify work executed per rendered row. Do not hide database queries, external I/O, expensive decryption, or repeated business calculations inside per-row callbacks such as `visible()`; `disabled()`; badges/state formatters; action/policy authorization callbacks; or `getStateUsing()` or equivalents, unless the design proves bounded/preloaded/request-scoped behavior. Do NOT weaken Application/Policy authorization to improve query count. Prefer explicit projections, preloading, and request-scoped reuse over hidden lazy loading; do not eager-load an entire relationship graph merely to reduce query count.
