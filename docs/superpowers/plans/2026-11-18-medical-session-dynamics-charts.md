# Medical Session Dynamics Charts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a compact, factual, responsive visual history for the current medical session and up to nine previous sessions without creating a second history subsystem.

**Architecture:** Extend `SessionDynamicsData` with a read-only presentation projection. Render that projection inside the existing «Динамика подтверждённых фактов» infolist section through one Blade `ViewEntry` and the existing Alpine runtime. Keep `GetSessionDynamics` as the authorization and history boundary; do not add persistence or AI behavior.

**Tech Stack:** Laravel/PHP, existing Application DTOs, Filament infolist `ViewEntry`, Blade, Alpine already shipped by Filament, PHPUnit/hosted PostgreSQL evidence, staging Chromium and WebKit.

**Spec:** `docs/superpowers/specs/2026-11-18-medical-session-dynamics-charts-design.md`

## Global Constraints

- Use only the existing `GetSessionDynamics` and `SessionDynamicsData` history.
- Show saved values only; no diagnosis, interpretation, direction labels, efficacy percentages, indices, thresholds, or recommendations.
- Keep «До» and «После» as separate series; preserve `null` as a gap and `0` as a value.
- Compare tests only by the saved test definition identity and compatible saved structured type/version; do not infer correspondence from names or labels.
- Keep organization authorization in the existing Application path and do not accept tenant scope from UI data.
- Do not add a migration, table, workflow, AI call, storage, client surface, or frontend dependency.

## Review Focus

- A current session without previous history still renders an understandable chart state. Test in Task 1.
- A saved zero is not rendered as a missing point. Test in Task 1.
- A missing before/after value creates a gap and never a synthetic zero or connecting line. Test in Task 1.
- Same-named but incompatible test data is not merged. Test in Task 1.
- Another organization cannot obtain a chart through the existing dynamics boundary. Test in Task 1.

### Task 1: Prove the dynamics projection before implementation

**Files:**
- Modify: `tests/Feature/Sessions/MedicalSessionTest.php`
- Modify: `tests/Feature/Sessions/SessionCockpitTest.php`

**Interfaces:**
- Consumes: `GetSessionDynamics::handle(User, MedicalSession, Client, historyLimit)`.
- Produces: failing assertions for `SessionDynamicsData::chartData()` and the existing detail page.

- [ ] **Step 1: Add a failing projection test**

  Add a focused test that creates ten dated sessions through `CreateSession`, gives the current and selected historical sessions saved vitals and pain values, and asserts that `chartData()` returns chronological labels, ten sessions maximum, distinct `before` and `after` series, a numeric zero point, and a missing-value gap. Add a second fixture with same test names but different saved test identities/structured signatures and assert that incompatible results are not placed in one series.

- [ ] **Step 2: Add a failing authorization/detail-page assertion**

  Extend the existing session cockpit coverage to assert that the detail page includes the new factual dynamics view marker and the existing section label. Add a cross-organization request assertion through the existing test helper if the projection path is not already covered by that test.

- [ ] **Step 3: Run the focused hosted feature shard and observe the expected failure**

  Run the repository's manually dispatched focused CI against the branch with the test filter for the new test names. Expected result: the new projection method/view contract is missing; no unrelated suite is required at this stage.

### Task 2: Implement the factual chart projection

**Files:**
- Modify: `app/Modules/Sessions/Application/DTOs/SessionDynamicsData.php`

**Interfaces:**
- Consumes: Existing `history`, `current`, `previous`, organization `timezone`, `MedicalSessionData`, `MedicalPainData`, and `MedicalSessionTestData`.
- Produces: `public function chartData(): array` with chronological sessions, fixed saved-vital metric definitions, pain series keyed by `lineageKey`, and test groups keyed by saved definition identity/signature.

- [ ] **Step 1: Implement chronological session snapshots**

  Reverse the existing current-first history for display, retain the organization-timezone date labels, and cap the projection at ten snapshots. Use the existing history fallback when a caller constructs a DTO without the history array.

- [ ] **Step 2: Implement saved-vital series**

  Project only `sys`, `dia`, `pulse`, `pi`, and `spo2` with their existing labels and units. Build separate before/after point lists, keep numeric zero, omit null from point coordinates, and provide contiguous segments so missing values remain visible gaps.

- [ ] **Step 3: Implement pain series and cautious test groups**

  Match pain rows by their saved `lineageKey`, not by display name. Group test rows by saved `testId` and compatible structured `type`/`version`; when compatibility is not proven, expose a non-merged factual group with a human-readable note instead of connecting values.

