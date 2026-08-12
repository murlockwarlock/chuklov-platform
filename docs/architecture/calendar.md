# Future calendar event readiness

M2 does not implement booking, scheduling, calendar providers, synchronization, or `.ics` generation.

Future scheduling should expose a provider-neutral calendar-event representation containing a stable event UID, canonical start and end instants, IANA timezone context, location, optional online meeting URL, event status, and a sequence/version for updates. `.ics` generation, calendar attachments, and provider adapters must consume that representation rather than preformatted display strings.

Google Calendar, iCal/external-calendar synchronization, and two-way synchronization remain separately scoped integration work for later milestones.
