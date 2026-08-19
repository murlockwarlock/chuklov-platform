# ADR-018: Survey Builder Stable Identities and Canonical Snapshots

- Status: Accepted
- Date: 2026-08-19

## Context

Survey definitions are stored as canonical JSON and are consumed by branching, scoring, attempts, reports, and repeat comparisons. The CRM builder must let staff edit human text and order without exposing or regenerating the identities used by those consumers.

## Decision

1. The Filament survey form uses a dedicated UI mapper. It owns repeater state, localized fields, human selectors, and form-only state; Application actions receive canonical survey intent.
2. New section, question, option, metric, and threshold identities are generated once in server-side Filament form state. Existing identities are preserved byte-for-byte. Identities are never derived from labels or order, and identity-bearing repeaters are not cloneable.
3. Technical definition, compatibility, provenance, and result-tag values remain hidden or server-preserved. Published versions and attempt/report snapshots remain immutable and historically interpretable.
4. Publishing validates the locked draft inside the publish transaction before retiring the previous published version or changing the active version.

## Consequences

- Human CRM edits do not change answer-map, condition, scoring, metric, threshold, or comparison references.
- Invalid or unresolved references block persistence and publication instead of being repaired silently.
- No database migration is required because the existing canonical JSON and version tables already persist the required identities.
