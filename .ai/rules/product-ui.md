# Product UI

Ordinary product UI is written for clients, Chuklov/organization owners, and ordinary staff. It expresses business intent and current business state, not the implementation architecture.

## Audience first

Developer, architecture, infrastructure, milestone, QA, implementation, queue, persistence, provider, runtime, internal security, and Domain terminology must not appear in ordinary Portal, Vue, Blade, or CRM UI merely because those concepts exist internally. Technical terminology belongs only on an explicitly technical diagnostics, observability, trace, or engineering screen for an authorized audience.

## Complexity stays behind the UI

Keep idempotency keys, event and revision versions, immutable technical keys, organization IDs, internal record IDs, raw enum values, retry/fallback and queue state, scheduler state, internal timestamps and timezone representations, provider details, correlation IDs, and template IDs generated, derived, translated, or hidden whenever ordinary users do not need to control them. Preserve the trusted Application/Domain boundary and existing security, tenant, idempotency, concurrency, audit, immutable revision, and durable workflow guarantees.

The preferred flow is simple business UI → trusted Application action → server-derived technical values → existing Domain guarantees. The frontend or Filament form must not become the source of truth for trusted values.

## User copy

Visible copy exists to help a user act, provide required information, understand a current state, understand an actionable error, or know the next step. Keep it concise and natural. Do not add generic AI/SaaS marketing filler. Translate expected business failures into a useful action; keep exception names, provider errors, SQL details, queue states, stack traces, and diagnostic codes in logs/observability.

## Russian-facing UI

Client and CRM surfaces are Russian-facing unless configured content explicitly says otherwise. Do not show English developer/demo copy or locale metadata as decorative UI. Proper names and genuine user content may remain in their original language. Translate raw technical enum values into business labels.

## Architecture separation must not leak

Different internal flows that represent one user concept must share one simple product action. For example, browser Telegram authentication and Mini App authentication are both “Войти через тг” in the UI. Scenario revisions, typed conditions, pinned template versions, durable scheduling, retries, fallbacks, and recipient revalidation remain internal; Chuklov configures when, after how long, whom, what message, any understandable condition, and whether the rule is active.

## Product UX definition of done

A user-facing feature is incomplete until it has understandable business terminology, sensible defaults, safely derived values where possible, minimal required steps, a clear primary action, human-readable statuses and errors, sensible empty states, and no unnecessary internal architecture. Before finishing a UI change, inspect every visible heading, label, helper, button, status, notice, error, empty state, field, and technical value as the intended user would see it. Ask whether the user needs it, whether it expresses business intent/state, whether the system can derive it, whether a normal user understands why it exists, whether the language is natural, and whether architecture leaked only because the backend model exposed it.
