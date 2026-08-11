# ADR-008: AI Provider and Workflow Architecture

- Status: Accepted
- Date: 2026-08-12

## Context

AI behavior must not belong to Telegram or one paid model and must remain fakeable in CI.

## Decision

Use Laravel AI SDK behind organization/agent configuration and an `AiWorkflowEngine` boundary. Current workflow engine is Laravel-native; no Python/LangGraph runtime in Phase 1.

## Consequences

Prompts/runs are versioned and structured; external calls are faked in normal tests. Complex durable workflows may justify a later ADR.
