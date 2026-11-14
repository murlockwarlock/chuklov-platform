# Medical Stage 1B implementation plan

## Goal

Implement the complete Stage 1B medical catalog and session workflow on the
approved Stage 1A branch, preserving old session records and the existing
Application/Domain boundaries. Deliver one draft PR dependent on PR #80, with
staging evidence and no merge, Ready transition, or production deployment.

## Architecture

Extend `MedicalClinicalCatalog`, `ClinicalTestProtocol`, and
`MedicalSessionTestWriter/Reader`. A typed T1–T12 registry owns configuration
validation, normalization, evaluation, and counters. Test definitions retain
legacy fields and receive validated structured configuration; session test rows
retain legacy before/after IDs and cause pivots while storing one encrypted
structured payload per configured test. No parallel result engine is added.

Shared causes remain one organization-scoped identity with searchable
synonyms, allowed-test links, moderation/merge history, and historical
references. Safety markers, root-cause metadata, recommendation drafts, and
durable recommendation delivery evidence are additive extensions to the
existing medical/session path. Telegram is called only through
`NotificationChannel`; claim-before-I/O and stable idempotency prevent a
duplicate send, and unknown delivery is never shown as success.

## Tech stack

PHP 8.5, Laravel 13.30, Filament 5.8, PostgreSQL, existing Laravel encryption,
Livewire CRM forms, existing NotificationChannel/Telegram adapter, PHPUnit,
and the repository's staging Chromium/WebKit control-plane scripts.

## Spec

Implement every `REQ-MED-1B-*`, tests 001–031, and catalog OQ Proposal/default
behavior from `docs/product/requirements/2026-11-14-medical-test-catalog.md`.
Do not invent missing cause lists, clinical thresholds, formulas, normal tor
direction, or pulse-wave names. Keep Test 31 threshold/direction fail-closed
until configured. Preserve the Stage 1A/1C boundaries and keep PDF behavior
as a 1C dependency contract rather than a fake export.

## Global constraints

- `organization_id` is the tenant boundary; all Application actions authorize
  the current organization and actor.
- Medical structured payloads and free text use the existing key-versioned
  encryption boundary; no protected data enters logs, audit metadata, queue
  payloads, or provider messages beyond the approved recommendation text.
- Catalog and session mutations are transactional, bounded, and idempotent;
  existing inactive definitions/values remain readable in history.
- CRM configuration must use human-readable controls, not JSON, IDs, or
  technical keys; the session form must support 0/1/many and 10–30 tests at
  320–1440px without horizontal scrolling.
- Every implementation task follows failing-test-first development and is
  verified with the narrowest useful local check plus staging PostgreSQL and
  real browser journeys where the changed risk requires them.

## Review focus

Tenant isolation, encryption/key-version handling, compatibility with Stage 1A
records, deterministic T1–T12 evaluation, fail-closed rules, test-selection
and default ordering, cause merge/history preservation, safety-marker lifecycle,
recommendation claim/delivery races, and first-time CRM/session usability.

## Implementation tasks

1. **Catalog model and migration foundation**
   - Files: Sessions catalog models, additive migrations, typed config/value
     objects, preset/upgrade action.
   - TDD: migration/model compatibility, tenant composite constraints, 31
     preset definitions and exact Main default selection/order.
   - Checks: focused PHP tests, migration/schema inspection on staging
     PostgreSQL.

2. **T1–T12 typed engine**
   - Files: type enum/registry, schemas, normalized payload DTOs, evaluator,
     deterministic violation/counter service.
   - TDD: one focused contract test per type, including T4 transition counts,
     T8/T10 warnings, T12 fail-closed behavior, and Test 1 deduplication.
   - Checks: focused type-engine tests and compatibility counter tests.

3. **Catalog CRM configuration and 31-test preset**
   - Files: `SaveMedicalClinicalCatalog`, catalog page/import path, preset
     loader, cause links and human-readable dynamic configuration controls.
   - TDD: create/edit/deactivate/reorder/search/add specialized test, allowed
     causes, safe empty cause state, and reload persistence.
   - Checks: focused Livewire/feature tests and staging CRM journey.

4. **Generic session protocol UI and writer/reader compatibility**
   - Files: `ClinicalTestProtocol`, session DTOs, writer/reader, dynamic type
     fields, previous-after projection, `перепроверить` handling.
   - TDD: create/default/remove/add/reload, before/after separation, 0–3
     visibility, structured validation, historical records, counters only for
     selected rows.
   - Checks: focused feature tests plus staging browser open → action → reload.

5. **Cause directory, roots, safety marker, and special rules**
   - Files: cause application actions/models/migrations/UI, root-cause block,
     client/session marker projections, Test 18/19 UI and persistence.
   - TDD: search/create/duplicate detection/apply/merge/history, one main root
     cause, Test 18 set/clear/edit audit-safe flow, Test 19 violation type.
   - Checks: tenant/auth/security tests and staging CRM/session journey.

6. **Result and effectiveness**
   - Files: deterministic result builder, manual-result protection, counters
     and effectiveness projection in Block 7/9.
   - TDD: five hints, complaint delta/negative worsening, resolved/new/unique
     causes, repeated collect confirmation, no integral percentage.
   - Checks: focused application and Livewire tests, staging reload evidence.

7. **Recommendations and history**
   - Files: encrypted recommendation draft/history, durable delivery record and
     Application action, Telegram identity resolution through existing channel
     contracts, session/client history surfaces, 1C PDF contract.
   - TDD: explicit approval only, edit-before-send, delivered/failed/unknown,
     duplicate click race, status/date/history, fake channel only.
   - Checks: fake-provider tests, PostgreSQL race/transaction tests, staging
     delivery dry-run without a real client/provider send.

8. **Integrated verification and handoff**
   - Files: focused regression tests and only required test/browser fixtures.
   - Checks: exact-SHA staging deployment, PostgreSQL, one Chromium run, one
     WebKit run, responsive widths, CRM and integration evidence; final diff
     and security review; draft PR only.
