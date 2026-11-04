# Product UI

This is the canonical product-UI rule for client, owner, staff, CRM, Portal,
Vue, Blade, and Telegram Mini App surfaces. It applies to ordinary users, not
only to the developer who built the screen.

## First-time usability release gate

A new or substantially changed surface is not production-ready until an
ordinary user without code, database, or ТЗ knowledge can use it independently.
Within 5–10 seconds the user must understand:

- what the screen is and why it exists;
- what is already configured or happening;
- what can be done now;
- what will change after the action.

This is a `REAL BUG`, not a preference, when ordinary work requires a
developer, database knowledge, or separate documentation; when a screen is a
giant repeater or backend-shaped form; or when it exposes raw keys, enum
values, IDs, or numeric sort order instead of a human reorder interaction.

The gate must work for 0, 1, and many records and remain understandable with
10–30 objects. Prefer:

- list/table/cards → detail/edit;
- progressive disclosure and a clear primary action;
- human status plus the next action;
- an empty state with a useful CTA;
- safe illustrative examples where they reduce ambiguity;
- consistent navigation and CTA semantics.

Examples are scaffolding, not business rules. They must never silently become
medical doctrine, a legal statement, a formula, or authored product content.

Existing or design-frozen UI is not automatically accepted UX. Preserve only
UX explicitly accepted by the owner. New, changed, or merely unverified UI
must pass this gate.

## Business language and safe boundaries

Write for the user’s business task and current state, not for the internal
architecture. Hide or derive organization IDs, internal IDs, raw enum values,
revision keys, queue/provider state, correlation IDs, retry details, and
implementation timestamps unless an explicitly technical screen needs them.

Business configuration must be editable through authorized human CRM controls
where its lifecycle requires it: create, edit, archive, reorder, activate,
deactivate, preview, publish, and correct. Do not require JSON, SQL, config
files, enum keys, internal IDs, source code, or a deploy for normal owner work.

Visible copy should help a user act, provide required input, understand state,
recover from an actionable error, or know the next step. Use Russian-facing
business labels by default; translate technical statuses and keep provider,
SQL, queue, exception, and diagnostic details out of ordinary UI.

Keep one simple product action for one user concept. The trusted Application
and Domain layers remain authoritative for tenant scope, authorization,
idempotency, concurrency, audit, revisions, scheduling, and durable workflows.
The frontend or Filament form must not become the source of trusted values.

## Portal and responsive behavior

Do not turn internal workflow states into a visible wizard unless the client
must make a real decision or provide information for the current action.
Collect optional profile information in Profile or just in time. Availability
is a focused selection problem, not an unbounded data dump.

After authentication, the shell leads with useful product destinations rather
than login/provider/onboarding implementation state. Preserve the configured
RU/EN localization mechanism and do not machine-translate organization content
or proper names.

Reuse the committed visual language and components. Do not introduce fake
business data, generic SaaS marketing treatments, or technical placeholders.
Responsive layouts must keep controls usable and content readable without
horizontal overflow or clipped states.
