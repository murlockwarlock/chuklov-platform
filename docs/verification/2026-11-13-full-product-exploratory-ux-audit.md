> EVIDENCE ONLY — NOT CURRENT INSTRUCTIONS

# Full product exploratory UX audit

Дата выполнения: 2026-11-13, Asia/Almaty.

Это один consolidated report для PR #77. Второй проход продолжил уже созданный
inventory и не запускал новый полный аудит с нуля. Проверка выполнялась через
реальные UI-контролы, с targeted code inspection только после browser
reproduction. Owner не использовался как exploratory QA.

После предыдущего отчёта owner обнаружил на реальном Journal UI пропущенный
REAL BUG: крупная текстовая кнопка rebooking ухудшала читаемость cancelled
booking cards. Предыдущий Journal PASS поэтому считался неполным. Выполнен
только bounded Journal regression: compact action, overlap geometry, click
prefill, Back/reload и narrow responsive states.

## Идентичность кандидата

| Поле | Значение |
| --- | --- |
| Starting SHA | `19d1e084df67f14a0060ced05a0e1496fa2b8905` |
| Previous audit SHA | `a032c172e2766337d086bf414f725355f4a6a9f5` |
| Final application SHA | `5e09a296b3d5670cae0770ef70f85b0027331bb6` |
| Repository | `murlockwarlock/chuklov-platform` |
| Branch | `audit/full-product-exploratory-ux` |
| Pull request | [PR #77](https://github.com/murlockwarlock/chuklov-platform/pull/77), Draft |
| Staging exact SHA | `5e09a296b3d5670cae0770ef70f85b0027331bb6` |
| Migrations | `Nothing to migrate.` |

`Final application SHA` и `Staging exact SHA` совпадают. После этого runtime
candidate был зафиксирован отдельным report-only commit, который не меняет
application code. Поэтому branch head может быть новее runtime SHA только за
счёт этого документа.

## Итоговый release status

| Gate | Result | Evidence / limitation |
| --- | --- | --- |
| CRM first-time usability | PASS | 60 grouped CRM surfaces; loading, labels, empty states, primary actions, validation and return paths checked |
| Portal first-time usability | PASS | Critical client flows, empty commerce/content states and mobile layouts checked on code-equivalent staging runtime |
| Mini App actual Telegram | NOT RUN | Approved interactive Telegram QA identity/browser was unavailable; direct public/protected route probes were run separately |
| Browser Chromium | PASS | CRM 1440/1024/768 and client 390/360/320 representative flows, including exact final CRM recheck |
| Browser WebKit | PASS for required representative flows | Client auth-equivalent, booking, specialist, payment boundary, survey, tracker, chat and CRM representative flows; actual Telegram client was not run |
| HOME VISIT | PASS | Temporary service/specialist/location/schedule fixture, mobile Chromium 390/360 and WebKit 390; cleanup completed |
| Finance local/manual UX | PASS | Obligations, partial/full manual payment, duplicate click, configuration, receipts/history and reconciliation states checked |
| AI configuration UX | PASS | Provider/prompt/evaluation/configuration unavailable and failure states checked; real provider execution is external limitation |
| Analytics configuration UX | PASS | Taxonomy, metric, attribution, cohort, segment, tag, age and purchase settings received safe mutation/reload/restore checks |
| Async feedback | PASS for representative platform classes | Dashboard loading, companion failure/retry, knowledge pending/unavailable and queue/runtime states checked |
| Silent failure | PASS | No audited user action ended silently; network aborts produced retryable human feedback or preserved validation state |
| State retention | PASS | Save/reload/back, stale booking selection, validation correction, double-submit and representative multi-tab checks |
| Tenant isolation | PASS | Representative clients, specialists, bookings, finance, files and configuration boundary checks; no cross-tenant data observed |
| Navigation/back/reload | PASS | Direct routes, menu, browser history, detail/list return, create/edit return and useful context preservation checked |
| Responsive | PASS for tested surfaces | Chromium 1440/1024/768/390/360/320 and WebKit 390 plus CRM representative checks |
| PostgreSQL | PASS | Staging PostgreSQL/pgvector health, persistence, money/idempotency, tracker ordering and tenant-scoped writes |
| Cleanup | PASS | Temporary HOME VISIT fixtures lifecycle-cleaned; message and audit history intentionally preserved |

Release gate: `READY FOR OWNER ACCEPTANCE` for the audited application
candidate, with the explicit external/access limitations below. PR #77 remains
Draft and was not marked Ready or merged.

## Coverage accounting

### Inventory and action depth

Counting convention: one Resource, one Filament Page, one Portal top-level flow,
or one Telegram destination family is one grouped human-facing surface. Create,
edit, view, action and detail routes are included under their parent surface and
are not counted as separate products.

| Coverage class | Count | Result |
| --- | ---: | --- |
| CRM implementation surface units | 60 = 31 Resources + 29 Filament Pages | 60/60 route/open covered |
| CRM writable/actionable surfaces | 55 | 55/55 meaningful mutation or action lifecycle covered |
| Portal top-level flows | 23 | 23/23 route/open and first-time meaning covered |
| Portal writable/action flows | 17 | 17/17 local action lifecycle covered; external payment side effect stopped at safe boundary |
| Deeply exercised writable surfaces / total writable surfaces | 72 / 72 | CRM 55/55 plus Portal 17/17; each received a real action lifecycle, with distinct invalid/correction coverage by form pattern |
| Telegram destination families | 2 | Runtime/API and route semantics checked; interactive session NOT RUN |
| Admin route variants discovered | 137 | Human routes separated from internal/debug endpoints |
| Portal route/action variants discovered | 78 | Authenticated, public and protected route families included |
| Browser journeys | 86 grouped journeys | Includes primary action, return/reload and representative failure paths |
| Invalid/correction journeys | 37 | Required, malformed, stale, duplicate and domain-conflict paths |
| Exploratory chaos sessions | 6 | 10–20 human actions per session, including filter/modal/back/reload/duplicate-click sequences |

The writable/action denominator includes ordinary CRUD and state-changing
actions, not every individual field. Distinct form patterns received at least
one invalid/correction path. Read-only analytics, monitoring and history views
received first-time, filters/empty/many and navigation checks instead of an
artificial write.

## Browser and runtime evidence

### Engines and viewports

- Chromium: 1440 and 1024 for CRM dashboard, tables, forms and modals; 768 for
  dense representative CRM surfaces; 390, 360 and 320 for client-critical
  Portal layouts and narrow controls.
- WebKit: Portal auth-equivalent, specialist, booking, payment boundary,
  survey, tracker and companion representative journeys at 390; CRM save form,
  modal validation and calendar representative checks.
- Mobile browser checks included page-level overflow, sticky bottom navigation,
  long labels, modal height, keyboard-safe controls and CTA clearance.
- Actual Telegram in-client interaction was not simulated or replaced by a
  forged initData/session.

### Exact staging candidate

`5e09a296b3d5670cae0770ef70f85b0027331bb6` was deployed with the protected
staging flow. Observed:

- health: database, pgvector, Redis and private storage all true;
- app, Horizon, scheduler, Telegram, Mailpit and PostgreSQL containers healthy;
- Nginx medical upload limit and safe access logging checks passed;
- `scripts/staging-smoke.sh`: `STAGING SMOKE: PASS`;
- deploy output: `INFO Nothing to migrate.`;
- exact Chromium recheck: notification catalog at 1440/1024 had human
  `Требуется настройка` status, no raw timezone key, no horizontal overflow;
- exact Chromium dashboard recheck: loading placeholders visible during cold
  load, then zero placeholders after deferred widgets were reached, no console
  errors and no horizontal overflow;
- exact staff companion network abort: POST requests were aborted in the
  browser, human failure/retry UI appeared, the entered text remained visible,
  and it was absent after reload.
- exact Journal recheck: three released-for-rebooking cards at the same time
  remained readable at 1440/1024 in compact, normal and wide density; the 28x28
  icon action stayed inside each card, did not overlap card text or create page
  overflow, and remained stable across reload. Click opened Create Booking with
  the released date/time/specialist prefilled; Back returned to Journal history.
  Narrow 390/360 checks also passed with the action visible and contained.

The Portal client browser state available for this final candidate was expired.
The approved client 2 is currently inactive and its email is outside the
staging QA recipient domain, so a normal UI reauthentication request correctly
returned `Не удалось отправить код. Попробуйте позже или войдите через
Telegram.` and produced no Mailpit message. No session bypass, new identity or
permission change was used. Portal browser evidence below is therefore reused
from the immediately preceding code-equivalent runtime where the same Portal
application files were deployed; the final commit after that runtime changes
only a Notification Catalog test assertion.

## Consolidated coverage matrix

`PASS` means the named browser journey was actually executed. `NOT RUN` is used
only for an external or access-boundary limitation with a concrete reason.

### CRM

| Surface | Role | Entry point | Tested journey | Result | Finding IDs |
| --- | --- | --- | --- | --- | --- |
| Login, session, MFA, profile and revoke sessions | QA administrator | `/admin/login`, `/admin/profile` | normal login, expired-session boundary, profile/session actions, reload | PASS | — |
| Dashboard and deferred widgets | QA administrator | `/admin` | cold load, early loading state, reload, scroll to deferred widgets, 1440/1024 | PASS | BUG-CRM-001 |
| Clients | QA administrator | CRM navigation, `/admin/clients` | search/filter, 0/1/many, open card, create/edit, invalid/correct/save/reload/back | PASS | BUG-CRM-003 |
| Client card and related context | QA administrator | client detail | profile, family/patient context, bookings, notes, attachments, medical tabs, return to list | PASS | BUG-CRM-003 |
| Companion staff side and messages | QA administrator | client companion, Messages | unread/read, takeover, reply, validation, network abort, retry, second reply, reload | PASS | BUG-CRM-005, BUG-CRM-006 |
| Medical sessions and clinical data | QA administrator/specialist | client sessions | invalid → correction → save → reload, long text, score fields, cancel and protected context | PASS | — |
| CRM attachments/media | QA administrator | client/session media | upload, invalid/large/wrong type boundary, replace/remove where lifecycle exists, reload and private preview | PASS | — |
| Bookings and booking journal | QA administrator | `/admin/bookings` | filters, open/edit, create, multiple, cancel/reschedule, released-history rebooking action, 3-way overlap, density states, reload/back, 1440/1024/390/360 | PASS | BUG-CRM-002, BUG-CRM-008 |
| Scheduling configuration | QA administrator | `/admin/scheduling-configuration` | understand scope, safe change, invalid/correction, save/reload/restore | PASS | — |
| Work schedule and working intervals | QA administrator | `/admin/work-schedule`, `/admin/working-intervals` | specialist selection, timezone, empty/one/many intervals, overlap validation, reload | PASS | BUG-CRM-002 |
| Schedule exceptions and unavailable periods | QA administrator | corresponding resources | create/edit, past/future date, invalid range, correction, archive/delete lifecycle, list return | PASS | — |
| Specialists | QA administrator | `/admin/specialists` | list 0/1/many, profile, create/edit, invalid/correction/save/reload, hide/restore lifecycle | PASS | BUG-CRM-002, BUG-CRM-003 |
| Services | QA administrator | `/admin/services` | create/edit, all visit formats, inactive historical reference, invalid/correction/reload | PASS | — |
| Specialist-service assignments | QA administrator | `/admin/specialist-service-assignments` | create, duplicate attempt, filter, remove, reload and stale selection | PASS | — |
| Working locations and location days | QA administrator | `/admin/working-locations`, `/admin/location-days` | create/edit, invalid/correction, timezone, deactivate/restore lifecycle | PASS | — |
| Financial obligations and payment history | QA administrator/finance | `/admin/financial-obligations` | list/filter, open, partial payment, second/full settlement, duplicate click, reload/history | PASS | BUG-CRM-004 |
| Manual payment and receipt surfaces | QA administrator/finance | obligation actions, receipt route | invalid amount, correction, currency/status display, receipt boundary, back/reload | PASS | BUG-CRM-004 |
| Finance configuration and movement settings | QA administrator/finance | finance settings | safe configuration change, inactive currency reference, save/reload/restore | PASS | BUG-CRM-004 |
| Finance expense entry | QA administrator/finance | `/admin/finance-expense-entry` | create/edit/cancel, invalid amount/date, correction, reload | PASS | — |
| Payment gateway reconciliation | QA administrator/finance | reconciliation resource | first-time empty/state meaning, filter, safe synthetic state/action, reload | PASS | — |
| Gift certificates | QA administrator/finance | `/admin/gift-certificates` | empty/one/many, human amount/currency/status, lifecycle actions and reload | PASS | — |
| Tracker plans | QA administrator | `/admin/tracker-plans` | create, invalid price, correction, save/reload/edit, reorder, archive/disable/restore | PASS | BUG-CRM-007 |
| Tracker settings | QA administrator | `/admin/tracker-settings` | configurable value change, reorder, save/reload/restore original | PASS | BUG-CRM-007 |
| Content sections | QA administrator | `/admin/content-sections` | create/edit, order controls, image/URL/empty content, archive/restore, reload | PASS | BUG-CRM-007 |
| Knowledge sources | QA administrator | `/admin/knowledge-sources` | text create, empty validation, correction, edit, invalid/valid file, retire/restore/delete disposable source | PASS | — |
| Knowledge retrieval inspector | QA administrator | `/admin/knowledge-retrieval-inspector` | empty/pending/unavailable/error state, search/filter, retry meaning, reload | PASS | — |
| Survey definitions and builder | QA administrator | `/admin/survey-definitions` | empty/one/many questions, reorder, conditional logic, invalid scoring input, draft/edit/publish/reload | PASS | — |
| Survey attempts and reports | QA administrator | `/admin/survey-attempts` | empty/one/many, open result, filters, return from detail, reload | PASS | — |
| Scenario rules and actions | QA administrator | `/admin/scenario-rules`, `/admin/scenario-actions` | create, embedded template modal, empty invalid, correction, save, enable/disable, reload | PASS | BUG-CRM-006 |
| Notification templates and catalog | QA administrator | `/admin/notification-templates`, `/admin/notification-catalog` | create, invalid rich text, correction, edit/reload, deactivate; catalog status/channel/template/delivery meaning | PASS | BUG-CRM-006 |
| Broadcast campaigns | QA administrator | `/admin/broadcast-campaigns` | first-time form, invalid segment/template, draft create/reload/cancel, future schedule/start/cancel, copy/edit draft, duplicate-click guard | PASS for local lifecycle; test send NOT RUN because no eligible synthetic recipient was available | — |
| Feedback configuration/submissions | QA administrator | feedback navigation | empty/one/many, configuration mutation, invalid/correction, submission detail, reload | PASS | — |
| AI providers and models | QA administrator | `/admin/ai-providers` | create/configure, invalid credentials, correction, enable/disable, reload, unavailable provider state | PASS | BUG-CRM-006 |
| AI prompts and versions | QA administrator | `/admin/ai-prompts` | create/edit/draft/validation/activate/rollback/reload | PASS | BUG-CRM-006 |
| AI evaluations and runs | QA administrator | `/admin/ai-evaluations`, `/admin/ai-runs` | create/config lifecycle, invalid/correction, empty/failed/successful safe records, reload/export boundary | PASS | — |
| AI monitoring and progress | QA administrator | `/admin/ai-monitoring-overview`, progress route | empty, pending, failed run, retry/return and technical-error humanization | PASS | BUG-CRM-006 |
| Analytics console | QA administrator | `/admin/analytics-console` | first open, empty/many filters, long labels, date range, reload, no technical labels | PASS | — |
| Analytics taxonomy groups | QA administrator | `/admin/analytics-taxonomy-groups` | create/edit, invalid/correction, reorder where applicable, save/reload/restore | PASS | BUG-CRM-007 |
| Analytics metric settings | QA administrator | `/admin/analytics-metric-settings` | safe setting change, invalid/correction, save/reload/restore | PASS | BUG-CRM-007 |
| Attribution source settings | QA administrator | `/admin/attribution-source-settings` | create/edit, duplicate/empty validation, correction/reload/restore | PASS | BUG-CRM-007 |
| Cohort, segment and tag candidate settings | QA administrator | `/admin/client-cohort-groups`, `/admin/client-segment-settings`, `/admin/analytics-tag-candidates` | 0/1/many, safe mutation/action, invalid/correction, reload | PASS | BUG-CRM-007 |
| Age range and purchase group settings | QA administrator | corresponding analytics pages | safe synthetic setting change, validation, correction, reload/restore | PASS | BUG-CRM-007 |
| Legal documents | QA administrator | `/admin/legal-documents` | create/edit/publish/retire, invalid/correction, reload and client projection boundary | PASS | — |
| B2B configuration and leads | QA administrator | `/admin/b2b-form-configuration`, `/admin/b2b-leads` | configuration change, lead create/view, invalid/correction, host launch boundary, reload | PASS | — |
| Referral partner profiles/relationships | QA administrator/finance | partner navigation | list/filter, profile, 0/1/many, state/action meaning, reload | PASS | — |
| Referral payout requests | QA administrator/finance | `/admin/referral-payout-requests` | safe synthetic pending/approve/reject/cancel state transitions, duplicate click, reload | PASS | — |
| Referral reward configuration | QA administrator/finance | `/admin/referral-reward-configuration` | safe configuration change, invalid/correction, save/reload/restore | PASS | — |
| Companion settings | QA administrator | `/admin/companion-settings` | first-time meaning, safe toggle/configuration, invalid/correction, reload | PASS | — |
| Notification center and database notifications | QA administrator | CRM header notifications | unread/read, navigation to source, stale notification, reload | PASS | BUG-CRM-006 |
| Restricted-role authorization boundary | existing permission model | code, policies, boundary tests, current staging navigation | allowed/denied action model and no new role creation | NOT RUN — no existing restricted-role staging QA identity; authorization boundary tests and UI contracts were inspected | — |

Все 31 Resource и 29 Page implementation units перечислены выше, включая
read-only/action-only resources. Для `restricted-role browser UX` использована
явная limitation из current staging: там есть только administrator membership;
новая бизнес-роль не создавалась.

### Portal, client web и Mini App-compatible surface

| Surface | Role | Entry point | Tested journey | Result | Finding IDs |
| --- | --- | --- | --- | --- | --- |
| First opening, authentication and onboarding | client | `/`, email/Telegram controls, `/portal/onboarding` | fresh entry, authenticated return, invalid/expired auth boundary, reload | PASS for browser client flow | — |
| Home and More navigation | client | `/`, `/portal/more` | first meaning, primary CTAs, back/forward, direct routes, mobile nav | PASS | — |
| Services and specialist selection | client | `/portal/services`, specialist profile | service → specialist → compact/full profile → booking, context retention | PASS | — |
| Booking flow: OFFICE | client | `/portal/bookings/create?format=office` | service → specialist → date/slot → review → result boundary, reload/back, 390/360/WebKit | PASS | BUG-PORTAL-002, BUG-PORTAL-003 |
| Booking flow: ONLINE | client | `/portal/bookings/create?format=online` | specialist → service → slot → review, unavailable state, timezone and reload | PASS | BUG-PORTAL-002, BUG-PORTAL-003 |
| Booking flow: HOME VISIT | client | HOME synthetic service | service → specialist → HOME VISIT → area → slot → review, invalid address/legal consent, timezone, back/reload | PASS | BUG-PORTAL-004 |
| Existing bookings | client | `/portal/bookings`, booking detail | upcoming/history, open detail, cancel confirmation, reschedule, stale slot, reload/back | PASS | BUG-PORTAL-001, BUG-PORTAL-004 |
| Profile, preferences and consents | client | `/portal/profile` | editable fields, invalid/correction/save/reload, timezone/currency/locale, legal consent display | PASS | — |
| Family and patient context | client | `/portal/family` | 0/1/many, select/deselect, invitation boundary, reload/back, protected context | PASS | — |
| Medical profile | client | `/portal/medical-profile` | first open required-field explanation, invalid submit, visible summary/inline errors, correction/save/reload at 390/360 | PASS | BUG-PORTAL-005 |
| Medical attachments | client | medical profile uploader | supported/invalid/large selection, pending/upload/error/retry, private download, reload | PASS for current upload/download lifecycle | OWNER DECISION REQUIRED for replace/remove/version policy |
| Finance and payment boundary | client | `/portal/finance` | no obligations, one/many obligation states, status/history/receipt, retry and unavailable provider boundary | PASS for local UX | External charge/refund NOT RUN |
| Gift certificates | client | `/portal/gift-certificates` | empty/one/many, apply/transfer/cancel states, back/reload | PASS | — |
| Referrals | client | `/portal/referrals` | first meaning, link/payout state, safe validation/cancel/reload | PASS | — |
| B2B | client | `/portal/b2b` | empty state, required answer explanation, submit/error/reload path | PASS | — |
| Content and communities | client | `/portal/sections/{section}` | missing image, long content, hidden/unpublished content, back/reload | PASS | — |
| Surveys and reports | client | `/portal/surveys` and attempt/report routes | empty/one/many, progression, save/complete, invalid/correction, result/reload | PASS | — |
| Health, progress and tracker | client | `/portal/health`, `/portal/tracker` | empty/one/many, check-in/task entry, pending/error/reload, navigation | PASS | — |
| Companion/chat | client | `/portal/companion` | first/second message, specialist handoff, retry, abort, unread/read, reload/back | PASS | BUG-PORTAL-006 |
| Feedback and attribution | client | `/portal/feedback`, `/portal/attribution` | first open, validation/correction/submit, duplicate/reload, no dead end | PASS | — |
| Protected and stale direct routes | client | direct deep links | stale/deleted/inactive entity, auth-expired response, public/protected destination semantics | PASS for available runtime routes; expired approved QA session itself is external limitation |
| Telegram launch and start parameters | Telegram client | `/portal/telegram/launch/{entry}` and bot menu contract | public/protected/stale start parameter route probes, 200/404 semantics, no technical error | NOT RUN — no approved interactive Telegram browser identity |

The exact HOME VISIT confirmation was intentionally stopped before final booking
creation because the synthetic client had no SELF profile. The address and
mandatory-document failure states were proven through the real confirmation
controls, with no booking side effect. Temporary CRM fixtures were then
archived/deactivated through normal lifecycle actions.

### Internal and non-human exclusions

Excluded from human-facing inventory with explicit reason:

- `/health`, runtime/queue/scheduler probes and deploy-only routes: operational
  diagnostics, not user product surfaces;
- `_boost/*`, Inertia devtools and debug endpoints: development-only;
- webhook/callback/provider transport endpoints, raw download endpoints and
  queue internals: exercised through their user-facing boundary or runtime
  smoke, not as standalone screens;
- internal exports and attachment download URLs: checked as actions from the
  originating human surface, not separate navigation products;
- database/audit/log views without a human route: diagnostic evidence only.

## Fixed defects matrix

The table records user-observable defects, not every low-risk implementation
change. All listed defects were reproduced before the corresponding fix and
rechecked through the affected browser journey.

| ID | Severity | Surface | Reproduction | Fix | Regression | Browser retest |
| --- | --- | --- | --- | --- | --- | --- |
| BUG-CRM-001 | REAL BUG | Dashboard | cold load briefly showed an unexplained empty dashboard | loading placeholders and human loading copy; deferred widgets keep layout | `CrmUxRemediationTest`, exact dashboard probe | Chromium 1440/1024, cold/reload/scroll PASS |
| BUG-CRM-002 | REAL BUG | scheduling/timezone | CRM exposed IANA keys and technical timezone context | human city plus UTC offset labels across scheduling and booking surfaces | `CrmUxRemediationTest`, scheduling tests | Chromium/WebKit scheduling and booking PASS |
| BUG-CRM-003 | REAL BUG | CRM tables/cards | long names and technical columns made primary work hard to scan | compact human columns, responsive visibility and readable action layout | CRM UX regression tests | Chromium 1440/1024/768, long-value and overflow checks PASS |
| BUG-CRM-004 | BLOCKING | manual finance payment | repeated submit could initiate duplicate manual payment state | idempotent duplicate-submit guard and focused persistence regression | `FinanceCrmUxTest`, PostgreSQL/staging payment journey | double-click/second payment/reload PASS |
| BUG-CRM-005 | REAL BUG | CRM network actions | failed Livewire action left the user without a result or safe retry | global human error banner, retry guidance and loading-control recovery | `CrmUxRemediationTest` | exact staging POST abort PASS; values retained, no write |
| BUG-CRM-006 | REAL BUG | communications and AI | notification/AI failure text exposed ambiguous or technical meaning | human labels, status explanations, next action and localized validation | notification, scenario and AI focused suites | catalog, template, rule, companion and AI states PASS |
| BUG-CRM-007 | REAL BUG | configuration ordering | ordinary admin had to understand numeric ordering fields/keys | reorder controls, hidden derived order, PostgreSQL lock/aggregate-safe update | tracker/configuration focused tests | tracker, medical, content and analytics reorder PASS |
| BUG-CRM-008 | REAL BUG | Booking Journal released history | large `+ Добавить` CTA with `shrink-0` consumed narrow/overlap card width and obscured specialist or booking text | retained the rebooking action as a keyboard/touch-friendly 28x28 `+` icon with human title/ARIA label; reserved flex space and compact `p-0`/`gap-0` geometry keep it inside overlap cards without widening the calendar | `SchedulingJournalProductionPassTest`, E2E Journal assertions | exact staging Chromium 1440/1024, compact/normal/wide, 3 overlapping cards, 390/360, click/prefill/Back/reload PASS |
| BUG-PORTAL-001 | REAL BUG | booking cancellation | cancel action could be triggered without an explicit confirmation step | confirmation dialog before cancellation | Portal feature/E2E regression | Chromium/WebKit booking detail PASS |
| BUG-PORTAL-002 | REAL BUG | mobile booking CTA | sticky action could sit under fixed mobile navigation | bottom clearance and sticky CTA placement | Portal product regression | Chromium 390/360 and WebKit 390 PASS |
| BUG-PORTAL-003 | REAL BUG | booking state retention | selected slot/confirmation step was lost after reload/back transition | URL-backed selection/step persistence and stale-slot validation | `PortalProductUxTest` | OFFICE/ONLINE/HOME reload and return PASS |
| BUG-PORTAL-004 | REAL BUG | stale/deleted booking URL | invalid selected slot could be reintroduced by Inertia history after cleanup | delayed post-mount stale selection cleanup | `PortalProductUxTest` | exact staging stale URL probe on code-equivalent runtime PASS; final runtime files unchanged |
| BUG-PORTAL-005 | REAL BUG | medical profile | required fields became understandable only after failed submit | visible required markers and top-level validation summary with inline errors | `PortalProductUxTest` | Chromium/WebKit 390/360 invalid → correction → reload PASS |
| BUG-PORTAL-006 | REAL BUG | Portal companion | network abort did not give a clear retry state | human error/retry copy, preserved composer state and safe action handling | Portal companion tests | Chromium/WebKit abort/retry/reload PASS |

Result: `BLOCKING found/fixed/open = 1/1/0` and
`REAL BUG found/fixed/open = 13/13/0` in this consolidated ledger. The owner
found the Journal defect after the earlier report, so the earlier Journal PASS
was explicitly superseded by BUG-CRM-008 and the bounded regression above. No unresolved
user-visible BLOCKING or REAL BUG remains in the locally testable scope.

## Remaining UX proposals

These are not release-blocking defects. They are deliberately limited to
changes where the ordinary flow already works or where product policy is
required before implementation.

| ID | Surface | Problem | Option A | Option B | Recommendation | Scope/risk |
| --- | --- | --- | --- | --- | --- | --- |
| UX-PROP-001 | Medical attachments | current lifecycle supports upload/download but no replace/remove control; retention/version policy is not explicit | add versioned replacement with immutable history | keep append-only materials and explain lifecycle in the UI | decide policy first; prefer versioned replacement if medical/legal owner accepts it | product/legal policy plus protected-data lifecycle |
| UX-PROP-002 | Broadcasts | real marketing send was intentionally not performed; safe test-send semantics need an explicit audience boundary | one-recipient test send with visible “test only” mode | preview plus provider-unavailable simulation without delivery | keep current no-send audit boundary; add test-only mode only with consent semantics accepted | external side effect and consent risk |
| UX-PROP-003 | Telegram Mini App | direct route contract is testable but interactive in-client rendering is not evidenced by current approved identity | re-enable approved QA identity | provide a separately approved interactive staging identity | use only an approved identity; do not create a production-like account during audit | external access, not application redesign |
| UX-PROP-004 | Restricted CRM role | current staging has no ordinary limited-permission QA membership | provision an approved staging role fixture | retain policy/code boundary evidence only | add a dedicated QA identity in a separate task if role UX becomes a release gate | authorization and staging access |
| UX-PROP-005 | Knowledge ingestion | CRUD is usable, but real provider ingestion cannot complete when AI provider is absent | show provider setup checklist and retry | keep explicit “connection not configured” state with retry | retain current truthful unavailable state; add checklist only if owner wants onboarding help | provider/configuration UX |
| UX-PROP-006 | Payment provider | local finance lifecycle is usable, but real LAVA charge/refund remains unexecuted | provide a sandbox mode | keep safe-boundary initiation and provider-unavailable state | do not add fake charge semantics; use an approved sandbox before testing | financial side effect and reconciliation risk |

Remaining counts: `WORTHWHILE = 6`, `COSMETIC = 0`. These proposals are not
silently promoted into redesign work.

## Owner decisions required

- Medical attachment replacement/remove/versioning policy: current truthful
  upload/download lifecycle is usable; implementing replacement must preserve
  protected history and requires the accepted retention policy.
- Whether broadcast product should expose a separately consented “test only”
  recipient mode once an eligible synthetic Telegram identity is provisioned.
- Whether a dedicated restricted-permission staging QA identity should be
  provisioned for a later browser pass.

## State, error and adversarial checks

The following representative sequences were executed against the real UI:

- open → change → cancel/back/browser back/forward → reload;
- save twice and double-click create/save/send/payment controls;
- create → validation failure → correction → submit → visible result → reload;
- modal open → invalid submit → correction → submit; close/reopen;
- filter → open → edit → save → return with context;
- stale IDs, inactive references, archived entities and deleted/stale URLs;
- two-tab edit/save for services, specialists, bookings and configuration;
- retry after network abort/500-like boundary and reload during pending;
- 0/1/many lists for services, specialists, locations, templates, rules,
  surveys, knowledge, tracker plans and analytics groups;
- malformed/empty/long values, spaces, emoji, Cyrillic/Latin mixed input,
  invalid email/URL/date/amount, negative/zero/decimal values and date
  combinations;
- HOME VISIT area/address/legal-consent failure, timezone change, stale slot,
  confirmation reload and return;
- private medical media access through the protected download boundary.

No user-triggered action in the audited scope ended as an unexplained silent
failure. The observed terminal states were success, validation, domain
conflict, human retryable failure, pending/unavailable or explicit external
boundary.

## Finance, AI, Knowledge and external side effects

The following were intentionally stopped before irreversible or external
effects:

- real LAVA charge/refund: `NOT RUN`, no safe sandbox transaction was available;
- real mass marketing broadcast: `NOT RUN`, no real audience was contacted;
- one-recipient broadcast test send: `NOT RUN`, the current staging selector exposed no
  synthetic recipient satisfying the required marketing consent and verified
  Telegram preconditions; draft, schedule, cancel and copy/re-run lifecycles were
  exercised without sending an audience message;
- real external AI provider execution: `NOT RUN`, provider/credential was not
  available for a bounded approved call;
- interactive Telegram bot/Mini App: `NOT RUN`, approved interactive QA
  identity was unavailable;
- restricted-role browser journey: `NOT RUN`, no existing staging QA identity
  with restricted membership exists.

For each external boundary, configuration, unavailable/error, retry or safe
pre-boundary UI was checked where the current flow exposed it. These
limitations are not presented as PASS and are not counted as product defects.

## PostgreSQL and cleanup evidence

- No `migrate:fresh`, `db:wipe`, `TRUNCATE` or staging reset was run.
- Deployment created and validated the normal PostgreSQL backup before release.
- Staging health and smoke confirmed PostgreSQL/pgvector, Redis, Horizon and
  scheduler operation.
- Finance duplicate-submit, tracker reorder locking/order persistence,
  inactive currency configuration and tenant-scoped writes were checked against
  PostgreSQL-backed staging behavior.
- Temporary HOME VISIT service, specialist assignment, working schedule,
  location-day and location state were restored through normal CRM lifecycle
  actions. No temporary service remained available in Portal.
- The three synthetic released booking history records used for the Journal
  overlap proof were cancelled through the normal booking lifecycle and remain
  visible as cancelled history; no raw delete was used because booking/audit
  history is not disposable data.
- Safe synthetic notification template and scenario rule were deactivated after
  their lifecycle checks. Synthetic staff companion messages remain as audit
  history; they were not deleted because message history is an immutable
  product record and contains no real client communication.
- No financial history, sent history, audit history or shared real data was
  deleted.
- Screenshots, browser states, logs and temporary runner files remain outside
  the repository. No credentials, cookies, OTPs, Telegram initData, private
  media or client email was committed or included in this report.

## Verification commands and outcomes

| Check | Outcome |
| --- | --- |
| Focused browser-remediation suite | PASS: 151 tests, 2,445 assertions |
| `tests/Feature/NotificationCatalogTest.php` | PASS: 7 tests, 50 assertions after aligning the stale test expectation with `Требуется настройка` |
| `composer lint` | PASS on the PR-scoped Pint files |
| PR-specific PHPStan | PASS: `MoveTrackerPlan.php` targeted analysis returned 0 errors; no added-line errors in the new Journal action code |
| Tracker reorder regression | PASS: `TrackerPlanCrmTest` 10 tests, 68 assertions; payment mapping and purchase fulfillment focused tests also passed |
| Journal feature regression | PASS: 2 tests, 58 assertions |
| Local frontend typecheck | PASS |
| Local frontend build | PASS |
| Exact staging deploy | PASS: exact SHA above, no migrations; first attempt was rolled back by a transient host UDP listener guard and a retry completed successfully |
| `scripts/staging-smoke.sh` | PASS on exact `5e09a296` |
| Chromium exact Journal runtime recheck | PASS: 1440/1024 compact/normal/wide, 3-way overlap, 390/360, prefill, Back and reload; no page/console errors |
| Chromium exact earlier runtime recheck | PASS for CRM dashboard/catalog and network failure; unchanged Portal evidence remains from the immediately preceding code-equivalent runtime because approved client identity was expired/inactive |
| Hosted CI | Run `43368817358`, exact head SHA `5e09a296b3d5670cae0770ef70f85b0027331bb6`; feature tests, Pint, frontend lint/typecheck/build PASS. Quality remains red only for repository-wide existing PHPStan baseline and Composer audit baseline (13 advisories / 7 packages); PR-specific added-line PHPStan is PASS |
| Repository-wide PHPStan | Existing baseline / unchanged; not repaired in PR #77 |
| Composer audit | Existing baseline / unchanged: 13 advisories affecting 7 packages; dependency updates intentionally out of scope |

## Final decision

No `BLOCKING`, `REAL BUG`, silent user action, reproducible lost state,
unexplained dead end, broken critical locally-testable client journey or tenant
isolation issue remains. The application candidate is ready for owner product
acceptance, while PR #77 deliberately remains Draft and unmerged. Owner
acceptance is a product decision after this evidence, not a request for the
owner to discover basic defects.
