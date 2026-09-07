---
name: ai-agents
description: Build Chuklov AI agents and workflows through the Laravel AI SDK boundary. Use for prompts, tools, routing, structured output, provider configuration, or AI evaluation work.
---

# AI Agents

1. Confirm the milestone permits the feature, then read affected AI `REQ-*`, `docs/architecture/ai.md`, and `docs/architecture/ai-data-flow.md`.
2. Consult Laravel Boost for installed Laravel AI SDK APIs.
3. Keep deterministic business rules outside prompts and provider responses.
4. Bound tool permissions, validate structured output, and preserve organization context server-side.
5. Classify data before sending it to a provider; do not include unnecessary sensitive or health data.
6. Fake all AI calls in normal tests and CI. Paid calls require an explicit, separate evaluation path.
7. Add deterministic regression coverage for the specific changed AI behavior, plus relevant cases under `evals/`; record model or prompt behavior changes without storing sensitive transcripts.
8. Do not rerun complete eval packs for every prompt or runtime adjustment. Use a small representative real-provider or staging subset by default; full eval packs are for benchmark or release-quality evaluation.
9. Owner-visible real staging conversation is higher-value than dozens of mocked green tests. If a real user scenario fails, the AI task remains FAIL regardless of eval count.

If `Привет, ты кто?` incorrectly triggers handoff: fix that exact path → focused regression test → real staging conversation → stop. Do not run all 44 provider cases unless the owner explicitly requests a full benchmark, prompt architecture changed materially across all agents, or a representative subset fails and broader diagnosis is needed.
