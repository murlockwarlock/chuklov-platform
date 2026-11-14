# Direct staging QA

Use the existing staging acceptance path before proposing new infrastructure or
asking the owner for credentials. The Mac is an 8 GB thin control plane for
editing, Git, SSH, and remote browser control only. Do not run application
tests, Docker, PostgreSQL, Redis, full builds, heavy linters, browser suites, or
a staging database copy on the Mac. Staging is the primary and, before
production exists, the only product acceptance environment.

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

## Staging evidence and diagnostics

- Use PostgreSQL for staging acceptance. Check migrations, constraints, transactions, concurrent behavior, locks, JSON, dates/time zones, and idempotency where the change touches them.
- Exercise real CRM, Portal, and Mini App controls, including both sides of the flow: staff action -> client result -> client action -> staff result.
- Significant operations and errors must be diagnosable in staging logs by safe context: time, organization, action, result, correlation ID, queue/job, and external call. Never expose passwords, tokens, cookies, medical text/files, payment data, or other protected values in logs, screenshots, reports, or queue payloads.
- Do not call providers directly as acceptance evidence. Keep provider references and error classifications safe and correlate them through the application runtime.
- Do not call a capability `VERIFIED` without evidence from the exact deployed SHA under test.

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

Do not create new QA accounts or infrastructure unless the existing path cannot
exercise the requested capability and the owner authorizes the exception.

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
and retain failed attempts and historical evidence. Safe fictional values are
allowed for field, validation, storage, and lifecycle checks, but must never be
presented as real client information or medical results. Do not invent medical
norms, diagnoses, scoring, formulas, recommendations, medical text, legal copy,
prices, or provider choices as business rules.

Keep technical fixtures out of the ordinary operational state. Use human-readable
illustrative records where helpful; ordinary CRM names must not contain `QA`,
`synthetic`, `fixture`, `test`, raw IDs, or invented medical/financial facts.
Temporary verification records may be cleaned up safely after verifying exact
identity, task provenance, dependencies, active jobs, and recoverable scope;
preserve required business and audit history and do not destroy necessary QA
access or data. For a synthetic Portal Client, use the CRM staging reset
lifecycle if safe. Staging permits necessary schema and record changes, but
before any potentially destructive action resolve the exact target and create a
recoverable backup.
Never reset the acceptance database or run `migrate:fresh`, `db:wipe`, or
`TRUNCATE` against staging.

If the direct path fails, diagnose the missing local binary, saved profile,
connection, or server response first. Report the exact unavailable capability;
do not replace it with new CI workflows, new accounts, or session bypasses.
