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
7. Add deterministic tests plus relevant cases under `evals/`; record model or prompt behavior changes without storing sensitive transcripts.
