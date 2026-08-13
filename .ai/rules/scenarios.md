---
paths:
  - 'app/Modules/Scenarios/**'
---

# Scenarios

## Evaluate materialized condition snapshots
ScenarioAction execution must evaluate its immutable condition_snapshot, not the mutable current ScenarioRule conditions. The current rule is_enabled flag may remain a deliberate live stop control, but rule edits must not rewrite already materialized action semantics.
