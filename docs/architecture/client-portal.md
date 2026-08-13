# Client Portal

Vue 3 + Inertia 3 + TypeScript + Tailwind 4 renders one responsive portal for desktop browser, mobile browser, and Telegram Mini App runtime mode. Telegram runtime detection and initData handoff stay at the presentation/adapter boundary; server routes call Application queries/actions.

M2 adds a server-session client portal context resolved from the server-derived organization, passwordless email-code browser authentication, verified Telegram Mini App entry, a single-use Telegram connection-token flow, a versioned four-stage onboarding progress record, and progressive confirmation of the M1 client profile fields. Known values are prefilled and cannot be overwritten without explicit confirmation. M2 presents configured versioned legal documents and records exact-version consent evidence. M4B adds one shared authenticated booking journey with explicit Service, Specialist, date/slot, visit-format, display-timezone, and confirmation/request projections; browser, mobile browser, and Telegram Mini App use the same route and Application actions. It does not duplicate a Telegram-specific booking UI.

Portal visual tokens and reusable component classes are centralized in `resources/css/app.css`; pages consume the same typography, spacing, surface, control, feedback, and responsive layout vocabulary. The palette is intentionally warm-neutral with sage and terracotta accents so the client experience remains calm, human, and distinct from the denser CRM interface.

Portal dates use the shared `PortalDateTime` component and centralized date/time utilities. See REQ-PORTAL-001 through REQ-PORTAL-005, REQ-EMAIL-001, REQ-LEGAL-001, REQ-TIME-001, ADR-003, ADR-013, and the client-portal skill.
