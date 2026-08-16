---
paths:
  - 'app/Modules/Knowledge/**'
---

# Knowledge

## Keep RAG retrieval tenant-first and configuration-compatible
Always constrain Organization, active source/revision, ready run, and allowlisted scope before vector ordering. Query and stored embedding provider/model/dimensions/configuration must match or retrieval fails closed. Phase 1 uses exact cosine ordering with a deterministic ID tie-break; add an approximate vector index only after measured scale and tenant-filter regression evidence.
