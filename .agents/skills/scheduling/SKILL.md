---
name: scheduling
description: Implement booking, availability, slot, hold, and calendar rules. Use for scheduling domain changes from Milestone 4 onward.
---

# Scheduling

1. Confirm the current milestone permits the work, then load the scheduling `REQ-*` group and `docs/architecture/scheduling.md`.
2. Model booking and payment state separately; never restore a combined `CONFIRMED_PAID` state.
3. Keep time zones, slot boundaries, concurrency, holds, cancellation, and rescheduling rules explicit.
4. Use Domain types for states and time concepts and Application actions for workflows.
5. Apply organization scope to every read and write.
6. Protect competing writes with database constraints or transactions appropriate to PostgreSQL.
7. Test transition rules, races, time-zone edges, and authorization before UI work is considered complete.