- [ ] **Step 4: Re-run the focused hosted feature shard**

  Expected result: the Task 1 projection assertions pass and no new warnings are emitted by the focused shard.

### Task 3: Render the projection in the existing CRM section

**Files:**
- Modify: `app/Filament/Resources/Clients/Resources/Sessions/Schemas/SessionInfolist.php`
- Create: `resources/views/filament/resources/clients/session-dynamics-chart.blade.php`

**Interfaces:**
- Consumes: Authorized `GetSessionDynamics::handle(...)->chartData()` from the existing section.
- Produces: A responsive, keyboard/screen-reader usable visual panel with metric tabs, separate «До»/«После» keys, exact date labels, and factual empty states.

- [ ] **Step 1: Add the existing-section ViewEntry**

  Render the view data from the same actor/parent client resolution already used by the section. Keep the existing `RepeatableEntry` factual history and authorization path; do not invoke a second query source.

- [ ] **Step 2: Add the compact responsive view**

  Use one Alpine tab state for «Показатели», «Боль», and «Тесты», with indicator buttons for available saved metrics. Render server-built SVG viewBoxes at `width: 100%`, preserve gaps, mark before/after with distinct keys and styles, and expose point values/date through accessible labels. Render one-session and no-value states without empty dead ends.

- [ ] **Step 3: Add keyboard/touch and overflow safeguards**

  Use real buttons with `aria-selected`/tabpanel relationships, visible focus styles, wrapping controls, `min-w-0`, and no fixed-width container that can overflow at 320px. Keep date labels short in the chart and the organization-timezone full date in accessible/value text.

- [ ] **Step 4: Run lightweight repository checks**

  Run `git diff --check` and `php -l` for changed PHP files only. Do not run application tests, builds, Docker, PostgreSQL, or Playwright on the Mac.

### Task 4: Record the implemented requirement boundary and prepare the candidate

**Files:**
- Modify: `docs/product/requirements/2026-11-14-medical-stage1.md`
- Modify: PR #89 description through GitHub CLI after the dependent PR exists.

**Interfaces:**
- Consumes: Verified code and test scope from Tasks 1–3.
- Produces: `REQ-MED-2-AI-011` and `REQ-MED-2-AI-012` marked `PARTIALLY IMPLEMENTED` only for factual display; clinical analysis remains deferred.

- [ ] **Step 1: Update the two requirement rows**

  Replace only the status and acceptance wording needed to describe stored-value visualization, compatible comparison boundaries, and the explicit deferral of pattern/trend interpretation.

- [ ] **Step 2: Inspect staged diff and commit**

  Run `git diff --cached --check`, `git diff --cached --stat`, and inspect the complete staged file list. Commit the coherent candidate with the repository's established short imperative style.

- [ ] **Step 3: Push and create one dependent Draft PR**

  Push the new branch and create one Draft PR whose base is the PR #89 head branch. Do not merge, mark Ready, or deploy production.

### Task 5: Exact-SHA staging and browser acceptance

**Files:**
- No product files beyond the candidate from Tasks 1–4.

**Interfaces:**
- Consumes: Exact pushed candidate SHA and the existing QA profile/browser state.
- Produces: PostgreSQL, Chromium, WebKit, responsive, authorization, reload, and navigation evidence.

- [ ] **Step 1: Run the existing auth-preflight**

  If it reaches login or MFA failure, stop product acceptance and diagnose access through the established `chuklov-staging-qa` path before continuing.

- [ ] **Step 2: Deploy the exact candidate to staging and run the staging smoke**

  Use the existing staging deployment procedure, preserve rollback/backup behavior, and verify the exact SHA, PostgreSQL health, private storage, application, workers, and queue identity.

- [ ] **Step 3: Exercise the real CRM interaction in Chromium and WebKit**

  Open the existing medical session, verify the dynamics section, switch indicators, inspect before/after and missing points, reload, use back/forward, and repeat at 320/360/390/768/1024/1440 widths with one browser at a time. Include 0/1/5/10 historical-session states using safe staging data, plus a cross-organization denial check.

- [ ] **Step 4: Inspect final evidence and update PR #89 description**

  Update the existing PR description with the factual final SHA, QA auth-preflight result, focused test result, staging PostgreSQL result, Chromium/WebKit result, and any baseline or real defects. Keep the new PR Draft.
