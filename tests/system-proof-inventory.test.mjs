import assert from 'node:assert/strict';
import { test } from 'node:test';
import { diagnostics, renderMatrix, scanSource, sourceExpression } from '../scripts/system-proof-inventory.mjs';

test('new resource action or Portal route fails proof mapping until explicitly inventoried', () => {
    const existing = scanSource('routes/web.php', "Route::get('/portal/profile', ProfileController::class);");
    const matrix = renderMatrix(existing);
    const changed = [...existing, ...scanSource('app/Filament/Resources/Bookings/Actions/Actions.php', "Action::make('cancel')->visible(fn () => true);")];
    assert.equal(diagnostics(existing, matrix).length, 0);
    assert.match(diagnostics(changed, matrix).join('\n'), /UNMAPPED CRM-ACTION/);
    const routes = scanSource('routes/web.php', "Route::get('/portal/profile', ProfileController::class); Route::post('/portal/new', NewController::class);");
    assert.match(diagnostics(routes, matrix).join('\n'), /UNMAPPED HTTP/);
});

test('inventory discovers filters, unnamed built-in actions and inherited submits', () => {
    const rows = scanSource('app/Filament/Resources/Clients/Pages/CreateClient.php', "class CreateClient extends LocalizedCreateRecord {} Action::make('block'); EditAction::make(); SelectFilter::make('status');");
    assert.deepEqual(rows.map((row) => row.kind).sort(), ['CRM-ACTION', 'CRM-ACTION', 'CRM-ACTION', 'CRM-FORM', 'CRM-FORM', 'CRM-SCREEN']);
    assert.ok(rows.some((row) => row.action === 'CreateClient create'));
    assert.ok(rows.some((row) => row.action === 'CreateClient cancel'));
});

test('Vue controls include submits and bindings containing arrow operators', () => {
    const rows = scanSource('resources/js/Pages/Portal/Example.vue', '<template><form @submit.prevent="save"><button @click="() => cancel()">Cancel</button><Link :href="url">Open</Link></form></template>');
    assert.equal(rows.length, 3);
    assert.ok(rows.some((row) => row.action.includes('() => cancel()')));
    assert.ok(rows.some((row) => row.action.includes('@submit.prevent=save')));
});

test('line shifts retain identifiers and reviewed contracts while removed actions fail', () => {
    const path = 'app/Filament/Pages/Example.php';
    const first = scanSource(path, "Action::make('save');");
    const shifted = scanSource(path, "\n\nAction::make('save');");
    assert.equal(first[0].id, shifted[0].id);
    const reviewed = renderMatrix(first).replace('| NOT DERIVED |', '| Known precondition |');
    assert.match(renderMatrix(shifted, reviewed), /Known precondition/);
    assert.match(diagnostics([], reviewed).join('\n'), /STALE/);
});

test('missing execution evidence cannot become VERIFIED', () => {
    const rows = scanSource('routes/web.php', "Route::post('/portal/bookings', BookingController::class);");
    const unsupported = renderMatrix(rows).replace('| NOT VERIFIED |', '| VERIFIED |');
    assert.match(diagnostics(rows, unsupported).join('\n'), /UNSUPPORTED VERIFIED/);
});

test('fluent visibility and label extraction respects nested closures and quoted delimiters', () => {
    const code = "Action::make('confirm')->label(__('Confirm'))->visible(fn ($record) => in_array($record->status, ['requested', 'confirmed']))->action(function () { send('a,b'); }), Action::make('cancel');";
    assert.equal(sourceExpression(code, 0), code.slice(0, code.indexOf(', Action')));
    const rows = scanSource('app/Filament/Pages/Example.php', code);
    assert.equal(rows[0].label, "__('Confirm')");
    assert.equal(rows[0].visible, "fn ($record) => in_array($record->status, ['requested', 'confirmed'])");
    assert.match(renderMatrix(rows), /Source predicate \(not executed\)/);
    assert.equal(rows[1].visible, null);
});
