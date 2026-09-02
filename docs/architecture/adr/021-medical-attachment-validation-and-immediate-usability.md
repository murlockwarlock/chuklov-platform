# ADR-021: Medical Attachment Validation and Immediate Private Usability

- Status: Accepted
- Date: 2026-08-30

## Context

The client Technical Specification requires authorized staff to upload private medical files for later CRM and medical workflows. It requires safe file handling and access control, but does not define malware quarantine or a scanner-backed cleared state. The previous implementation made an unconfigured internal scanner a prerequisite for ordinary uploads and made valid files unusable on production-like environments without that service.

## Decision

1. An allowed attachment becomes a persisted, privately stored record immediately after server-side validation succeeds. The active lifecycle has no scanner, quarantine, pending, cleared, or rejected malware state.
2. Attachment security remains enforced by private storage, organization-scoped ownership, authentication and authorization, signed download URLs, safe UUID-based paths and original filenames, MIME/content and extension validation, size limits, raw DICOM rejection, checksums, and allowlisted audit metadata.
3. CRM, Sessions, Companion, and AI consumers treat a persisted validated attachment as usable, while each access path retains its own authorization and integrity checks.
4. The obsolete attachment scan columns are removed with a forward migration. Historical migration files remain unchanged, and rollback reintroduces only nullable legacy columns without fabricated scan outcomes.

## Consequences

- Ordinary valid uploads work without an antivirus daemon or provider configuration.
- An independently contracted malware-scanning capability would require a new product and architecture decision; it is not implied by the current attachment contract.
- Existing private-storage, tenant-isolation, validation, download, checksum, and audit controls remain mandatory.

This ADR supersedes the scanner/quarantine portions of ADR-017 for the active attachment product flow.
