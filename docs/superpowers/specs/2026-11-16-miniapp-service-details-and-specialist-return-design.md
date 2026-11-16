# Mini App service details and specialist return design

## Intent

Let a client understand a published service before booking and open the existing full specialist profile from every booking stage where a specialist is already known, without losing the booking selection on return.

## Goals

- Keep the service catalog compact while exposing the existing full service description in place.
- Keep `summary`, localized description, title, image, price, duration, and booking identifiers on their existing projections and CRM controls.
- Make disclosure and booking independent interactive actions.
- Make the selected specialist's existing profile reachable on the time-selection screen, including when the backend auto-selects the only specialist.
- Preserve the service, specialist, format, date range, timezone, location, patient, selected slot, and booking step when returning from the profile where those values are present.

## Non-goals and anti-goals

- No new service fields, tables, editors, catalogs, or content changes.
- No change to booking authorization, availability, pricing, specialist assignment, or medical flow.
- No catalog redesign, modal, unsafe HTML rendering, or automatic service/specialist content edits.
- No change to external links, production, merge state, or PR readiness.

## Chosen approach

Use a semantic `article` for service cards. Service cards of type `service` contain the existing image, title, compact summary, metadata, a native `<details>/<summary>` disclosure only when a non-empty full description differs from the summary, and an independent booking link. Full text remains Vue text interpolation with pre-wrapped CSS, so newlines and special characters remain safe. Non-service catalog items keep their existing purchase CTA and use the same description disclosure rules where data is available.

For specialist navigation, keep the existing specialist profile route and `detailsUrl`. Include the selected specialist in the booking-origin URL and preserve the allowlisted booking query state in the specialist profile's back URL. On the time-selection screen, derive the profile link from the current reactive booking state so a selected slot and confirmation step are retained even after client-side URL replacement. The profile's existing back link returns to the booking route; the existing specialist booking CTA remains unchanged.

## Verification

Add focused projection/feature assertions for separate and empty descriptions and focused Portal E2E coverage for disclosure, collapse, equal/empty text, keyboard activation, independent booking navigation, specialist profile access from the time screen, return-state preservation, RU/EN, and 320/360/390/768/1024/1440 pixel widths without horizontal overflow. Run the affected checks against the exact staging SHA in Chromium and WebKit.
