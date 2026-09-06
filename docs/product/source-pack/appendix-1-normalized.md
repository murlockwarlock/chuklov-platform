# Appendix 1 — normalized onboarding requirements

Source: `ПРИЛОЖЕНИЕ №1: Спецификация WebApp-Анкеты и Форм Onboarding`.

## Product rules

### Progressive profiling

- On WebApp entry, identify a returning Telegram client through the verified Telegram identity.
- Prefill known client/profile values from the existing profile and medical-profile boundaries.
- Show prefilled values for confirmation rather than silently replacing them.
- Keep the existing server-derived organization and client context. The source does not authorize client-supplied tenant identifiers.

### Smart attribution

- A referral entry such as `start=ref_123` stores referral attribution and hides the manual “Откуда вы про нас узнали?” field.
- UTM attribution stores the automatic source and hides the same manual field.
- Organic entry shows the manual source field.
- The existing first-touch and attribution implementation remains authoritative.

### B2B segmentation

- Ask whether the client is a massage/bodywork specialist.
- Persist the explicit answer through the existing B2B profile authority.
- Do not introduce a parallel tag or segmentation subsystem. Any existing warm-up/routing flow remains the application boundary.

## Wizard content

### Screen 1 — contacts and source

The source lists first name, last name, read-only Telegram username when available, phone, country, city, birth date, an organic lead source when automatic attribution is absent, and the B2B specialist question.

Organic source choices are Recommendation, Telegram channel, Instagram, Yandex/Google search, Website, and Other. Recommendation conditionally asks for the recommender’s contact or details.

### Screen 2 — clinical profile

The source lists complaint chips, complaint details, pain intensity from 0 to 10, operations yes/no with conditional year/details, injuries/fractures yes/no with conditional year/details, and medication/supplement free text.

The complete 9 systems/MSQ body and scoring are not defined here and must not be fabricated.

### Screen 3 — service and format

The source lists Office, Home Visit, and Online. Home Visit conditionally collects area, address, and participants. Services come from the active service catalog and show the catalog’s available duration, description, and price.

Newer v2.2 booking, working-location, location-day, and timezone behavior supersedes any less precise source wording.

### Screen 4 — goals, documents, consent

The source lists health goals, medical-document upload, required legal consents, and a format-dependent CTA.

- Legal text comes from the existing published/versioned legal-document system; no legal copy is invented here.
- Medical documents are images/PDFs and may be multiple files in the source intent.
- The existing private Class C attachment boundary, UUID storage, MIME validation, size limits, checksums, and authorized access remain stricter platform requirements.

## Platform reconciliation

Appendix 1 describes a configurable four-screen JSON-driven wizard. The current accepted product uses progressive, action-specific Portal/Profile/Booking collection rather than making a legacy onboarding route the destination. The source fields are therefore a requirements inventory and acceptance reference, not permission to replace newer v2.2 behavior or to add a duplicate survey, attribution, B2B, legal, or attachment subsystem.
