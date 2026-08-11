---
name: payments
description: Implement payment workflows and gateways with idempotency, separate booking state, and auditability. Use for payment initiation, callbacks, refunds, reconciliation, or gateway adapters from Milestone 5 onward.
---

# Payments

1. Confirm the milestone permits payment work, then load payment `REQ-*` entries and `docs/architecture/payments.md`.
2. Integrate providers through `PaymentGateway`; keep provider payloads out of Domain logic.
3. Maintain payment state separately from booking state.
4. Verify callback authenticity and organization ownership server-side.
5. Make callback processing and commands idempotent and auditable.
6. Never log credentials, full payment instruments, or sensitive callback payloads.
7. Use gateway fakes in tests; normal CI must not perform paid or external calls.
8. Test duplicate callbacks, stale transitions, failures, authorization, and reconciliation behavior.
