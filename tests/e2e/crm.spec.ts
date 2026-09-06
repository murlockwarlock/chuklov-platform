import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';

type CrmFixture = {
    email: string;
    password: string;
    clientId: number;
    partnerId: number;
    serviceId: number;
    specialistId: number;
    clientName: string;
    partnerName: string;
    serviceName: string;
    specialistName: string;
    contentSectionId: number;
    contentSectionTitle: string;
    attachmentFilename: string;
    bookingStartsAt: string;
    financeBookingId: number | null;
    partnerProfileId: number | null;
};

function validPdfBuffer(): Buffer {
    return Buffer.from([
        '%PDF-1.4',
        '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]>>endobj',
        'xref',
        '0 4',
        '0000000000 65535 f',
        '0000000009 00000 n',
        '0000000052 00000 n',
        '0000000101 00000 n',
        'trailer<</Size 4/Root 1 0 R>>',
        'startxref',
        '150',
        '%%EOF',
    ].join('\n'));
}

function createCrmFixture(options: { financeFlow?: boolean; payoutFlow?: boolean } = {}): CrmFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-crm-'.$suffix.'@example.test';
        $password = 'password';
        $payoutFlow = getenv('PLAYWRIGHT_PAYOUT_FLOW') === '1';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::query()
            ->where('organization_id', $organization->getKey())
            ->update([
                'viewer_timezone' => null,
                'viewer_timezone_source' => 'organization',
                'viewer_timezone_suggestion' => null,
            ]);
        \\Illuminate\\Support\\Facades\\RateLimiter::clear('livewire-rate-limiter:'.sha1('Filament\\Auth\\Pages\\Login|authenticate|127.0.0.1'));
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([[
            'organization_id' => $organization->getKey(),
            'feature_key' => 'service_catalog',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([[
            'organization_id' => $organization->getKey(),
            'feature_key' => 'client_records',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'CRM Клиент '.$suffix,
        ]);
        $partner = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'CRM Партнёр '.$suffix,
        ]);
        $organizationTimezone = $organization->defaultTimezone();
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'CRM Специалист '.$suffix,
            'timezone' => $organizationTimezone,
        ]);
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => 'CRM Услуга '.$suffix,
            'formats' => ['office'],
            'price_minor' => getenv('PLAYWRIGHT_FINANCE_FLOW') === '1' || $payoutFlow ? 10000 : null,
            'price_currency' => getenv('PLAYWRIGHT_FINANCE_FLOW') === '1' || $payoutFlow ? 'USD' : null,
        ]);
        $contentSection = \\App\\Modules\\Content\\Domain\\Models\\ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'О нашей академии '.$suffix,
            'body' => 'Описание академии для проверки CRM.',
        ]);
        \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistServiceAssignment::factory()
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        foreach (range(1, 7) as $weekday) {
            \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistWorkingHour::factory()
                ->forSpecialist($specialist)
                ->create([
                    'weekday' => $weekday,
                    'start_time' => '00:00',
                    'end_time' => '23:59',
                ]);
        }
        $leadTimeMinutes = (int) (\\App\\Modules\\Organizations\\Domain\\Models\\OrganizationSetting::query()
            ->where('organization_id', $organization->getKey())
            ->where('setting_key', 'booking_lead_time_minutes')
            ->value('integer_value') ?? 0);
        $minimumBookingStart = \\Carbon\\CarbonImmutable::now($organizationTimezone)->addMinutes($leadTimeMinutes + 60);
        $bookingStartsAt = $minimumBookingStart->startOfDay()->addDay()->setTime(9, 0);
        $financeBooking = null;
        $partnerProfileId = null;
        if (getenv('PLAYWRIGHT_FINANCE_FLOW') === '1') {
            app(\\App\\Modules\\Finance\\Application\\SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'USD',
                'display_currency' => 'USD',
                'allowed_currencies' => ['USD'],
                'force_single_currency' => true,
                'rounding_mode' => 'half_up',
            ]);
            $financeBooking = app(\\App\\Modules\\Scheduling\\Application\\CreateBooking::class)->handle(
                actor: $admin,
                client: $client,
                specialist: $specialist,
                service: $service,
                startsAt: $bookingStartsAt,
                format: \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office,
                idempotencyKey: 'playwright-finance-'.$suffix,
            );
            $pastStartsAt = \\Carbon\\CarbonImmutable::now('UTC')->subHours(2);
            $financeBooking->forceFill([
                'starts_at' => $pastStartsAt,
                'ends_at' => $pastStartsAt->addHour(),
                'blocking_ends_at' => $pastStartsAt->addHour(),
                ])->save();
        }
        if ($payoutFlow) {
            app(\\App\\Modules\\Finance\\Application\\SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'USD',
                'display_currency' => 'USD',
                'allowed_currencies' => ['USD'],
                'force_single_currency' => true,
                'rounding_mode' => 'half_up',
            ]);
            app(\\App\\Modules\\Referrals\\Application\\SaveReferralRewardProgram::class)->handle(
                actor: $admin,
                enabled: true,
                qualificationRule: 'first_settled_payment',
                formula: 'fixed_amount',
                fixedAmount: '10.00',
                fixedCurrency: 'USD',
                percentage: null,
                effectiveAt: \\Carbon\\CarbonImmutable::now()->subMinute(),
            );
            $profile = app(\\App\\Modules\\Referrals\\Application\\ActivateReferralPartner::class)->handle($partner, 'crm', $admin);
            $partnerProfileId = $profile->getKey();
            $defaultLink = $profile->campaignLinks()->where('is_default', true)->firstOrFail();
            $referred = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
                'full_name' => 'CRM Referred '.$suffix,
                'email' => 'crm-referred-'.$suffix.'@example.test',
            ]);
            $relationship = new \\App\\Modules\\Referrals\\Domain\\Models\\ReferralRelationship;
            $relationship->forceFill([
                'organization_id' => $organization->getKey(),
                'referrer_client_id' => $partner->getKey(),
                'referred_client_id' => $referred->getKey(),
                'establishment_method' => 'automatic_referral_link',
                'referral_campaign_link_id' => $defaultLink->getKey(),
                'registered_at' => now(),
            ]);
            $relationship->save();
            $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
                ->forOrganization($organization)
                ->forClient($referred)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create();
            $amountMinor = 10000;
            $snapshot = [
                'source_amount_minor' => (string) $amountMinor,
                'source_currency' => 'USD',
                'target_amount_minor' => (string) $amountMinor,
                'target_currency' => 'USD',
                'rate' => '1',
                'rate_id' => null,
                'rate_version' => null,
                'effective_at' => null,
                'rounding_mode' => 'half_up',
                'source_scale' => 2,
                'target_scale' => 2,
            ];
            $obligation = new \\App\\Modules\\Finance\\Domain\\Models\\FinancialObligation;
            $obligation->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $referred->getKey(),
                'booking_id' => $booking->getKey(),
                'service_id' => $service->getKey(),
                'amount_minor' => $amountMinor,
                'currency' => 'USD',
                'base_amount_minor' => $amountMinor,
                'base_currency' => 'USD',
                'display_amount_minor' => $amountMinor,
                'display_currency' => 'USD',
                'payment_amount_minor' => $amountMinor,
                'payment_currency' => 'USD',
                'settlement_amount_minor' => $amountMinor,
                'settlement_currency' => 'USD',
                'price_snapshot' => ['amount_minor' => $amountMinor],
                'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
                'creation_key' => 'playwright-crm-payout-'.$suffix,
            ]);
            $obligation->save();
            $entry = new \\App\\Modules\\Finance\\Domain\\Models\\FinancialLedgerEntry;
            $entry->forceFill([
                'organization_id' => $organization->getKey(),
                'obligation_id' => $obligation->getKey(),
                'entry_type' => 'manual_payment',
                'source' => 'crm',
                'amount_minor' => $amountMinor,
                'currency' => 'USD',
                'payment_amount_minor' => $amountMinor,
                'payment_currency' => 'USD',
                'base_amount_minor' => $amountMinor,
                'base_currency' => 'USD',
                'display_amount_minor' => $amountMinor,
                'display_currency' => 'USD',
                'settlement_amount_minor' => $amountMinor,
                'settlement_currency' => 'USD',
                'payment_method' => 'cash',
                'conversion_snapshot' => null,
                'occurred_at' => now(),
                'idempotency_key' => 'playwright-crm-payout-entry-'.$suffix,
                'created_at' => now(),
            ]);
            $entry->save();
            app(\\App\\Modules\\Finance\\Application\\RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
            $event = \\App\\Modules\\Integration\\Domain\\Models\\IntegrationEvent::query()
                ->where('organization_id', $organization->getKey())
                ->where('aggregate_id', $obligation->getKey())
                ->firstOrFail();
            app(\\App\\Modules\\Referrals\\Application\\ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
            app(\\App\\Modules\\Referrals\\Application\\RequestReferralPayout::class)->handle($partner, '2.00', 'USD', 'playwright-crm-payout-reject-'.$suffix);
            app(\\App\\Modules\\Referrals\\Application\\RequestReferralPayout::class)->handle($partner, '3.00', 'USD', 'playwright-crm-payout-paid-'.$suffix);
        }
        config()->set('medical.keys.1', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
        app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
            $admin,
            $client,
            new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                specialistId: $specialist->getKey(),
                occurredAt: \\Illuminate\\Support\\Carbon::parse('2026-08-10 09:00:00', 'UTC'),
                pain: 'Предыдущая запись о боли',
                result: 'Предыдущий подтверждённый результат',
            ),
        );
        if (getenv('CLINICAL_AI_E2E_ENABLED') === '1') {
            $providerName = getenv('CLINICAL_AI_PROVIDER') ?: 'openai';
            $modelName = getenv('CLINICAL_AI_MODEL') ?: '';
            $apiKey = getenv('CLINICAL_AI_API_KEY') ?: '';
            if ($modelName === '' || $apiKey === '') {
                throw new \\RuntimeException('Clinical AI E2E requires CLINICAL_AI_MODEL and CLINICAL_AI_API_KEY.');
            }

            $credential = new \\App\\Modules\\Security\\Domain\\Models\\OrganizationCredential([
                'provider' => $providerName,
                'credential_name' => 'Playwright clinical AI '.$suffix,
                'revision_id' => (string) \\Illuminate\\Support\\Str::uuid(),
            ]);
            $credential->organization_id = $organization->getKey();
            $credential->credentials = ['api_key' => $apiKey];
            $credential->status = \\App\\Modules\\Security\\Domain\\Enums\\CredentialStatus::Active;
            $credential->save();

            $provider = \\App\\Modules\\AI\\Domain\\Models\\AiProviderConfiguration::query()->updateOrCreate(
                ['organization_id' => $organization->getKey(), 'provider_name' => $providerName],
                [
                    'display_name' => 'Playwright clinical AI '.$suffix,
                    'is_enabled' => true,
                    'health_status' => 'healthy',
                    'credential_id' => $credential->getKey(),
                    'tested_credential_revision' => $credential->revision_id,
                    'tested_configuration_digest' => \\App\\Modules\\AI\\Infrastructure\\Providers\\AiProviderExecutionConfiguration::digest($providerName),
                ],
            );
            $pricing = new \\App\\Modules\\AI\\Domain\\ValueObjects\\AiPricingSnapshot('USD', 15, 60);
            $capabilities = [
                'clinical_document_extraction',
                'posture_analysis',
                'clinical_synthesizer',
                'text_generation',
                'structured_output',
                'image_input',
                'document_input',
            ];
            $model = \\App\\Modules\\AI\\Domain\\Models\\AiModelConfiguration::query()->create([
                'organization_id' => $organization->getKey(),
                'provider_config_id' => $provider->getKey(),
                'model_name' => $modelName,
                'display_name' => 'Playwright clinical AI '.$suffix,
                'is_enabled' => true,
                'capabilities' => $capabilities,
                'pricing_snapshot' => $pricing->toArray(),
                'failover_priority' => 1,
            ]);
            $release = \\App\\Modules\\AI\\Domain\\Models\\AiModelRelease::query()->create([
                'organization_id' => $organization->getKey(),
                'model_config_id' => $model->getKey(),
                'release_number' => 1,
                'status' => 'active',
                'provider_name' => $providerName,
                'model_name' => $modelName,
                'capabilities' => $capabilities,
                'pricing_snapshot' => $pricing->toArray(),
                'activated_at' => now(),
            ]);
            $model->update(['active_release_id' => $release->getKey()]);

            $prompts = [
                ['capability' => \\App\\Modules\\AI\\Domain\\Enums\\AiCapability::ClinicalDocumentExtraction, 'key' => 'e2e-document-'.$suffix, 'template' => '{{document_text}}', 'system' => 'Return only valid JSON matching the configured medical-document schema. Preserve only facts present in the attachment. Use null or empty arrays for unknown information. Never invent a diagnosis, measurement, severity, or contraindication.'],
                ['capability' => \\App\\Modules\\AI\\Domain\\Enums\\AiCapability::PostureAnalysis, 'key' => 'e2e-posture-'.$suffix, 'template' => 'Analyze the three attached posture images as front, side, and back views.', 'system' => 'Return only valid JSON matching the configured posture schema. Describe visual observations and practitioner focus. Do not invent angles or measurements and do not make a diagnosis.'],
                ['capability' => \\App\\Modules\\AI\\Domain\\Enums\\AiCapability::ClinicalSynthesizer, 'key' => 'e2e-synthesis-'.$suffix, 'template' => '{{client_name}} {{anamnesis}} {{complaints_goals}} {{recent_sessions}} {{agent_one_result}} {{agent_two_result}} {{survey_results}}', 'system' => 'Return only valid JSON matching the configured clinical synthesis schema. Separate source facts, hypotheses, missing information, risks, and practitioner focus. Do not turn hypotheses into diagnoses.'],
            ];
            foreach ($prompts as $definition) {
                $prompt = \\App\\Modules\\AI\\Domain\\Models\\AiPrompt::query()->create([
                    'organization_id' => $organization->getKey(),
                    'key' => $definition['key'],
                    'name' => $definition['key'],
                    'capability' => $definition['capability'],
                ]);
                $version = \\App\\Modules\\AI\\Domain\\Models\\AiPromptVersion::query()->create([
                    'organization_id' => $organization->getKey(),
                    'prompt_id' => $prompt->getKey(),
                    'version' => 1,
                    'status' => 'active',
                    'system_prompt' => $definition['system'],
                    'user_prompt_template' => $definition['template'],
                    'output_schema' => \\App\\Modules\\AI\\Domain\\Registry\\AiCapabilityRegistry::get($definition['capability'])->defaultOutputSchema,
                    'context_policy' => $definition['capability'] === \\App\\Modules\\AI\\Domain\\Enums\\AiCapability::ClinicalSynthesizer
                        ? ['include_client_profile' => true, 'include_medical_summary' => true, 'include_recent_sessions_count' => 5]
                        : [],
                    'activated_at' => now(),
                ]);
                $prompt->update(['active_version_id' => $version->getKey()]);
            }
        }
        $attachmentFilename = 'Заключение '.$suffix.'.pdf';
        \\App\\Modules\\Attachments\\Domain\\Models\\MedicalAttachment::query()->create([
            'uuid' => (string) \\Illuminate\\Support\\Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $admin->getKey(),
            'attachment_type' => \\App\\Modules\\Attachments\\Domain\\Enums\\AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organization->getKey().'/'.\\Illuminate\\Support\\Str::uuid().'.pdf',
            'original_filename' => $attachmentFilename,
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256_checksum' => hash('sha256', $suffix),
        ]);
        echo json_encode([
            'email' => $email,
            'password' => $password,
            'clientId' => $client->getKey(),
            'partnerId' => $partner->getKey(),
            'serviceId' => $service->getKey(),
            'specialistId' => $specialist->getKey(),
            'clientName' => $client->full_name,
            'partnerName' => $partner->full_name,
            'serviceName' => $service->name,
            'specialistName' => $specialist->display_name,
            'contentSectionId' => $contentSection->getKey(),
            'contentSectionTitle' => $contentSection->title,
            'attachmentFilename' => $attachmentFilename,
            'bookingStartsAt' => $bookingStartsAt->format('Y-m-d').'T'.$bookingStartsAt->format('H:i'),
            'financeBookingId' => $financeBooking?->getKey(),
            'partnerProfileId' => $partnerProfileId,
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-${process.pid}`;
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
                PLAYWRIGHT_FINANCE_FLOW: options.financeFlow ? '1' : '0',
                PLAYWRIGHT_PAYOUT_FLOW: options.payoutFlow ? '1' : '0',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as CrmFixture;
}

async function login(page: Page, fixture: CrmFixture): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

async function assertNoHorizontalOverflow(page: Page): Promise<void> {
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

type RenderedGeometry = {
    clientWidth: number;
    scrollWidth: number;
    primary: Array<{ selector: string; left: number; right: number }>;
    boundaries: Array<{ selector: string; left: number; right: number }>;
};

async function assertRenderedViewportGeometry(
    page: Page,
    primarySelectors: string[],
    boundarySelectors: string[] = [],
): Promise<void> {
    const geometry = await page.evaluate(({ primarySelectors: selectors, boundarySelectors: boundaries }): RenderedGeometry => {
        const visible = (element: Element): boolean => {
            const htmlElement = element as HTMLElement;
            const styles = window.getComputedStyle(htmlElement);
            const bounds = htmlElement.getBoundingClientRect();

            return styles.display !== 'none'
                && styles.visibility !== 'hidden'
                && bounds.width > 0
                && bounds.height > 0;
        };
        const collect = (selectors: string[]): Array<{ selector: string; left: number; right: number }> => selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector))
            .filter(visible)
            .map((element) => {
                const bounds = (element as HTMLElement).getBoundingClientRect();

                return { selector, left: bounds.left, right: bounds.right };
            }));

        return {
            clientWidth: document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            primary: collect(selectors),
            boundaries: collect(boundaries),
        };
    }, { primarySelectors, boundarySelectors });

    expect(geometry.scrollWidth, `document scrollWidth at ${geometry.clientWidth}px`).toBeLessThanOrEqual(geometry.clientWidth);
    expect(geometry.primary, 'expected rendered primary controls').not.toHaveLength(0);

    for (const box of [...geometry.primary, ...geometry.boundaries]) {
        expect(box.left, `${box.selector} left edge at ${geometry.clientWidth}px`).toBeGreaterThanOrEqual(-1);
        expect(box.right, `${box.selector} right edge at ${geometry.clientWidth}px`).toBeLessThanOrEqual(geometry.clientWidth + 1);
    }
}

async function assertNoTableHorizontalOverflow(page: Page): Promise<void> {
    const overflowingTables = await page.evaluate(() => Array.from(document.querySelectorAll<HTMLElement>('.fi-ta-content'))
        .map((element) => ({
            clientWidth: element.clientWidth,
            scrollWidth: element.scrollWidth,
        }))
        .filter(({ clientWidth, scrollWidth }) => scrollWidth > clientWidth + 1));

    expect(overflowingTables, 'visible table containers must not require horizontal scrolling').toEqual([]);
}

async function searchTableFor(page: Page, query: string): Promise<void> {
    const searchInput = page.getByRole('searchbox', {
        name: 'Поиск',
        exact: true,
    });

    await expect(searchInput).toBeVisible();
    await searchInput.fill(query);
    await expect(page.getByRole('row').filter({ hasText: query }).first()).toBeVisible();
}

async function assertBusinessField(page: Page, label: string, value: string): Promise<void> {
    const main = page.getByRole('main');

    const term = main
        .locator('dt, [role="term"]')
        .filter({ hasText: label });
    const definition = main
        .locator('dd, [role="definition"]')
        .filter({ hasText: value });

    await expect(term).toHaveCount(1);
    await expect(term).toBeVisible();
    await expect(term).toHaveText(label);
    await expect(definition).toHaveCount(1);
    await expect(definition).toBeVisible();
    await expect(definition).toHaveText(value);
}

test.describe('specialist viewer timezone suggestion', () => {
    test.use({ timezoneId: 'Europe/Berlin' });

    test('specialist can reject a device timezone without being asked again', async ({ page }) => {
        const fixture = createCrmFixture();

        await login(page, fixture);
        await page.goto('/admin/scheduling-configuration');

        await expect(page.getByText('Время: Asia/Almaty', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Оставить Asia/Almaty', exact: true }).click();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();

        await page.reload();
        await expect(page.getByText('Время: Asia/Almaty', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();
    });

    test('specialist can accept the device timezone without changing booking instants', async ({ page }) => {
        const fixture = createCrmFixture();

        await login(page, fixture);
        await page.goto('/admin/scheduling-configuration');

        await expect(page.getByRole('button', { name: 'Использовать Europe/Berlin', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Использовать Europe/Berlin', exact: true }).click();
        await expect(page.getByText('Время: Europe/Berlin', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();
    });
});

test('staff can create a booking without technical inputs', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/bookings/create');
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();
    await expect(page.locator('input[name*="idempotency"], input[name*="timezone"], select[name*="meeting_link"]')).toHaveCount(0);

    await page.getByRole('combobox', { name: 'Клиент*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.clientName);
    await page.getByText(fixture.clientName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByText(fixture.serviceName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Специалист*', exact: true }).click();
    await page.getByText(fixture.specialistName, { exact: true }).click();
    const dateCommitResponse = page.waitForResponse((response) => response.url().includes('/livewire-')
        && response.request().method() === 'POST'
        && response.status() === 200);
    const dateInput = page.getByLabel('Дата и время');
    await dateInput.fill(fixture.bookingStartsAt);
    await dateInput.press('Tab');
    await dateCommitResponse;
    const formatCommitResponse = page.waitForResponse((response) => response.url().includes('/livewire-')
        && response.request().method() === 'POST'
        && response.status() === 200);
    await page.getByLabel('Формат визита').selectOption('office');
    await formatCommitResponse;
    await page.getByRole('button', { name: 'Создать', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/bookings\/\d+$/);
    await expect(page.locator('.fi-in-text-item').filter({ hasText: fixture.clientName }).first()).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await expect(page.getByText(/idempotency|event version|schedule timezone|client timezone/i)).toHaveCount(0);
});

test('staff sees business labels for client and content settings', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/clients');
    await expect(page.getByRole('heading', { name: 'База клиентов', exact: true })).toBeVisible();

    await searchTableFor(page, fixture.clientName);

    if ((page.viewportSize()?.width ?? 0) >= 1024) {
        const clientsTimezoneCell = page
            .getByRole('row')
            .filter({ hasText: fixture.clientName })
            .getByRole('cell', { name: 'Всемирное время', exact: true });
        await expect(clientsTimezoneCell).toBeVisible();
        await expect(clientsTimezoneCell).toHaveText('Всемирное время');
    }

    await page.goto(`/admin/clients/${fixture.clientId}`);
    await expect(page.getByRole('heading', { name: fixture.clientName, exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Клиент', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Клинический профиль', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Контакты и связь', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Настройки клиента', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Операционный статус', exact: true })).toHaveCount(0);
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await assertBusinessField(page, 'Часовой пояс', 'Всемирное время');

    await page.goto('/admin/content-sections');
    await expect(page.getByRole('heading', { name: 'Разделы контента' })).toBeVisible();

    await searchTableFor(page, fixture.contentSectionTitle);

    const contentSectionRow = page.getByRole('row').filter({ hasText: fixture.contentSectionTitle });
    await expect(contentSectionRow).toBeVisible();
    await expect(contentSectionRow.getByRole('cell', { name: 'Об академии', exact: true })).toBeVisible();
    await expect(contentSectionRow.getByRole('cell', { name: 'Русский', exact: true })).toBeVisible();

    await page.goto(`/admin/content-sections/${fixture.contentSectionId}`);
    await expect(page.getByRole('heading', { name: 'Раздел контента', exact: true })).toBeVisible();

    await assertBusinessField(page, 'Раздел', 'Об академии');
    await assertBusinessField(page, 'Язык', 'Русский');
    await assertBusinessField(page, 'Название', fixture.contentSectionTitle);
});

test('staff can activate a partner, create a campaign link, and assign a referrer from a client page', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.partnerId}`);
    await expect(page.getByRole('heading', { name: fixture.partnerName, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Сделать партнёром', exact: true }).click();
    await expect(page.getByText('Клиент стал партнёром', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Открыть партнёрский кабинет', exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Открыть партнёрский кабинет', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/referral-partner-profiles\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
    await expect(page.getByText('Личные рекомендации', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Создать ссылку', exact: true }).click();
    const linkDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Название' }).last();
    await expect(linkDialog).toBeVisible();
    await linkDialog.getByRole('textbox', { name: /^Название/ }).fill('Instagram — шапка профиля');
    await linkDialog.getByRole('combobox', { name: /^Канал/ }).click();
    await page.getByText('Instagram', { exact: true }).last().click();
    await linkDialog.getByRole('button', { name: 'Создать', exact: true }).click();
    await expect(page.getByText('Instagram — шапка профиля', { exact: true })).toBeVisible();
    const campaignLink = page.locator('.fi-in-repeatable-item').filter({ hasText: 'Instagram — шапка профиля' }).last();
    await expect(campaignLink.getByText('Instagram', { exact: true })).toBeVisible();
    await expect(campaignLink.getByRole('link')).toHaveAttribute('href', /https:\/\/t\.me\/[^?]+\?start=ref_[A-Za-z0-9_-]{16,128}/);
    await expect(campaignLink.getByText('0', { exact: true })).toHaveCount(3);
    await expect(campaignLink.getByText('—', { exact: true })).toHaveCount(1);

    await page.goto('/admin/referral-partner-profiles');
    await expect(page.getByRole('heading', { name: 'Партнёры', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.partnerName);
    const partnerRow = page.getByRole('row').filter({ hasText: fixture.partnerName });
    await expect(partnerRow.getByText('Активен', { exact: true })).toBeVisible();
    await partnerRow.getByRole('link', { name: 'Открыть', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();

    await page.goto(`/admin/clients/${fixture.clientId}`);
    await assertNoHorizontalOverflow(page);
    await page.getByRole('button', { name: 'Указать, кто пригласил', exact: true }).click();
    const assignmentDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Реферер' }).last();
    const referrerSelect = assignmentDialog.getByRole('combobox', { name: /^Реферер/ });
    await referrerSelect.click();
    await page.getByRole('textbox', { name: 'Search', exact: true }).last().fill(fixture.partnerName);
    await page.getByRole('option').filter({ hasText: fixture.partnerName }).last().click();
    await assignmentDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Реферер указан', { exact: true })).toBeVisible();

    await page.goto('/admin/referral-relationships');
    await expect(page.getByRole('heading', { name: 'Рекомендации', exact: true })).toBeVisible();
    await expect(page.getByText(fixture.partnerName, { exact: true })).toBeVisible();
    await expect(page.getByText(fixture.clientName, { exact: true })).toBeVisible();
});

test('CRM partner, recommendations, bookings, and AI run controls fit every acceptance viewport', async ({ page }) => {
    const fixture = createCrmFixture({ payoutFlow: true });

    if (fixture.partnerProfileId === null) {
        throw new Error('The partner fixture did not create a profile.');
    }

    await login(page, fixture);

    for (const width of [1440, 1280, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });

        await page.goto(`/admin/referral-partner-profiles/${fixture.partnerProfileId}`);
        await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
        await expect(page.locator('[data-testid^="partner-primary-"]')).toHaveCount(3);
        await assertRenderedViewportGeometry(page, [
            '[data-testid="partner-primary-open-client"]',
            '[data-testid="partner-primary-create-link"]',
            '[data-testid="partner-primary-credit-bonus"]',
        ]);

        await page.getByRole('button', { name: 'Ещё', exact: true }).click();
        const partnerMenu = page.locator('.fi-dropdown-panel:visible').last();
        await expect(partnerMenu).toBeVisible();
        await assertRenderedViewportGeometry(page, ['[data-testid="partner-primary-open-client"]'], ['.fi-dropdown-panel']);
        await page.keyboard.press('Escape');

        await page.goto('/admin/referral-relationships');
        await expect(page.getByRole('heading', { name: 'Рекомендации', exact: true })).toBeVisible();
        await assertRenderedViewportGeometry(page, ['input[type="search"]']);
        if (width >= 1024) {
            await assertNoTableHorizontalOverflow(page);
        }

        await page.goto('/admin/bookings/create');
        await expect(page.getByRole('heading', { name: /Создать запись/i })).toBeVisible();
        await assertRenderedViewportGeometry(page, ['button[type="submit"]']);

        await page.goto('/admin/ai-runs');
        await expect(page.getByRole('heading', { name: 'История запусков', exact: true })).toBeVisible();
        await assertRenderedViewportGeometry(page, ['input[type="search"]']);
    }
});

test('staff can complete a visit and record a manual payment through the normal CRM actions', async ({ page }) => {
    const fixture = createCrmFixture({ financeFlow: true });

    if (fixture.financeBookingId === null) {
        throw new Error('The finance fixture did not create a booking.');
    }

    await login(page, fixture);
    await page.goto(`/admin/bookings/${fixture.financeBookingId}`);
    await expect(page.getByRole('heading', { name: 'Запись на приём', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Действия', exact: true }).click();
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    const confirmationDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Подтвердить запись' }).last();
    await expect(confirmationDialog).toBeVisible();
    await confirmationDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Запись подтверждена', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Действия', exact: true }).click();
    await page.getByRole('button', { name: 'Завершить визит', exact: true }).click();
    const completionDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Завершить визит' }).last();
    await expect(completionDialog).toBeVisible();
    await completionDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Визит успешно завершён', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Действия', exact: true }).click();
    await page.getByRole('button', { name: 'Записать оплату', exact: true }).click();
    const paymentDialog = page.locator('.fi-modal-window:visible').last();
    await expect(paymentDialog).toBeVisible();
    await expect(paymentDialog.getByRole('textbox', { name: /^Сумма оплаты/ })).toHaveValue('100.00');
    await paymentDialog.getByRole('combobox', { name: /^Способ оплаты/ }).selectOption('cash');
    await paymentDialog.getByRole('button', { name: 'Записать оплату', exact: true }).click();
    await expect(page.getByText('Оплата записана. Остаток обновлён.', { exact: true })).toBeVisible();

    await page.goto('/admin/financial-obligations');
    await expect(page.getByRole('heading', { name: 'Оплаты', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.clientName);
    await expect(page.getByRole('row').filter({ hasText: fixture.clientName })).toContainText('Оплачено');
});

test('staff can reject, approve, and mark a partner payout as paid from CRM', async ({ page }) => {
    const fixture = createCrmFixture({ payoutFlow: true });

    await login(page, fixture);
    await page.goto('/admin/referral-payout-requests');
    await expect(page.getByRole('heading', { name: 'Запросы выплат', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.partnerName);

    const rejectedRow = page.getByRole('row').filter({ hasText: fixture.partnerName }).filter({ hasText: '2.00 USD' }).first();
    await expect(rejectedRow).toBeVisible();
    await rejectedRow.getByRole('button', { name: 'Отклонить', exact: true }).click();
    const rejectDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Причина отклонения' }).last();
    await expect(rejectDialog).toBeVisible();
    await rejectDialog.getByRole('textbox', { name: /^Причина отклонения/ }).fill('Проверка тестовой выплаты');
    await rejectDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(rejectedRow).toContainText('Отклонена');

    const approvedRow = page.getByRole('row').filter({ hasText: fixture.partnerName }).filter({ hasText: '3.00 USD' }).first();
    await expect(approvedRow).toBeVisible();
    await approvedRow.getByRole('button', { name: 'Одобрить', exact: true }).click();
    const approvalDialog = page.locator('.fi-modal-window:visible').last();
    await expect(approvalDialog).toBeVisible();
    await approvalDialog.getByRole('button', { name: 'Подтвердить', exact: true }).click();
    await expect(approvedRow).toContainText('Одобрена');

    await approvedRow.getByRole('button', { name: 'Отметить как выплаченную', exact: true }).click();
    const paidDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Платёжная пометка или ссылка' }).last();
    await expect(paidDialog).toBeVisible();
    await paidDialog.getByRole('textbox', { name: /^Платёжная пометка или ссылка/ }).fill('manual-test-payout');
    await paidDialog.getByRole('textbox', { name: /^Комментарий о ручной выплате/ }).fill('Тестовая ручная выплата');
    await paidDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(approvedRow).toContainText('Отмечена как выплаченная');
    await assertNoHorizontalOverflow(page);
});

test('staff can use the client cockpit for medical profile and private files', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/clients');
    await searchTableFor(page, fixture.clientName);
    await page.getByRole('row').filter({ hasText: fixture.clientName }).getByRole('link', { name: fixture.clientName, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}$`));

    for (const tabLabel of ['Клинический профиль', 'Сеансы', 'Записи на приём', 'Опросы', 'Файлы и МРТ']) {
        await expect(page.getByRole('tab', { name: tabLabel, exact: true })).toBeVisible();
    }

    await page.getByRole('button', { name: 'Изменить медицинский профиль', exact: true }).click();
    const medicalDialog = page.getByRole('dialog', { name: 'Изменить медицинский профиль' });
    const anamnesis = medicalDialog.getByRole('textbox', { name: 'Анамнез', exact: true });
    await expect(anamnesis).toBeVisible();
    await anamnesis.fill('Запись из клиентского рабочего места');
    await medicalDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Запись из клиентского рабочего места', { exact: true })).toBeVisible();

    await page.getByRole('tab', { name: 'Файлы и МРТ', exact: true }).click();
    await page.getByRole('button', { name: 'Загрузить файл', exact: true }).click();
    const uploadDialog = page.getByRole('dialog', { name: 'Загрузить файл' });
    const attachmentType = uploadDialog.getByLabel('Тип файла');
    await expect(attachmentType).toBeVisible();
    await attachmentType.selectOption('medical_report');
    await expect(attachmentType).toHaveValue('medical_report');
    const uploadControl = uploadDialog
        .getByRole('group', { name: 'Файл*', exact: true })
        .locator('label')
        .filter({ hasText: 'Перетащите файлы или выберите', visible: true });
    await expect(uploadControl).toHaveCount(1);
    await expect(uploadControl).toBeVisible();
    const [fileChooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        uploadControl.click(),
    ]);
    await fileChooser.setFiles({
        name: 'ux-a-report.pdf',
        mimeType: 'application/pdf',
        buffer: validPdfBuffer(),
    });
    const selectedFile = uploadDialog.getByRole('group', { name: 'ux-a-report.pdf', exact: true });
    await expect(selectedFile).toHaveCount(1);
    await expect(selectedFile).toBeVisible();
    const uploadSubmit = uploadDialog.getByRole('button', { name: 'Отправить', exact: true });
    await expect(uploadSubmit).toBeVisible();
    await expect(uploadSubmit).toBeEnabled();
    await expect(uploadDialog.getByText('Ошибка при загрузке', { exact: true })).toBeHidden();
    await uploadSubmit.click();
    await expect(uploadDialog).toBeHidden();

    const attachmentsTable = page.getByRole('table', { name: 'Файлы и МРТ' });
    const uploadedRow = attachmentsTable.getByRole('row').filter({ hasText: 'ux-a-report.pdf' });
    const uploadedFileCell = uploadedRow.getByRole('cell').filter({ hasText: 'ux-a-report.pdf' });
    await expect(attachmentsTable).toBeVisible();
    await expect(uploadedRow).toHaveCount(1);
    await expect(uploadedRow).toBeVisible();
    await expect(uploadedFileCell).toHaveCount(1);
    await expect(uploadedFileCell).toBeVisible();
    await expect(uploadedFileCell).toContainText('ux-a-report.pdf');
    const openAttachment = uploadedRow.getByRole('button', { name: 'Открыть', exact: true });
    await expect(openAttachment).toHaveCount(1);
    const [download, attachmentResponse] = await Promise.all([
        page.waitForEvent('download'),
        page.waitForResponse((response) => {
            const pathname = new URL(response.url()).pathname;

            return response.status() === 200 && /^\/admin\/attachments\/[^/]+$/.test(pathname);
        }),
        openAttachment.click(),
    ]);
    expect(download.suggestedFilename()).toBe('ux-a-report.pdf');
    expect(attachmentResponse.headers()['content-type']).toContain('application/pdf');
    expect(attachmentResponse.headers()['content-disposition']).toContain('ux-a-report.pdf');
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath === null) {
        throw new Error('The authorized attachment download did not produce a file.');
    }
    expect(readFileSync(downloadPath)).toEqual(validPdfBuffer());
});

test('staff can complete the clinical AI workflow from the client cockpit', async ({ page }) => {
    test.skip(
        process.env.CLINICAL_AI_E2E_ENABLED !== '1'
            || !process.env.CLINICAL_AI_MODEL
            || !process.env.CLINICAL_AI_API_KEY,
        'The real clinical AI browser workflow requires an explicitly configured synthetic-data provider.',
    );

    const fixture = createCrmFixture();
    const report = validPdfBuffer();
    const posture = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}`);
    await page.getByRole('tab', { name: 'Файлы и МРТ', exact: true }).click();

    const upload = async (name: string, mimeType: string, buffer: Buffer): Promise<void> => {
        await page.getByRole('button', { name: 'Загрузить файл', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Загрузить файл' });
        const type = dialog.getByLabel('Тип файла');
        await type.selectOption(mimeType === 'application/pdf' ? 'medical_report' : 'posture_photo');
        const uploadControl = dialog
            .getByRole('group', { name: 'Файл*', exact: true })
            .locator('label')
            .filter({ hasText: 'Перетащите файлы или выберите', visible: true });
        const [fileChooser] = await Promise.all([
            page.waitForEvent('filechooser'),
            uploadControl.click(),
        ]);
        await fileChooser.setFiles({ name, mimeType, buffer });
        await dialog.getByRole('button', { name: 'Отправить', exact: true }).click();
        await expect(dialog).toBeHidden();
    };

    await upload('clinical-e2e-report.pdf', 'application/pdf', report);
    await upload('clinical-e2e-front.png', 'image/png', posture);
    await upload('clinical-e2e-side.png', 'image/png', posture);
    await upload('clinical-e2e-back.png', 'image/png', posture);

    const attachments = page.getByRole('table', { name: 'Файлы и МРТ' });
    const reportRow = attachments.getByRole('row').filter({ hasText: 'clinical-e2e-report.pdf' });
    await reportRow.getByRole('button', { name: 'Запустить анализ', exact: true }).click();
    const reportConfirm = page.getByRole('button', { name: 'Подтвердить', exact: true });
    if (await reportConfirm.isVisible()) {
        await reportConfirm.click();
    }

    await page.getByRole('tab', { name: 'Клинический AI', exact: true }).click();
    const clinicalTable = page.getByRole('table', { name: 'Клинический AI' });
    const documentRow = clinicalTable.getByRole('row').filter({ hasText: 'Анализ документов' }).first();
    await expect(documentRow).toBeVisible({ timeout: 120_000 });
    await expect(documentRow).toContainText('Ожидает проверки');
    await documentRow.getByRole('button', { name: 'Проверено', exact: true }).click();
    const documentReviewConfirm = page.getByRole('button', { name: 'Подтвердить', exact: true });
    if (await documentReviewConfirm.isVisible()) {
        await documentReviewConfirm.click();
    }

    await page.getByRole('button', { name: 'Анализ осанки', exact: true }).click();
    for (const [label, filename] of [['Спереди', 'clinical-e2e-front.png'], ['Сбоку', 'clinical-e2e-side.png'], ['Сзади', 'clinical-e2e-back.png']] as const) {
        await page.getByRole('combobox', { name: label, exact: true }).click();
        await page.getByRole('option', { name: filename, exact: true }).click();
    }
    await page.getByRole('dialog').getByRole('button', { name: 'Отправить', exact: true }).click();

    const postureRow = clinicalTable.getByRole('row').filter({ hasText: 'Анализ осанки' }).first();
    await expect(postureRow).toBeVisible({ timeout: 120_000 });
    await expect(postureRow).toContainText('Ожидает проверки');
    await postureRow.getByRole('button', { name: 'Проверено', exact: true }).click();
    const postureReviewConfirm = page.getByRole('button', { name: 'Подтвердить', exact: true });
    if (await postureReviewConfirm.isVisible()) {
        await postureReviewConfirm.click();
    }

    await page.getByRole('button', { name: 'Клиническое резюме', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('9 систем/MSQ');
    await page.getByRole('dialog').getByRole('button', { name: 'Подтвердить', exact: true }).click();

    const synthesisRow = clinicalTable.getByRole('row').filter({ hasText: 'Клиническое резюме' }).first();
    await expect(synthesisRow).toBeVisible({ timeout: 120_000 });
    await expect(synthesisRow).toContainText('Ожидает проверки');
    await synthesisRow.getByRole('button', { name: 'Проверено', exact: true }).click();
    const synthesisReviewConfirm = page.getByRole('button', { name: 'Подтвердить', exact: true });
    if (await synthesisReviewConfirm.isVisible()) {
        await synthesisReviewConfirm.click();
    }

    await page.reload();
    await page.getByRole('tab', { name: 'Клинический AI', exact: true }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Клиническое резюме' }).first()).toContainText('Проверено');
    await expect(page.getByRole('row').filter({ hasText: 'Анализ документов' }).first()).toContainText('Проверено');
    await expect(page.getByRole('row').filter({ hasText: 'Анализ осанки' }).first()).toContainText('Проверено');
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('staff can create, view, and edit a client session from the CRM client flow', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/clients');
    await expect(page.getByRole('heading', { name: 'База клиентов', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.clientName);

    const clientRow = page.getByRole('row').filter({ hasText: fixture.clientName });
    await clientRow.getByRole('link', { name: fixture.clientName, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}$`));

    await page.goto(`/admin/clients/${fixture.clientId}/sessions`);
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    await expect(page.getByRole('heading', { name: 'Сеансы клиента' })).toBeVisible();

    await page.getByRole('link', { name: 'Новый сеанс', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create$`));

    await page.getByLabel('Дата и время сеанса').fill('2026-08-18T09:00');
    await page.getByLabel('Специалист').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByText(`${fixture.specialistName} (активен)`, { exact: true }).click();
    await page.getByLabel('Боль').fill('Первичная запись о боли');
    await page.getByRole('button', { name: 'Создать', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    const sessionRow = page.getByRole('row').filter({ hasText: '18.08.2026' });
    await expect(sessionRow).toBeVisible();
    await sessionRow.getByRole('link', { name: 'Открыть', exact: true }).click();
    await expect(page.getByText('Первичная запись о боли', { exact: true })).toBeVisible();
    await expect(page.getByText('Предыдущая запись о боли', { exact: true })).toBeVisible();
    await expect(page.getByText('Предыдущий подтверждённый результат', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Связать файл', exact: true }).click();
    await page.getByLabel('Файл клиента').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.attachmentFilename);
    await page.getByText(new RegExp(fixture.attachmentFilename), { exact: false }).click();
    await page.getByRole('button', { name: 'Связать', exact: true }).click();
    await expect(page.getByText(fixture.attachmentFilename, { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Редактировать', exact: true }).click();
    await page.getByLabel('Боль').fill('Обновлённая запись о боли');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    await page.getByRole('row').filter({ hasText: '18.08.2026' }).getByRole('link', { name: 'Открыть', exact: true }).click();
    await expect(page.getByText('Обновлённая запись о боли', { exact: true })).toBeVisible();
    await expect(page.getByText(fixture.attachmentFilename, { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'К истории сеансов', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
});

test('crm sidebar navigation operates via SPA mode without full page reloads', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);

    // Set a marker on the window object to detect full document reloads
    await page.evaluate(() => {
        (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker = 998877;
    });

    const openSidebarToggle = page
        .locator('.fi-topbar-open-sidebar-btn, [aria-controls="fi-main-sidebar"]')
        .first();

    const navigationSidebar = page.locator('#fi-main-sidebar');

    const navigateViaSidebar = async (linkName: string, expectedUrl: RegExp, expectedHeading: string): Promise<void> => {
        const targetLink = navigationSidebar.getByRole('link', { name: linkName, exact: true });

        if (await openSidebarToggle.isVisible()) {
            await openSidebarToggle.click();
        }

        await expect(targetLink).toBeVisible();
        await expect(targetLink).toBeEnabled();
        await targetLink.click();

        await expect(page).toHaveURL(expectedUrl);
        await expect(page.getByRole('heading', { name: expectedHeading })).toBeVisible();
    };

    // Navigate to Clients via sidebar link
    await navigateViaSidebar('База клиентов', /\/admin\/clients$/, 'База клиентов');

    // Verify window marker persists (no full page reload)
    const markerAfterClients = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterClients).toBe(998877);

    // Navigate to Content Sections via sidebar link
    await navigateViaSidebar('Разделы контента', /\/admin\/content-sections$/, 'Разделы контента');

    const markerAfterContent = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterContent).toBe(998877);

    // Navigate to Services via sidebar link
    await navigateViaSidebar('Каталог услуг', /\/admin\/services$/, 'Каталог услуг');

    const markerAfterServices = await page.evaluate(() => (window as Window & { __crm_spa_marker?: number }).__crm_spa_marker);
    expect(markerAfterServices).toBe(998877);
});
