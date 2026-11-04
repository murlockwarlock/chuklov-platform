# Direct staging QA

Use the existing staging acceptance path before proposing new infrastructure or
asking the owner for credentials. The owner's Mac may serve as a thin control
plane for SSH and Chromium, Playwright, or WebKit against the real staging URL.
Run product, runtime, and data checks on staging. Do not start the application,
Docker, PostgreSQL, Redis, a staging database copy, or heavy suites on the Mac.

## Connection and source identity

Read the ignored `.codex/staging-deploy.md` for the staging URL and remote root.
Load the existing ignored `.env.staging-deploy` inside automation without printing
its contents. Reuse the SSH transport options from `scripts/deploy-staging.sh`.
Never print environment dumps, credentials, browser cookies, or authentication
codes. Keep temporary scripts, screenshots, and evidence outside the repository.

Check the deployed `current/REVISION`, running service image revisions, health,
PostgreSQL, Redis, Horizon, scheduler, Telegram, and private storage. Compare the
deployed revision with the task's authoritative GitHub source. A merge-only SHA
difference without file changes does not require a redeploy. Use the protected
staging deployment flow only when a runtime update is needed and authorized.

## Existing identities and browser access

Use the `chuklov-staging-qa` skill and the private profile under
`$HOME/.codex/private/chuklov-staging-qa/`. Read only allowlisted
non-secret profile fields. Load referenced credentials and browser states inside
automation, never into tool output. CRM QA uses the existing QA staff identity
referenced by the private QA profile. Try its genuine saved browser state first;
when expired, use the ordinary login form, saved QA password, and fresh enrolled
TOTP. Preserve reusable browser state after successful login. Never use the owner
account, create another staff account,
rotate credentials, disable MFA, or inject server-side sessions as browser proof.

For passwordless Portal acceptance, use a separate synthetic Client and the
configured staging QA email domain. Request a code through the Portal UI, retrieve
only that recipient's message from staging Mailpit through SSH, and enter the code
in the same browser. Keep codes in automation memory. Reuse a live session during
the journey. Telegram checks may use only the separately approved QA identity;
CRM QA authorization does not authorize other Telegram accounts.

## Acceptance and cleanup

Exercise important flows as `open → action → second action → result → reload →
return`, using the actual user controls. Wait for asynchronous UI changes and
completed saves before editing new repeater rows or reloading. HTTP status, DOM
presence, database rows, and mocks alone are not browser acceptance.

Real AI-provider evidence requires execution through the application runtime and
matching run/provider provenance. Never call a provider directly as a substitute.
Snapshot shared AI/settings before changes, restore test-only changes in cleanup,
and retain failed attempts and historical evidence. Do not invent medical text,
scoring, legal copy, prices, or provider choices.

Keep technical fixtures out of the ordinary operational state. Use human-readable
illustrative records where helpful. Before removing a synthetic Portal Client,
verify exact identity, task provenance, dependencies, active jobs, and recoverable
scope, then use the CRM staging reset lifecycle if safe. Preserve commerce and
audit evidence when reset is blocked. Never reset the acceptance database or run
`migrate:fresh`, `db:wipe`, or `TRUNCATE` against staging.

If the direct path fails, diagnose the missing local binary, saved profile,
connection, or server response first. Report the exact unavailable capability;
do not replace it with new CI workflows, new accounts, or session bypasses.
