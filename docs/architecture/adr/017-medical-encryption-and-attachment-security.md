# ADR-017: Medical Encryption and Private Attachment Security Foundation

- Status: Accepted
- Date: 2026-08-15

## Context

`REQ-CLIENT-002`, `REQ-MEDICAL-SEC-001`, `REQ-ATTACHMENT-001`, and `REQ-ATTACHMENT-002` require storing sensitive longitudinal medical profiles and private attachments within the organization security boundary.

Under Data Classification (Class C — Sensitive personal/medical), medical anamnesis, complaints/goals, operations/injuries, medicines, supplements, and clinical files require strict confidentiality at rest, explicit tenant isolation, prevention of leakage into audit/log streams, and an encryption architecture that avoids permanent coupling to a single global key or cast while allowing future organization-scoped key rotation.

## Decision

1. **Application-Level Encryption Boundary**:
   Sensitive medical profile fields (`anamnesis`, `complaints_goals`, `operations_injuries`, `medicines`, `supplements`) pass through an explicit application encryption boundary (`MedicalEncryptorInterface`, `MedicalKeyResolverInterface`) rather than relying on global model casts. Domain and Application services interact with structured plaintext DTOs (`MedicalProfileData`) after explicit authorization.
2. **Dedicated Medical Encryption Secret**:
   Medical data is encrypted using a dedicated versioned medical encryption secret (`MEDICAL_ENCRYPTION_KEY_V1` / `config('medical.keys.1')`) outside the database. It does not depend on or fall back to `APP_KEY`, ensuring `APP_KEY` rotation does not invalidate medical ciphertext and medical secrets remain independently managed. Framework-vetted `Illuminate\Encryption\Encrypter` (AES-256-CBC with HMAC-SHA256 authenticated envelopes) is used without custom cryptography.
3. **Key Version and Rotation Seam**:
   Persisted medical records store `encryption_key_version` and resolve cryptographic keys dynamically by `(organization_id, key_version)`. Future organization-scoped KMS or vault keys can be added to `MedicalKeyResolverInterface` without changing domain models or medical consumers.
4. **Private Attachment Storage & Temporary Signed Access**:
   Attachments are stored on disk `private` under UUID-based storage paths (`medical/attachments/{org_id}/{uuid}.{ext}`). Access is granted exclusively via short-lived, server-generated temporary signed URLs (`URL::temporarySignedRoute`, 15-minute default TTL). The streaming controller verifies signature validity/expiry, rechecks server-side organization context and actor permissions, enforces attachment ownership, and confirms `cleared` scan status. Physical disk paths are never exposed.
5. **Server-Side MIME Sniffing and DICOM Exclusion**:
   Uploaded files are inspected via server-side MIME sniffing (`finfo`) against an allowlist (`text/plain`, `application/pdf`, `image/jpeg`, `image/png`, `image/webp`). Extension spoofing is rejected. Raw DICOM files (MIME `application/dicom`, extension `.dcm`/`.dicom`, and DICOM header signatures `DICM` at byte offset 128) are strictly rejected.
6. **Attachment Taxonomy & Configurable Limits**:
   Taxonomy is restricted to confirmed requirement categories: `medical_report` (medical conclusions and documents) and `posture_photo` (posture photos). Attachment size limit is configurable via `MEDICAL_ATTACHMENT_MAX_BYTES` (default 20 MB, recorded as technical assumption ASM-008).
7. **Malware / Quarantine Lifecycle & Fail-Closed Runtime**:
   `AttachmentScannerInterface` manages typed lifecycle states (`pending`, `cleared`, `quarantined`, `rejected`). Normal runtime binds `FailClosedAttachmentScanner`, ensuring untrusted uploads without a live scanner remain quarantined and unavailable. A deterministic scanner is provided solely as a test fake.
8. **Client Save Boundary Separation**:
   Client identity/contact editing is strictly separated from medical profile mutations in the CRM. Ordinary client profile updates do not decrypt, re-encrypt, or rewrite medical data. Medical profiles are managed via a dedicated modal action and explicit application service.
9. **Audit, Log Redaction & Safe Scanner Metadata**:
   Audit metadata records allowlisted operational indicators only. Scanner metadata is sanitized to safe keys (`scanner_name`, `scanned_at`, `matched_rule`, `reason`). Log redaction masks sensitive medical keys. Physical file deletion is restricted to internal transactional cleanup; business retention/erasure remains deferred under OQ-013.

## Consequences

- Direct SQL queries cannot search encrypted medical columns as plaintext.
- Every medical profile or attachment read requires server-side organization and authorization validation.
- All file downloads require temporary signed URLs and server-side authorization.
