# CRM specialist workspace

## Starting point

- Starting SHA: `d168240818ac3e4997506581f586954c3aff1666`
- Branch: `codex/crm-specialist-workspace`
- Existing scheduling, booking, client, organization, timezone, and impact-acknowledgement application services remain authoritative.

## Design

The booking resource list page becomes the weekly journal at `/admin/bookings`. Its existing Filament table remains available as a `Список` mode. Journal projections scope every query through the current organization and display booking instants in the organization IANA timezone exactly once. A bounded Livewire poll refreshes the journal after ordinary booking changes and the create flow can return to the same week.

The work schedule is a new Filament page backed by `SpecialistWorkingHour` and `ScheduleException`. The page uses the existing scheduling application actions, impact digest, and acknowledgement flow. A small application action updates one existing date exception atomically; it does not introduce storage or slot calculation. The month projection derives working/non-working days from the same recurring hours and exceptions used by availability.

The client page keeps `ClientResource`, `ClientSearch`, and `ClientsTable`. A read-only Analytics application query owns six CRM segment definitions and the default thresholds. The same query builder applies segments to the table and calculates sidebar counts, including the current search term. No unsupported DIKIDI categories are added.

## Implementation sequence

1. Add focused failing coverage for journal projections and prefilled creation, authoritative schedule projection/mutation safety, client segments/counts/search composition, and organization isolation.
2. Add the shared schedule projection, client segment query, and date-exception update action.
3. Turn the booking list into a weekly journal while preserving the existing table mode and creation/lifecycle actions.
4. Add the month schedule page and retain the existing scheduling configuration page for advanced settings/backward-compatible coverage.
5. Add the client category panel and responsive filter presentation around the existing client table.
6. Run focused tests, PHP formatting/syntax/diff checks, review the changed code for tenant/timezone/impact regressions, and prepare a draft PR without merging or deploying production.

## Acceptance evidence to collect

- Focused automated evidence for tenant-scoped journal, schedule, and client segment queries.
- PostgreSQL/staging evidence for schedule-to-availability-to-booking-to-journal behavior when staging access is available.
- Browser evidence at 320, 360, 390, 768, 1024, and 1440 pixels when browser runtime is available, including horizontal overflow check.
- Draft PR records the segment defaults: new within 30 days, regular from 3 completed visits, dormant after 90 days without a completed visit.
