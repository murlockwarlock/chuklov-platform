# Survey Builder Admin UX

## Status

Design approved by the owner on 2026-09-13. Implementation is in progress on `codex/survey-builder-admin-ux` from `813d175a2bc6695401953d08149fdf479a57026b`.

## Goal

Make the existing CRM survey and test editor usable by an ordinary administrator while allowing the current platform-created scoring configuration to be edited and round-tripped safely. Add a small human-readable explanation to the existing referral configuration page without changing referral economics.

## Existing authorities to preserve

The implementation extends these existing authorities:

- `SurveyDefinition`, `SurveyVersion`, `SurveyDefinitionFormMapper`, and `SurveyDefinitionFormCompatibility` for form projection and compatibility;
- `SurveyDefinitionValidator` for human validation before persistence;
- `UpdateSurveyDefinitionDraft` and `PublishSurveyVersion` for draft, stale-write, publication, and immutable history behavior;
- `PlatformSurveyCatalog` and `InstallPlatformSurveyCatalog` for current platform defaults and provisioning safety;
- `GetReferralRewardProgram` and `SaveReferralRewardProgram` for versioned referral configuration.

No second survey subsystem, scoring engine, referral service, migration, or frontend dependency is introduced.

## Survey form projection

The persisted canonical definition remains authoritative. The form mapper gains a focused rich-scoring projection that can represent every shape currently emitted by `PlatformSurveyCatalog`:

- answer scale values and human labels;
- metric membership, maximum, normalization, and existing attention/observation/Road Map text;
- question and option point rules, including multipliers;
- result thresholds and localized result text;
- summary, safe steps, specialist questions, and comparison basis.

Known current platform shapes are accepted by compatibility checks and are converted to human form state. Values are normalized back without dropping fields or changing the version lifecycle. A shape outside the explicit supported contract remains in `legacy_scoring`; its original scoring data is carried through unchanged and the UI shows a compact warning.

The UI never requires an administrator to enter UUIDs, question keys, metric keys, JSON paths, or internal enum values. Selectors use existing question, option, and metric labels. Hidden technical identities remain in the form state only where the existing mapper needs them to preserve stable references.

## Survey editor UX

The Filament schema uses the existing capabilities of the installed version:

1. `Основное` — title, description, availability, and concise provenance/status text;
2. `Вопросы` — collapsible sections, questions, options, and display conditions with reorder behavior preserved;
3. `Подсчёт результата` — answer scale, metrics, metric rules, option points, multipliers, and comparison settings;
4. `Результат для клиента` — summary, thresholds, attention reason, observation, Road Map, safe steps, and specialist questions;
5. `Публикация / версия` — draft/published state, version explanation, and the existing new-scale action context.

Repeater item labels are derived from current human fields. Sections show their title and question count; questions show their title and type. New questions remain expanded, while existing nested items are collapsed by default. The existing ordering and stable repeater identities are preserved.

Save draft and publish remain the existing authoritative actions and are available from the page header. There is no second persistence path. A draft/published status summary is visible without scrolling. The scoring preview is derived only from the current form state and does not introduce scoring rules.

Unsupported scoring is shown as a compact warning explaining that the data is preserved unchanged. It is not rendered as a long disabled editor. Human validation reports missing references, invalid ranges, overlaps, gaps where the score domain requires continuity, and missing rules without exposing technical identifiers in the administrator-facing message.

## Referral explanation

The referral page keeps its current form and authoritative save action. It adds a derived “Как сейчас работает” block containing enabled state, qualification strategy, reward type/value, effective date, the fact that partner-specific overrides take precedence, and an example calculated from the configured organization currency and current reward value. No reward formula or settlement behavior changes.

## Safety and versioning

Published versions and historical attempts remain immutable. Draft updates continue through stale-write protection and application validation. Provisioning continues to create missing platform defaults only and must not replace a saved draft, published version, active pointer, scoring, result text, Road Map, or referral version. Historical attempts retain their original version and materialized data.

## Verification

Focused tests cover:

- 9-systems and extended platform scoring round trips;
- scoring point, threshold, summary, and Road Map edits;
- truly unknown scoring preservation;
- tabs, summaries, draft/publish status, and the single save path;
- historical attempt and published-version preservation;
- repeated platform provisioning after admin edits;
- referral human summary and calculation example.

Verification consists of targeted PHPUnit tests, PHP syntax, Pint, and diff checks, followed by isolated PostgreSQL checks for version/provisioning behavior and an exact-SHA staging deployment/smoke check. Browser and owner acceptance remain manual follow-up and no merge is performed.

## Non-goals

- no clinical content or methodology redesign;
- no raw JSON as the primary editor;
- no rewrite of historical attempts or accepted UX outside surveys and the small referral explanation;
- no generic optimistic-lock framework;
- no changes to referral economics, external sends, or unrelated CRM pages.
