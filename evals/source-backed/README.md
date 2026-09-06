# Source-backed AI evaluation suites

`source-backed-agent-suites.json` is a versioned synthetic fixture manifest for the existing `AiEvalSuite`/`AiEvalCase`/`RunEvaluationSuite` framework. It contains no client records, production references, medical documents, images, or provider credentials.

The manifest is deliberately not auto-imported or auto-executed. An authorized operator must import its cases through the existing organization-scoped actions, pin a reviewed prompt version and model release, and run it through the existing evaluation lifecycle.

Agent 2 uses a controlled synthetic front/side/back fixture through the existing `RunEvaluationSuite` and attachment resolver. The fixture is private, deterministic, marked as evaluation-only, and cannot be selected by the production CRM attachment flow.
