---
paths:
  - 'resources/js/**'
---

# JavaScript-specific constraints

The canonical UI, copy, first-time usability, and responsive rules are in
`.ai/rules/product-ui.md`. Do not duplicate them here.

After authentication, render the useful CHUKLOV shell immediately. Keep login
copy, provider status, onboarding progress, and generic Continue entry points
out of the authenticated shell. Collect optional profile data in Profile or
just in time for the action that needs it. Keep RU/EN selection visible and
persist the selected locale through the existing trusted mechanism.
