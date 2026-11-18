# Plan: medical session preparation

> **Execution:** implement in the isolated worktree from exact PR #88 HEAD, with focused deterministic tests first and staging verification after the candidate is pushed.

## Scope

- Inspect and preserve the existing session AI, document AI, posture AI, client summary, and session dynamics contracts.
- Add the smallest session-preparation read projection and navigation needed for the appointment/client workflow.
- Reuse the existing session draft generation and review actions; do not add a second workflow or persistence model.
- Remove or keep unavailable any session AI acceptance target that would create deferred clinical content.

## Implementation steps

1. Add failing focused tests for the prep projection/navigation and factual session-context contract.
2. Refactor session context assembly to use `GetSessionDynamics`, keep exact current-session source boundaries, and include only accepted upstream AI runs.
3. Add the appointment/client entry point and render the existing session draft state, source versions, missing-data state, and review history.
4. Harden review target availability and duplicate/cross-tab behavior without changing generic client synthesis.
5. Classify and correct the five bounded `SessionCockpitTest` issues without changing business behavior merely to satisfy stale assertions.
6. Run lightweight local checks, then push/deploy the exact candidate to staging and run PostgreSQL, deterministic fake, Chromium, WebKit, and provider checks separately.
7. Review the final diff, create one dependent Draft PR from PR #88, and report exact SHAs and evidence.

## Focused checks

- `ClinicalAiWorkflowTest` session-source and idempotency cases.
- `SessionCockpitTest` session prep, navigation, review, tenant, and the five known issue cases.
- PostgreSQL staging flow: upcoming booking, preparation, source list, review/edit/accept, reload, and later-session history.
- Chromium and WebKit real interaction, including provider/configuration failure and duplicate/two-tab paths.
