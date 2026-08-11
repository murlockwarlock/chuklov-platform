---
name: ai-sdk-development
description: Use for this project's Laravel AI SDK work, including agents, prompts, tools, structured output, streaming, queues, images, audio, transcription, embeddings, RAG, vector stores, reranking, provider configuration/failover, and AI fakes. Trigger on `Laravel\Ai\`, ai-sdk, or Chuklov AI features; do not use for unrelated AI packages.
---

# Laravel AI SDK Development

1. Read the affected `REQ-AI-*` or `REQ-RAG-*` rows and the linked architecture/ADR before changing code.
2. Confirm the installed `laravel/ai` version from Composer or Laravel Boost application info.
3. Use Boost `search-docs` with several broad topic queries before relying on any SDK API. Search the exact capability plus its testing/faking API; do not embed the package name in queries.
4. Keep organization ownership, authorization, provider/model configuration, prompt versions, and permitted data scopes in the Application boundary. Telegram and other delivery adapters must not own AI behavior.
5. Use the SDK's current first-party contracts and fakes. Normal tests and CI must prevent stray prompts/provider calls and require no paid credential.
6. Keep structured outputs schema-validated and distinguish AI suggestions from specialist-confirmed facts. Queue payloads carry safe identifiers, not sensitive medical text.
7. Verify provider capability support and failover semantics from current docs instead of assuming all providers support every modality.
8. Run focused PHPUnit coverage, then the affected static, quality, and integration gates.

Useful Boost query groups include `agent prompting structured output`, `agent tools middleware`, `conversation memory`, `streaming queue broadcasting`, `testing agent fake prevent stray`, `embeddings vector stores reranking`, and the exact image/audio/transcription capability in scope.
