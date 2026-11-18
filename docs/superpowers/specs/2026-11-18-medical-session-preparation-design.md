# Design: medical session preparation

## Intent

Make the existing session-scoped clinical AI draft useful as a specialist's preparation surface from an upcoming booking and the client card. The surface must show only authorized, source-backed information and retain the existing review and audit path.

## Goals

- Reuse `StartClinicalSynthesis::handleSession`, `AiRun`, `GetClinicalAiResult`, `GetSessionDynamics`, and the existing review actions.
- Expose the preparation entry point from an appointment that already has a medical session and from the client's session history.
- Include factual session dynamics, confirmed document runs for the current session, and confirmed posture observations with exact upstream `AiRun` references.
- Keep pending, rejected, and unrelated-organization sources out of the context.
- Preserve visible loading, provider/configuration errors, review editing, accept/reject decisions, and durable history after reload.
- Keep prohibited clinical targets unavailable and avoid automatic writes to session fields.

## Non-goals

- No new AI capability, workflow, table, storage, or client-facing publication.
- No diagnoses, root-cause generation, treatment protocols, manipulation recommendations, forecasts, scoring, indices, or automatic clinical interpretation.
- No changes to the working posture-analysis workflow or the generic client clinical summary workflow.

## Considered approaches

1. Extend the existing session draft and add navigation plus a factual preparation projection. This preserves one source of truth, existing provenance and review history, and is the selected approach.
2. Add a separate preparation workflow or table. Rejected because it duplicates the clinical synthesizer and would split provenance and review decisions.
3. Only link to the client-level clinical summary. Rejected because it cannot express the current session boundary and exact session-linked document sources.

## Chosen design

`StartClinicalSynthesis::handleSession` remains the only generation entry point. Its session input is assembled through `GetSessionDynamics` and session-scoped source selectors. A read-only preparation projection is rendered in the existing session surface and linked from booking/client navigation. Review continues through the existing `AcceptClinicalSessionAiDraft` and `RejectClinicalSessionAiDraft` actions. The accepted decision remains tied to the session-scoped `AiRun`; prohibited targets stay disabled.

The generic `handle` path and the client-level `ReadClinicalAiClientSummary` path keep their current source-selection behavior.

## Verification boundary

Regression coverage will use the deterministic AI fake and PostgreSQL-backed tests for source selection, tenant authorization, idempotent launch, review history, and cross-tab/session snapshot handling. Staging acceptance will separately exercise the real PostgreSQL path, Chromium, WebKit, and the configured real provider when available.
