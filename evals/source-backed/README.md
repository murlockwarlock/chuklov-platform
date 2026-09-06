# Source-backed AI evaluation suites

`source-backed-agent-suites.json` is a versioned synthetic fixture manifest for the existing `AiEvalSuite`/`AiEvalCase`/`RunEvaluationSuite` framework. It contains no client records, production references, medical documents, images, or provider credentials.

The manifest is deliberately not auto-imported or auto-executed. An authorized operator must import its cases through the existing organization-scoped actions, pin a reviewed prompt version and model release, and run it through the existing evaluation lifecycle.

Agent 2 remains execution-blocked in the current framework because `RunEvaluationSuite` requires a controlled three-photo fixture and the repository has no safe synthetic image fixture provider. The suite records the exact three-role requirement and tests the source-backed text contract without pretending that text can validate vision behavior.
