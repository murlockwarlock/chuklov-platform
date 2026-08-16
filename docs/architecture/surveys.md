# Surveys and Deterministic Road Map Reports

M8 implements the generic engine required by `REQ-SURVEY-001` and the source-backed import boundary required for `REQ-SURVEY-002`. It contains no LLM, diagnosis, treatment recommendation, or generated clinical interpretation.

`SurveyDefinition` owns a stable organization-relative key and ordered versions. A version moves from `draft` to `published`; the previous published version becomes `retired`. Published and retired localized titles/descriptions, definition, scoring, compatibility, and provenance fields are immutable. Starting an attempt pins the current published version and snapshots its definition and scoring. A later publication never migrates or rewrites an existing attempt.

Definitions are declarative data produced by structured CRM controls or `surveys:import`. Supported question types are `single_choice`, `multiple_choice`, `boolean`, `integer`, `number`, `short_text`, and `long_text`. Conditions use allowlisted equality, membership, answered, and numeric comparison operators. Scoring uses answer-value maps, selected-option sums, and numeric values. Unknown types/operators fail validation or evaluate closed; no PHP, JavaScript, SQL, class name, `eval`, or arbitrary expression is stored or executed.

Draft saves and completion validate answer types and bound free-text payloads before encryption. Completion locks the attempt, validates condition-aware required answers, calculates configured metrics/thresholds/tags once, stores encrypted immutable snapshots, materializes one encrypted deterministic report, records structural audit evidence, and records one identifier-only `survey.completed` Scenario event in the same transaction. Repeat comparison and any configured stagnation event are materialized transactionally with completion. Replays return the existing completion without changing answers or duplicating reports, comparisons, audit evidence, or events.

Repeat comparison requires the same non-empty `metric_schema_key` and an explicit `no_decrease` configuration with named metric keys. Missing or incompatible schemas create `not_comparable`; no judgment event is emitted. Compatible attempts emit `TEST_STAGNATION_DETECTED` once only when none of the configured metrics decreased. Message tone and recipients remain Scenario configuration.

The approved local v2.2 source names “9 systems” and MSQ but refers their omitted questionnaire bodies to separate documents that are not present. OQ-015 blocks activation of those initial definitions, not the engine or import contract.
