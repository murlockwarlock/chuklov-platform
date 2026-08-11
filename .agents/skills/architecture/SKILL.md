---
name: architecture
description: Apply Chuklov modular-monolith boundaries and record architectural decisions. Use for cross-module design, new ports or adapters, dependency direction, or ADR work.
---

# Architecture

1. Read `AGENTS.md`, then `docs/architecture/overview.md` and `docs/architecture/modules.md`.
2. Load only the affected `REQ-*` entries from `docs/product/requirements.md` and relevant ADRs.
3. Keep delivery as a Laravel modular monolith unless a new accepted ADR proves a deployment boundary is required.
4. Put business behavior in Domain or Application code. Keep controllers, Filament resources, handlers, jobs, and Vue components thin.
5. Add a port only for a demonstrated boundary such as channels, payments, knowledge retrieval, storage, AI providers, or external synchronization.
6. Treat `organization_id` as the tenant and security boundary; never substitute `master_id`.
7. Record consequential, durable architecture choices in a focused ADR. Put implementation changes in `CHANGELOG.md`, not the ADR.
8. Check file cohesion and the size guardrails in `AGENTS.md` before finishing.
