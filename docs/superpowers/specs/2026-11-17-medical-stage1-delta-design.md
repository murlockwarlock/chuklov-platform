# Medical Stage 1B Delta and Stage 1C Design

## Scope

Extend the existing medical catalog, clinical protocol, session history, private attachment storage, and client-card adapters from PR #82. Package A is the 16.11 requirements delta plus Stage 1B. Package B is Stage 1C. Both packages keep the existing application boundary and encrypted structured session payload.

## Decisions

- `MedicalClinicalCatalog → ClinicalTestProtocol → MedicalSessionTestWriter/Reader` remains the only clinical test path.
- `MedicalTestType::T13` is added to the existing type registry. Test 32 is a specialized catalog definition with structured Otto, Schober, Tomayer, Lasègue, and Patrick subtests.
- Every test result keeps a general confirmed index in addition to type-specific row indexes. Norms are catalog configuration; missing or unconfirmed norms fail closed and never classify automatically.
- Cause selections remain in the existing encrypted payload and cause link tables. Multiple primary causes are retained per region with a six-cause validation limit. Client-level root causes use a single persistent history table and session links, not a second cause database.
- Priority, stabilization, complex-case counts, and prior-test identity are persisted as additive metadata. Existing legacy value IDs and payloads remain readable.
- Stage 1C reuses `MedicalAttachment`, private storage, session attachment links, Telegram messaging ports, Filament client/session resources, and the existing PDF dependency where available.
- Pulse wave and “Наши специалисты” are explicitly deferred from Stage 1; no implementation or clinical inference is added for either.

## Safety boundaries

- Organization context and authorization are resolved server-side.
- Medical files, photos, and audio stay on private storage and are never exposed through public URLs.
- Telegram delivery is an explicit, idempotent adapter operation with durable status; staging never sends to real clients.
- Medical thresholds are configurable only through CRM. Values absent from the authoritative 16.11 sources remain disabled or informational.

## Verification boundary

Local checks are limited to documentation, diff, and syntax checks. PostgreSQL, migration, integration, and user-journey acceptance run on staging at the exact candidate SHA. Browser checks use one sequential Chromium or WebKit session and exercise the real CRM/Portal controls.
