---
name: ai-agents
description: Build Chuklov AI agents and Laravel AI SDK workflows through the Application boundary. Use for prompts, tools, structured output, provider configuration, evaluation, or Chuklov AI features.
---

# Chuklov AI

1. Confirm the milestone permits the change, then read the affected
   `REQ-AI-*` or `REQ-RAG-*` rows and linked AI architecture/ADR.
2. Confirm the installed `laravel/ai` version from Composer or Boost. Use
   Boost documentation search for the exact capability and its testing/faking
   API before relying on version-sensitive SDK behavior.
3. Keep deterministic business rules, organization ownership, authorization,
   provider/model configuration, prompt versions, data classification, and
   permitted scopes in the Application boundary. Delivery adapters do not own
   AI behavior.
4. Bound tool permissions, validate structured outputs, distinguish AI
   suggestions from specialist-confirmed facts, and keep queue payloads to safe
   identifiers rather than sensitive medical text.
5. Use first-party SDK contracts and fakes. Normal tests and CI must prevent
   stray provider calls and require no paid credential; real-provider checks
   are an explicit evaluation or staging slice.
6. Verify provider capability and failover semantics from current documentation.
   Add deterministic regression coverage for the changed behavior and record
   prompt/model changes without sensitive transcripts.
7. Use a small representative real-provider or staging subset by default. Full
   evaluation packs are for benchmark/release evaluation, a material
   architecture-wide prompt change, or a representative failure.
8. An owner-visible real scenario outranks a large mocked green suite. If the
   exact scenario fails, keep the task open and fix that path before expanding
   evaluation scope.
