# Chuklov Project Roadmap

Statuses are objective: `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `DONE`. Completion is based only on exit criteria, never subjective percentages.

| Milestone | Business outcome | Technical outcome | Status | Exit criteria | Dependencies | REQ groups |
|---|---|---|---|---|---|---|
| M0 Repository Foundation | A demonstrable, supportable project foundation | Locked stack, harness, Compose/CI, Organization context, CRM→Service→Portal slice | DONE | All M0 definition-of-done items and `make ci` pass; status docs updated | Authoritative sources | FOUNDATION, ORG, PORTAL, CHANNEL, SERVICE, AI, SEC |
| M1 Organizations / Identity / Settings / Security | Safe staff/client ownership foundation | Memberships, clients/identities, RBAC, settings, encryption, audit | DONE | Cross-organization access is impossible across implemented entities; focused security and complete quality gates pass | M0 | ORG, IDENTITY, CLIENT, SEC |
| M2 Client Portal + Telegram | Same client journey foundation on responsive web and Telegram | Shared portal runtime, verified Telegram initData/session, progressive profiling, localized bot entry, conversation foundation | BLOCKED | Telegram-authenticated client data is consistent across responsive web, Mini App, and CRM; ordinary web auth is implemented only after OQ-001 is resolved | M1, OQ-001 | PORTAL, CHANNEL, IDENTITY |
| M3 CRM Core / Services / Specialists | Owner manages core catalog and practice settings | Full Service/catalog settings, specialists, content, branding | NOT_STARTED | Authorized CRM configuration drives portal behavior | M1–M2 | CRM, SERVICE, PRODUCT, CHANNEL |
| M4 Scheduling / Booking | Clients can request/manage safe appointments | UTC/IANA availability, office/home/online, conflict controls, history | NOT_STARTED | All booking paths and concurrency/security tests pass | M3, OQ-002 | BOOKING, TIMEZONE |
| M5 Scenario / Notification Engine | Configurable follow-up and retention | Typed triggers/actions, timings/templates, idempotent delivery | NOT_STARTED | Seeded defaults are editable and replay cannot duplicate delivery | M2, M4 | NOTIFY, FEEDBACK |
| M6 Finance / Multi-Currency | Accurate prices, payments, balances, and debt | Money/currency snapshots, ledger, manual/partial payment, fake gateway | NOT_STARTED | Reconciliation and precision/security tests pass | M3–M5 | CURRENCY, PAYMENT |
| M7 Medical Profiles / Sessions / Attachments | Secure longitudinal client record | Consents, cockpit, private files, dynamics, access controls | NOT_STARTED | Sensitive data/file authorization suite passes | M1, M4 | MEDICAL, ATTACHMENT, SEC |
| M8 Surveys / Road Map | Repeatable diagnostic surveys and client reports | Versioned survey engine, 9-systems/MSQ import path, immutable attempts | NOT_STARTED | Version/scoring/stagnation/report tests pass | M2, M5, M7 | SURVEY |
| M9 RAG | Controlled organization knowledge retrieval | Versioned ingestion, pgvector retrieval, scopes, references, eval seed | NOT_STARTED | Cross-org/injection/retrieval regression tests pass | M1, M7 | RAG, SEC |
| M10 AI Components | Reviewable AI-assisted workflows | Provider config, agents, prompts/runs, structured output, queues, RAG tools | NOT_STARTED | No unreviewed clinical claim becomes confirmed fact; fake and provider tests pass | M7–M9 | AI |
| M11 Attribution / Referrals / Feedback / Marketing | Measurable acquisition and governed outreach | Attribution, referral ledger, NPS, Broadcast Engine, segmentation/audit | NOT_STARTED | Ledger, consent, segmentation, batching, idempotency tests pass | M2, M5–M6 | ATTRIBUTION, REFERRAL, FEEDBACK, BROADCAST |
| M12 B2C Subscription / Tracker / Zoom | Paid retention and expert upsell foundation | Products, entitlements, tracker scenarios, Zoom offer | NOT_STARTED | Entitlement flow works; billing behavior matches confirmed lifecycle | M5–M6, M8, OQ-004 | SUBSCRIPTION |
| M13 Real Payment Adapters | Agreed payment methods operate safely | Contracted gateways, sandbox/webhooks, reconciliation | NOT_STARTED | Per-provider security/idempotency/reconciliation suite passes | M6, OQ-005 | PAYMENT |
| M14 Omnichannel Adapters | Optional additional contracted channels | MAX/Instagram adapters through shared capabilities | NOT_STARTED | Only confirmed adapters pass capability/delivery tests | M2, M5, M11 | CHANNEL |
| M15 Hardening / E2E | Release candidate withstands critical failures | Full security/E2E, dependency/privacy/load/failure-mode review | NOT_STARTED | Critical Playwright/security suite and release checklist pass | M1–M14 in scope | All Phase 1 |
| M16 Production | Recoverable monitored production service | Atomic deploy, rollback, backup/restore, monitoring, smoke tests | NOT_STARTED | Exact revision healthy; restore and rollback drills verified | M15 | FOUNDATION, SEC |

Current detail: `PROJECT_STATUS.md`. Product acceptance detail: `docs/product/requirements.md`.
