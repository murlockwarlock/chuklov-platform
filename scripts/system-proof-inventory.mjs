import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const columns = ['ID', 'Area', 'Actor', 'Surface', 'Page/state', 'Action/button', 'Preconditions', 'Visible when', 'Hidden/denied when', 'Input variants', 'Expected state change', 'Expected DB effect', 'Expected notification/message', 'Expected UI after action', 'Reverse/correction path', 'Retry/idempotency behavior', 'Concurrency behavior', 'Tenant/security behavior', 'Test type', 'Test name', 'Evidence/run', 'Status', 'Notes'];

function files(root, directory) {
    const absolute = resolve(root, directory);
    if (!existsSync(absolute)) return [];
    return readdirSync(absolute, { withFileTypes: true }).flatMap((entry) => {
        const path = `${directory}/${entry.name}`;
        return entry.isDirectory() ? files(root, path) : [path];
    }).sort();
}

function clean(value) {
    return value.replace(/\s+/g, ' ').trim();
}

export function sourceExpression(source, offset) {
    let depth = 0;
    let quote = null;
    for (let index = offset; index < source.length; index++) {
        const character = source[index];
        if (quote) {
            if (character === '\\') index++;
            else if (character === quote) quote = null;
            continue;
        }
        if (character === '"' || character === "'") quote = character;
        else if ('([{'.includes(character)) depth++;
        else if (')]}'.includes(character)) {
            if (depth === 0) return source.slice(offset, index);
            depth--;
        } else if (depth === 0 && ',;'.includes(character)) return source.slice(offset, index);
    }
    return source.slice(offset);
}

function fluentCall(expression, name) {
    const offset = expression.indexOf(`->${name}(`);
    if (offset < 0) return null;
    const argument = sourceExpression(expression, offset + name.length + 3);
    return clean(argument);
}

