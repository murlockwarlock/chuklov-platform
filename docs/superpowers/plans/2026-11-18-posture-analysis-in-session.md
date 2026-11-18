# Stage 2: posture analysis in the medical session

## Goal

Extend the existing `StartPostureAnalysis` and `AiRun` review flow so a specialist can analyze exactly three private photos from one session phase, inspect an observation-only draft in that session, edit and accept or reject it, and retain versioned provenance without writing confirmed medical fields.

## Implementation sequence

1. Add a session-aware posture start path to `StartPostureAnalysis`.
   - Authorize the organization, client, session, and AI view permission through existing application services.
   - Resolve exactly one `front`, `side`, and `back` `PosturePhoto` link from the requested `before` or `after` gallery.
   - Reject missing, duplicated, cross-client, cross-session, cross-organization, wrong-plane, non-private, unsupported-MIME, or fixture attachments before dispatch.
   - Add a typed `medical_session` reference with the phase and use a distinct session posture workflow key while retaining the same `AiCapability`, `AiWorkflowEngine`, provider selection, prompt versioning, `AiRun`, and `AiAttachmentResolver`.
   - Reuse the existing idempotency and rerun semantics.

2. Add fail-closed readiness and session run reading.
   - Reuse `ResolveAiExecutionCandidates` for image-capable provider/model readiness and check the tenant-owned active posture prompt.
   - Do not dispatch when the organization policy, provider/model modality, or active prompt is unavailable; expose a human-readable reason.
   - Add an organization/client/session-scoped application reader for session posture runs, including human reviews and immutable input references.

3. Add the existing review boundary for posture drafts.
   - Add a session posture review action that validates the current organization, client, session, workflow, successful pending run, and payload before calling `ReviewAiRun`.
   - Accept an edited observation draft with `target_field=posture_observations`; reject with a safe reason and optional note.
   - Preserve original AI payload and encrypted edited output. Do not call `UpdateSession` or mutate test results, measurements, or `visual_posture_diagnostics`.
   - Let the existing row lock reject duplicate review decisions from two tabs.

4. Add the tablet-friendly session UI in `EditMedicalSession`.
   - Add separate `До` and `После` sections with the action `Анализировать с ИИ` and three role-labeled photo selectors.
   - Disable the action with an actionable empty/configuration state when the phase does not have all three photos or image-capable AI is not ready.
   - Render used private photos, the related Stage 1 visual test #2 facts when present, observation-only AI output, AI version, specialist version, status, and decision history directly in the session.
   - Keep correction input in the review modal, preserve entered text on errors, show retry for failed generation, and never expose a client-send action.

5. Update the client AI list and existing requirements only for this scope.
   - Keep session posture runs out of the client-level posture table while preserving the existing client-level posture workflow.
   - Update the existing Stage 2 posture/version/review requirement rows with implementation scope and evidence wording; leave health audit, diagnosis, prediction, index, threshold, and red-flag work deferred.

## Tests first

- Add focused application tests for exact phase-bound attachment selection, tenant/session/client isolation, private storage, idempotency/rerun, prompt/provider/modality fail-closed readiness, and session references.
- Add review tests for edited acceptance, rejection, duplicate decisions, original payload preservation, and unchanged confirmed session/test data.
- Add focused session cockpit tests for the two phase actions, missing-photo/configuration states, used-photo/test #2 presentation, history/version labels, and no client publication.
- Run local PHP syntax and diff checks only. Run PostgreSQL/application tests and the real Chromium/WebKit journeys on staging, sequentially, against the exact deployed SHA.
