# ADR-022: Direct Telegram Upload for Managed Media

- Status: Accepted
- Date: 2026-09-03

## Context

Broadcasts and Telegram content sections stored organization-managed images as public storage URLs. Telegram then had to fetch those URLs itself, so a URL that opened in an operator's browser could still be rejected by Telegram's image fetcher.

## Decision

1. Organization-managed content is read through the organization-scoped storage contract as a one-shot stream after the broadcast delivery claim and immediately before channel I/O.
2. The Telegram adapter wraps that stream in Nutgram's `InputFile`, which sends the image as multipart data. External HTTPS images continue to use the validated URL path.
3. The shared notification message carries only the provider-neutral stream source; Telegram-specific upload types remain inside the Telegram adapter. Streams are closed on every channel exit path.
4. Telegram 4xx responses are converted to bounded safe reason codes such as blocked bot, unavailable chat, formatting rejection, or unavailable media. Raw provider descriptions are not persisted or logged.

## Consequences

- Uploaded broadcast and content images no longer depend on Telegram reaching the CRM's public storage route.
- Storage adapters must provide an organization-validated readable stream for managed content.
- A lost acknowledgement remains `unknown` under the existing Telegram delivery policy; direct upload does not create provider-side idempotency.
