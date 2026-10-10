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
    let depth = 0;
    let quote = null;
    for (let index = 0; index < expression.length; index++) {
        const character = expression[index];
        if (quote) {
            if (character === '\\') index++;
            else if (character === quote) quote = null;
            continue;
        }
        if (character === '"' || character === "'") quote = character;
        else if ('([{'.includes(character)) depth++;
        else if (')]}'.includes(character)) depth--;
        else if (depth === 0 && expression.startsWith(`->${name}(`, index)) {
            return clean(sourceExpression(expression, index + name.length + 3));
        }
    }
    return null;
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


export function renderMatrix(rows, previous = '') {
    const existing = new Map(matrixRows(previous).map((row) => [row[0], row]));
    const lines = rows.map((row) => {
        const current = existing.get(row.id);
        const crm = row.kind.startsWith('CRM') || row.kind === 'METRIC';
        const actor = crm ? 'CRM authorized member' : row.kind.startsWith('PORTAL') ? 'Client' : 'See source';
        const area = row.path.match(/Resources\/([^/]+)|Modules\/([^/]+)/)?.slice(1).find(Boolean) ?? row.path.split('/').slice(-2, -1)[0];
        const note = `IMPLEMENTED declaration; source ${row.path}:${row.line}; ${row.detail}; BLOCKER: action contract, outcome assertions and runtime evidence not yet reconciled`;
        const values = current ?? [row.id, area, actor, row.kind, row.path, row.action, 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT DERIVED', 'NOT MAPPED', 'NOT MAPPED', 'NOT RUN', 'NOT VERIFIED', note];
        if ((values[7] === 'NOT DERIVED' || values[7].startsWith('Source predicate (not executed):')) && row.visible) values[7] = `Source predicate (not executed): ${row.visible}`;
        if ((values[8] === 'NOT DERIVED' || values[8].startsWith('Source predicate (not executed):')) && row.hidden) values[8] = `Source predicate (not executed): ${row.hidden}`;
        if (row.label && !values[22].includes('Source label:')) values[22] += `; Source label: ${row.label}`;
        if (row.catalog) {
            if (values[2] === 'See source') values[2] = row.catalog.recipients ?? 'NOT DERIVED';
            if (values[12] === 'NOT DERIVED') values[12] = `Catalog template: ${row.catalog.template}; catalog is not delivery evidence`;
            if (!values[22].includes('Catalog label:')) values[22] += `; Catalog label: ${row.catalog.label}; marketing/transactional policy and active tenant rule must be derived from execution, not catalog enabled flag`;
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
