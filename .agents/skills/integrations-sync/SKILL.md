---
name: integrations-sync
description: Implement external system adapters and reliable synchronization. Use for CRM, calendar, catalog, messaging, or other external import/export work from its approved milestone onward.
---

# Integrations and Synchronization

1. Confirm the milestone and load affected integration requirements plus `docs/architecture/integrations.md`.
2. Keep vendor SDKs and payloads in Infrastructure adapters behind an explicit port.
3. Define ownership, direction, mapping, cursor, conflict, retry, and deletion semantics before coding sync loops.
4. Preserve organization scope and validate external identifiers against the resolved organization.
5. Make operations idempotent and observable without logging sensitive payloads.
6. Use bounded retries and dead-letter handling; prevent retry storms.
7. Test duplicate, reordered, partial, invalid, and unavailable-provider scenarios with fakes.