export function scanSource(path, source) {
    const rows = [];
    const occurrences = new Map();
    const add = (kind, action, offset, detail = '') => {
        const identity = `${path}:${kind}:${clean(action)}`;
        const ordinal = (occurrences.get(identity) ?? 0) + 1;
        occurrences.set(identity, ordinal);
        const key = `${identity}:${ordinal}`;
        const id = `${kind}-${createHash('sha256').update(key).digest('hex').slice(0, 12)}`;
        const line = source.slice(0, offset).split('\n').length;
        const expression = sourceExpression(source, offset);
        const visible = kind === 'CRM-ACTION' ? fluentCall(expression, 'visible') : null;
        const hidden = kind === 'CRM-ACTION' ? fluentCall(expression, 'hidden') : null;
        const label = kind === 'CRM-ACTION' ? fluentCall(expression, 'label') : null;
        rows.push({ id, kind, path, line, action: clean(action), detail: clean(detail), key, visible, hidden, label });
    };
    const matches = (pattern, kind, label) => {
        for (const match of source.matchAll(pattern)) {
            add(kind, label(match), match.index, match[0]);
        }
    };

    if (path.endsWith('.php')) {
        matches(/Route::(get|post|put|patch|delete|match|any)\s*\(\s*(['"])(.*?)\2/g, 'HTTP', (m) => `${m[1].toUpperCase()} ${m[3]}`);
        if (path.startsWith('app/Filament/') || path.startsWith('app/Providers/Filament/')) {
            matches(/\bclass\s+(\w+)\s+extends\s+(?:Localized)?Resource\b/g, 'CRM-RESOURCE', (m) => m[1]);
            matches(/\b([A-Za-z_][A-Za-z_0-9]*(?:Action|Filter)|Action|Filter)::make\s*\(([^)]*)\)/g, 'CRM-ACTION', (m) => `${m[1]}(${m[2]})`);
            matches(/(['"])([^'"]+)\1\s*=>\s*(\w+)::route\s*\(\s*(['"])(.*?)\4/g, 'CRM-PAGE', (m) => `${m[2]} ${m[3]} ${m[5]}`);
            matches(/function\s+(getCreateFormAction|getSaveFormAction|getSubmitFormAction|save|submit|sendReply|search)\s*\(/g, 'CRM-SUBMIT', (m) => m[1]);
            matches(/protected\s+static\s+[^;]*\$navigationLabel\s*=\s*(['"])(.*?)\1/g, 'CRM-NAV', (m) => m[2]);
            matches(/\bclass\s+(\w+)\s+extends\s+(Localized)?(CreateRecord|EditRecord|ViewRecord|ListRecords|ManageRecords|Page|Dashboard|RelationManager|ManageRelatedRecords)\b/g, 'CRM-SCREEN', (m) => `${m[1]} ${m[3]}`);
            for (const match of source.matchAll(/\bclass\s+(\w+)\s+extends\s+(?:Localized)?(CreateRecord|EditRecord)\b/g)) {
                add('CRM-FORM', `${match[1]} ${match[2] === 'CreateRecord' ? 'create' : 'save'}`, match.index, 'Inherited Filament form submit');
                add('CRM-FORM', `${match[1]} cancel`, match.index, 'Inherited Filament form cancel');
            }
        }
        matches(/->(onCommand|onCallbackQuery|onText|onMessage|onPhoto|onDocument)\s*\(\s*(?:['"]([^'"]*)['"])?/g, 'TELEGRAM', (m) => `${m[1]} ${m[2] ?? 'handler'}`);
        matches(/\b(InlineKeyboardButton|KeyboardButton)::make\s*\(/g, 'TELEGRAM-BUTTON', (m) => `${m[1]} ${source.slice(m.index, m.index + 250)}`);
        matches(/Artisan::command\s*\(\s*(['"])(.*?)\1/g, 'COMMAND', (m) => m[2]);
        matches(/Schedule::command\s*\(\s*(['"])(.*?)\1/g, 'SCHEDULER', (m) => m[2]);
        matches(/\$signature\s*=\s*(['"])(.*?)\1/gs, 'COMMAND', (m) => m[2]);
        matches(/\bclass\s+(\w+)[^{;]*\bimplements\s+[^\{]*ShouldQueue\b/g, 'JOB', (m) => m[1]);
        if (/Infrastructure\/(?:Telegram|Video|Lava|Providers|Mail)|ServiceProvider\.php$/.test(path)) {
            matches(/\bclass\s+(\w+)[^{;]*\bimplements\s+([^\{]+)/g, 'ADAPTER', (m) => `${m[1]} ${m[2]}`);
            matches(/\$this->app->(?:bind|singleton)\s*\(\s*([\w\\]+)::class\s*,\s*([\w\\]+)::class/g, 'WIRING', (m) => `${m[1]} -> ${m[2]}`);
        }
        if (/Domain\/Enums\/(?:.*Status|.*State|.*EventType|OrganizationRole|OrganizationPermission)\.php$/.test(path)) {
            matches(/\bcase\s+(\w+)\s*=\s*(['"])(.*?)\2/g, 'STATE', (m) => `${m[1]} = ${m[3]}`);
        }
        if (path.startsWith('app/Filament/Widgets/')) {
            matches(/Stat::make\s*\(\s*([^,\n]+)/g, 'METRIC', (m) => m[1]);
        }
        if (path === 'config/portal.php') {
            matches(/\['key'\s*=>\s*(['"])(.*?)\1\s*,\s*'label'\s*=>\s*(['"])(.*?)\3\]/g, 'TELEGRAM-MENU', (m) => `${m[2]} ${m[4]}`);
        }
        if (path.endsWith('/Application/ScenarioNotificationCatalog.php')) {
            for (const match of source.matchAll(/\['event'\s*=>\s*'([^']+)'/g)) {
                const definition = sourceExpression(source, match.index);
                add('NOTIFICATION', match[1], match.index, definition);
                rows.at(-1).catalog = Object.fromEntries(['label', 'recipients', 'template'].map((key) => [
                    key, definition.match(new RegExp(`'${key}'\\s*=>\\s*'([^']*)'`))?.[1] ?? null,
                ]));
            }
        }
    }
    if (path.endsWith('.vue') || path.endsWith('.blade.php')) {
        const templateStart = path.endsWith('.vue') ? source.indexOf('<template') : 0;
        const template = source.slice(Math.max(0, templateStart));
        for (const match of template.matchAll(/<(button|a|Link|form|x-filament::button|x-filament::icon-button)\b((?:[^>"']|"[^"]*"|'[^']*')*)>/g)) {
            const attributes = match[2];
            const bindings = [...attributes.matchAll(/(?:@(?:click|submit)(?:\.[\w]+)*|wire:(?:click|submit)(?:\.[\w]+)*|:?href|:?action|aria-label|type)\s*=\s*(["'])(.*?)\1/g)].map((m) => `${m[0].split('=')[0].trim()}=${m[2]}`);
            const after = template.slice(match.index + match[0].length);
            const text = clean(after.slice(0, after.indexOf(`</${match[1]}>`)).replace(/<[^>]*>/g, ' ')).slice(0, 150);
            add(path.endsWith('.vue') ? 'PORTAL-CONTROL' : 'CRM-CONTROL', `${match[1]} ${bindings.join('; ') || text}`, Math.max(0, templateStart) + match.index, `${bindings.join('; ')} ${text}`);
        }
    }
    return rows;
}

export function inventory(root) {
    const paths = ['app/Filament', 'app/Providers', 'app/Modules', 'app/Jobs', 'app/Console', 'routes', 'config', 'resources/js/Pages', 'resources/js/Components', 'resources/js/Layouts', 'resources/views/filament'].flatMap((directory) => files(root, directory));
    return paths.flatMap((path) => scanSource(path, readFileSync(resolve(root, path), 'utf8'))).sort((a, b) => a.path.localeCompare(b.path) || a.line - b.line || a.id.localeCompare(b.id));
}

function cell(value) {
    return clean(String(value)).replaceAll('|', '&#124;');
}

export function matrixRows(markdown) {
    return markdown.split('\n').filter((line) => /^\| (?:HTTP|CRM-|PORTAL-|TELEGRAM|COMMAND|SCHEDULER|JOB|ADAPTER|WIRING|STATE|METRIC|NOTIFICATION)/.test(line)).map((line) => line.split('|').slice(1, -1).map((value) => value.trim()));
}

export function diagnostics(rows, markdown) {
    const mapped = matrixRows(markdown);
    const errors = [];
    const ids = mapped.map((row) => row[0]);
    for (const row of rows) {
        if (!ids.includes(row.id)) errors.push(`UNMAPPED ${row.id} ${row.path}:${row.line} ${row.action}`);
    }
    for (const row of mapped) {
        if (row.length !== columns.length) errors.push(`INVALID COLUMNS ${row[0]}: ${row.length}`);
        if (!rows.some((entry) => entry.id === row[0])) errors.push(`STALE ${row[0]}`);
        if (ids.filter((id) => id === row[0]).length !== 1) errors.push(`DUPLICATE ${row[0]}`);
        if (row[21] === 'VERIFIED' && (row[19] === 'NOT MAPPED' || row[20] === 'NOT RUN')) errors.push(`UNSUPPORTED VERIFIED ${row[0]}`);
    }
    return [...new Set(errors)];
}

const reviewedContracts = {
    'CRM-ACTION-9bc6daeb5e61': {
        6: 'Owner/Administrator with ManageScheduling; Booking is Requested and Office or Online',
        7: 'CRM action predicate is true for the authorized actor and matching format/status',
        8: 'Staff, unauthorized organization, Home Visit, or non-Requested booking',
        9: 'Optional comment; maximum 500 characters; confirmation action can be retried',
        10: 'Requested → Confirmed; event_version increments',
        11: 'Locked booking update; BookingEvent, audit, financial obligation, scenario event and reminder schedule are created inside the application transaction',
        12: 'Scenario/reminder delivery is scheduled; external channel delivery remains separate evidence',
        13: 'Success notification appears and the confirm action disappears after refresh',
        14: 'Invalid status and duplicate confirmation are rejected without a second event or obligation',
        15: 'Application re-loads the row under lock; no idempotency duplicate on repeated transition',
        16: 'PostgreSQL row lock and event-version tests cover competing transitions; full CRM race evidence remains outstanding',
        17: 'Organization-scoped authorization is enforced server-side; direct foreign IDs are denied',
        18: 'Feature + PostgreSQL integration + browser E2E',
        19: 'SystemProofBookingStateMachineTest; MilestoneFourFinalLifecycleTest',
        20: 'Hosted CI run 37974428024 passed feature/PostgreSQL portions; real browser/staging confirmation on final SHA pending',
        22: 'Contract derived from BookingLifecycleActions.php and ConfirmBooking.php; final UI/provider evidence is still required',
    },
    'CRM-ACTION-bbabf2f474b1': {
        6: 'Owner/Administrator with ManageScheduling; PendingReview Home Visit',
        7: 'CRM action predicate is true only for pending Home Visit requests',
        8: 'Staff, unauthorized organization, non-Home format, or any other status',
        9: 'Optional comment; payment requirement FullPayment or TransportDeposit; confirmation required',
        10: 'PendingReview → Confirmed with selected slot and payment requirement',
        11: 'Locked booking/specialist/service; availability is rechecked; BookingEvent, scenario event, reminders and audit are recorded',
        12: 'Confirmation scenario/reminders are scheduled; provider delivery not yet proven',
        13: 'Success notification; approve action disappears and confirmed booking is shown',
        14: 'Reject pending request or cancel before approval; stale/occupied slot is rejected without mutation',
        15: 'Repeated approval is rejected by status guard; no duplicate event',
        16: 'PostgreSQL availability conflict handling is transactional; dedicated Home Visit race evidence pending',
        17: 'Organization-scoped actor and related records are checked server-side',
        18: 'Feature + PostgreSQL integration + browser E2E',
        19: 'MilestoneFourFinalLifecycleTest; Home Visit approval tests',
        20: 'Source/action tests exist; final browser and staging Home Visit evidence pending',
        22: 'Payment requirement is stored intent, not a payment charge; full Home Visit journey remains NOT VERIFIED',
    },
    'CRM-ACTION-a475f8a5b2c8': {
        6: 'Owner/Administrator with ManageScheduling; PendingReview Home Visit; non-empty reason ≤500',
        7: 'CRM action predicate is true only for pending Home Visit requests',
        8: 'Staff, unauthorized organization, missing/too-long reason, non-Home format, or other status',
        9: 'Required rejection reason, maximum 500 characters, confirmation modal',
        10: 'PendingReview → Rejected; event_version increments',
        11: 'Locked booking, BookingEvent and audit event recorded in one transaction',
        12: 'No approval/reminder delivery is created; delivery verification pending',
        13: 'Error is rendered as a human validation notification; success removes the action',
        14: 'Approval is the opposite Home Visit path; rejected request cannot be rejected twice',
        15: 'Status guard makes repeat rejection side-effect free',
        16: 'Row lock protects concurrent approval/rejection; dedicated race evidence pending',
        17: 'Organization-scoped authorization and direct-request denial are required',
        18: 'Feature + browser E2E',
        19: 'Home Visit rejection tests',
        20: 'Source contract inspected; final browser/staging evidence pending',
        22: 'No unsupported re-open transition is assumed',
    },
    'CRM-ACTION-6caa950f8ead': {
        6: 'Owner/Administrator with ManageScheduling; booking status is in blockingValues; current event_version',
        7: 'Action is visible for non-terminal blocking statuses to authorized CRM actor',
        8: 'Staff, foreign tenant, terminal status, stale version, invalid end, overlap or outside schedule',
        9: 'Viewer-timezone DateTimePicker; expected_event_version; blocking end after start',
        10: 'blocking_ends_at and event_version update; service end and price remain unchanged',
        11: 'Locked booking/specialist/service; BookingEvent BlockingIntervalUpdated and audit are recorded',
        12: 'No financial amount change; calendar/reminder effects require separate delivery evidence',
        13: 'Success notification and updated occupied interval after refresh',
        14: 'Stale/overlapping update is rejected with unchanged interval; booking remains editable while non-terminal',
        15: 'Expected version prevents stale retry from duplicating an event',
        16: 'PostgreSQL exclusion/locking tests cover competing interval updates; final CRM race evidence pending',
        17: 'Organization-scoped selectors and authorization are enforced server-side',
        18: 'Feature + PostgreSQL integration',
        19: 'MilestoneFourCrmBookingTest blocking interval cases; MilestoneFourConcurrencyTest',
        20: 'Hosted CI PostgreSQL concurrency portions passed on 6c7d7d6; final candidate rerun pending',
        22: 'Accepted rule: blocking interval is staff-controlled and does not change service duration or finance',
    },
    'CRM-ACTION-97bf7e75862d': {
        6: 'Owner/Administrator with ManageScheduling; booking is non-terminal; party_size 1–20',
        7: 'Visible for authorized CRM actor on every non-terminal booking',
        8: 'Staff, foreign tenant, terminal status, non-integer/out-of-range input, stale version',
        9: 'Integer party size from 1 to configured max (default 20); expected_event_version',
        10: 'party_size and event_version update; one Booking remains one financial obligation',
        11: 'Locked row, BookingEvent and audit record; no price multiplication',
        12: 'No payment or marketing message is implied by participant-count edit',
        13: 'Success notification and updated party count after refresh',
        14: 'Set a different valid count; terminal booking cannot be corrected through this action',
        15: 'Stale retry is rejected without a duplicate event',
        16: 'Expected-version/row-lock path is covered by booking concurrency tests; browser race pending',
        17: 'Organization authorization and server validation apply to direct requests',
        18: 'Feature + PostgreSQL integration + browser E2E',
        19: 'MilestoneFourCrmBookingTest group/party-size cases',
        20: 'Group forward journey observed in staging booking 112; final candidate exact-SHA evidence pending',
        22: 'Current accepted behavior is one Booking; dependent accounts are out of scope',
    },
    'CRM-ACTION-8a251f99d69e': {
        6: 'Owner/Administrator with ManageScheduling; non-terminal booking; Office/Home/Online form fields as applicable',
        7: 'Visible for authorized CRM actor on non-terminal booking; location fields follow visit format',
        8: 'Staff, foreign tenant, terminal status, unavailable/overlapping slot, stale version or cutoff without reason',
        9: 'Date, available time, location/area, optional reason, expected_event_version; CRM timezone display',
        10: 'Booking time/location and event_version update; custom occupied span and ID are preserved',
        11: 'Locked client/specialist/service/location; BookingEvent Rescheduled, audit, scenario/reminder and optional video lifecycle effects',
        12: 'Reschedule scenario/reminder is scheduled; actual channel delivery pending',
        13: 'Success notification, refreshed detail/list and updated availability',
        14: 'Client cancellation or another reschedule; stale conflict leaves prior booking intact',
        15: 'Retry uses current version and cannot duplicate reschedule event',
        16: 'PostgreSQL stale-version and same-slot race tests exist; final browser concurrency pending',
        17: 'Foreign working locations are excluded from selectors and server-side organization checks apply',
        18: 'Feature + PostgreSQL integration + browser E2E',
        19: 'MilestoneFourCrmBookingTest reschedule tests; MilestoneFourConcurrencyTest',
        20: 'Staging client booking 112 rescheduled and reflected on both Portal/CRM; final candidate evidence pending',
        22: 'Online/Home variant coverage and boundary dates remain open',
    },
    'CRM-ACTION-c3af35212c4d': {
        6: 'Owner/Administrator with ManageScheduling; booking is Confirmed and planned interval has ended',
        7: 'Action is visible for authorized CRM actor while status is Confirmed',
        8: 'Staff, foreign tenant, non-Confirmed status, or before ends_at; early direct request is rejected',
        9: 'Optional comment ≤500; confirmation modal',
        10: 'Confirmed → Completed; event_version increments; reminders are cancelled',
        11: 'Locked booking; BookingEvent, financial-obligation reconciliation, scenario event and audit recorded',
        12: 'Completion/retention scenario may be scheduled; delivery is not inferred from state change',
        13: 'Success notification, terminal detail and action removal after refresh',
        14: 'No terminal re-open path is implemented; correction is via supported financial/session workflows',
        15: 'Repeated completion is rejected without another event',
        16: 'Row lock protects duplicate completion; dedicated CRM race evidence pending',
        17: 'Server-side organization authorization is mandatory for direct action calls',
        18: 'Feature + browser E2E',
        19: 'SystemProofBookingStateMachineTest; MilestoneFourFinalLifecycleTest',
        20: 'Past-time backend transition tests passed on 6c7d7d6; exact final SHA/browser pending',
        22: 'UI visibility does not remove the server time guard; before-end click must remain a human error with no mutation',
    },
    'CRM-ACTION-f9544735e2f6': {
        6: 'Owner/Administrator with ManageScheduling; Requested or Confirmed booking and start time has passed',
        7: 'Action is visible for authorized CRM actor on Requested/Confirmed statuses',
        8: 'Staff, foreign tenant, terminal status, or before starts_at; early direct request is rejected',
        9: 'Optional comment ≤500; confirmation modal',
        10: 'Requested/Confirmed → NoShow; event_version increments; reminders cancelled',
        11: 'Locked booking, BookingEvent NoShow and audit recorded',
        12: 'No-show scenario/delivery requires separate notification evidence',
        13: 'Success notification, terminal state and action removal after refresh',
        14: 'No-show cannot be applied again or reversed through this action',
        15: 'Status/time guards make duplicate and early retry side-effect free',
        16: 'Row-lock path is present; dedicated CRM race evidence pending',
        17: 'Organization-scoped authorization and direct request checks apply',
        18: 'Feature + browser E2E',
        19: 'SystemProofBookingStateMachineTest; MilestoneFourFinalLifecycleTest',
        20: 'Past-time transition cases passed on 6c7d7d6; exact final SHA/browser pending',
        22: 'Requested visibility is current accepted UI; early execution must still fail server-side',
    },
    'CRM-ACTION-4465db6a6e45': {
        6: 'Owner/Administrator with ManageScheduling; Online, manual meeting-link mode; Requested or Confirmed',
        7: 'Visible only for authorized CRM actor on manual Online booking in Requested/Confirmed status',
        8: 'Staff, foreign tenant, Office/Home, automatic-link mode or terminal status',
        9: 'HTTP/HTTPS URL ≤2000 characters and optional comment ≤500',
        10: 'meeting_url and event_version update; prior URL is replaced with an event record',
        11: 'Locked booking; BookingEvent MeetingLinkUpdated and audit; confirmed bookings may materialize scenario event',
        12: 'Online confirmation/reminder link effects require actual delivery evidence',
        13: 'Success notification and link visibility in booking detail/client path',
        14: 'Replace URL while eligible; terminal booking cannot be edited through this action',
        15: 'Validation and row lock prevent invalid/duplicate side effects',
        16: 'Concurrent URL edits are serialized; dedicated browser race evidence pending',
        17: 'Organization authorization and URL validation are server-side',
        18: 'Feature + browser E2E',
        19: 'SetOnlineMeetingUrl tests; Booking lifecycle E2E',
        20: 'Source/application tests inspected; final Online browser/staging proof pending',
        22: 'Automatic Zoom lifecycle is a separate B2B/video adapter contract',
    },
    'CRM-ACTION-eea4825c3cd9': {
        6: 'Owner/Administrator with ManageScheduling; non-terminal booking; client/staff cutoff rules apply',
        7: 'Visible for authorized CRM actor while status is not terminal',
        8: 'Staff, foreign tenant, terminal status, or missing required cutoff reason',
        9: 'Optional reason ≤500; confirmation modal; pending Home Visit withdrawal exception',
        10: 'Requested/Confirmed/PendingReview Home Visit → Cancelled; cancelled_at and event_version update',
        11: 'Locked booking; BookingEvent Cancelled, audit, scenario/reminder cancellation and optional video cancellation lifecycle',
        12: 'Cancellation scenario/reminder delivery is scheduled/cancelled; actual channel evidence pending',
        13: 'Success notification, terminal detail and action removal after refresh',
        14: 'Client cancellation is the reverse actor path; terminal booking cannot be reopened',
        15: 'Status guard makes duplicate cancellation side-effect free',
        16: 'Row lock protects competing cancellation/reschedule; final browser race evidence pending',
        17: 'Organization-scoped authorization and cutoff rules are server-side; direct foreign IDs denied',
        18: 'Feature + PostgreSQL integration + browser E2E',
        19: 'Cancel booking lifecycle tests; SystemProofBookingStateMachineTest',
        20: 'Staging booking 112 was cancelled from Portal and CRM reflected terminal history; exact final SHA pending',
        22: 'Cutoff, Home Visit and Online cancellation variants remain open',
    },
};

export function renderMatrix(rows, previous = '') {
    const existing = new Map(matrixRows(previous).map((row) => [row[0], row]));
    const lines = rows.map((row) => {
        const current = existing.get(row.id);
        const crm = row.kind.startsWith('CRM') || row.kind === 'METRIC';
        const actor = crm ? 'CRM authorized member' : row.kind.startsWith('PORTAL') ? 'Client' : 'See source';
        const area = row.path.match(/Resources\/([^/]+)|Modules\/([^/]+)/)?.slice(1).find(Boolean) ?? row.path.split('/').slice(-2, -1)[0];
        const note = `IMPLEMENTED declaration; source ${row.path}:${row.line}; ${row.detail}; BLOCKER: action contract, outcome assertions and runtime evidence not yet reconciled`;
        const values = current ?? [row.id, area, actor, row.kind, row.path, row.action, 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT MAPPED', 'NOT MAPPED', 'NOT RUN', 'NOT VERIFIED', note];
        if (values[7] === 'NOT DERIVED' && row.visible) values[7] = `Source predicate (not executed): ${row.visible}`;
        if (values[8] === 'NOT DERIVED' && row.hidden) values[8] = `Source predicate (not executed): ${row.hidden}`;
        if (row.label && !values[22].includes('Source label:')) values[22] += `; Source label: ${row.label}`;
        if (row.catalog) {
            if (values[2] === 'See source') values[2] = row.catalog.recipients ?? 'NOT DERIVED';
            if (values[12] === 'NOT DERIVED') values[12] = `Catalog template: ${row.catalog.template}; catalog is not delivery evidence`;
            if (!values[22].includes('Catalog label:')) values[22] += `; Catalog label: ${row.catalog.label}; marketing/transactional policy and active tenant rule must be derived from execution, not catalog enabled flag`;
        }
        const reviewed = reviewedContracts[row.id];
        if (reviewed) {
            for (const [index, value] of Object.entries(reviewed)) values[Number(index)] = value;
        }
        return `| ${values.map(cell).join(' | ')} |`;
    });
    return `# Current system proof matrix\n\nStarting SHA: \`c201b41a14d91c57c1890e62737f9e2001a231f1\`. Branch: \`codex/full-system-proof\`.\n\nDeclaration inventory, not acceptance evidence. Each source control, HTTP route, Filament action/filter, inherited CRUD submit/cancel, page/navigation declaration, Telegram handler/button, queue job, command, scheduler entry and wired adapter has its own stable ID. STATE rows are reference values, not invented transitions; METRIC rows require independent expected-value proof. Dynamic declarations and inherited vendor controls still require runtime reconciliation. A source declaration alone is never VERIFIED.\n\nRegenerate preserving reviewed rows: \`node scripts/system-proof-inventory.mjs --update\`. Validate new/unmapped/stale declarations: \`node scripts/system-proof-inventory.mjs --check\`. Changing a control contract must also invalidate its previous evidence manually.\n\n${rows.length} declaration rows. Unknown contracts are explicitly NOT DERIVED; no mock or page render is recorded as complete proof. Every NOT VERIFIED row includes its remaining evidence blocker.\n\n| ${columns.join(' | ')} |\n| ${columns.map(() => '---').join(' | ')} |\n${lines.join('\n')}\n`;
}

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const rows = inventory(root);
    const path = resolve(root, 'docs/verification/system-proof-matrix.md');
    const previous = existsSync(path) ? readFileSync(path, 'utf8') : '';
    if (process.argv.includes('--update')) {
        writeFileSync(path, renderMatrix(rows, previous));
    } else if (process.argv.includes('--check')) {
        const errors = diagnostics(rows, previous);
        for (const error of errors) process.stderr.write(`${error}\n`);
        process.stdout.write(`${rows.length} inventory declarations; ${errors.length} mapping errors\n`);
        process.exitCode = errors.length ? 1 : 0;
    } else {
        process.stdout.write(`${JSON.stringify(rows, null, 2)}\n`);
    }
}
