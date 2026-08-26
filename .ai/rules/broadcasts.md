---
paths:
  - 'app/Modules/Broadcasts/**'
---

# Broadcasts

## Broadcast audiences freeze before marketing delivery
Broadcast production dispatch uses one immutable organization-scoped audience/template snapshot bound to the campaign's exact draft revision. Each recipient has a durable attempt row created before channel I/O. Telegram does not provide provider-backed `sendMessage` idempotency, so an acknowledgement/transport ambiguity becomes terminal `unknown` and is never blindly replayed; database uniqueness is not treated as external exactly-once delivery.

Revalidate current affirmative marketing consent, the exact verified target, the active/published marketing template, and the initiating creator's current organization membership/permission immediately before production work. Scheduler and queue state are wake-up mechanisms only: PostgreSQL campaign/batch state, bounded backoff, leases, and fencing are authoritative recovery state. Generic survey completion is eligible as a non-clinical engagement fact; encrypted survey results/categories and every clinical, medical, attachment, AI-medical, or free-text health filter remain unavailable until separately approved privacy/legal scope exists.

Marketing templates are Broadcast-only. Scenario rules accept only service/transactional purposes and their compatible active published templates. Broadcast template variables are limited to the context guaranteed by the snapshot (`client.full_name` and `client.language`).
