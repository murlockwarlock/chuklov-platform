# Architecture Overview

Chuklov is a modular monolith. Web, Filament CRM, Telegram, queues, and future adapters are delivery boundaries over one Application/Domain core and one PostgreSQL data model.

```text
HTTP / Filament / Inertia / Telegram / Jobs
                    ↓
               Application
                    ↓
                 Domain
                    ↓
         Infrastructure adapters
                    ↓
PostgreSQL + pgvector / Redis / private Storage / external APIs
```

Phase 1 runs one organization but all owned data uses `organization_id`. See ADR-001 through ADR-006 and `modules.md`.
