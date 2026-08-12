# Date and time conventions

M2 establishes one date/time boundary for the portal, CRM integrations, future notifications, and future scheduling.

## Storage and domain values

- Real instants use timezone-aware PostgreSQL timestamps and are handled canonically at the application boundary. M2 authentication expiry/consumption, legal publication, consent evidence, onboarding completion, identity verification, and audit event instants use this convention.
- Calendar-only values, such as a future date-of-birth field, must use a database `DATE`; no date-of-birth field is added in M2.
- Formatted strings such as `DD-MM-YYYY` are presentation values only and are never domain/database values.
- Timezone context is an IANA identifier, for example `Asia/Almaty`, `Europe/Moscow`, or `Asia/Bangkok`. Fixed UTC offsets are not accepted as timezone context.

The organization has a legacy-compatible timezone column and the M1 `default_timezone` organization setting. The setting is the application-level default when present; the organization timezone is the safe fallback. Both are validated as IANA identifiers.

Future client, specialist, location, and booking timezone context remains optional and must be stored as an IANA identifier or resolved from an explicit context—not as a fixed offset.

## Presentation

The portal uses the shared `resources/js/utils/dateTime.ts` utilities and `PortalDateTime` component. Phase 1 defaults are date `DD-MM-YYYY` and time `HH:mm` (24-hour). Locale, order, separator, time cycle, and timezone are passed through the shared preference boundary so future organizations can localize presentation without page-level formatting logic.

The same instant can therefore be rendered for organization, specialist, and client contexts without changing stored data.
