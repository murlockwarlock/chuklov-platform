# Assumptions

| ID | Assumption | Scope | Revisit |
|---|---|---|---|
| ASM-001 | `Asia/Bangkok` is seed configuration for the initial organization, not a code invariant. | Milestone 0 seed | Organization settings |
| ASM-002 | Local private filesystem is the Phase 1 default; S3-compatible storage remains a future adapter. | Storage foundation | Operational need or ADR |
| ASM-003 | PostgreSQL 18 and Redis 8.2 are development/CI runtime choices compatible with the application stack. | Infrastructure | Deliberate dependency upgrade |
| ASM-004 | English bootstrap copy is non-business shell copy; final RU/EN content comes from managed content requirements. | Client Portal shell | Milestone 3 |
| ASM-005 | In the Phase 1 single-organization runtime, platform-managed legal documents are stored with the active organization scope; organization-managed wording remains a future platform-controlled entitlement. | M2 legal readiness | Phase 2 legal-content decision |
| ASM-006 | M4A uses one organization-level booking lead-time setting. Service-, specialist-, location-, and format-specific precedence remains an explicit future policy decision. | M4A scheduling | M4B/M4C product policy |
