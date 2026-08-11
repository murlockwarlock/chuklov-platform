# ADR-015: Security and Data Protection

- Status: Accepted
- Date: 2026-08-12

## Context

The platform handles identity, medical, file, payment, and integration-secret data.

## Decision

Apply data classification, least privilege, server-derived tenancy, policies, private storage, framework encryption, safe logs/payloads, webhook/replay controls, and security tests from first implementation.

## Consequences

Sensitive marketing/research use requires explicit legal/product approval. Security rules cannot be disabled as ordinary feature flags.
