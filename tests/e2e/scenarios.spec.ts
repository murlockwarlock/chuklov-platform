import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test } from '@playwright/test';

type ScenarioFixture = {
    email: string;
    password: string;
    templateId: number;
    ruleId: number;
    actionId: number;
};

function createScenarioFixture(): ScenarioFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-scenario-'.$suffix.'@example.test';
        $password = 'password';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        \\Illuminate\\Support\\Facades\\RateLimiter::clear('livewire-rate-limiter:'.sha1('Filament\\Auth\\Pages\\Login|authenticate|127.0.0.1'));
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $template = app(\\App\\Modules\\Scenarios\\Application\\CreateNotificationTemplate::class)->handle($admin, [
            'template_key' => 'playwright-scenario-'.$suffix,
            'name' => 'Сообщение после визита',
            'locale' => 'ru',
            'purpose' => 'service',
            'is_active' => true,
            'subject' => null,
            'body' => 'Здравствуйте, {{ client.full_name }}.',
            'variables' => ['client.full_name'],
        ]);
        $version = $template->versions()->latest('version')->firstOrFail();
        $rule = app(\\App\\Modules\\Scenarios\\Application\\CreateScenarioRule::class)->handle($admin, [
            'rule_key' => 'playwright-scenario-'.$suffix,
            'name' => 'Напоминание после визита',
            'trigger_event' => 'booking.completed',
            'is_enabled' => true,
            'delay_value' => 24,
            'delay_unit' => 'hours',
            'max_occurrences' => 3,
            'repeat_interval_value' => 12,
            'repeat_interval_unit' => 'hours',
            'purpose' => 'service',
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $version->getKey(),
        ]);
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Playwright Scenario Client '.$suffix,
        ]);
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create();
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create();
        $start = \\Carbon\\CarbonImmutable::now()->subHours(3);
        $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => 'completed',
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'blocking_ends_at' => $start->addHour(),
            ]);
        $event = app(\\App\\Modules\\Scenarios\\Application\\RecordScenarioEvent::class)->bookingCompleted($booking, 'playwright-'.$suffix, \\Carbon\\CarbonImmutable::now());
        app(\\App\\Modules\\Scenarios\\Application\\MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = \\App\\Modules\\Scenarios\\Domain\\Models\\ScenarioAction::query()->where('scenario_rule_id', $rule->getKey())->sole();
        echo json_encode(['email' => $email, 'password' => $password, 'templateId' => $template->getKey(), 'ruleId' => $rule->getKey(), 'actionId' => $action->getKey()], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-scenario-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    let output: string;

    try {
        output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
            encoding: 'utf8',
            env: {
                ...process.env,
                XDG_CONFIG_HOME: psyshConfigDirectory,
                DB_CONNECTION: 'pgsql',
                DB_HOST: '127.0.0.1',
                DB_PORT: '5432',
                DB_DATABASE: process.env.DB_DATABASE ?? 'chuklov',
                DB_USERNAME: process.env.DB_USERNAME ?? 'chuklov',
                DB_PASSWORD: process.env.DB_PASSWORD ?? 'chuklov_local',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as ScenarioFixture;
}

test('staff can configure a scenario timing and inspect delivery history', async ({ page }) => {
    const fixture = createScenarioFixture();

    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);

    await page.goto('/admin/scenario-rules');
    await expect(page.getByRole('heading', { name: 'Авто-сообщения' })).toBeVisible();
    await page.goto(`/admin/scenario-rules/${fixture.ruleId}/edit`);
    await page.getByRole('spinbutton', { name: 'Через сколько*', exact: true }).fill('48');
    const save = page.getByRole('button', { name: 'Сохранить' });
    const saveResponse = page.waitForResponse((response) => response.url().includes('/livewire-')
        && response.request().method() === 'POST'
        && response.status() === 200);
    await save.click();
    await saveResponse;
    await page.goto(`/admin/scenario-rules/${fixture.ruleId}`);
    await expect(page.getByText('48 ч.', { exact: true })).toBeVisible();
    await expect(page.getByText('3 раза, каждые 12 ч.', { exact: true })).toBeVisible();

    await page.goto(`/admin/notification-templates/${fixture.templateId}/edit`);
    await expect(page.getByRole('combobox', { name: 'Добавить данные', exact: true })).toBeVisible();
    await page.locator('textarea').fill('Обновлённое сообщение для {{ client.full_name }}.');
    const templateSave = page.getByRole('button', { name: 'Сохранить' });
    const templateSaveResponse = page.waitForResponse((response) => response.url().includes('/livewire-')
        && response.request().method() === 'POST'
        && response.status() === 200);
    await templateSave.click();
    await templateSaveResponse;
    await page.goto(`/admin/notification-templates/${fixture.templateId}`);
    await expect(page.getByText('Текст сохранён')).toBeVisible();
    await expect(page.getByText('Обновлённое сообщение для {{ client.full_name }}.')).toBeVisible();

    await page.goto('/admin/scenario-actions');
    await expect(page.getByRole('heading', { name: 'История сообщений' })).toBeVisible();
    await page.goto(`/admin/scenario-actions/${fixture.actionId}`);
    await expect(page.getByText('История отправки')).toBeVisible();
    await expect(page.getByText('1 из 3', { exact: true })).toBeVisible();
    await expect(page.getByText('Telegram — Ожидает отправки')).toBeVisible();
});
