---
paths:
  - 'resources/js/**'
---

# Js

## User-facing copy only
Write Portal UI for clients and task completion only. Never expose developer, architecture, runtime, tenant, milestone, implementation, channel-internals, demo, or generic AI/SaaS marketing copy. Russian-facing screens must not contain English developer/demo text; omit explanatory text unless it enables an action, state, required input, or actionable next step.

## Authenticated client shell and progressive profiling
After authentication, render the useful CHUKLOV product shell immediately; do not show login copy, provider status, onboarding progress, or generic Continue entry points. Internal onboarding state remains an Application concern. Collect optional profile data in Profile or just in time for the action that requires it. Keep RU/EN selection visible in the shell and persist the selected locale.
