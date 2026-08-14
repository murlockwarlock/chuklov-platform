# Assumptions

| ID | Assumption | Scope | Revisit |
|---|---|---|---|
| ASM-001 | `Asia/Bangkok` is seed configuration for the initial organization, not a code invariant. | Milestone 0 seed | Organization settings |
| ASM-002 | Local private filesystem is the Phase 1 default; S3-compatible storage remains a future adapter. | Storage foundation | Operational need or ADR |
| ASM-003 | PostgreSQL 18 and Redis 8.2 are development/CI runtime choices compatible with the application stack. | Infrastructure | Deliberate dependency upgrade |
| ASM-004 | Client-facing shell copy is maintained in one RU/EN localization dictionary; organization-owned service/legal/content records are shown in the selected locale when configured and otherwise fall back without inventing translations. | Client Portal shell | Localized content requirements |
| ASM-005 | In the Phase 1 single-organization runtime, platform-managed legal documents are stored with the active organization scope; organization-managed wording remains a future platform-controlled entitlement. | M2 legal readiness | Phase 2 legal-content decision |
| ASM-006 | M4A uses one organization-level booking lead-time setting. Service-, specialist-, location-, and format-specific precedence remains an explicit future policy decision. | M4A scheduling | M4B/M4C product policy |
| ASM-007 | No current normalized requirement establishes a global consent gate before unrelated authenticated Portal use; published legal consents are therefore presented directly in Profile until an action-specific legal rule is confirmed. Immutable versioned evidence remains unchanged. | Client Portal legal presentation | Legal/product decision |
