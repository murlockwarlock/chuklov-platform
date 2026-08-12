# Client Portal

Vue 3 + Inertia 3 + TypeScript + Tailwind 4 renders one responsive portal for desktop browser, mobile browser, and Telegram Mini App runtime mode. Telegram runtime detection and initData handoff stay at the presentation/adapter boundary; server routes call Application queries/actions.

M2 adds a server-session client portal context resolved from the server-derived organization, verified Telegram onboarding entry, a versioned four-stage onboarding progress record, and progressive confirmation of the M1 client profile fields. Known values are prefilled and cannot be overwritten without explicit confirmation. Clinical, scheduling, service-format, and consent behavior remains outside the implemented M2 foundation; consent completion also waits for OQ-006. Ordinary browser authentication remains open under OQ-001.

Portal visual tokens and reusable component classes are centralized in `resources/css/app.css`; pages consume the same typography, spacing, surface, control, feedback, and responsive layout vocabulary. The palette is intentionally warm-neutral with sage and terracotta accents so the client experience remains calm, human, and distinct from the denser CRM interface.

See REQ-PORTAL-001 through REQ-PORTAL-005, ADR-003, and the client-portal skill.
