# ADR-013: Authentication Approach

- Status: Accepted
- Date: 2026-08-12

## Context

Privileged CRM requires secure Laravel session authentication. Client browser authentication and Telegram channel connection are separate from the Client identity itself. Telegram Mini App uses verified initData.

## Decision

Use Laravel session/CSRF primitives for CRM and Client Portal sessions. Ordinary browser clients authenticate with a normalized email and a short-lived, hashed, single-use, bounded one-time verification code delivered through a provider-neutral email port. Successful authentication regenerates the session. Telegram Mini App initData is verified server-side with freshness/replay controls; connecting Telegram to an existing web client uses a server-generated, short-lived, single-use token bound to the current organization/client and authentic Telegram bot evidence. Never use display names or usernames as identity linkage and never make Telegram the sole identity model.

## Consequences

M1 provides staff identity and organization RBAC for the CRM boundary. M2 keeps staff User identity, Client identity, authentication identities, and communication-channel identities distinct. Email authentication may create or resolve only a verified email channel identity inside the server-derived organization; unverified CRM profile email fields do not merge clients. Future verified channels can use the same identity boundary without changing Client semantics.
