# AI Evaluation

Normal CI uses Laravel AI SDK fakes. M9/M10 add curated query/source/required/forbidden-fact datasets under `evals/` and optional Ragas tooling for deliberate retrieval/prompt/embedding/chunking regression runs. Expensive provider evaluation is not a per-change gate.
