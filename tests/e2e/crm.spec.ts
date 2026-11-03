import { execFile, execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import { expect, test, type Locator, type Page } from '@playwright/test';

type CrmFixture = {
    email: string;
    password: string;
    clientId: number;
    partnerId: number;
    serviceId: number;
    specialistId: number;
    clientName: string;
    painCountry: string | null;
    problemTagName: string | null;
    partnerName: string;
    serviceName: string;
    specialistName: string;
    organizationTimezone: string;
    contentSectionId: number;
    contentSectionTitle: string;
    attachmentFilename: string;
    workingLocationName: string;
    bookingStartsAt: string;
    pastBookingDate: string;
    pastBookingStartsAtUtc: string;
    financeBookingId: number | null;
    partnerProfileId: number | null;
    referralStatsCampaignName: string | null;
    telegramUsername: string | null;
    giftProductName: string | null;
    aggregateServiceAName: string | null;
    aggregateServiceBName: string | null;
    aggregateServiceClientAName: string | null;
    aggregateServiceClientBName: string | null;
    aggregateServiceClientBothName: string | null;
    aggregateProblemTagName: string | null;
    aggregateBackTagName: string | null;
    aggregateProblemClientNeckName: string | null;
    aggregateProblemClientBackName: string | null;
    aggregateProblemClientBothName: string | null;
    aggregateMissingCountryClientName: string | null;
    countryCountClientNames: {
        kzA: string;
        kzB: string;
        ru: string;
        unknown: string;
    } | null;
    candidateLabel: string | null;
    staleCandidateLabel: string | null;
    tagCandidatePainName: string | null;
    tagCandidateUnrelatedClientName: string | null;
    medicalHistoryPainName: string | null;
};

async function startAnalyticsProviderStub(label: string): Promise<Server> {
    const server = createServer((request, response) => {
        if (request.method !== 'POST' || request.url !== '/api/chat') {
            response.writeHead(404).end();

            return;
        }

        request.resume();
        request.on('end', () => {
            response.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({
                model: 'e2e-analytics-stub',
                message: {
                    role: 'assistant',
                    content: JSON.stringify({ tags: [label] }),
                },
                done: true,
                done_reason: 'stop',
                prompt_eval_count: 1,
                eval_count: 1,
            }));
        });
    });

    await new Promise<void>((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => resolve());
    });

    return server;
}

function analyticsProviderStubPort(server: Server): number {
    const address = server.address();
    if (address === null || typeof address === 'string') {
        throw new Error('Analytics provider stub did not expose a TCP port.');
    }

    return address.port;
}

async function closeAnalyticsProviderStub(server: Server): Promise<void> {
    await new Promise<void>((resolve, reject) => {
        server.close((error) => error === undefined ? resolve() : reject(error));
    });
}

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

function validWavBuffer(): Buffer {
    const data = Buffer.alloc(1600);
    const header = Buffer.alloc(44);
    header.write('RIFF', 0, 'ascii');
    header.writeUInt32LE(36 + data.length, 4);
    header.write('WAVE', 8, 'ascii');
    header.write('fmt ', 12, 'ascii');
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(1, 22);
    header.writeUInt32LE(8000, 24);
    header.writeUInt32LE(16000, 28);
    header.writeUInt16LE(2, 32);
    header.writeUInt16LE(16, 34);
    header.write('data', 36, 'ascii');
    header.writeUInt32LE(data.length, 40);

    return Buffer.concat([header, data]);
}

function validPngBuffer(): Buffer {
    return Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
}

async function uploadSessionPhoto(page: Page, label: string, filename: string): Promise<void> {
    await page.getByRole('button', { name: label, exact: true }).click();
    const dialog = page.getByRole('dialog').last();
    const uploadControl = dialog
        .getByRole('group', { name: 'Фото*', exact: true })
        .locator('label')
        .filter({ hasText: 'Перетащите файлы или выберите', visible: true });
    const [fileChooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        uploadControl.click(),
    ]);
    await fileChooser.setFiles({
        name: filename,
        mimeType: 'image/png',
        buffer: validPngBuffer(),
    });
    const selectedPhoto = dialog.getByRole('group', { name: filename, exact: true });
    await expect(selectedPhoto).toBeVisible({ timeout: 15_000 });
    await expect(selectedPhoto.getByText('Загрузка завершена', { exact: true })).toBeVisible({ timeout: 15_000 });
    await dialog.getByRole('button', { name: 'Загрузить', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Фото загружено в галерею', { exact: true })).toBeVisible();
}

async function uploadCrmMedicalReport(page: Page, filename: string, expectedOutcome: 'uploaded' | 'limit_rejected' = 'uploaded'): Promise<void> {
    await page.getByRole('button', { name: 'Загрузить файл', exact: true }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Загрузить файл' });
    await dialog.getByLabel('Тип файла').selectOption('medical_report');
    const uploadControl = dialog
        .getByRole('group', { name: 'Файл*', exact: true })
        .locator('label')
        .filter({ hasText: 'Перетащите файлы или выберите', visible: true });
    const [fileChooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        uploadControl.click(),
    ]);
    await fileChooser.setFiles({
        name: filename,
        mimeType: 'application/pdf',
        buffer: validPdfBuffer(),
    });
    const selectedFile = dialog.getByRole('group', { name: filename, exact: true });
    await expect(selectedFile).toBeVisible({ timeout: 15_000 });
    await expect(selectedFile.getByText('Загрузка завершена', { exact: true })).toBeVisible({ timeout: 15_000 });
    await dialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(dialog).toBeHidden();

    if (expectedOutcome === 'limit_rejected') {
        await expect(page.getByText('Файл не загружен', { exact: true })).toBeVisible();
        await expect(page.getByText('В медицинском профиле уже пять файлов. Откройте список «Файлы и МРТ», чтобы проверить загруженные документы.', { exact: true })).toBeVisible();

        return;
    }

    await expect(page.getByText('Файл загружен', { exact: true })).toBeVisible();
}

async function removeEmptyRepeaterItems(page: Page, label: string): Promise<void> {
    const inputs = page.getByLabel(label, { exact: true });

    for (let index = (await inputs.count()) - 1; index >= 0; index -= 1) {
        const input = inputs.nth(index);
        if ((await input.inputValue()).trim() !== '') {
            continue;
        }

        await input.locator('xpath=ancestor::li[1]').getByRole('button', { name: 'Удалить', exact: true }).first().click();
        await page.waitForLoadState('networkidle');
    }
}

async function setLivewireRangeValue(page: Page, slider: Locator, value: number): Promise<void> {
    await slider.press('Home');
    await expect.poll(() => slider.getAttribute('aria-valuenow'), { timeout: 5_000 }).toBe('0.0');

    for (let attempt = 0; attempt < 20; attempt += 1) {
        const currentValue = Number(await slider.getAttribute('aria-valuenow'));
        if (currentValue === value) {
            break;
        }

        await slider.press(currentValue < value ? 'ArrowRight' : 'ArrowLeft');
        await page.waitForTimeout(350);
    }

    await expect(slider).toHaveAttribute('aria-valuenow', `${value}.0`);
}

function createCrmFixture(options: { financeFlow?: boolean; payoutFlow?: boolean; referralStatsFlow?: boolean; telegramLinkFlow?: boolean; messagesFlow?: boolean; giftSaleFlow?: boolean; includeMedicalAttachment?: boolean; taxonomyFlow?: boolean; clinicalCatalogFlow?: boolean; painScoreFlow?: boolean; problemAxisFlow?: boolean; aggregateDrilldownFlow?: boolean; countryCountFlow?: boolean; tagCandidateFlow?: boolean; savedGroupFlow?: boolean; medicalHistoryFlow?: boolean } = {}): CrmFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $email = 'playwright-crm-'.$suffix.'@example.test';
        $password = 'password';
        $payoutFlow = getenv('PLAYWRIGHT_PAYOUT_FLOW') === '1';
        $referralStatsFlow = getenv('PLAYWRIGHT_REFERRAL_STATS_FLOW') === '1';
        $telegramLinkFlow = getenv('PLAYWRIGHT_TELEGRAM_LINK_FLOW') === '1';
        $admin = \\App\\Models\\User::factory()->forOrganization($organization)->create(['email' => $email]);
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $candidateLabel = null;
        $staleCandidateLabel = null;
        $tagCandidatePainName = null;
        $tagCandidateUnrelatedClientName = null;
        \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::query()
            ->where('organization_id', $organization->getKey())
            ->update([
                'viewer_timezone' => null,
                'viewer_timezone_source' => 'organization',
                'viewer_timezone_suggestion' => null,
            ]);
        \\Illuminate\\Support\\Facades\\RateLimiter::clear('livewire-rate-limiter:'.sha1(\\App\\Filament\\Auth\\Login::class.'|authenticate|127.0.0.1'));
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
        if (${options.savedGroupFlow ? 'true' : 'false'}) {
            $seededGroupName = 'E2E persisted group '.$suffix;
            $group = \\App\\Modules\\Analytics\\Domain\\Models\\AnalyticsClientGroup::forceCreate([
                'organization_id' => $organization->getKey(),
                'name' => $seededGroupName,
                'description' => null,
                'group_type' => 'manual',
                'is_active' => true,
                'sort_order' => 0,
                'version' => 1,
                'rule_definition' => null,
                'created_by_user_id' => $admin->getKey(),
                'updated_by_user_id' => $admin->getKey(),
                'archived_at' => null,
            ]);
            \\App\\Modules\\Analytics\\Domain\\Models\\AnalyticsClientGroupVersion::forceCreate([
                'organization_id' => $organization->getKey(),
                'analytics_client_group_id' => $group->getKey(),
                'version' => 1,
                'name' => $seededGroupName,
                'description' => null,
                'group_type' => 'manual',
                'is_active' => true,
                'sort_order' => 0,
                'rule_definition' => null,
                'client_ids' => [],
                'created_by_user_id' => $admin->getKey(),
            ]);
        }
        if (${options.painScoreFlow ? 'true' : 'false'}) {
            $client->update(['country' => 'E2E-PAIN-'.$suffix]);
        }
        if (${options.taxonomyFlow ? 'true' : 'false'}) {
            \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProblemTag::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => 'E2E проблема '.$suffix,
                'group_name' => 'E2E группа '.$suffix,
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }
        if (${options.tagCandidateFlow ? 'true' : 'false'}) {
            $candidateLabel = 'E2E новый кандидат '.$suffix;
            $staleCandidateLabel = 'E2E устаревший кандидат '.$suffix;
            $tagCandidatePainName = 'E2E источник боли '.$suffix;
            $tagCandidateUnrelatedClientName = 'E2E unrelated analytics client '.$suffix;
            app(\\App\\Modules\\MedicalProfiles\\Application\\UpdateMedicalProfile::class)->handle(
                $admin,
                $client,
                new \\App\\Modules\\MedicalProfiles\\Application\\DTOs\\UpdateMedicalProfileCommand(
                    pains: [['name' => $tagCandidatePainName, 'score' => 7]],
                    painsProvided: true,
                ),
            );
            $sourcePain = \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProfilePain::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->sole();
            \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
                'full_name' => $tagCandidateUnrelatedClientName,
            ]);
            $setting = $organization->settings()->where('setting_key', \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsAiNewTagCandidatesEnabled->value)->first()
                ?? new \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationSetting;
            $setting->forceFill([
                'organization_id' => $organization->getKey(),
                'setting_key' => \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsAiNewTagCandidatesEnabled->value,
                'value_type' => \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingType::Boolean,
                'string_value' => null,
                'integer_value' => null,
                'boolean_value' => true,
            ])->save();
            foreach ([[$candidateLabel, 'complaints', 'needs_review'], [$staleCandidateLabel, 'feedback', 'succeeded']] as [$label, $taxonomy, $runStatus]) {
                $run = \\App\\Modules\\Analytics\\Domain\\Models\\AnalyticsClassificationRun::forceCreate([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'taxonomy' => $taxonomy,
                    'source_type' => $taxonomy === 'complaints' ? 'medical_profile_pain' : 'synthetic_e2e',
                    'source_id' => $taxonomy === 'complaints' ? $sourcePain->getKey() : $client->getKey(),
                    'source_digest' => hash('sha256', $label),
                    'status' => $runStatus === 'needs_review'
                        ? \\App\\Modules\\Analytics\\Domain\\Enums\\ClassificationRunStatus::NeedsReview
                        : \\App\\Modules\\Analytics\\Domain\\Enums\\ClassificationRunStatus::Succeeded,
                    'attempt_count' => 1,
                    'suggestions' => [['id' => null, 'label' => $label, 'candidate_type' => 'new']],
                ]);
                \\App\\Modules\\Analytics\\Domain\\Models\\AnalyticsTagCandidate::forceCreate([
                    'organization_id' => $organization->getKey(),
                    'classification_run_id' => $run->getKey(),
                    'client_id' => $client->getKey(),
                    'taxonomy' => $taxonomy,
                    'source_type' => $taxonomy === 'complaints' ? 'medical_profile_pain' : 'synthetic_e2e',
                    'source_id' => $taxonomy === 'complaints' ? $sourcePain->getKey() : $client->getKey(),
                    'candidate_type' => 'new',
                    'suggested_existing_id' => null,
                    'label' => $label,
                    'status' => \\App\\Modules\\Analytics\\Domain\\Enums\\AnalyticsTagCandidateStatus::NeedsReview,
                    'version' => 1,
                ]);
            }
        }
        if (${options.clinicalCatalogFlow ? 'true' : 'false'}) {
            $test = \\App\\Modules\\Sessions\\Domain\\Models\\MedicalTestDefinition::query()->create([
                'organization_id' => $organization->getKey(),
                'key' => 'e2e_test_'.$suffix,
                'name' => 'E2E тест '.$suffix,
                'section' => 'E2E раздел',
                'is_active' => true,
                'sort_order' => 0,
            ]);
            foreach ([['normal', 'Норма', false], ['abnormal', 'Отклонение', true]] as [$key, $label, $isAbnormal]) {
                \\App\\Modules\\Sessions\\Domain\\Models\\MedicalTestValue::query()->create([
                    'organization_id' => $organization->getKey(),
                    'medical_test_definition_id' => $test->getKey(),
                    'key' => $key,
                    'label' => $label,
                    'is_abnormal' => $isAbnormal,
                    'is_active' => true,
                    'sort_order' => $isAbnormal ? 1 : 0,
                ]);
            }
            \\App\\Modules\\Sessions\\Domain\\Models\\MedicalCauseDefinition::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => 'E2E причина '.$suffix,
                'group_name' => 'E2E причины',
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }
        $telegramUsername = null;
        if ($telegramLinkFlow) {
            $telegramUsername = 'crm_'.$suffix;
            \\App\\Modules\\Identity\\Domain\\Models\\ClientChannelIdentity::forceCreate([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'channel' => 'telegram',
                'external_id' => '900'.random_int(1000000, 9999999),
                'external_username' => $telegramUsername,
                'verification_status' => \\App\\Modules\\Identity\\Domain\\Enums\\ChannelIdentityStatus::Verified,
                'verified_at' => now(),
            ]);
        }
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
        $aggregateServiceAName = null;
        $aggregateServiceBName = null;
        $aggregateServiceClientAName = null;
        $aggregateServiceClientBName = null;
        $aggregateServiceClientBothName = null;
        $aggregateProblemTagName = null;
        $aggregateBackTagName = null;
        $aggregateProblemClientNeckName = null;
        $aggregateProblemClientBackName = null;
        $aggregateProblemClientBothName = null;
        $aggregateMissingCountryClientName = null;
        $countryCountClientNames = null;
        if (${options.aggregateDrilldownFlow ? 'true' : 'false'}) {
            $client->forceFill([
                'country' => 'E2E known country '.$suffix,
                'gender' => 'FEMALE',
                'birth_date' => '1985-06-10',
            ])->save();
            $partner->forceFill([
                'country' => 'E2E known partner country '.$suffix,
                'gender' => 'MALE',
                'birth_date' => '1985-06-10',
            ])->save();
            $aggregateServiceAName = 'E2E аналитика услуга A '.$suffix;
            $aggregateServiceBName = 'E2E аналитика услуга B '.$suffix;
            $aggregateServiceA = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create(['name' => $aggregateServiceAName]);
            $aggregateServiceB = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create(['name' => $aggregateServiceBName]);
            $aggregateServiceClientAName = 'E2E клиент услуга A '.$suffix;
            $aggregateServiceClientBName = 'E2E клиент услуга B '.$suffix;
            $aggregateServiceClientBothName = 'E2E клиент услуги A B '.$suffix;
            $aggregateServiceClientA = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['full_name' => $aggregateServiceClientAName, 'country' => 'E2E service country '.$suffix]);
            $aggregateServiceClientB = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['full_name' => $aggregateServiceClientBName, 'country' => 'E2E service country '.$suffix]);
            $aggregateServiceClientBoth = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['full_name' => $aggregateServiceClientBothName, 'country' => 'E2E service country '.$suffix]);
            $aggregateBookingAttributes = [
                'status' => 'completed',
                'starts_at' => '2026-10-10 12:00:00',
                'ends_at' => '2026-10-10 13:00:00',
                'blocking_ends_at' => '2026-10-10 13:00:00',
            ];
            \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()->forClient($aggregateServiceClientA)->forSpecialist($specialist)->forService($aggregateServiceA)->create($aggregateBookingAttributes);
            \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()->forClient($aggregateServiceClientB)->forSpecialist($specialist)->forService($aggregateServiceB)->create($aggregateBookingAttributes);
            \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()->forClient($aggregateServiceClientBoth)->forSpecialist($specialist)->forService($aggregateServiceA)->create($aggregateBookingAttributes);
            \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()->forClient($aggregateServiceClientBoth)->forSpecialist($specialist)->forService($aggregateServiceB)->create($aggregateBookingAttributes);
            $aggregateProblemTagName = 'E2E аналитика шея '.$suffix;
            $aggregateBackTagName = 'E2E аналитика спина '.$suffix;
            $aggregateProblemTag = \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProblemTag::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => $aggregateProblemTagName,
                'is_active' => true,
                'sort_order' => 20,
            ]);
            $aggregateBackTag = \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProblemTag::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => $aggregateBackTagName,
                'is_active' => true,
                'sort_order' => 21,
            ]);
            $aggregateProblemClientNeckName = 'E2E клиент только шея '.$suffix;
            $aggregateProblemClientBackName = 'E2E клиент только спина '.$suffix;
            $aggregateProblemClientBothName = 'E2E клиент шея и спина '.$suffix;
            $aggregateProblemClientNeck = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['full_name' => $aggregateProblemClientNeckName, 'country' => 'E2E problem country '.$suffix]);
            $aggregateProblemClientBack = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['full_name' => $aggregateProblemClientBackName, 'country' => 'E2E problem country '.$suffix]);
            $aggregateProblemClientBoth = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['full_name' => $aggregateProblemClientBothName, 'country' => 'E2E problem country '.$suffix]);
            $createAggregateSession = static function ($aggregateClient, array $pains) use ($admin, $specialist): void {
                app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
                    $admin,
                    $aggregateClient,
                    new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                        specialistId: $specialist->getKey(),
                        occurredAt: \\Illuminate\\Support\\Carbon::parse('2026-10-10 14:00:00', 'UTC'),
                        pains: $pains,
                    ),
                );
            };
            $createAggregateSession($aggregateProblemClientNeck, [[
                'name' => 'E2E шея', 'tag_ids' => [$aggregateProblemTag->getKey()], 'before_score' => 8, 'after_score' => 4,
            ]]);
            $createAggregateSession($aggregateProblemClientBack, [[
                'name' => 'E2E спина', 'tag_ids' => [$aggregateBackTag->getKey()], 'before_score' => 8, 'after_score' => 4,
            ]]);
            $createAggregateSession($aggregateProblemClientBoth, [
                ['name' => 'E2E шея', 'tag_ids' => [$aggregateProblemTag->getKey()], 'before_score' => 8, 'after_score' => 4],
                ['name' => 'E2E спина', 'tag_ids' => [$aggregateBackTag->getKey()], 'before_score' => 8, 'after_score' => 4],
            ]);
            $aggregateMissingCountryClientName = 'E2E клиент без страны '.$suffix;
            \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
                'full_name' => $aggregateMissingCountryClientName,
                'country' => null,
                'gender' => null,
                'birth_date' => '1985-06-10',
            ]);
        }
        if (${options.countryCountFlow ? 'true' : 'false'}) {
            $countryCountClientNames = [
                'kzA' => 'E2E страна Kazakhstan Alpha '.$suffix,
                'kzB' => 'E2E страна Kazakhstan Beta '.$suffix,
                'ru' => 'E2E страна Russia Client '.$suffix,
                'unknown' => 'E2E страна без Country '.$suffix,
            ];
            foreach ([
                [$countryCountClientNames['kzA'], 'KZ'],
                [$countryCountClientNames['kzB'], 'KZ'],
                [$countryCountClientNames['ru'], 'RU'],
                [$countryCountClientNames['unknown'], null],
            ] as [$name, $country]) {
                \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
                    'full_name' => $name,
                    'country' => $country,
                    'created_at' => '2026-10-10 10:00:00',
                    'updated_at' => '2026-10-10 10:00:00',
                ]);
            }
        }
        $giftSaleFlow = getenv('PLAYWRIGHT_GIFT_SALE_FLOW') === '1';
        $giftProductName = null;
        if ($giftSaleFlow) {
            app(\\App\\Modules\\Finance\\Application\\SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'USD',
                'display_currency' => 'USD',
                'allowed_currencies' => ['USD'],
                'force_single_currency' => true,
                'rounding_mode' => 'half_up',
            ]);
            $giftProductName = 'Подарочный сертификат E2E '.$suffix;
        }
        $workingLocation = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::factory()
            ->forOrganization($organization)
            ->create([
                'name' => 'CRM Локация '.$suffix,
                'address' => 'CRM адрес '.$suffix,
                'timezone' => $organizationTimezone,
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
        $pastBookingDate = \\Carbon\\CarbonImmutable::now($organizationTimezone)->subDay()->toDateString();
        $pastBookingStartsAtUtc = \\Carbon\\CarbonImmutable::createFromFormat('!Y-m-d H:i', $pastBookingDate.' 10:00', $organizationTimezone)
            ->utc()
            ->format('Y-m-d\\TH:i:s\\Z');
        if (getenv('PLAYWRIGHT_MESSAGES_FLOW') === '1') {
            config()->set('medical.keys.1', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
            $recordMessage = app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class);
            foreach ([[$client, 65], [$partner, 40]] as [$messageClient, $messageCount]) {
                $conversation = \\App\\Modules\\Conversations\\Domain\\Models\\Conversation::factory()
                    ->forOrganization($organization)
                    ->forClient($messageClient)
                    ->create([
                        'conversation_type' => \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationType::ClientCompanion,
                    ]);
                $historyNow = \\Carbon\\CarbonImmutable::now('UTC');
                for ($index = 1; $index <= $messageCount; $index++) {
                    $recordMessage->handle(
                        organizationId: $organization->getKey(),
                        client: $messageClient,
                        conversation: $conversation,
                        channel: 'portal',
                        direction: $index % 2 === 0
                            ? \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Outbound
                            : \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Inbound,
                        authorType: $index % 2 === 0
                            ? \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Staff
                            : \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Client,
                        body: 'CRM long history '.$messageClient->getKey().' message '.$index,
                        authorUserId: $index % 2 === 0 ? $admin->getKey() : null,
                        occurredAt: $historyNow->subMinutes($messageCount - $index),
                    );
                }
            }
        }
        $financeBooking = null;
        $partnerProfileId = null;
        $referralStatsCampaignName = null;
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
                workingLocationId: $workingLocation->getKey(),
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
            if ($referralStatsFlow) {
                $statsCampaign = app(\\App\\Modules\\Referrals\\Application\\CreateReferralCampaignLink::class)->handle(
                    client: $partner,
                    name: 'CRM Campaign '.$suffix,
                    channel: \\App\\Modules\\Referrals\\Domain\\Enums\\ReferralCampaignChannel::Instagram,
                    actor: $admin,
                );
                $referralStatsCampaignName = $statsCampaign->name;
                $recordVisit = app(\\App\\Modules\\Referrals\\Application\\RecordReferralLinkVisit::class);
                $recordVisit->handle($defaultLink->public_token, 'e2e-default-visit-one-'.$suffix);
                $recordVisit->handle($defaultLink->public_token, 'e2e-default-visit-two-'.$suffix);
                $recordVisit->handle($statsCampaign->public_token, 'e2e-campaign-visit-'.$suffix);
            }
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
            if ($referralStatsFlow) {
                $registeredClient = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
                    'full_name' => 'CRM Registered '.$suffix,
                    'email' => 'crm-registered-'.$suffix.'@example.test',
                ]);
                $registeredRelationship = new \\App\\Modules\\Referrals\\Domain\\Models\\ReferralRelationship;
                $registeredRelationship->forceFill([
                    'organization_id' => $organization->getKey(),
                    'referrer_client_id' => $partner->getKey(),
                    'referred_client_id' => $registeredClient->getKey(),
                    'establishment_method' => 'automatic_referral_link',
                    'referral_campaign_link_id' => $statsCampaign->getKey(),
                    'registered_at' => now(),
                ]);
                $registeredRelationship->save();
                foreach ([$referred, $registeredClient] as $attributedClient) {
                    \\App\\Modules\\Attribution\\Domain\\Models\\ClientAttribution::forceCreate([
                        'organization_id' => $organization->getKey(),
                        'client_id' => $attributedClient->getKey(),
                        'source_type' => 'referral',
                        'source' => null,
                        'referral_code' => $defaultLink->public_token,
                        'utm_source' => null,
                        'utm_medium' => null,
                        'utm_campaign' => null,
                        'utm_content' => null,
                        'utm_term' => null,
                        'capture_channel' => 'portal',
                        'capture_context' => 'test',
                        'captured_at' => $attributedClient->created_at,
                        'accepted_at' => $attributedClient->created_at,
                    ]);
                }
            }
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
        $medicalHistoryPainName = null;
        if (${options.medicalHistoryFlow ? 'true' : 'false'}) {
            $medicalHistoryPainName = 'HISTORY PAIN '.$suffix;
            foreach ([['2026-07-01 09:00:00', 6, 0], ['2026-07-02 09:00:00', 5, 4]] as [$occurredAt, $beforeScore, $afterScore]) {
                app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
                    $admin,
                    $client,
                    new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                        specialistId: $specialist->getKey(),
                        occurredAt: \\Illuminate\\Support\\Carbon::parse($occurredAt, 'UTC'),
                        pains: [[
                            'name' => $medicalHistoryPainName,
                            'before_score' => $beforeScore,
                            'after_score' => $afterScore,
                        ]],
                    ),
                );
            }
        }
        if (${options.painScoreFlow ? 'true' : 'false'}) {
            app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
                $admin,
                $client,
                new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                    specialistId: $specialist->getKey(),
                    occurredAt: \\Illuminate\\Support\\Carbon::parse('2026-10-10 09:00:00', 'UTC'),
                    pains: [[
                        'name' => 'E2E боль после сеанса',
                        'before_score' => null,
                        'after_score' => 2,
                    ]],
                ),
            );
        }
        $problemTagName = null;
        if (${options.problemAxisFlow ? 'true' : 'false'}) {
            app(\\App\\Modules\\Organizations\\Application\\SetOrganizationSetting::class)->handle(
                $admin,
                \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsSessionAxisSemantics,
                \\App\\Modules\\Analytics\\Application\\ClientSessionAxisCatalog::ProblemChronological,
            );
            $problemTagName = 'E2E шея '.$suffix;
            $problemTag = \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProblemTag::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => $problemTagName,
                'group_name' => 'E2E ось сеансов',
                'is_active' => true,
                'sort_order' => 0,
            ]);
            foreach (['2026-09-01 09:00:00', '2026-09-02 09:00:00', '2026-09-03 09:00:00'] as $occurredAt) {
                app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
                    $admin,
                    $client,
                    new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                        specialistId: $specialist->getKey(),
                        occurredAt: \\Illuminate\\Support\\Carbon::parse($occurredAt, 'UTC'),
                        pains: [],
                    ),
                );
            }
            foreach ([
                ['2026-10-01 09:00:00', 8],
                ['2026-10-02 09:00:00', 4],
                ['2026-10-03 09:00:00', 1],
            ] as [$occurredAt, $afterScore]) {
                app(\\App\\Modules\\Sessions\\Application\\CreateSession::class)->handle(
                    $admin,
                    $client,
                    new \\App\\Modules\\Sessions\\Application\\DTOs\\CreateSessionCommand(
                        specialistId: $specialist->getKey(),
                        occurredAt: \\Illuminate\\Support\\Carbon::parse($occurredAt, 'UTC'),
                        pains: [[
                            'name' => 'E2E боль шеи',
                            'tag_ids' => [$problemTag->getKey()],
                            'before_score' => 10,
                            'after_score' => $afterScore,
                        ]],
                    ),
                );
            }
        }
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
        if (${options.includeMedicalAttachment === false ? 'false' : 'true'}) {
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
        }
        echo json_encode([
            'email' => $email,
            'password' => $password,
            'clientId' => $client->getKey(),
            'partnerId' => $partner->getKey(),
            'serviceId' => $service->getKey(),
            'specialistId' => $specialist->getKey(),
            'clientName' => $client->full_name,
            'painCountry' => ${options.painScoreFlow ? '$client->country' : 'null'},
            'problemTagName' => $problemTagName,
            'partnerName' => $partner->full_name,
            'serviceName' => $service->name,
            'specialistName' => $specialist->display_name,
            'organizationTimezone' => $organizationTimezone,
            'contentSectionId' => $contentSection->getKey(),
            'contentSectionTitle' => $contentSection->title,
            'attachmentFilename' => $attachmentFilename,
            'workingLocationName' => $workingLocation->name,
            'bookingStartsAt' => $bookingStartsAt->format('Y-m-d').'T'.$bookingStartsAt->format('H:i'),
            'pastBookingDate' => $pastBookingDate,
            'pastBookingStartsAtUtc' => $pastBookingStartsAtUtc,
            'financeBookingId' => $financeBooking?->getKey(),
            'partnerProfileId' => $partnerProfileId,
            'referralStatsCampaignName' => $referralStatsCampaignName,
            'telegramUsername' => $telegramUsername,
            'giftProductName' => $giftProductName,
            'aggregateServiceAName' => $aggregateServiceAName,
            'aggregateServiceBName' => $aggregateServiceBName,
            'aggregateServiceClientAName' => $aggregateServiceClientAName,
            'aggregateServiceClientBName' => $aggregateServiceClientBName,
            'aggregateServiceClientBothName' => $aggregateServiceClientBothName,
            'aggregateProblemTagName' => $aggregateProblemTagName,
            'aggregateBackTagName' => $aggregateBackTagName,
            'aggregateProblemClientNeckName' => $aggregateProblemClientNeckName,
            'aggregateProblemClientBackName' => $aggregateProblemClientBackName,
            'aggregateProblemClientBothName' => $aggregateProblemClientBothName,
            'aggregateMissingCountryClientName' => $aggregateMissingCountryClientName,
            'countryCountClientNames' => $countryCountClientNames,
            'candidateLabel' => $candidateLabel,
            'staleCandidateLabel' => $staleCandidateLabel,
            'tagCandidatePainName' => $tagCandidatePainName,
            'tagCandidateUnrelatedClientName' => $tagCandidateUnrelatedClientName,
            'medicalHistoryPainName' => $medicalHistoryPainName,
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
                MEDICAL_ENCRYPTION_KEY_V1: process.env.MEDICAL_ENCRYPTION_KEY_V1
                    ?? 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
                PLAYWRIGHT_FINANCE_FLOW: options.financeFlow ? '1' : '0',
                PLAYWRIGHT_PAYOUT_FLOW: options.payoutFlow ? '1' : '0',
                PLAYWRIGHT_REFERRAL_STATS_FLOW: options.referralStatsFlow ? '1' : '0',
                PLAYWRIGHT_TELEGRAM_LINK_FLOW: options.telegramLinkFlow ? '1' : '0',
                PLAYWRIGHT_MESSAGES_FLOW: options.messagesFlow ? '1' : '0',
                PLAYWRIGHT_GIFT_SALE_FLOW: options.giftSaleFlow ? '1' : '0',
                PLAYWRIGHT_TAXONOMY_FLOW: options.taxonomyFlow ? '1' : '0',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as CrmFixture;
}

function configureAutomaticAnalyticsFixture(fixture: CrmFixture, providerPort: number, candidateLabel: string): {
    sourcePainName: string;
    disabledSourcePainName: string;
    candidateLabel: string;
    sourcePainId: number;
    disabledSourcePainId: number;
} {
    const suffix = fixture.clientName.replace(/^CRM Клиент /, '');
    const sourcePainName = `E2E Auto Source ${suffix}`;
    const disabledSourcePainName = `E2E Auto Disabled Source ${suffix}`;
    const encodedEmail = Buffer.from(fixture.email).toString('base64');
    const encodedSourcePainName = Buffer.from(sourcePainName).toString('base64');
    const encodedDisabledSourcePainName = Buffer.from(disabledSourcePainName).toString('base64');

    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $adminEmail = base64_decode('${encodedEmail}', true);
        $sourcePainName = base64_decode('${encodedSourcePainName}', true);
        $disabledSourcePainName = base64_decode('${encodedDisabledSourcePainName}', true);
        $admin = \\App\\Models\\User::query()->where('email', $adminEmail)->firstOrFail();
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${fixture.clientId})
            ->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProfilePain::withoutEvents(function () use ($admin, $client, $sourcePainName, $disabledSourcePainName): void {
            app(\\App\\Modules\\MedicalProfiles\\Application\\UpdateMedicalProfile::class)->handle(
                $admin,
                $client,
                new \\App\\Modules\\MedicalProfiles\\Application\\DTOs\\UpdateMedicalProfileCommand(
                    pains: [
                        ['name' => $sourcePainName, 'score' => 4],
                        ['name' => $disabledSourcePainName, 'score' => 5],
                    ],
                    painsProvided: true,
                ),
            );
        });
        $setBoolean = static function (string $key, bool $value) use ($organization): void {
            $setting = $organization->settings()->where('setting_key', $key)->first()
                ?? new \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationSetting;
            $setting->forceFill([
                'organization_id' => $organization->getKey(),
                'setting_key' => $key,
                'value_type' => \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingType::Boolean,
                'string_value' => null,
                'integer_value' => null,
                'boolean_value' => $value,
            ])->save();
        };
        $setBoolean(\\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsAiAutomaticTagSuggestionsEnabled->value, true);
        $setBoolean(\\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsAiNewTagCandidatesEnabled->value, true);
        $providerOptions = ['base_url' => 'http://127.0.0.1:${providerPort}'];
        $provider = \\App\\Modules\\AI\\Domain\\Models\\AiProviderConfiguration::query()->updateOrCreate(
            ['organization_id' => $organization->getKey(), 'provider_name' => 'ollama'],
            [
                'display_name' => 'E2E analytics provider',
                'is_enabled' => true,
                'health_status' => 'healthy',
                'credential_id' => null,
                'options' => $providerOptions,
                'tested_credential_revision' => null,
                'tested_configuration_digest' => \\App\\Modules\\AI\\Infrastructure\\Providers\\AiProviderExecutionConfiguration::digest('ollama', $providerOptions),
            ],
        );
        $pricing = (new \\App\\Modules\\AI\\Domain\\ValueObjects\\AiPricingSnapshot('USD', 15, 60))->toArray();
        $capabilities = [\\App\\Modules\\AI\\Domain\\Enums\\AiCapability::GeneralAssistant->value];
        $model = \\App\\Modules\\AI\\Domain\\Models\\AiModelConfiguration::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => 'e2e-analytics-stub',
        ]);
        $model->forceFill([
            'display_name' => 'E2E analytics provider',
            'is_enabled' => true,
            'lifecycle_status' => 'active',
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing,
            'failover_priority' => 1,
        ])->save();
        $release = \\App\\Modules\\AI\\Domain\\Models\\AiModelRelease::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'model_config_id' => $model->getKey(),
            'release_number' => 1,
        ]);
        $release->forceFill([
            'status' => 'active',
            'provider_name' => 'ollama',
            'model_name' => 'e2e-analytics-stub',
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing,
            'activated_at' => now(),
        ])->save();
        $model->forceFill(['active_release_id' => $release->getKey()])->save();
        $prompt = \\App\\Modules\\AI\\Domain\\Models\\AiPrompt::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'key' => 'analytics_classification',
        ]);
        $prompt->forceFill([
            'name' => 'E2E analytics classification',
            'capability' => \\App\\Modules\\AI\\Domain\\Enums\\AiCapability::GeneralAssistant,
        ])->save();
        $version = \\App\\Modules\\AI\\Domain\\Models\\AiPromptVersion::query()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
        ]);
        $version->forceFill([
            'status' => 'active',
            'system_prompt' => 'Return only JSON with the tags array.',
            'user_prompt_template' => '{{source_text}} {{allowed_tags}}',
            'variables_schema' => [],
            'parameter_config' => [],
            'context_policy' => [],
            'output_schema' => [
                'type' => 'object',
                'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'required' => ['tags'],
            ],
            'allowed_tools' => [],
            'activated_at' => now(),
        ])->save();
        $prompt->forceFill(['active_version_id' => $version->getKey()])->save();
        $sourcePains = \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProfilePain::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->orderByDesc('id')
            ->limit(2)
            ->get()
            ->sortBy('id')
            ->values();
        if ($sourcePains->count() !== 2) {
            throw new \\RuntimeException('Automatic analytics fixture did not create exactly two source pains.');
        }
        $sourcePain = $sourcePains->get(0);
        $disabledSourcePain = $sourcePains->get(1);
        echo json_encode([
            'sourcePainId' => $sourcePain->getKey(),
            'disabledSourcePainId' => $disabledSourcePain->getKey(),
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-auto-${process.pid}`;
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
                MEDICAL_ENCRYPTION_KEY_V1: process.env.MEDICAL_ENCRYPTION_KEY_V1
                    ?? 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }

    const state = JSON.parse(output.trim().split('\n').at(-1) ?? '') as {
        sourcePainId: number;
        disabledSourcePainId: number;
    };

    return { sourcePainName, disabledSourcePainName, candidateLabel, ...state };
}

function setAutomaticAnalyticsSuggestions(enabled: boolean): void {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $setting = $organization->settings()->where('setting_key', \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsAiAutomaticTagSuggestionsEnabled->value)->first()
            ?? new \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationSetting;
        $setting->forceFill([
            'organization_id' => $organization->getKey(),
            'setting_key' => \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingKey::AnalyticsAiAutomaticTagSuggestionsEnabled->value,
            'value_type' => \\App\\Modules\\Organizations\\Domain\\Enums\\OrganizationSettingType::Boolean,
            'string_value' => null,
            'integer_value' => null,
            'boolean_value' => ${enabled ? 'true' : 'false'},
        ])->save();
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-auto-setting-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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
            MEDICAL_ENCRYPTION_KEY_V1: process.env.MEDICAL_ENCRYPTION_KEY_V1
                ?? 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
}

function runAnalyticsClassificationWorker(): Promise<void> {
    return new Promise((resolve, reject) => {
        execFile('php', [
            'artisan',
            'queue:work',
            'redis',
            '--queue=analytics-classification',
            '--stop-when-empty',
            '--tries=1',
            '--timeout=45',
        ], {
            encoding: 'utf8',
            env: {
                ...process.env,
                APP_ENV: 'e2e',
                DB_CONNECTION: 'pgsql',
                DB_HOST: '127.0.0.1',
                DB_PORT: '5432',
                DB_DATABASE: process.env.DB_DATABASE ?? 'chuklov',
                DB_USERNAME: process.env.DB_USERNAME ?? 'chuklov',
                DB_PASSWORD: process.env.DB_PASSWORD ?? 'chuklov_local',
                MEDICAL_ENCRYPTION_KEY_V1: process.env.MEDICAL_ENCRYPTION_KEY_V1
                    ?? 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        }, (error, stdout, stderr) => {
            if (error !== null) {
                reject(new Error(`${stderr}${stdout}`));

                return;
            }

            resolve();
        });
    });
}

function readAutomaticAnalyticsState(sourceId: number, label: string): {
    runStatus: string | null;
    candidateStatus: string | null;
    activeTagCount: number;
    runErrorCode: string | null;
    runErrorMessage: string | null;
    sourceExists: boolean;
    currentPains: Array<{ id: number; name: string }>;
    aiRunStatus: string | null;
    aiRunErrorCategory: string | null;
    aiRunErrorMessage: string | null;
    attemptStatus: string | null;
    attemptErrorCategory: string | null;
    attemptErrorMessage: string | null;
} {
    const encodedLabel = Buffer.from(label).toString('base64');
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $label = base64_decode('${encodedLabel}', true);
        $run = \\App\\Modules\\Analytics\\Domain\\Models\\AnalyticsClassificationRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('source_type', 'medical_profile_pain')
            ->where('source_id', ${sourceId})
            ->latest('id')
            ->first();
        $candidate = $run === null ? null : \\App\\Modules\\Analytics\\Domain\\Models\\AnalyticsTagCandidate::query()
            ->where('organization_id', $organization->getKey())
            ->where('classification_run_id', $run->getKey())
            ->latest('id')
            ->first();
        $source = \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProfilePain::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${sourceId})
            ->with('profile')
            ->first();
        $profile = $source?->profile;
        $currentPains = $profile === null ? [] : app(\\App\\Modules\\MedicalProfiles\\Application\\MedicalProfilePainReader::class)
            ->forProfile($profile);
        $aiRun = $source?->client_id === null
            ? null
            : \\App\\Modules\\AI\\Domain\\Models\\AiRun::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $source->client_id)
                ->where('workflow_key', 'analytics_classification')
                ->latest('id')
                ->first();
        $attempt = $aiRun === null
            ? null
            : \\App\\Modules\\AI\\Domain\\Models\\AiRunAttempt::query()->where('ai_run_id', $aiRun->getKey())->latest('id')->first();
        echo json_encode([
            'runStatus' => $run?->status?->value,
            'candidateStatus' => $candidate?->status?->value,
            'runErrorCode' => $run?->error_code,
            'runErrorMessage' => $run?->error_message_sanitized,
            'sourceExists' => $source !== null,
            'currentPains' => array_map(static fn ($pain): array => ['id' => $pain->id, 'name' => $pain->name], $currentPains),
            'aiRunStatus' => $aiRun?->status?->value,
            'aiRunErrorCategory' => $aiRun?->error_category?->value,
            'aiRunErrorMessage' => $aiRun?->error_message_sanitized,
            'attemptStatus' => $attempt?->status,
            'attemptErrorCategory' => $attempt?->error_category?->value,
            'attemptErrorMessage' => $attempt?->error_message_sanitized,
            'activeTagCount' => \\App\\Modules\\MedicalProfiles\\Domain\\Models\\MedicalProblemTag::query()
                ->where('organization_id', $organization->getKey())
                ->where('name', $label)
                ->where('is_active', true)
                ->count(),
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-auto-state-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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
            MEDICAL_ENCRYPTION_KEY_V1: process.env.MEDICAL_ENCRYPTION_KEY_V1
                ?? 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as {
        runStatus: string | null;
        candidateStatus: string | null;
        activeTagCount: number;
        runErrorCode: string | null;
        runErrorMessage: string | null;
        sourceExists: boolean;
        currentPains: Array<{ id: number; name: string }>;
        aiRunStatus: string | null;
        aiRunErrorCategory: string | null;
        aiRunErrorMessage: string | null;
        attemptStatus: string | null;
        attemptErrorCategory: string | null;
        attemptErrorMessage: string | null;
    };
}

function readCrmBookingSnapshot(bookingId: number): { startsAtUtc: string; scheduleTimezone: string } {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${bookingId})
            ->firstOrFail();
        echo json_encode([
            'startsAtUtc' => $booking->startsAtUtc()->format('Y-m-d\\TH:i:s\\Z'),
            'scheduleTimezone' => $booking->schedule_timezone,
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-booking-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as { startsAtUtc: string; scheduleTimezone: string };
}

function readCrmServiceSnapshot(name: string): { id: number; bufferOfficeMinutes: number; bufferHomeMinutes: number; bufferOnlineMinutes: number } {
    const encodedName = Buffer.from(name).toString('base64');
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $name = base64_decode('${encodedName}', true);
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', $name)
            ->firstOrFail();
        echo json_encode([
            'id' => $service->getKey(),
            'bufferOfficeMinutes' => (int) $service->buffer_office_minutes,
            'bufferHomeMinutes' => (int) $service->buffer_home_minutes,
            'bufferOnlineMinutes' => (int) $service->buffer_online_minutes,
        ], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-crm-service-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as {
        id: number;
        bufferOfficeMinutes: number;
        bufferHomeMinutes: number;
        bufferOnlineMinutes: number;
    };
}

function createJournalSecondSpecialist(fixture: CrmFixture): { id: number; name: string } {
    const name = `CRM Журнал второй ${Date.now()}`;
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${fixture.serviceId})
            ->firstOrFail();
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()
            ->forOrganization($organization)
            ->create([
                'display_name' => '${name}',
                'timezone' => $organization->defaultTimezone(),
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
        echo json_encode(['id' => $specialist->getKey(), 'name' => $specialist->display_name], JSON_THROW_ON_ERROR);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-journal-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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

    return JSON.parse(output.trim().split('\n').at(-1) ?? '') as { id: number; name: string };
}

function createJournalBooking(fixture: CrmFixture, status: 'cancelled' | 'confirmed'): void {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${fixture.clientId})
            ->firstOrFail();
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${fixture.specialistId})
            ->firstOrFail();
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${fixture.serviceId})
            ->firstOrFail();
        $startsAt = \\Carbon\\CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', '${fixture.bookingStartsAt}', $specialist->timezone)->utc();
        $duration = max(30, (int) ($service->duration_minutes ?? 60));
        $buffer = (int) ($service->buffer_minutes ?? 0);
        \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'visit_format' => \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office->value,
                'status' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingStatus::from('${status}'),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($duration),
                'blocking_ends_at' => $startsAt->addMinutes($duration + $buffer),
                'schedule_timezone' => $specialist->timezone,
                'cancelled_at' => '${status}' === 'cancelled' ? now() : null,
            ]);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-journal-booking-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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
}

function appendCrmMessage(clientId: number, body: string): void {
    const encodedBody = Buffer.from(body).toString('base64');
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        config()->set('medical.keys.1', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey(${clientId})
            ->firstOrFail();
        $conversation = \\App\\Modules\\Conversations\\Domain\\Models\\Conversation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('conversation_type', \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationType::ClientCompanion)
            ->firstOrFail();
        app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class)->handle(
            organizationId: $organization->getKey(),
            client: $client,
            conversation: $conversation,
            channel: 'portal',
            direction: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Inbound,
            authorType: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Client,
            body: base64_decode('${encodedBody}', true),
        );
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-message-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    execFileSync('php', ['artisan', 'tinker', '--execute', php], {
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
}

async function login(page: Page, fixture: CrmFixture): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
}

async function selectAnalyticsOption(page: Page, combobox: Locator, optionName: string): Promise<void> {
    await expect(combobox).toBeVisible({ timeout: 15_000 });
    let dropdown = page.locator('.fi-dropdown-panel:visible').last();
    if (await dropdown.count() === 0) {
        await combobox.click();
        dropdown = page.locator('.fi-dropdown-panel:visible').last();
    }
    await expect(dropdown).toBeVisible({ timeout: 15_000 });

    const search = dropdown.locator('input[type="text"]:visible');
    if (await search.count() > 0) {
        await search.fill(optionName);
    }

    const option = dropdown.getByRole('option').filter({ hasText: optionName }).first();
    await expect(option).toBeVisible({ timeout: 15_000 });
    await option.click();
    await page.waitForLoadState('networkidle');
    await expect(combobox).toContainText(optionName, { timeout: 15_000 });
}

test('multi-specialist Journal keeps the accepted layout and slot ownership', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();
    const secondSpecialist = createJournalSecondSpecialist(fixture);

    await login(page, fixture);
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(`/admin/bookings?view=week&week=${fixture.bookingStartsAt.slice(0, 10)}`);
    await expect(page.getByRole('heading', { name: 'Журнал записей', exact: true })).toBeVisible();

    const filter = page.locator('#booking-journal-specialist');
    await expect(filter).toHaveAccessibleName('Все специалисты');
    await filter.click();
    let panel = page.locator('.fi-dropdown-panel:visible').last();
    await expect(panel).toBeVisible();
    const checkbox = (name: string) => panel.locator('label').filter({ hasText: name }).locator('input[type="checkbox"]').first();

    await checkbox(fixture.specialistName).check();
    await expect(panel).toBeVisible();
    await expect(filter).toHaveAccessibleName('Специалисты: 1');
    await checkbox(secondSpecialist.name).check();
    await expect(panel).toBeVisible();
    await expect.poll(() => page.locator('[wire\\:key="booking-journal-specialist-filter"] input[type="checkbox"]:checked').count()).toBe(2);
    await expect(filter).toHaveAccessibleName('Специалисты: 2');
    await assertNoHorizontalOverflow(page);

    await checkbox(fixture.specialistName).uncheck();
    await expect.poll(() => page.locator('[wire\\:key="booking-journal-specialist-filter"] input[type="checkbox"]:checked').count()).toBe(1);
    await expect(checkbox(secondSpecialist.name)).toBeChecked();
    await expect(filter).toHaveAccessibleName('Специалисты: 1');

    await panel.getByRole('button', { name: 'Все специалисты', exact: true }).click();
    await expect.poll(() => page.locator('[wire\\:key="booking-journal-specialist-filter"] input[type="checkbox"]:checked').count()).toBe(0);
    await expect(filter).toHaveAccessibleName('Все специалисты');
    await expect(filter).toContainText('Все специалисты');
    await expect.poll(() => {
        const url = new URL(page.url());

        return url.searchParams.has('specialist_ids') || url.searchParams.has('specialist_id');
    }).toBe(false);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Журнал записей', exact: true })).toBeVisible();
    await expect(filter).toHaveAccessibleName('Все специалисты');
    await expect(filter).toContainText('Все специалисты');

    await page.goBack();
    await expect(filter).toHaveAccessibleName('Специалисты: 1');
    await expect.poll(() => {
        const url = new URL(page.url());

        return url.searchParams.get('specialist_ids[0]');
    }).toBe(String(secondSpecialist.id));
    await filter.click();
    panel = page.locator('.fi-dropdown-panel:visible').last();
    await expect(checkbox(secondSpecialist.name)).toBeChecked();
    await expect.poll(() => page.locator('[wire\\:key="booking-journal-specialist-filter"] input[type="checkbox"]:checked').count()).toBe(1);

    await page.goForward();
    await expect(filter).toHaveAccessibleName('Все специалисты');
    await expect.poll(() => page.locator('[wire\\:key="booking-journal-specialist-filter"] input[type="checkbox"]:checked').count()).toBe(0);
    await expect.poll(() => {
        const url = new URL(page.url());

        return url.searchParams.has('specialist_ids') || url.searchParams.has('specialist_id');
    }).toBe(false);

    const layout = await page.evaluate(() => {
        const isVisible = (element: Element): boolean => {
            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();

            return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
        };

        return {
            desktopGridVisible: [...document.querySelectorAll('[class*="lg:block"]')].some(isVisible),
            mobileCardsVisible: [...document.querySelectorAll('[class*="lg:hidden"]')].some(isVisible),
        };
    });
    expect(layout.desktopGridVisible).toBe(true);
    expect(layout.mobileCardsVisible).toBe(false);
    await expect.poll(() => page.locator(`[title="${fixture.specialistName}"]`).count(), { timeout: 15_000 }).toBeGreaterThan(0);
    await expect.poll(() => page.locator(`[title="${secondSpecialist.name}"]`).count(), { timeout: 15_000 }).toBeGreaterThan(0);
    await expect(page.locator(`[title="${fixture.specialistName}"]`).first()).toBeVisible();
    await expect(page.locator(`[title="${secondSpecialist.name}"]`).first()).toBeVisible();

    if (!(await panel.isVisible())) {
        await filter.click();
        panel = page.locator('.fi-dropdown-panel:visible').last();
    }
    await expect(panel).toBeVisible();
    await checkbox(fixture.specialistName).check();
    await expect(panel).toBeVisible();
    await checkbox(secondSpecialist.name).check();
    await expect(panel).toBeVisible();
    await expect.poll(async () => {
        const specialistIds = await page.locator('a[aria-label*="Добавить запись"]').evaluateAll((links) => [
            ...new Set(links.map((link) => new URL((link as HTMLAnchorElement).href).searchParams.get('specialist_id'))),
        ]);

        return specialistIds.filter((specialistId): specialistId is string => specialistId !== null).length;
    }).toBeGreaterThanOrEqual(2);
    const slotLinks = await page.locator('a[aria-label*="Добавить запись"]').evaluateAll((links) => links.map((link) => new URL((link as HTMLAnchorElement).href)));
    expect(slotLinks.length).toBeGreaterThan(0);
    expect(slotLinks.every((link) => link.searchParams.has('specialist_id'))).toBe(true);
    expect(slotLinks.some((link) => link.searchParams.get('specialist_id') === String(fixture.specialistId))).toBe(true);
    expect(slotLinks.some((link) => link.searchParams.get('specialist_id') === String(secondSpecialist.id))).toBe(true);
    await assertNoHorizontalOverflow(page);
});

test('cancelled Journal booking offers its released time as a real create action', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();
    createJournalBooking(fixture, 'cancelled');
    await login(page, fixture);
    await page.setViewportSize({ width: 1440, height: 900 });

    const date = fixture.bookingStartsAt.slice(0, 10);
    const time = fixture.bookingStartsAt.slice(11, 16);
    const expectedStartsAt = `${date} ${time}`;
    await page.goto(`/admin/bookings?view=week&week=${date}&specialist_id=${fixture.specialistId}`);

    const cancelledCard = page.getByTestId('journal-released-booking-card');
    let createReleasedSlot = cancelledCard.getByTestId('journal-released-booking-create');

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalOverflow(page);

        if (width >= 1024) {
            await expect(cancelledCard).toBeVisible();
            await expect(cancelledCard).toContainText('Отменена');
            await expect(cancelledCard).toHaveAttribute('draggable', 'false');
            createReleasedSlot = cancelledCard.getByTestId('journal-released-booking-create');
            await expect(createReleasedSlot).toBeVisible();
            await expect(createReleasedSlot).toHaveAccessibleName(`Создать новую запись на ${time}`);

            continue;
        }

        await expect.poll(async () => {
            const createLinks = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((anchor) => {
                const link = anchor as HTMLAnchorElement;
                const rectangle = link.getBoundingClientRect();

                return {
                    href: link.href,
                    visible: rectangle.width > 0 && rectangle.height > 0,
                };
            }));

            return createLinks.some(({ href, visible }) => {
                const target = new URL(href);

                return target.pathname === '/admin/bookings/create'
                    && target.searchParams.get('starts_at') === expectedStartsAt
                    && visible;
            });
        }).toBe(true);
    }

    const target = new URL(await createReleasedSlot.getAttribute('href') ?? '', page.url());
    expect(target.pathname).toBe('/admin/bookings/create');
    expect(target.searchParams.get('starts_at')).toBe(expectedStartsAt);
    expect(target.searchParams.get('specialist_id')).toBe(String(fixture.specialistId));

    await createReleasedSlot.click();
    await expect(page).toHaveURL(/\/admin\/bookings\/create\?/);
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();
    await expect(page.getByLabel('Дата')).toHaveValue(date);
    await expect(page.getByRole('combobox', { name: 'Специалист*', exact: true })).toContainText(fixture.specialistName);
    const bookingTime = page.getByRole('combobox', { name: /^Доступное время/ }).first();
    await expect(bookingTime).toContainText(`${time} — выбрано в Журнале, доступность не проверена`);
    await expect(bookingTime).toBeDisabled();
    await expect(page.getByText('После выбора услуги и формата визита проверим, свободно ли это время.', { exact: true })).toBeVisible();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(bookingTime).toBeVisible();
        await expect(bookingTime).toBeDisabled();
        await assertNoHorizontalOverflow(page);
    }
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByRole('option', { name: fixture.serviceName, exact: true }).click();
    await page.getByRole('combobox', { name: 'Клиент*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.clientName);
    await page.getByRole('option').filter({ hasText: fixture.clientName }).last().click();
    await page.getByLabel('Формат визита').selectOption('office');

    const workingLocation = page.getByRole('combobox', { name: 'Локация', exact: true });
    await workingLocation.click();
    await page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.workingLocationName }).first().click();

    await bookingTime.click();
    const releasedTime = page.locator('.fi-select-input-option:visible').filter({ hasText: `${time}–` }).first();
    await expect(releasedTime).toBeVisible({ timeout: 15_000 });
    await releasedTime.click();
    await expect(bookingTime).toContainText(time);
    await expect(page.getByLabel('Адрес приёма', { exact: true })).toHaveValue(/\S+/);
    await assertNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/admin/bookings'
        && url.searchParams.get('week') === date
        && url.searchParams.get('view') === 'week'
        && url.searchParams.get('specialist_id') === String(fixture.specialistId), { timeout: 15_000 });
    await expect(page.getByRole('heading', { name: 'Журнал записей', exact: true })).toBeVisible();
    const createdBookingCard = page.locator('[draggable="true"]')
        .filter({ hasText: fixture.clientName })
        .filter({ hasText: time });
    await expect(createdBookingCard).toHaveCount(1);
    await expect(createdBookingCard).toContainText(fixture.serviceName);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Журнал записей', exact: true })).toBeVisible();
    await expect(createdBookingCard).toBeVisible();
});

test('stale Journal time explains the conflict and allows another slot', async ({ page }) => {
    const fixture = createCrmFixture();
    createJournalBooking(fixture, 'confirmed');
    await login(page, fixture);

    const date = fixture.bookingStartsAt.slice(0, 10);
    const time = fixture.bookingStartsAt.slice(11, 16);
    const startsAt = encodeURIComponent(`${date} ${time}`);
    await page.goto(`/admin/bookings/create?starts_at=${startsAt}&specialist_id=${fixture.specialistId}&return_to_journal=1&week=${date}&view=week`);

    const bookingTime = page.getByRole('combobox', { name: /^Доступное время/ }).first();
    await expect(bookingTime).toContainText(`${time} — выбрано в Журнале, доступность не проверена`);
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByRole('option', { name: fixture.serviceName, exact: true }).click();
    await page.getByLabel('Формат визита').selectOption('office');

    const warning = page.getByText('Выбранное время больше недоступно. Выберите другое свободное время.', { exact: true });
    await expect(warning).toBeVisible();
    await expect(bookingTime).toBeEnabled();
    await bookingTime.click();
    const availableSlot = page.locator('.fi-select-input-option:visible').first();
    await expect(availableSlot).toBeVisible();
    const alternativeLabel = (await availableSlot.innerText()).trim();
    expect(alternativeLabel).not.toBe(time);
    await availableSlot.click();
    await expect(warning).toBeHidden();
    await expect(bookingTime).toContainText(alternativeLabel);
});

test('enrolled CRM MFA requires a valid code before native browser login', async ({ page }) => {
    const fixture = createCrmFixture();
    execFileSync('php', ['artisan', 'tinker', '--execute', `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        \\Filament\\Facades\\Filament::setCurrentPanel(\\Filament\\Facades\\Filament::getPanel('admin'));
        $admin = \\App\\Models\\User::query()->where('email', '${fixture.email}')->firstOrFail();
        \\Filament\\Facades\\Filament::auth()->setUser($admin);
        $provider = app(\\App\\Modules\\Security\\Infrastructure\\Filament\\AuditedAppAuthentication::class);
        $provider->saveSecret($admin, $provider->generateSecret());
    `], { env: process.env, stdio: ['ignore', 'pipe', 'pipe'] });
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    const authentication = page.waitForResponse((response) => response.request().method() === 'POST' && /\/livewire[^/]*\/update$/.test(new URL(response.url()).pathname));
    await page.locator('button[type="submit"]').click();
    expect((await authentication).status()).toBe(200);
    const digits = page.locator('.fi-one-time-code-input-ctn input');
    await expect(digits).toHaveCount(6);
    await expect(digits.first()).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/login$/);
    expect((await page.request.get('/admin', { maxRedirects: 0 })).status()).toBe(302);
    await page.locator('button[type="submit"]:visible').click();
    await expect(page).toHaveURL(/\/admin\/login$/);
    await digits.first().pressSequentially('12345');
    await page.locator('button[type="submit"]:visible').click();
    await expect(page).toHaveURL(/\/admin\/login$/);
    expect((await page.request.get('/admin', { maxRedirects: 0 })).status()).toBe(302);
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', `
        $admin = \\App\\Models\\User::query()->where('email', '${fixture.email}')->firstOrFail();
        echo json_encode(['code' => app(\\App\\Modules\\Security\\Infrastructure\\Filament\\AuditedAppAuthentication::class)->getCurrentCode($admin)]);
    `], { encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'pipe'] });
    const { code } = JSON.parse(output.trim().split('\n').at(-1) ?? '') as { code: string };
    for (let index = 0; index < 6; index += 1) {
        await digits.nth(index).fill('');
    }
    await digits.first().pressSequentially(code);
    await page.locator('button[type="submit"]:visible').click();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Аналитика', exact: true })).toBeVisible();
    await page.reload();
    await expect(page).toHaveURL(/\/admin(?:\/)?$/);
});

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
    await expect.poll(() => new URL(page.url()).searchParams.get('search'), { timeout: 15_000 }).toBe(query);
    await page.waitForLoadState('networkidle');
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
        await page.goto('/admin/working-intervals');

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
        await page.goto('/admin/working-intervals');

        await expect(page.getByRole('button', { name: 'Использовать Europe/Berlin', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Использовать Europe/Berlin', exact: true }).click();
        await expect(page.getByText('Время: Europe/Berlin', { exact: true })).toBeVisible();
        await expect(page.getByText('Мы определили ваш часовой пояс как Europe/Berlin.', { exact: true })).not.toBeVisible();
    });
});

test('staff gets actionable validation and can save a location day after correction', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();
    const areaName = `Playwright район ${Date.now()}`;

    await login(page, fixture);
    await page.goto('/admin/location-days/create');
    await expect(page.getByRole('heading', { name: 'Добавить день выезда' })).toBeVisible();
    await expect(page.locator('form#form')).toHaveAttribute('novalidate', 'novalidate');

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByText('Выберите специалиста.', { exact: true })).toBeVisible();
    await expect(page.getByText('Укажите район.', { exact: true })).toBeVisible();
    await expect(page.getByText('Укажите время начала выезда.', { exact: true })).toBeVisible();
    await expect(page.getByText('Укажите время окончания выезда.', { exact: true })).toBeVisible();
    await expect(page.getByText('Выберите часовой пояс.', { exact: true })).toBeVisible();
    await expect(page.getByText('validation.required', { exact: true })).toHaveCount(0);
    await expect(page).toHaveURL(/\/admin\/location-days\/create$/);

    const specialist = page.getByRole('combobox', { name: 'Специалист*', exact: true });
    await specialist.click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByRole('option', { name: fixture.specialistName, exact: true }).click();
    await page.getByRole('textbox', { name: 'Район*', exact: true }).fill(areaName);
    await page.getByLabel('День недели', { exact: true }).selectOption('5');
    await page.getByLabel('Начало*', { exact: true }).fill('10:00');
    await page.getByLabel('Окончание*', { exact: true }).fill('11:00');

    const timezone = page.getByRole('combobox', { name: 'Часовой пояс*', exact: true });
    await timezone.click();
    await page.getByRole('textbox', { name: 'Search' }).fill('Asia/Almaty');
    await page.getByRole('option', { name: /Asia\/Almaty/ }).click();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/location-days\/\d+\/edit$/);
    const area = page.getByRole('textbox', { name: 'Район*', exact: true });
    await expect(area).toHaveValue(areaName);
    await expect(page.getByRole('combobox', { name: 'Специалист*', exact: true })).toContainText(fixture.specialistName);
    await page.reload();
    await expect(page.locator('form#form')).toHaveAttribute('novalidate', 'novalidate');
    await expect(area).toHaveValue(areaName);
    await expect(page.getByRole('combobox', { name: 'Специалист*', exact: true })).toContainText(fixture.specialistName);
});

test('staff gets actionable unavailable-time errors and can save the corrected schedule change', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();
    const reason = `Playwright недоступность ${Date.now()}`;

    await login(page, fixture);
    await page.goto('/admin/schedule-exceptions');
    await expect(page.getByRole('heading', { name: 'Изменения расписания', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Тип изменения: Рабочие интервалы', exact: true }).click();
    await page.getByRole('link', { name: 'Недоступное время', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/schedule-exceptions\/create\?change_type=unavailable$/);
    await expect(page.locator('form#form')).toHaveAttribute('novalidate', 'novalidate');

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByText('Выберите специалиста.', { exact: true })).toBeVisible();
    await expect(page.getByText('Укажите дату и время начала недоступности.', { exact: true })).toBeVisible();
    await expect(page.getByText('Укажите дату и время окончания недоступности.', { exact: true })).toBeVisible();
    await expect(page.getByText('validation.required', { exact: true })).toHaveCount(0);
    await expect(page).toHaveURL(/\/admin\/schedule-exceptions\/create\?change_type=unavailable$/);

    const specialist = page.getByRole('combobox', { name: 'Специалист*', exact: true });
    await specialist.click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByRole('option', { name: fixture.specialistName, exact: true }).click();
    await page.locator('[id="form.starts_at"]').fill('2040-04-06T12:00');
    await page.locator('[id="form.ends_at"]').fill('2040-04-06T11:30');
    await page.getByRole('textbox', { name: 'Причина', exact: true }).fill(reason);
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByText('Окончание периода должно быть позже начала.', { exact: true })).toBeVisible();
    await expect(page.getByText('validation.after', { exact: true })).toHaveCount(0);
    await expect(page).toHaveURL(/\/admin\/schedule-exceptions\/create\?change_type=unavailable$/);

    await page.locator('[id="form.ends_at"]').fill('2040-04-06T13:30');
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/schedule-exceptions(?:\?.*)?$/);
    await expect(page.getByText('Создано', { exact: true })).toBeVisible();
    const lastPageButton = page.getByRole('button', { name: /Перейти к странице \d+/ }).last();
    if (await lastPageButton.count() && await lastPageButton.isEnabled()) {
        await lastPageButton.click();
    }
    const savedRow = page.getByRole('row').filter({ hasText: reason });
    await expect(savedRow).toBeVisible();
    await page.reload();
    await expect(page.getByRole('row').filter({ hasText: reason })).toBeVisible();
});

test('staff can create a booking without technical inputs', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();

    await login(page, fixture);

    await page.goto('/admin/bookings/create');
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();
    await expect(page.locator('input[name*="idempotency"], input[name*="timezone"], select[name*="meeting_link"]')).toHaveCount(0);

    await page.getByRole('combobox', { name: 'Специалист*', exact: true }).click();
    await page.getByText(fixture.specialistName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    const serviceOption = page.getByRole('option', { name: fixture.serviceName, exact: true });
    await expect(serviceOption).toBeVisible({ timeout: 15_000 });
    await serviceOption.click();
    await page.getByRole('combobox', { name: 'Клиент*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.clientName);
    await page.getByRole('option').filter({ hasText: fixture.clientName }).last().click();
    const dateInput = page.getByLabel('Дата');
    await dateInput.fill(fixture.bookingStartsAt.slice(0, 10));
    await dateInput.blur();
    await expect(dateInput).toHaveValue(fixture.bookingStartsAt.slice(0, 10));
    await page.getByLabel('Формат визита').selectOption('office');
    await expect(page.getByLabel('Формат визита')).toHaveValue('office');
    const workingLocation = page.getByRole('combobox', { name: 'Локация', exact: true });
    await workingLocation.click();
    const locationOption = page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.workingLocationName }).first();
    await expect(locationOption).toBeVisible({ timeout: 15_000 });
    await locationOption.click();
    await expect(workingLocation).not.toContainText('Выбрать вариант', { timeout: 15_000 });
    await expect(page.getByLabel('Адрес приёма', { exact: true })).toHaveValue(/\S+/, { timeout: 15_000 });
    const bookingTime = page.getByRole('combobox', { name: /^Доступное время/ }).first();
    await bookingTime.click();
    const availableTime = page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.bookingStartsAt.slice(11, 16) }).first();
    await expect(availableTime).toBeVisible({ timeout: 15_000 });
    await availableTime.click();
    await expect(bookingTime).toContainText(fixture.bookingStartsAt.slice(11, 16));
    const createButton = page.getByRole('button', { name: 'Сохранить', exact: true });
    await expect(createButton).toBeEnabled({ timeout: 15_000 });
    await createButton.click();

    await expect(page).toHaveURL(/\/admin\/bookings\/\d+$/, { timeout: 15_000 });
    await expect(page.locator('.fi-in-text-item').filter({ hasText: fixture.clientName }).first()).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await expect(page.getByText(/idempotency|event version|schedule timezone|client timezone/i)).toHaveCount(0);
    const writeClient = page.getByRole('link', { name: 'Написать клиенту', exact: true });
    await expect(writeClient).toHaveAttribute('href', new RegExp(`/admin/messages\\?client=${fixture.clientId}$`));
    await writeClient.click();
    await expect(page).toHaveURL(new RegExp(`/admin/messages\\?client=${fixture.clientId}$`));
    await expect(page.getByRole('heading', { level: 1, name: 'Сообщения', exact: true })).toBeVisible();
});

test('staff can create a backdated booking from the ordinary availability form', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/bookings/create');
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();

    await page.getByRole('combobox', { name: 'Специалист*', exact: true }).click();
    await page.getByText(fixture.specialistName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByRole('option', { name: fixture.serviceName, exact: true }).click();
    await page.getByRole('combobox', { name: 'Клиент*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.clientName);
    await page.getByRole('option').filter({ hasText: fixture.clientName }).last().click();

    await page.getByLabel('Формат визита').selectOption('office');
    const workingLocation = page.getByRole('combobox', { name: 'Локация', exact: true });
    await workingLocation.click();
    await page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.workingLocationName }).first().click();
    const dateInput = page.getByLabel('Дата');
    await dateInput.fill(fixture.pastBookingDate);
    await dateInput.blur();

    const bookingTime = page.getByRole('combobox', { name: /^Доступное время/ }).first();
    await bookingTime.click();
    const availableTime = page.locator('.fi-select-input-option:visible').filter({ hasText: '10:00–11:00' }).first();
    await expect(availableTime).toBeVisible({ timeout: 15_000 });
    await availableTime.click();
    const [year, month, day] = fixture.pastBookingDate.split('-');
    const localWarning = `Вы создаёте запись задним числом: ${day}.${month}.${year} 10:00.`;
    const warning = page.getByText(localWarning, { exact: true });
    await expect(page.getByText('Внимание', { exact: true })).toBeVisible();
    await expect(warning).toBeVisible();
    const warningUsesRedEmphasis = await warning.evaluate((element) => {
        const color = getComputedStyle(element).color;
        const oklch = color.match(/^oklch\(\s*[\d.]+%?\s+([\d.]+)\s+([\d.]+)/i);

        if (oklch) {
            const chroma = Number(oklch[1]);
            const hue = Number(oklch[2]);

            return chroma > 0.08 && (hue <= 50 || hue >= 330);
        }

        const channels = color.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i)?.slice(1).map(Number);

        return channels !== undefined && channels[0] > channels[1] && channels[0] > channels[2];
    });
    expect(warningUsesRedEmphasis).toBe(true);
    await expect(page.getByLabel('Подтверждаю создание записи задним числом', { exact: true })).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/bookings\/create$/);

    const saveButton = page.getByRole('button', { name: 'Сохранить', exact: true });
    await saveButton.click();
    await expect(page.getByText('Подтвердите создание записи задним числом.', { exact: true })).toBeVisible();
    await page.getByLabel('Подтверждаю создание записи задним числом', { exact: true }).check();
    await saveButton.click();

    await expect(page).toHaveURL(/\/admin\/bookings\/\d+$/, { timeout: 15_000 });
    await expect(page.getByText('Ожидает подтверждения', { exact: true })).toBeVisible();
    const bookingId = Number(page.url().match(/\/admin\/bookings\/(\d+)$/)?.[1]);
    expect(Number.isInteger(bookingId)).toBe(true);
    const bookingSnapshot = readCrmBookingSnapshot(bookingId);
    expect(bookingSnapshot).toEqual({
        startsAtUtc: fixture.pastBookingStartsAtUtc,
        scheduleTimezone: fixture.organizationTimezone,
    });
    await expect(page.getByText(`${day}.${month}.${year} 10:00`, { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Запись на приём', exact: true })).toBeVisible();
    await expect(page.getByText(`${day}.${month}.${year} 10:00`, { exact: true })).toBeVisible();
    await expect(page.getByText('Ожидает подтверждения', { exact: true })).toBeVisible();
});

test('staff can open a verified client Telegram username from the CRM card', async ({ page }) => {
    const fixture = createCrmFixture({ telegramLinkFlow: true });
    if (fixture.telegramUsername === null) {
        throw new Error('The CRM fixture did not create a verified Telegram username.');
    }

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}`);
    const expectedUrl = `https://t.me/${fixture.telegramUsername}`;
    const telegramLink = page.getByRole('link', { name: `@${fixture.telegramUsername}`, exact: true });
    await expect(telegramLink).toHaveAttribute('href', expectedUrl);
    await expect(telegramLink).toHaveAttribute('target', '_blank');
    let requestedTelegramUrl = '';
    await page.context().route('https://t.me/**', async (route) => {
        requestedTelegramUrl = route.request().url();
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Telegram navigation intercepted</title>' });
    });
    const [telegramPage] = await Promise.all([
        page.waitForEvent('popup'),
        telegramLink.click(),
    ]);
    await telegramPage.waitForLoadState();
    expect(requestedTelegramUrl).toBe(expectedUrl);
    await expect(telegramPage).toHaveURL(expectedUrl);
    await telegramPage.close();

    await page.reload();
    await expect(page.getByRole('link', { name: `@${fixture.telegramUsername}`, exact: true })).toHaveAttribute('href', expectedUrl);
});

test('service create actions save buffers, reset for another service, and reopen persisted values', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture();
    const firstName = `${fixture.serviceName} Buffers one`;
    const secondName = `${fixture.serviceName} Buffers two`;

    await login(page, fixture);
    await page.goto('/admin/services/create');
    await expect(page.getByRole('heading', { name: 'Добавить услугу', exact: true })).toBeVisible();

    const fillService = async (name: string, buffers: { office: string; home: string; online: string }): Promise<void> => {
        await page.getByLabel('Название*', { exact: true }).fill(name);
        await page.getByLabel('Тип предложения*', { exact: true }).selectOption('service');
        await page.getByLabel('Краткое описание*', { exact: true }).fill('Синтетическая услуга для проверки интервалов между записями.');
        await page.getByLabel('Длительность (минуты)', { exact: true }).fill('60');
        await page.getByLabel('Перерыв после приёма, минут', { exact: true }).fill('0');
        await page.getByLabel('Перерыв — кабинет, минут', { exact: true }).fill(buffers.office);
        await page.getByLabel('Перерыв — выезд, минут', { exact: true }).fill(buffers.home);
        await page.getByLabel('Перерыв — онлайн, минут', { exact: true }).fill(buffers.online);
        await page.getByRole('checkbox', { name: 'В клинике', exact: true }).check();
        await page.getByRole('checkbox', { name: 'Выезд на дом', exact: true }).check();
        await page.getByRole('checkbox', { name: 'Онлайн', exact: true }).check();
        await page.getByLabel('Когда клиент оплачивает*', { exact: true }).selectOption('postpay');
    };

    await fillService(firstName, { office: '7', home: '13', online: '19' });
    await page.getByRole('button', { name: 'Сохранить и создать ещё', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/services\/create$/);
    await expect(page.locator('.fi-no-notification.fi-status-success').last()).toBeVisible();
    await expect(page.getByLabel('Название*', { exact: true })).toHaveValue('');
    const createdFirst = readCrmServiceSnapshot(firstName);
    expect(createdFirst).toMatchObject({ bufferOfficeMinutes: 7, bufferHomeMinutes: 13, bufferOnlineMinutes: 19 });

    await fillService(secondName, { office: '8', home: '14', online: '20' });
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/services\/\d+\/edit$/, { timeout: 15_000 });
    await expect(page.locator('.fi-no-notification.fi-status-success').last()).toBeVisible();
    const createdSecond = readCrmServiceSnapshot(secondName);
    expect(createdSecond).toMatchObject({ bufferOfficeMinutes: 8, bufferHomeMinutes: 14, bufferOnlineMinutes: 20 });
    await expect(page).toHaveURL(new RegExp(`/admin/services/${createdSecond.id}/edit$`));

    await page.goto(`/admin/services/${createdFirst.id}/edit`);
    await expect(page.getByLabel('Перерыв — кабинет, минут', { exact: true })).toHaveValue('7');
    await expect(page.getByLabel('Перерыв — выезд, минут', { exact: true })).toHaveValue('13');
    await expect(page.getByLabel('Перерыв — онлайн, минут', { exact: true })).toHaveValue('19');
    await page.reload();
    await expect(page.getByLabel('Перерыв — кабинет, минут', { exact: true })).toHaveValue('7');
    await expect(page.getByLabel('Перерыв — выезд, минут', { exact: true })).toHaveValue('13');
    await expect(page.getByLabel('Перерыв — онлайн, минут', { exact: true })).toHaveValue('19');
});

test('staff can create a group booking with a manually extended calendar block', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/bookings/create');
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();

    await page.getByRole('combobox', { name: 'Специалист*', exact: true }).click();
    await page.getByText(fixture.specialistName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByRole('option', { name: fixture.serviceName, exact: true }).click();
    await page.getByRole('combobox', { name: 'Клиент*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.clientName);
    await page.getByRole('option').filter({ hasText: fixture.clientName }).last().click();
    await page.getByLabel('Формат визита').selectOption('office');

    const workingLocation = page.getByRole('combobox', { name: 'Локация', exact: true });
    await workingLocation.click();
    await page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.workingLocationName }).first().click();

    const dateInput = page.getByLabel('Дата');
    await dateInput.fill(fixture.bookingStartsAt.slice(0, 10));
    await dateInput.blur();
    const bookingTime = page.getByRole('combobox', { name: /^Доступное время/ }).first();
    await bookingTime.click();
    await page.locator('.fi-select-input-option:visible')
        .filter({ hasText: fixture.bookingStartsAt.slice(11, 16) })
        .first()
        .click();

    const partySize = page.getByRole('spinbutton', { name: /^Количество человек/ });
    await expect(partySize).toHaveValue('1');
    await partySize.fill('3');

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/bookings\/\d+$/, { timeout: 15_000 });
    await expect(page.getByText('Количество человек', { exact: true })).toBeVisible();
    await expect(page.getByText('3', { exact: true })).toBeVisible();
    const bookingDetailUrl = page.url();

    await page.getByRole('button', { name: 'Действия', exact: true }).click();
    await page.getByRole('button', { name: 'Изменить время занятости', exact: true }).click();
    const blockingDialog = page.locator('.fi-modal-window:visible').last();
    await expect(blockingDialog).toBeVisible();
    const blockingEndsAt = blockingDialog.locator('input[type="datetime-local"]');
    await expect(blockingEndsAt).toHaveCount(1);
    await blockingEndsAt.fill(`${fixture.bookingStartsAt.slice(0, 10)}T14:00`);
    await expect(blockingEndsAt).toHaveValue(`${fixture.bookingStartsAt.slice(0, 10)}T14:00`);
    await blockingEndsAt.blur();
    await page.waitForLoadState('networkidle');
    await blockingDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Время занятости в календаре обновлено', { exact: true })).toBeVisible();
    await expect(page.getByText('Время занято в календаре', { exact: true })).toBeVisible();

    await page.goto(`/admin/bookings?view=list&week=${fixture.bookingStartsAt.slice(0, 10)}&specialist_id=${fixture.specialistId}`);
    await expect(page.getByRole('heading', { name: 'Журнал записей', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.clientName);
    const bookingRow = page.getByRole('row').filter({ hasText: fixture.clientName }).filter({ hasText: fixture.serviceName }).first();
    await bookingRow.getByRole('button', { name: 'Действия', exact: true }).click();
    const tableBookingActionsMenu = page.locator('.fi-dropdown-panel:visible').last();
    await expect(tableBookingActionsMenu).toBeVisible();
    const tableBlockingAction = tableBookingActionsMenu.getByRole('button', { name: 'Изменить время занятости', exact: true });
    await expect(tableBlockingAction).toBeVisible();
    const tableBlockingDialog = page.locator('.fi-modal-window:visible').last();
    await Promise.allSettled([
        tableBlockingAction.click({ timeout: 10_000 }),
        tableBlockingDialog.waitFor({ state: 'visible', timeout: 10_000 }),
    ]);
    await expect(tableBlockingDialog).toBeVisible();
    const tableBlockingEndsAt = tableBlockingDialog.locator('input[type="datetime-local"]');
    await expect(tableBlockingEndsAt).toHaveValue(`${fixture.bookingStartsAt.slice(0, 10)}T14:00`);
    await tableBlockingEndsAt.fill(`${fixture.bookingStartsAt.slice(0, 10)}T15:00`);
    await tableBlockingEndsAt.blur();
    await page.waitForLoadState('networkidle');
    await tableBlockingDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Время занятости в календаре обновлено', { exact: true })).toBeVisible();

    await page.goto(bookingDetailUrl);
    await expect(page.getByText(/До .*14:00 → до .*15:00/)).toBeVisible();
});

test('staff Messages opens at the latest history and preserves intentional scrolling', async ({ page }) => {
    test.setTimeout(90_000);

    const fixture = createCrmFixture({ messagesFlow: true });

    await login(page, fixture);
    await page.goto(`/admin/messages?client=${fixture.clientId}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Сообщения', exact: true })).toBeVisible();

    const history = page.getByTestId('messages-history');
    const isAtBottom = async (): Promise<boolean> => history.evaluate((element) => {
        const container = element as HTMLElement;

        return container.scrollHeight - container.scrollTop - container.clientHeight <= 2;
    });
    const selectDialog = async (clientId: number): Promise<void> => {
        const dialogButton = page.locator(`[wire\\:key="dialog-${clientId}"]`).getByRole('button');
        if (! await dialogButton.isVisible()) {
            await page.getByRole('button', { name: 'К списку диалогов', exact: true }).click();
        }
        await expect(dialogButton).toBeVisible();
        await dialogButton.click();
    };

    await expect.poll(isAtBottom).toBe(true);
    await expect(history.getByText(`CRM long history ${fixture.clientId} message 65`, { exact: true })).toBeVisible();

    await selectDialog(fixture.partnerId);
    await expect(history.getByText(`CRM long history ${fixture.partnerId} message 40`, { exact: true })).toBeVisible();
    await expect.poll(isAtBottom).toBe(true);

    await page.getByRole('button', { name: 'Подключиться к диалогу', exact: true }).click();
    await expect(page.locator('#messages-composer-form')).toBeVisible();
    await page.getByRole('textbox', { name: 'Сообщение', exact: true }).fill('Новое CRM сообщение');
    await page.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(history.getByText('Новое CRM сообщение', { exact: true })).toBeVisible();
    await expect.poll(isAtBottom).toBe(true);

    await selectDialog(fixture.clientId);
    await expect(history.getByText(`CRM long history ${fixture.clientId} message 65`, { exact: true })).toBeVisible();
    await expect.poll(isAtBottom).toBe(true);

    const existingMessage = history.getByText(`CRM long history ${fixture.clientId} message 36`, { exact: true });
    await history.evaluate((element) => {
        (element as HTMLElement).scrollTop = 120;
    });
    const existingMessageTop = await existingMessage.evaluate((element) => element.getBoundingClientRect().top);
    await page.getByTestId('messages-load-older').dispatchEvent('click');
    await expect(history.getByText(`CRM long history ${fixture.clientId} message 6`, { exact: true })).toHaveCount(1);
    const existingMessageTopAfterLoad = await existingMessage.evaluate((element) => element.getBoundingClientRect().top);
    expect(Math.abs(existingMessageTopAfterLoad - existingMessageTop)).toBeLessThan(2);

    await history.evaluate((element) => {
        const container = element as HTMLElement;
        container.scrollTop = container.scrollHeight;
    });
    await expect.poll(isAtBottom).toBe(true);
    appendCrmMessage(fixture.clientId, 'Сообщение из poll у нижней границы');
    await expect(history.getByText('Сообщение из poll у нижней границы', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect.poll(isAtBottom, { timeout: 15_000 }).toBe(true);

    await history.evaluate((element) => {
        (element as HTMLElement).scrollTop = 120;
    });
    const scrollTopBeforePoll = await history.evaluate((element) => (element as HTMLElement).scrollTop);
    appendCrmMessage(fixture.clientId, 'Сообщение из poll при чтении истории');
    await expect(history.getByText('Сообщение из poll при чтении истории', { exact: true })).toBeVisible({ timeout: 15_000 });
    const scrollTopAfterPoll = await history.evaluate((element) => (element as HTMLElement).scrollTop);
    expect(Math.abs(scrollTopAfterPoll - scrollTopBeforePoll)).toBeLessThan(5);
});

test('staff can create a new client inline while creating a booking', async ({ page }) => {
    test.setTimeout(60_000);

    const fixture = createCrmFixture();
    const newClientName = `Новый CRM клиент ${Date.now()}`;
    const newClientPhone = '+7 700 234-56-78';

    await login(page, fixture);

    await page.goto('/admin/bookings/create');
    await expect(page.getByRole('heading', { name: 'Создать Запись' })).toBeVisible();

    await page.getByRole('combobox', { name: 'Специалист*', exact: true }).click();
    await page.getByText(fixture.specialistName, { exact: true }).click();
    await page.getByRole('combobox', { name: 'Услуга*', exact: true }).click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.serviceName);
    await page.getByRole('option', { name: fixture.serviceName, exact: true }).click();
    const dateInput = page.getByLabel('Дата');
    await dateInput.fill(fixture.bookingStartsAt.slice(0, 10));
    await dateInput.blur();
    await expect(dateInput).toHaveValue(fixture.bookingStartsAt.slice(0, 10));
    await page.getByLabel('Формат визита').selectOption('office');
    const workingLocation = page.getByRole('combobox', { name: 'Локация', exact: true });
    await workingLocation.click();
    await page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.workingLocationName }).first().click();
    const bookingTime = page.getByRole('combobox', { name: /^Доступное время/ }).first();
    await bookingTime.click();
    const availableTime = page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.bookingStartsAt.slice(11, 16) }).first();
    await expect(availableTime).toBeVisible({ timeout: 15_000 });
    await availableTime.click();
    await expect(bookingTime).toContainText(fixture.bookingStartsAt.slice(11, 16));
    const newClientAction = page.getByTestId('booking-create-client');
    await expect(newClientAction).toBeVisible();
    await expect(newClientAction).toContainText('Добавить нового клиента');
    await newClientAction.click();

    const modal = page.getByRole('dialog').last();
    await expect(modal.getByLabel('Имя и фамилия*', { exact: true })).toBeVisible();
    await expect(modal.getByLabel('Язык', { exact: true })).toHaveCount(0);
    await expect(modal.getByLabel('Часовой пояс', { exact: true })).toHaveCount(0);
    await modal.getByLabel('Имя и фамилия*', { exact: true }).fill(newClientName);
    await modal.getByLabel('Телефон', { exact: true }).fill(newClientPhone);
    await modal.getByRole('button', { name: 'Создать', exact: true }).click();
    await expect(modal).not.toBeVisible();

    await expect(page.getByRole('combobox', { name: 'Клиент*', exact: true })).toContainText(newClientName);
    await expect(page.getByRole('combobox', { name: 'Услуга*', exact: true })).toContainText(fixture.serviceName);
    await expect(page.getByRole('combobox', { name: 'Специалист*', exact: true })).toContainText(fixture.specialistName);
    await expect(dateInput).toHaveValue(fixture.bookingStartsAt.slice(0, 10));
    await expect(bookingTime).toContainText(fixture.bookingStartsAt.slice(11, 16));
    await expect(page.getByLabel('Формат визита')).toHaveValue('office');
    await expect(page.getByLabel('Адрес приёма', { exact: true })).toHaveValue(/\S+/);

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/bookings\/\d+$/, { timeout: 15_000 });
    await expect(page.locator('.fi-in-text-item').filter({ hasText: newClientName }).first()).toBeVisible();
});

test('client picker remains usable across narrow, tablet, and desktop widths', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile');

    const fixture = createCrmFixture();

    await page.setViewportSize({ width: 320, height: 720 });
    await login(page, fixture);
    await page.goto('/admin/bookings/create');

    const field = page.locator('.booking-client-select-field');
    const picker = field.locator('.fi-input-wrp');
    const selection = field.locator('.fi-input-wrp-content-ctn');
    const selector = field.getByRole('combobox').first();
    const createClient = page.getByTestId('booking-create-client');

    await expect(field).toBeVisible();
    await expect(createClient).toBeVisible();

    for (const width of [320, 1024, 1440]) {
        await page.setViewportSize({ width, height: 720 });

        const selectionWidth = await selection.evaluate((element) => element.getBoundingClientRect().width);
        const pickerBounds = await picker.boundingBox();
        const selectionBounds = await selection.boundingBox();
        const selectorBounds = await selector.boundingBox();
        const actionBounds = await createClient.boundingBox();
        const documentWidth = await page.evaluate(() => ({
            client: document.documentElement.clientWidth,
            scroll: document.documentElement.scrollWidth,
        }));

        expect(selectionWidth).toBeGreaterThan(width <= 1279 ? 200 : 120);
        expect(pickerBounds).not.toBeNull();
        expect(selectionBounds).not.toBeNull();
        expect(selectorBounds).not.toBeNull();
        expect(actionBounds).not.toBeNull();
        expect(actionBounds!.x).toBeGreaterThanOrEqual(pickerBounds!.x);
        expect(actionBounds!.x + actionBounds!.width).toBeLessThanOrEqual(pickerBounds!.x + pickerBounds!.width + 1);
        expect(documentWidth.scroll).toBeLessThanOrEqual(documentWidth.client);

        if (width <= 1279) {
            expect(actionBounds!.y).toBeGreaterThanOrEqual(selectionBounds!.y + selectionBounds!.height - 1);
        } else {
            expect(actionBounds!.y).toBeLessThan(selectorBounds!.y + selectorBounds!.height);
        }
    }

    await page.setViewportSize({ width: 320, height: 720 });

    await createClient.click();
    await expect(page.getByRole('dialog').last().getByLabel('Имя и фамилия*', { exact: true })).toBeVisible();
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

test('owner remediation configuration pages explain empty states and usage', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture({ financeFlow: true });
    const suffix = fixture.clientName.replace(/^CRM Клиент /, '');
    const accountName = `Счёт для проверки ${suffix}`;
    const categoryName = `Категория для проверки ${suffix}`;
    const storyId = Number(execFileSync('php', ['artisan', 'tinker', '--execute', `
        $story = \\App\\Modules\\Content\\Domain\\Models\\ContentSection::query()->findOrFail(${fixture.contentSectionId});
        $story->forceFill([
            'section_key' => 'story_author',
            'title' => 'История автора для проверки',
            'body' => '',
            'delivery_mode' => 'mini_app',
            'media' => [],
            'sort_order' => 0,
            'is_visible' => true,
        ])->save();
        echo $story->getKey();
    `], { encoding: 'utf8', env: { ...process.env, APP_ENV: 'e2e' } }).trim().split('\n').at(-1));

    await login(page, fixture);

    await page.goto('/admin/finance-expense-entry');
    await expect(page.getByRole('heading', { name: 'Добавить расход', exact: true })).toBeVisible();
    const accountSelect = page.getByRole('combobox', { name: /^Счёт или касса/ });
    await expect(accountSelect).toBeVisible();
    await expect(page.getByText('Выберите, откуда списаны деньги: банковский счёт или касса.', { exact: true })).toBeVisible();
    const addAccountLink = page.getByText('Добавить счёт или кассу', { exact: true }).last();
    await expect(addAccountLink).toBeVisible();
    await addAccountLink.click();
    await expect(page).toHaveURL(/\/admin\/finance-movement-settings$/);
    await expect(page.getByRole('heading', { name: 'Счета и кассы', exact: true })).toBeVisible();
    await expect(page.getByText('Эти варианты появляются в форме расхода и показывают, откуда списаны деньги.', { exact: false })).toBeVisible();

    const accountSection = page.getByRole('heading', { name: 'Счета и кассы', exact: true }).locator('xpath=ancestor::section[1]');
    await accountSection.getByRole('button', { name: 'Добавить счёт или кассу', exact: true }).click();
    const accountItem = accountSection.locator('.fi-fo-repeater-item').last();
    await accountItem.getByRole('textbox', { name: /^Название/ }).fill(accountName);
    await accountItem.getByRole('combobox', { name: /^Тип/ }).selectOption('bank');
    await accountItem.getByRole('combobox', { name: /^Валюта/ }).selectOption('USD');

    const categorySection = page.getByRole('heading', { name: 'Категории расходов', exact: true }).locator('xpath=ancestor::section[1]');
    await categorySection.getByRole('button', { name: 'Добавить категорию', exact: true }).click();
    const categoryItem = categorySection.locator('.fi-fo-repeater-item').last();
    await categoryItem.getByRole('textbox', { name: /^Название/ }).fill(categoryName);
    await page.getByRole('button', { name: 'Сохранить справочники', exact: true }).click();
    await page.waitForTimeout(500);

    await page.goto('/admin/finance-expense-entry');
    await selectAnalyticsOption(page, accountSelect, `${accountName} (USD)`);
    const categorySelect = page.getByRole('combobox', { name: /^Категория/ });
    await selectAnalyticsOption(page, categorySelect, categoryName);
    await page.getByRole('spinbutton', { name: /^Сумма/ }).fill('25');
    await page.getByRole('button', { name: 'Записать расход', exact: true }).click();
    await expect(page.getByText('Расход записан', { exact: true })).toBeVisible({ timeout: 20_000 });

    await page.goto('/admin/medical-clinical-catalog-configuration');
    await expect(page.getByRole('heading', { name: 'Клинические справочники', exact: true })).toBeVisible();
    await expect(page.getByText('Эти тесты появляются у специалиста в блоке «Кинезиологические/мануальные тесты» медицинского сеанса.', { exact: false })).toBeVisible();
    await expect(page.getByText('Добавьте первый тест кнопкой «Добавить тест». После сохранения он станет доступен в новом медицинском сеансе.', { exact: true })).toBeVisible();

    await page.goto('/admin/b2b-form-configuration');
    await expect(page.getByRole('heading', { name: 'Поля заявки для бизнеса', exact: true })).toBeVisible();
    await expect(page.getByText('Здесь вы настраиваете вопросы для бизнес-клиента. Клиент увидит опубликованные вопросы перед выбором времени разговора.', { exact: false })).toBeVisible();
    await expect(page.getByText('«Сохранить черновик» сохраняет текущую версию только для команды. «Опубликовать для клиентов» делает её видимой в форме клиента.', { exact: false })).toBeVisible();
    await expect(page.getByText('Пока нет вопросов. Добавьте поле, чтобы увидеть пример здесь.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Добавить поле', exact: true }).click();
    const b2bItem = page.locator('.fi-fo-repeater-item').last();
    await b2bItem.getByRole('textbox', { name: 'Название поля', exact: false }).fill('Контактное лицо');
    await b2bItem.getByRole('combobox', { name: 'Тип ответа', exact: false }).selectOption('text');
    await page.getByRole('button', { name: 'Сохранить черновик', exact: true }).click();
    await page.waitForTimeout(500);
    await expect(page.getByText('Перед выбором времени клиент увидит: Контактное лицо.', { exact: true })).toBeVisible();

    await page.goto(`/admin/content-sections/${storyId}/edit`);
    await expect(page.getByRole('heading', { name: 'Редактировать раздел контента', exact: true })).toBeVisible();
    await expect(page.getByText('Статус показа на главной', { exact: true })).toBeVisible();
    await expect(page.getByText('Видео не загружено. Загрузите MP4 кнопкой «Загрузить видео».', { exact: true })).toBeVisible();
    await expect(page.getByText('Видео не загружено. Загрузите MP4 после сохранения раздела, чтобы он мог показываться клиентам.', { exact: true })).toBeVisible();
});

test('staff sees the Telegram limit and preview while writing to a client', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}/companion`);
    await expect(page.getByRole('heading', { name: 'Общение с клиентом', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Начать диалог как специалист', exact: true }).click();
    await expect(page.locator('#companion-composer-form')).toBeVisible();

    const message = 'фывфывфывфыв';
    await page.getByRole('textbox', { name: 'Сообщение', exact: true }).fill(message);

    await expect(page.getByText('12 / 4096', { exact: true })).toBeVisible();
    await expect(page.locator('.fi-in-text-item').filter({ hasText: message })).toHaveCount(1);

    await page.getByRole('button', { name: 'Предпросмотр Telegram', exact: true }).click();
    const telegramPreview = page.getByRole('dialog').filter({ hasText: 'Предпросмотр Telegram' }).last();
    const telegramPreviewWindow = telegramPreview.locator('.fi-modal-window');
    await expect(telegramPreviewWindow).toBeVisible();
    await expect(telegramPreviewWindow).toContainText('Telegram');
    await expect(telegramPreviewWindow).toContainText(message);
    await telegramPreviewWindow.getByRole('button', { name: 'Закрыть', exact: true }).last().click();
    await expect(telegramPreviewWindow).toBeHidden();
});

test('staff can choose and send an attachment in CRM Messages', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto(`/admin/messages?client=${fixture.clientId}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Сообщения', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Начать диалог как специалист', exact: true }).click();
    await expect(page.locator('#messages-composer-form')).toBeVisible();
    await expect(page.locator('#messages-composer-form .filepond--browser')).toBeAttached({ timeout: 15_000 });

    const [fileChooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        page.getByTestId('messages-attachment-trigger').click(),
    ]);
    await fileChooser.setFiles({
        name: 'crm-message-attachment.pdf',
        mimeType: 'application/pdf',
        buffer: validPdfBuffer(),
    });
    await expect(page.locator('.messages-attachment-summary')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('.messages-attachment-summary')).toContainText('crm-message-attachment.pdf');
    await expect(page.locator('#messages-composer-form .filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(1, { timeout: 15_000 });

    await page.getByRole('textbox', { name: 'Сообщение', exact: true }).fill('Сообщение с вложением');
    await page.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.locator('article').getByText('crm-message-attachment.pdf', { exact: true })).toBeVisible();
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
    const assignmentDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Кто пригласил' }).last();
    const referrerSelect = assignmentDialog.getByRole('combobox', { name: /^Кто пригласил/ });
    await referrerSelect.click();
    await page.getByRole('textbox', { name: 'Search', exact: true }).last().fill(fixture.partnerName);
    await page.getByRole('option').filter({ hasText: fixture.partnerName }).last().click();
    await assignmentDialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByText('Пригласивший указан', { exact: true })).toBeVisible();

    await page.goto('/admin/referral-relationships');
    await expect(page.getByRole('heading', { name: 'Приглашения клиентов', exact: true })).toBeVisible();
    await expect(page.getByText(fixture.partnerName, { exact: true })).toBeVisible();
    await expect(page.getByText(fixture.clientName, { exact: true })).toBeVisible();
});

test('CRM referral statistics show non-zero overall and campaign results after reload', async ({ page }) => {
    const fixture = createCrmFixture({ payoutFlow: true, referralStatsFlow: true });

    if (fixture.partnerProfileId === null || fixture.referralStatsCampaignName === null) {
        throw new Error('The referral statistics fixture is incomplete.');
    }

    await login(page, fixture);
    const assertOverallReferralStatistics = async (): Promise<void> => {
        await page.goto('/admin', { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'Аналитика', exact: true })).toBeVisible();
        const acquisitionSection = page.locator('.fi-section').filter({ hasText: 'Источники новых клиентов' });
        await expect(acquisitionSection.getByText('Источники новых клиентов', { exact: true })).toBeVisible();

        const newClientsLabel = acquisitionSection.getByText('Новые клиенты', { exact: true });
        const newClientsCard = newClientsLabel.locator('xpath=..');
        await expect(newClientsCard.locator('p').nth(1)).toHaveText(/^[1-9]\d*$/);

        const referralSourceRow = acquisitionSection.getByText('Реферальный переход', { exact: true }).locator('xpath=..');
        await expect(referralSourceRow).toBeVisible();
        const referralCount = Number((await referralSourceRow.innerText()).match(/(\d+)\s*·/)?.[1] ?? 0);
        expect(referralCount).toBeGreaterThanOrEqual(2);
    };

    await assertOverallReferralStatistics();
    await page.reload();
    await assertOverallReferralStatistics();

    await page.goto(`/admin/referral-partner-profiles/${fixture.partnerProfileId}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
    await expect(page.getByText(fixture.partnerName, { exact: true })).toBeVisible();
    await expect(page.getByText('Переходы: 3', { exact: false })).toBeVisible();
    await expect(page.getByText('Регистрации: 2', { exact: false })).toBeVisible();
    await expect(page.getByText('Оплатили: 1', { exact: false })).toBeVisible();
    await expect(page.getByText(fixture.referralStatsCampaignName, { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Приглашённые клиенты', exact: true }).click();
    await expect(page.getByText('Оплатил', { exact: true })).toBeVisible();
    await expect(page.getByText('Не оплатил', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByText('Переходы: 3', { exact: false })).toBeVisible();
    await expect(page.getByText('Регистрации: 2', { exact: false })).toBeVisible();
    await expect(page.getByText('Оплатили: 1', { exact: false })).toBeVisible();
    await page.goto('/admin/referral-relationships');
    await page.goto(`/admin/referral-partner-profiles/${fixture.partnerProfileId}`);
    await expect(page.getByText('Переходы: 3', { exact: false })).toBeVisible();
    await expect(page.getByText('Оплатили: 1', { exact: false })).toBeVisible();
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto(`/admin/referral-partner-profiles/${fixture.partnerProfileId}`);
    await page.getByRole('button', { name: 'Ещё', exact: true }).click();
    const partnerMenu = page.locator('.fi-dropdown-panel:visible').last();
    await expect(partnerMenu).toBeVisible();
    await assertRenderedViewportGeometry(page, ['[data-testid="partner-primary-open-client"]'], ['.fi-dropdown-panel']);
    await page.keyboard.press('Escape');
});

test('CRM partner, recommendations, bookings, and AI run controls fit every acceptance viewport', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ payoutFlow: true });

    if (fixture.partnerProfileId === null) {
        throw new Error('The partner fixture did not create a profile.');
    }

    await login(page, fixture);

    for (const width of [1440, 1280, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });

        await page.goto(`/admin/referral-partner-profiles/${fixture.partnerProfileId}`, { waitUntil: 'domcontentloaded' });
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
        await expect(page.getByRole('heading', { name: 'Приглашения клиентов', exact: true })).toBeVisible();
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

    await page.getByRole('link', { name: 'Создать медицинский сеанс', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create\\?booking_id=${fixture.financeBookingId}$`));
    await expect(page.getByRole('heading', { name: 'Новый сеанс', exact: true })).toBeVisible();
    await page.goto(`/admin/bookings/${fixture.financeBookingId}`);
    await expect(page.getByRole('heading', { name: 'Запись на приём', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    const confirmationDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Подтвердить запись' }).last();
    await expect(confirmationDialog).toBeVisible();
    await confirmationDialog.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page.getByText('Запись подтверждена', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Действия', exact: true }).click();
    const bookingActionsMenu = page.locator('.fi-dropdown-panel:visible').last();
    await expect(bookingActionsMenu).toBeVisible();
    await expect(bookingActionsMenu.getByRole('button', { name: 'Завершить визит', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Завершить визит', exact: true }).click();
    const completionDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Завершить визит' }).last();
    await expect(completionDialog).toBeVisible();
    await completionDialog.getByRole('button', { name: 'Подтвердить', exact: true }).click();
    await expect(page.getByText('Визит успешно завершён', { exact: true })).toBeVisible();

    const recordPaymentButton = page.getByRole('button', { name: 'Записать оплату', exact: true });
    await expect(recordPaymentButton).toBeVisible();
    await recordPaymentButton.click();
    const paymentDialog = page.locator('.fi-modal-window:visible').last();
    await expect(paymentDialog).toBeVisible();
    const paymentAmount = paymentDialog.getByRole('textbox', { name: /^Сумма оплаты/ });
    await expect(paymentAmount).toHaveValue('100.00');
    await paymentAmount.fill('25.00');
    await paymentDialog.getByRole('combobox', { name: /^Способ оплаты/ }).selectOption('cash');
    await paymentDialog.getByRole('button', { name: 'Записать оплату', exact: true }).click();
    await expect(page.getByText('Оплата записана. Остаток обновлён.', { exact: true })).toBeVisible();
    await expect(page.getByText('Оплачено частично', { exact: true })).toBeVisible();
    await expect(page.getByText('25.00 USD', { exact: true })).toBeVisible();
    await expect(page.getByText('75.00 USD', { exact: true })).toBeVisible();

    await page.goto('/admin/financial-obligations');
    await expect(page.getByRole('heading', { name: 'Оплаты', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.clientName);
    await expect(page.getByRole('row').filter({ hasText: fixture.clientName })).toContainText('Оплачено частично');
});

test('staff can create and fully settle a gift certificate through the CRM catalog', async ({ page }) => {
    test.setTimeout(90_000);

    const fixture = createCrmFixture({ giftSaleFlow: true });
    if (fixture.giftProductName === null) {
        throw new Error('The gift certificate CRM fixture did not provide an offering name.');
    }

    await login(page, fixture);

    await page.goto('/admin/services/create');
    await expect(page.getByRole('heading', { name: 'Добавить услугу', exact: true })).toBeVisible();
    await page.getByLabel('Название*', { exact: true }).fill(fixture.giftProductName);
    await page.getByLabel('Тип предложения*', { exact: true }).selectOption('gift_certificate');
    await expect(page.getByText('Цена сертификата одновременно является его номиналом.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Длительность (минуты)', { exact: true })).not.toBeVisible();
    await expect(page.getByLabel('Доступные форматы визита', { exact: true })).not.toBeVisible();
    await page.getByLabel('Краткое описание*', { exact: true }).fill('Сертификат для E2E проверки CRM.');
    await page.getByPlaceholder('15000 или 15000.50', { exact: true }).fill('10000');

    const currency = page.getByRole('combobox', { name: 'Валюта', exact: true });
    await currency.click();
    await page.getByRole('option', { name: 'Доллар США', exact: true }).click();
    await expect(page.getByRole('switch', { name: 'Показывать клиентам*', exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(/\/admin\/services\/\d+\/edit$/, { timeout: 15_000 });
    await page.goto('/admin/services');
    await expect(page.getByRole('heading', { name: 'Каталог услуг', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.giftProductName);
    const giftOfferingRow = page.getByRole('row').filter({ hasText: fixture.giftProductName }).first();
    await expect(giftOfferingRow).toContainText('Подарочный сертификат');

    await page.goto('/admin/gift-certificates');
    await expect(page.getByRole('heading', { name: 'Подарочные сертификаты', exact: true })).toBeVisible();

    await page.getByTestId('sell-gift-certificate').click();
    const saleDialog = page.locator('.fi-modal-window:visible').last();
    await expect(saleDialog).toBeVisible();

    const clientSelect = saleDialog.getByRole('combobox', { name: /^Клиент/ }).first();
    await clientSelect.click();
    await page.getByRole('textbox', { name: 'Search' }).last().fill(fixture.clientName);
    await page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.clientName }).last().click();

    const productSelect = saleDialog.getByRole('combobox', { name: /^Сертификат/ }).first();
    await productSelect.click();
    await page.locator('.fi-select-input-option:visible').filter({ hasText: fixture.giftProductName }).last().click();
    await expect(saleDialog.getByText('10000.00', { exact: true })).toBeVisible();
    await expect(saleDialog.getByText('USD', { exact: true })).toBeVisible();

    await saleDialog.getByRole('button', { name: 'Создать продажу', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/financial-obligations\/\d+$/, { timeout: 15_000 });
    await expect(page.getByText('К оплате', { exact: true })).toBeVisible();
    await expect(page.getByText('Ожидает оплаты', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Записать оплату', exact: true }).click();
    const paymentDialog = page.locator('.fi-modal-window:visible').last();
    await expect(paymentDialog.getByRole('textbox', { name: /^Сумма оплаты/ })).toHaveValue('10000.00');
    await paymentDialog.getByRole('combobox', { name: /^Способ оплаты/ }).selectOption('cash');
    await paymentDialog.getByRole('button', { name: 'Записать оплату', exact: true }).click();

    await expect(page.getByText('Оплачено', { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Сертификат выпущен', { exact: true })).toBeVisible({ timeout: 15_000 });
    const openCertificate = page.getByRole('link', { name: 'Открыть сертификат', exact: true });
    await expect(openCertificate).toBeVisible();
    await openCertificate.click();
    await expect(page).toHaveURL(/\/admin\/gift-certificates\/\d+$/, { timeout: 15_000 });
    await expect(page.getByRole('heading', { name: 'Подарочный сертификат', exact: true })).toBeVisible();

    await page.goto('/admin/gift-certificates');
    await searchTableFor(page, fixture.clientName);
    await expect(page.getByRole('row').filter({ hasText: fixture.clientName })).toHaveCount(1);
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
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ includeMedicalAttachment: false });

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

    await medicalDialog.getByRole('button', { name: 'Добавить боль', exact: true }).click();
    const profilePainName = medicalDialog.getByLabel('Название или описание боли', { exact: true }).last();
    await profilePainName.fill('Боль для проверки шкалы');
    const profilePainScore = medicalDialog.getByRole('slider', { name: 'Оценка боли', exact: true }).last();
    await profilePainScore.press('Home');
    await expect(medicalDialog.getByText('0 / 10', { exact: true })).toBeVisible();
    for (let index = 0; index < 5; index += 1) {
        await profilePainScore.press('ArrowRight');
    }
    await expect(medicalDialog.getByText('5 / 10', { exact: true })).toBeVisible();
    await medicalDialog.getByRole('button', { name: 'Без оценки', exact: true }).last().click();
    await expect(medicalDialog.getByText('Без оценки', { exact: true }).last()).toBeVisible();

    await medicalDialog.getByLabel('Были операции', { exact: true }).check();
    await medicalDialog.getByRole('button', { name: 'Добавить операцию', exact: true }).click();
    const operationItem = medicalDialog.getByRole('textbox', { name: 'Описание*', exact: true }).last().locator('xpath=ancestor::li[1]');
    await operationItem.getByLabel('Год*', { exact: true }).fill('2026');
    await operationItem.getByLabel('Описание*', { exact: true }).fill('Удаление грыжи');
    await operationItem.getByLabel('Описание*', { exact: true }).press('Tab');
    await expect(medicalDialog.getByText('2026 — Удаление грыжи', { exact: true })).toBeVisible();

    await medicalDialog.getByLabel('Были травмы', { exact: true }).check();
    await expect(medicalDialog.getByRole('button', { name: 'Добавить травму', exact: true })).toBeVisible();
    await medicalDialog.getByRole('button', { name: 'Добавить травму', exact: true }).click();
    await expect(medicalDialog.getByText('Новая травма', { exact: true }).last()).toBeVisible();
    const injuryHeader = medicalDialog.getByText('Новая травма', { exact: true }).last();
    const injuryFields = medicalDialog.getByRole('textbox', { name: 'Описание*', exact: true }).last();
    if (await injuryHeader.isVisible() && !await injuryFields.isVisible()) {
        await injuryHeader.click();
    }
    const injuryItem = injuryFields.locator('xpath=ancestor::li[1]');
    await injuryItem.getByLabel('Год*', { exact: true }).fill('2021');
    await injuryItem.getByLabel('Описание*', { exact: true }).fill('Перелом ключицы');
    await injuryItem.getByLabel('Описание*', { exact: true }).press('Tab');
    await expect(medicalDialog.getByText('2021 — Перелом ключицы', { exact: true })).toBeVisible();

    await anamnesis.fill('Запись из клиентского рабочего места');
    await anamnesis.blur();
    await medicalDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(medicalDialog).toBeHidden({ timeout: 15_000 });
    await page.reload();
    await page.getByRole('button', { name: 'Изменить медицинский профиль', exact: true }).click();
    const reopenedMedicalDialog = page.getByRole('dialog', { name: 'Изменить медицинский профиль' });
    await expect(reopenedMedicalDialog.getByRole('textbox', { name: 'Анамнез', exact: true })).toHaveValue('Запись из клиентского рабочего места');
    await expect(reopenedMedicalDialog.getByText('2026 — Удаление грыжи', { exact: true })).toBeVisible();
    await expect(reopenedMedicalDialog.getByText('2021 — Перелом ключицы', { exact: true })).toBeVisible();
    await expect(reopenedMedicalDialog.getByText('Без оценки', { exact: true }).last()).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(reopenedMedicalDialog).toBeHidden();

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
    await expect(selectedFile).toHaveCount(1, { timeout: 15_000 });
    await expect(selectedFile).toBeVisible({ timeout: 15_000 });
    await expect(selectedFile.getByText('Загрузка завершена', { exact: true })).toBeVisible({ timeout: 15_000 });
    const uploadSubmit = uploadDialog.getByRole('button', { name: 'Отправить', exact: true });
    await expect(uploadSubmit).toBeVisible();
    await expect(uploadSubmit).toBeEnabled();
    await expect(uploadDialog.getByText('Ошибка при загрузке', { exact: true })).toBeHidden();
    await uploadSubmit.click();
    await expect(uploadDialog).toBeHidden();
    await expect(page.getByText('Файл загружен', { exact: true })).toBeVisible();

    const attachmentsTable = page.getByRole('table', { name: 'Файлы и МРТ' });
    const uploadedRow = attachmentsTable.getByRole('row').filter({ hasText: 'ux-a-report.pdf' });
    const uploadedFileCell = uploadedRow.getByRole('cell').filter({ hasText: 'ux-a-report.pdf' });
    await expect(attachmentsTable).toBeVisible();
    await expect(uploadedRow).toHaveCount(1);
    await expect(uploadedRow).toBeVisible();
    await expect(uploadedFileCell).toHaveCount(1);
    await expect(uploadedFileCell).toBeVisible();
    await expect(uploadedFileCell).toContainText('ux-a-report.pdf');
    const previewAttachment = uploadedRow.getByRole('button', { name: 'Просмотр', exact: true });
    await expect(previewAttachment).toHaveCount(1);
    const [previewResponse] = await Promise.all([
        page.waitForResponse((response) => {
            const url = new URL(response.url());

            return response.status() === 200
                && url.pathname.startsWith('/admin/attachments/')
                && url.searchParams.get('mode') === 'preview';
        }),
        previewAttachment.click(),
    ]);
    await expect(page.locator('iframe[title="ux-a-report.pdf"]')).toBeVisible();
    expect(previewResponse.headers()['content-type']).toContain('application/pdf');
    expect(previewResponse.headers()['content-disposition']).toContain('inline');
    const previewDialog = page.locator('.fi-modal-window:visible').filter({ has: page.locator('iframe[title="ux-a-report.pdf"]') });
    await expect(previewDialog).toBeVisible();
    await previewDialog.getByRole('button', { name: 'Закрыть', exact: true }).last().click();

    await uploadedRow.getByRole('button', { name: 'Действия', exact: true }).click();
    const attachmentActionsMenu = page.locator('.fi-dropdown-panel:visible').last();
    await expect(attachmentActionsMenu).toBeVisible();
    const downloadAttachment = attachmentActionsMenu.getByRole('button', { name: 'Скачать', exact: true });
    await expect(downloadAttachment).toHaveCount(1);
    const [download, attachmentResponse] = await Promise.all([
        page.waitForEvent('download'),
        page.waitForResponse((response) => {
            const url = new URL(response.url());

            return response.status() === 200
                && url.pathname.startsWith('/admin/attachments/')
                && url.searchParams.get('mode') === 'download';
        }),
        downloadAttachment.click(),
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

    for (const filename of ['ux-b-report.pdf', 'ux-c-report.pdf', 'ux-d-report.pdf', 'ux-e-report.pdf']) {
        await uploadCrmMedicalReport(page, filename);
        await expect(attachmentsTable.getByRole('row').filter({ hasText: filename })).toHaveCount(1);
    }

    await uploadCrmMedicalReport(page, 'ux-sixth-report.pdf', 'limit_rejected');
    await expect(attachmentsTable.getByRole('row').filter({ hasText: 'ux-sixth-report.pdf' })).toHaveCount(0);
    await expect(attachmentsTable.getByRole('row').filter({ hasText: /ux-[a-e]-report\.pdf/ })).toHaveCount(5);
});

test('staff can manage private client audio recordings', async ({ page, request }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}`);
    await page.getByRole('tab', { name: 'Аудиозаписи', exact: true }).click();
    const audioPanel = page.getByRole('tabpanel', { name: /Аудиозаписи/ });
    const audioTable = page.getByRole('table', { name: 'Аудиозаписи' });
    await expect(audioPanel.getByRole('heading', { name: 'Аудиозаписи', exact: true })).toBeVisible();
    await expect(audioPanel.getByRole('heading', { name: 'Аудиозаписей пока нет', exact: true })).toBeVisible();

    for (const index of [1, 2, 3]) {
        await page.getByRole('button', { name: 'Загрузить аудиозапись', exact: true }).click();
        const audioDialog = page.getByRole('dialog').last();
        const title = `Сеанс QA ${index}`;
        const filename = `qa-session-${index}.wav`;
        await audioDialog.getByLabel('Название записи*', { exact: true }).fill(title);
        const audioUploadControl = audioDialog
            .getByRole('group', { name: 'Аудиофайл*', exact: true })
            .locator('label')
            .filter({ hasText: 'Перетащите файлы или выберите', visible: true });
        const [fileChooser] = await Promise.all([
            page.waitForEvent('filechooser'),
            audioUploadControl.click(),
        ]);
        await fileChooser.setFiles({
            name: filename,
            mimeType: 'audio/x-wav',
            buffer: validWavBuffer(),
        });
        const selectedAudio = audioDialog.getByRole('group', { name: filename, exact: true });
        await expect(selectedAudio).toBeVisible({ timeout: 15_000 });
        await expect(selectedAudio.getByText('Загрузка завершена', { exact: true })).toBeVisible({ timeout: 15_000 });
        await audioDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
        await expect(audioDialog).toBeHidden();
        await expect(page.getByText('Аудиозапись загружена', { exact: true })).toBeVisible();
        await expect(audioTable.getByRole('row').filter({ hasText: title })).toHaveCount(1);
    }

    const audioRow = audioTable.getByRole('row').filter({ hasText: 'Сеанс QA 1' });
    await expect(audioTable.getByRole('row').filter({ hasText: /Сеанс QA [123]/ })).toHaveCount(3);
    await expect(audioRow).toBeVisible();
    await audioRow.getByRole('button', { name: 'Воспроизвести', exact: true }).click();
    const playbackDialog = page.locator('.fi-modal-window:visible').last();
    await expect(playbackDialog.locator('audio')).toBeVisible();
    const playbackSource = await playbackDialog.locator('audio').getAttribute('src');
    expect(playbackSource).toMatch(/\/admin\/attachments\//);
    await playbackDialog.getByRole('button', { name: 'Закрыть', exact: true }).last().click();

    if (playbackSource === null) {
        throw new Error('The authorized audio preview did not expose a private URL.');
    }

    const unauthorizedResponse = await request.get(playbackSource, { maxRedirects: 0, timeout: 10_000 });
    expect([302, 401, 403]).toContain(unauthorizedResponse.status());

    const downloadResponseMatcher = (response: { status(): number; url(): string }): boolean => {
        const url = new URL(response.url());

        return response.status() === 200
            && url.pathname.startsWith('/admin/attachments/')
            && url.searchParams.get('mode') === 'download';
    };
    if (test.info().project.name === 'desktop') {
        const [download, downloadResponse] = await Promise.all([
            page.waitForEvent('download'),
            page.waitForResponse(downloadResponseMatcher),
            audioRow.getByRole('button', { name: 'Скачать', exact: true }).click(),
        ]);
        expect(download.suggestedFilename()).toBe('qa-session-1.wav');
        expect(downloadResponse.headers()['content-type']).toContain('audio');
        const downloadPath = await download.path();
        expect(downloadPath).not.toBeNull();
        if (downloadPath === null) {
            throw new Error('The authorized audio download did not produce a file.');
        }
        expect(readFileSync(downloadPath)).toEqual(validWavBuffer());
    } else {
        const downloadResponse = page.waitForResponse(downloadResponseMatcher);
        await audioRow.getByRole('button', { name: 'Скачать', exact: true }).click();
        const response = await downloadResponse;
        expect(response.headers()['content-type']).toContain('audio');
        expect(Number(response.headers()['content-length'])).toBeGreaterThan(0);
        await expect(page).toHaveURL(/\/admin\/attachments\/.+mode=download/, { timeout: 10_000 });
        await page.goto(`/admin/clients/${fixture.clientId}`);
        await page.getByRole('tab', { name: 'Аудиозаписи', exact: true }).click();
    }

    const deletedAudioRow = audioTable.getByRole('row').filter({ hasText: 'Сеанс QA 2' });
    await deletedAudioRow.getByRole('button', { name: 'Удалить', exact: true }).click();
    const deleteConfirmation = page
        .getByRole('alertdialog', { name: 'Удалить', exact: true })
        .getByRole('button', { name: 'Подтвердить', exact: true });
    await expect(deleteConfirmation).toBeVisible();
    await deleteConfirmation.click();
    await expect(deletedAudioRow).toHaveCount(0, { timeout: 15_000 });
    await page.reload();
    await page.getByRole('tab', { name: 'Аудиозаписи', exact: true }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Сеанс QA 1' })).toHaveCount(1);
    await expect(page.getByRole('row').filter({ hasText: 'Сеанс QA 2' })).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: 'Сеанс QA 3' })).toHaveCount(1);

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

test('staff can fill the current create medical result in the CRM client flow', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ clinicalCatalogFlow: true });
    const suffix = fixture.clientName.replace(/^CRM Клиент /, '');
    const testName = `E2E тест ${suffix}`;
    const painName = `CREATE WEBKIT PAIN ${suffix}`;
    const subjective = `CREATE WEBKIT SUBJECTIVE ${suffix}`;
    const posture = `CREATE WEBKIT POSTURE ${suffix}`;
    const manualResult = `CREATE WEBKIT MANUAL ${suffix}`;

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}`);
    await page.getByRole('tab', { name: 'Записи на приём', exact: true }).click();
    await page.getByRole('link', { name: 'Новый сеанс', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create$`));

    await page.getByLabel('Дата и время сеанса').fill('2026-08-19T09:00');
    await page.getByLabel('Специалист').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByText(`${fixture.specialistName} (активен)`, { exact: true }).click();

    const protocolItem = page.locator('.fi-fo-repeater-item').filter({ hasText: testName }).last();
    await expect(protocolItem).toBeVisible();
    await page.getByRole('button', { name: 'Добавить боль', exact: true }).click();
    await page.getByLabel('Название или описание боли', { exact: true }).fill(painName);
    const beforePainScore = page.getByRole('slider', { name: 'До сеанса', exact: true });
    const afterPainScore = page.getByRole('slider', { name: 'После сеанса', exact: true });
    await setLivewireRangeValue(page, beforePainScore, 6);
    const painItem = page.getByLabel('Название или описание боли', { exact: true }).locator('xpath=ancestor::li[1]');
    await painItem.getByRole('button', { name: 'Указать 0', exact: true }).last().click();
    await expect(afterPainScore).toHaveAttribute('aria-valuenow', '0.0');
    await page.getByLabel('Особые жалобы', { exact: true }).fill(subjective);
    await page.getByLabel('Субъективные ощущения клиента после сеанса', { exact: true }).fill(subjective);
    await page.getByLabel('Визуальная диагностика осанки', { exact: true }).fill(posture);

    const beforeTestValue = protocolItem.getByRole('combobox', { name: 'До сеанса', exact: true });
    await beforeTestValue.click();
    await page.getByRole('option', { name: 'Отклонение · нарушение', exact: true }).click();
    const afterTestValue = protocolItem.getByRole('combobox', { name: 'После сеанса', exact: true });
    await afterTestValue.click();
    await page.getByRole('option', { name: 'Норма', exact: true }).click();

    const resultField = page.getByLabel('Результат после сеанса', { exact: true });
    await resultField.fill(manualResult);
    await resultField.blur();
    const fillResultButton = page.getByRole('button', { name: 'Заполнить', exact: true }).last();
    await fillResultButton.click();
    const cancelDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Заменить текст результата?' }).last();
    await expect(cancelDialog).toBeVisible();
    await cancelDialog.getByRole('button', { name: 'Отменить', exact: true }).click();
    await expect(resultField).toHaveValue(manualResult);
    await fillResultButton.click();
    const confirmDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Заменить текст результата?' }).last();
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.getByRole('button', { name: 'Подтвердить', exact: true }).click();
    await expect(resultField).toHaveValue(new RegExp(`${painName}: было 6, стало 0 из 10`));
    await expect(resultField).toHaveValue(new RegExp(`Субъективные ощущения: ${subjective}`));
    await expect(resultField).toHaveValue(new RegExp(`Визуальная диагностика осанки: ${posture}`));
    await expect(resultField).not.toHaveValue(manualResult);
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`), { timeout: 15_000 });
    const sessionRow = page.getByRole('row').filter({ hasText: '19.08.2026' });
    await expect(sessionRow).toBeVisible();
    await sessionRow.getByRole('link', { name: 'Открыть', exact: true }).click({ force: true });
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/\\d+$`), { timeout: 15_000 });
    await expect(page.getByText(new RegExp(`${painName}: было 6, стало 0 из 10`), { exact: false }).first()).toBeVisible();
    await expect(page.getByText(subjective, { exact: true }).first()).toBeVisible();
    await expect(page.getByText(posture, { exact: true }).first()).toBeVisible();
});

test('staff can maintain three independent session pains through save and reload', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture();
    const suffix = fixture.clientName.replace(/^CRM Клиент /, '');
    const painNames = [
        `THREE PAIN ONE ${suffix}`,
        `THREE PAIN TWO ${suffix}`,
        `THREE PAIN THREE ${suffix}`,
    ];

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}`);
    await page.getByRole('tab', { name: 'Записи на приём', exact: true }).click();
    await page.getByRole('link', { name: 'Новый сеанс', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create$`));
    await page.getByLabel('Дата и время сеанса').fill('2026-08-20T09:00');
    await page.getByLabel('Специалист').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByText(`${fixture.specialistName} (активен)`, { exact: true }).click();

    for (let index = 0; index < painNames.length; index += 1) {
        await page.getByRole('button', { name: 'Добавить боль', exact: true }).click();
        await page.waitForLoadState('networkidle');
        await expect(page.getByLabel('Название или описание боли', { exact: true })).toHaveCount(index + 1);
    }
    const painInputs = page.getByLabel('Название или описание боли', { exact: true });
    for (let index = 0; index < painNames.length; index += 1) {
        await painInputs.nth(index).fill(painNames[index]);
        await painInputs.nth(index).blur();
        await expect(painInputs.nth(index)).toHaveValue(painNames[index]);
    }

    const firstPain = page.getByLabel('Название или описание боли', { exact: true }).nth(0).locator('xpath=ancestor::li[1]');
    const firstBefore = firstPain.getByRole('slider', { name: 'До сеанса', exact: true });
    const firstAfter = firstPain.getByRole('slider', { name: 'После сеанса', exact: true });
    await setLivewireRangeValue(page, firstBefore, 7);
    await setLivewireRangeValue(page, firstAfter, 3);
    await firstPain.getByRole('combobox', { name: 'Частота', exact: true }).selectOption('permanent');
    for (let index = 0; index < painNames.length; index += 1) {
        await expect(page.getByLabel('Название или описание боли', { exact: true }).nth(index)).toHaveValue(painNames[index]);
    }

    const thirdPain = page.getByLabel('Название или описание боли', { exact: true }).nth(2).locator('xpath=ancestor::li[1]');
    await expect(thirdPain.getByText('Без оценки', { exact: true }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`), { timeout: 15_000 });
    const sessionRow = page.getByRole('row').filter({ hasText: '20.08.2026' });
    await expect(sessionRow).toBeVisible();
    await sessionRow.getByRole('link', { name: 'Открыть', exact: true }).click({ force: true });
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/\\d+$`), { timeout: 15_000 });
    for (const painName of painNames) {
        await expect(page.getByText(painName, { exact: true })).toBeVisible();
    }

    await page.getByRole('link', { name: 'Редактировать', exact: true }).click();
    const secondPainEdit = page.getByLabel('Название или описание боли', { exact: true }).nth(1).locator('xpath=ancestor::li[1]');
    const secondFrequency = secondPainEdit.getByRole('combobox', { name: 'Частота', exact: true });
    await secondFrequency.click();
    await secondFrequency.press('End');
    await secondFrequency.press('Enter');
    await expect(secondFrequency).toHaveValue('count');
    await secondFrequency.press('Tab');
    await page.waitForTimeout(1_000);
    await expect(secondFrequency).toHaveValue('count');
    const frequencyCount = secondPainEdit.getByRole('spinbutton', { name: /Количество/ });
    await expect(frequencyCount).toBeVisible({ timeout: 10_000 });
    await frequencyCount.fill('2');
    await secondPainEdit.getByRole('combobox', { name: /Период/ }).click();
    await page.getByRole('option', { name: 'неделя', exact: true }).last().click();
    await secondPainEdit.getByRole('checkbox', { name: 'Весь день', exact: true }).uncheck();
    await secondPainEdit.getByRole('checkbox', { name: 'Утро', exact: true }).check();
    await secondPainEdit.getByRole('checkbox', { name: 'Вечер', exact: true }).check();
    await setLivewireRangeValue(page, secondPainEdit.getByRole('slider', { name: 'До сеанса', exact: true }), 4);
    await secondPainEdit.getByRole('button', { name: 'Указать 0', exact: true }).last().click();
    await expect(secondPainEdit.getByText('0 / 10', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`), { timeout: 15_000 });
    await page.getByRole('row').filter({ hasText: '20.08.2026' }).getByRole('link', { name: 'Открыть', exact: true }).click({ force: true });
    await expect(page.getByText('2 раз за неделя', { exact: true })).toBeVisible();
    await expect(page.getByText('Утро, Вечер', { exact: true })).toBeVisible();
    await expect(page.getByText('0/10', { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Редактировать', exact: true }).click();
    const secondPainAfterFrequency = page.getByLabel('Название или описание боли', { exact: true }).nth(1).locator('xpath=ancestor::li[1]');
    await secondPainAfterFrequency.getByRole('button', { name: 'Удалить', exact: true }).first().click();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`), { timeout: 15_000 });
    await page.getByRole('row').filter({ hasText: '20.08.2026' }).getByRole('link', { name: 'Открыть', exact: true }).click({ force: true });
    await expect(page.getByText(painNames[0], { exact: true })).toBeVisible();
    await expect(page.getByText(painNames[1], { exact: true })).toHaveCount(0);
    await expect(page.getByText(painNames[2], { exact: true })).toBeVisible();
});

test('staff can create, view, and edit a client session from the CRM client flow', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ clinicalCatalogFlow: true });
    const suffix = fixture.clientName.replace(/^CRM Клиент /, '');
    const testName = `E2E тест ${suffix}`;
    const causeName = `E2E причина ${suffix}`;
    const causeOptionName = `E2E причины: ${causeName}`;
    const oldPainName = `OLD PAIN ${suffix}`;
    const draftPainName = `DRAFT PAIN UNIQUE ${suffix}`;
    const oldSubjective = `OLD SUBJECTIVE ${suffix}`;
    const draftSubjective = `DRAFT SUBJECTIVE UNIQUE ${suffix}`;
    const oldPosture = `OLD POSTURE ${suffix}`;
    const draftPosture = `DRAFT POSTURE UNIQUE ${suffix}`;
    const oldManualResult = `OLD MANUAL RESULT ${suffix}`;

    await login(page, fixture);

    await page.goto('/admin/clients');
    await expect(page.getByRole('heading', { name: 'База клиентов', exact: true })).toBeVisible();
    await searchTableFor(page, fixture.clientName);

    const clientRow = page.getByRole('row').filter({ hasText: fixture.clientName });
    await clientRow.getByRole('link', { name: fixture.clientName, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}$`));

    await page.goto(`/admin/clients/${fixture.clientId}/sessions`);
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`), { timeout: 15_000 });
    await expect(page.getByRole('heading', { name: 'Сеансы клиента' })).toBeVisible();

    await page.goto(`/admin/clients/${fixture.clientId}`);
    await page.getByRole('tab', { name: 'Записи на приём', exact: true }).click();
    await expect(page.getByText('У клиента пока нет записей на приём.', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Создать запись', exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Новый сеанс', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create$`));

    const assertSessionBlockOrder = async (): Promise<void> => {
        const labels = [
            'Боли',
            'Клинические тесты',
            'Причины по результатам тестов',
            'Первопричина нарушений',
            'Алгоритм сеанса (пошаговый протокол сеанса)',
            'Субъективные ощущения клиента после сеанса',
            'Визуальная диагностика осанки',
            'Результат после сеанса',
            'Фото до / после',
        ];
        const positions: number[] = [];
        for (const label of labels) {
            const box = await page.getByText(label, { exact: true }).last().boundingBox();
            if (box === null) {
                throw new Error(`Medical session block is not positioned: ${label}`);
            }
            positions.push(box.y);
        }
        expect(positions).toEqual([...positions].sort((left, right) => left - right));
        await expect(page.getByLabel('Гипотеза первопричины', { exact: true })).toHaveCount(0);
        await expect(page.getByLabel('Шкала интенсивности боли (ВАШ):', { exact: true })).toHaveCount(0);
    };
    await assertSessionBlockOrder();

    await page.getByLabel('Дата и время сеанса').fill('2026-08-18T09:00');
    await page.getByLabel('Специалист').click();
    await page.getByRole('textbox', { name: 'Search' }).fill(fixture.specialistName);
    await page.getByText(`${fixture.specialistName} (активен)`, { exact: true }).click();
    const protocolItem = page.locator('.fi-fo-repeater-item').filter({ hasText: testName }).last();
    await expect(protocolItem).toBeVisible();
    const protocolTestLabel = protocolItem.locator('input[type="hidden"][wire\\:model*="test_results"][wire\\:model*="test_label"]').first();
    await expect(protocolTestLabel).toHaveValue(/E2E/);
    await page.getByRole('button', { name: 'Добавить боль', exact: true }).click();
    await page.getByLabel('Название или описание боли', { exact: true }).fill(oldPainName);
    const beforePainScore = page.getByRole('slider', { name: 'До сеанса', exact: true });
    await beforePainScore.press('Home');
    for (let index = 0; index < 7; index += 1) {
        await beforePainScore.press('ArrowRight');
    }
    const afterPainScore = page.getByRole('slider', { name: 'После сеанса', exact: true });
    await afterPainScore.press('Home');
    for (let index = 0; index < 3; index += 1) {
        await afterPainScore.press('ArrowRight');
    }
    await expect(page.getByText('7 / 10', { exact: true })).toBeVisible();
    await expect(page.getByText('3 / 10', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Без оценки', exact: true }).first().click();
    await expect(page.getByText('Без оценки', { exact: true }).first()).toBeVisible();
    await setLivewireRangeValue(page, beforePainScore, 7);
    await expect(page.getByText('7 / 10', { exact: true })).toBeVisible();
    await page.getByLabel('Особые жалобы', { exact: true }).fill(oldSubjective);
    const beforeTestValue = protocolItem.getByRole('combobox', { name: 'До сеанса', exact: true });
    await expect(beforeTestValue).toBeVisible();
    await beforeTestValue.click();
    await page.getByRole('option', { name: 'Норма', exact: true }).click();
    const afterTestValue = protocolItem.getByRole('combobox', { name: 'После сеанса', exact: true });
    await afterTestValue.click();
    await page.getByRole('option', { name: 'Отклонение · нарушение', exact: true }).click();
    const testCauseSelect = protocolItem.getByRole('combobox', { name: 'Причины нарушений', exact: true });
    await testCauseSelect.click();
    await page.getByRole('option', { name: causeOptionName, exact: true }).click();
    await expect(page.getByText('Причины по результатам тестов', { exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('option', { name: causeOptionName, exact: true }).last()).toBeHidden();
    const manualCauseButton = page.getByRole('button', { name: 'Изменить список вручную', exact: true });
    await manualCauseButton.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'nearest' }));
    await manualCauseButton.click();
    await expect(page.getByRole('combobox', { name: 'Причины сеанса', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Вернуться к причинам из тестов', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Причины сеанса', exact: true })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Заполнить', exact: true })).toHaveCount(2);
    await page.getByRole('button', { name: 'Добавить первопричину', exact: true }).click();
    const rootCauseSelect = page.getByRole('combobox', { name: 'Причина из сеанса', exact: true });
    await rootCauseSelect.click();
    const rootCauseOption = page.getByRole('option', { name: causeOptionName, exact: true }).last();
    await expect(rootCauseOption).toBeVisible();
    await rootCauseOption.click({ force: true });
    await page.getByLabel('Дополнительное описание', { exact: true }).fill('E2E описание первопричины');
    await expect(page.getByLabel('Гипотеза первопричины', { exact: true })).toHaveCount(0);
    await page.getByLabel('Алгоритм сеанса (пошаговый протокол сеанса)', { exact: true }).fill('E2E протокол');
    await page.getByLabel('Субъективные ощущения клиента после сеанса', { exact: true }).fill(oldSubjective);
    await page.getByLabel('Визуальная диагностика осанки', { exact: true }).fill(oldPosture);
    await page.getByLabel('Результат после сеанса', { exact: true }).fill(oldManualResult);
    const createResultField = page.getByLabel('Результат после сеанса', { exact: true });
    const createFillResultButton = page.getByRole('button', { name: 'Заполнить', exact: true }).last();
    await createFillResultButton.click();
    const createReplaceResultDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Заменить текст результата?' }).last();
    await expect(createReplaceResultDialog).toBeVisible();
    await createReplaceResultDialog.getByRole('button', { name: 'Отменить', exact: true }).click();
    await expect(createReplaceResultDialog).toBeHidden();
    await expect(createResultField).toHaveValue(oldManualResult);
    await createFillResultButton.click();
    const createConfirmResultDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Заменить текст результата?' }).last();
    await expect(createConfirmResultDialog).toBeVisible();
    await createConfirmResultDialog.getByRole('button', { name: 'Подтвердить', exact: true }).click();
    await expect(createResultField).toHaveValue(new RegExp(`${oldPainName}: было 7, стало 3 из 10`));
    await expect(createResultField).toHaveValue(new RegExp(`Субъективные ощущения: ${oldSubjective}`));
    await expect(createResultField).toHaveValue(new RegExp(`Визуальная диагностика осанки: ${oldPosture}`));
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
    const sessionRow = page.getByRole('row').filter({ hasText: '18.08.2026' });
    await expect(sessionRow).toBeVisible();
    const sessionViewLink = sessionRow.getByRole('link', { name: 'Открыть', exact: true });
    await sessionViewLink.click({ force: true });
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/\\d+$`), { timeout: 15_000 });
    await expect(page.getByText(oldPainName, { exact: true })).toBeVisible({ timeout: 15_000 });
    const testNameText = page.getByText(new RegExp(testName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))).last();
    await expect(testNameText).toBeVisible();
    await expect(page.getByText(causeName, { exact: true }).first()).toBeVisible();
    await expect(page.getByText('E2E описание первопричины', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Протокол и результат сеанса').getByText('E2E протокол', { exact: true })).toBeVisible();
    await expect(page.getByText(new RegExp(`${oldPainName}: было 7, стало 3 из 10`), { exact: false }).first()).toBeVisible();
    const dynamics = page.getByLabel('Динамика подтверждённых фактов');
    await expect(dynamics.getByText('Предыдущая запись о боли', { exact: true })).toBeVisible();
    await expect(dynamics.getByText('Предыдущий подтверждённый результат', { exact: true })).toBeVisible();

    if (test.info().project.name !== 'webkit') {
        for (const filename of ['session-before-1.png', 'session-before-2.png', 'session-before-3.png']) {
            await uploadSessionPhoto(page, 'Загрузить фото до сеанса', filename);
        }
        for (const filename of ['session-after-1.png', 'session-after-2.png']) {
            await uploadSessionPhoto(page, 'Загрузить фото после сеанса', filename);
        }
        for (const filename of ['session-before-1.png', 'session-before-2.png', 'session-before-3.png']) {
            const photo = page.getByRole('img', { name: filename, exact: true });
            await expect(photo).toHaveCount(1);
            const photoCard = photo.locator('xpath=ancestor::article[1]');
            await expect(photoCard).toContainText(/\d{2}\.\d{2}\.\d{4}/);
        }
        for (const filename of ['session-after-1.png', 'session-after-2.png']) {
            await expect(page.getByRole('img', { name: filename, exact: true })).toHaveCount(1);
        }
        const previewPhoto = page.getByRole('link', { name: 'Открыть фото session-before-1.png', exact: true });
        const previewPhotoHref = await previewPhoto.getAttribute('href');
        expect(previewPhotoHref).toMatch(/\/admin\/attachments\//);
        if (previewPhotoHref === null) {
            throw new Error('The photo preview did not expose a private URL.');
        }
        const previewPhotoResponse = await page.request.get(previewPhotoHref);
        expect(previewPhotoResponse.status()).toBe(200);
        const photoDownload = previewPhoto.locator('xpath=ancestor::article[1]').getByRole('link', { name: 'Скачать', exact: true });
        const photoDownloadHref = await photoDownload.getAttribute('href');
        expect(photoDownloadHref).toMatch(/mode=download/);
        if (photoDownloadHref === null) {
            throw new Error('The photo download did not expose a private URL.');
        }
        const photoDownloadResponse = await page.request.get(photoDownloadHref);
        expect(photoDownloadResponse.status()).toBe(200);
        await page.getByRole('button', { name: 'Отвязать файл', exact: true }).click();
        const unlinkPhotoDialog = page.getByRole('dialog').last();
        await unlinkPhotoDialog.locator('button.fi-select-input-btn').click();
        await page.getByRole('option', { name: 'session-before-1.png', exact: true }).click();
        await unlinkPhotoDialog.getByRole('button', { name: 'Отвязать', exact: true }).click();
        await expect(page.getByRole('img', { name: 'session-before-1.png', exact: true })).toHaveCount(0);
        await page.reload();
        await expect(page.getByRole('img', { name: 'session-before-1.png', exact: true })).toHaveCount(0);
        await expect(page.getByRole('img', { name: 'session-before-2.png', exact: true })).toHaveCount(1);
        await expect(page.getByRole('img', { name: 'session-before-3.png', exact: true })).toHaveCount(1);
        await expect(page.getByRole('img', { name: 'session-after-1.png', exact: true })).toHaveCount(1);
        await expect(page.getByRole('img', { name: 'session-after-2.png', exact: true })).toHaveCount(1);
    }

    if (test.info().project.name !== 'webkit') {
        await page.getByRole('button', { name: 'Связать файл', exact: true }).click();
        await page.getByLabel('Файл клиента').click();
        await page.getByRole('textbox', { name: 'Search' }).fill(fixture.attachmentFilename);
        await page.getByText(new RegExp(fixture.attachmentFilename), { exact: false }).click();
        await page.getByRole('button', { name: 'Связать', exact: true }).click();
        await expect(page.getByText(fixture.attachmentFilename, { exact: true })).toBeVisible();
    }

    await page.getByRole('link', { name: 'Редактировать', exact: true }).click();
    await assertSessionBlockOrder();
    await expect(page.getByLabel('Субъективные ощущения клиента после сеанса', { exact: true })).toHaveValue(oldSubjective);
    await expect(page.getByLabel('Визуальная диагностика осанки', { exact: true })).toHaveValue(oldPosture);
    await expect(page.getByLabel('Результат после сеанса', { exact: true })).toHaveValue(new RegExp(`${oldPainName}: было 7, стало 3 из 10`));
    await page.getByLabel('Название или описание боли', { exact: true }).fill(draftPainName);
    const draftBeforePainScore = page.getByRole('slider', { name: 'До сеанса', exact: true });
    const draftAfterPainScore = page.getByRole('slider', { name: 'После сеанса', exact: true });
    await setLivewireRangeValue(page, draftBeforePainScore, 2);
    await setLivewireRangeValue(page, draftAfterPainScore, 8);
    const editedProtocolItem = page.locator('.fi-fo-repeater-item').filter({ hasText: testName }).last();
    await expect(editedProtocolItem.getByRole('combobox', { name: 'До сеанса', exact: true })).toContainText('Норма');
    await expect(editedProtocolItem.getByRole('combobox', { name: 'После сеанса', exact: true })).toContainText('Отклонение · нарушение');
    await editedProtocolItem.getByRole('combobox', { name: 'До сеанса', exact: true }).click();
    await page.getByRole('option', { name: 'Отклонение · нарушение', exact: true }).click();
    await editedProtocolItem.getByRole('combobox', { name: 'После сеанса', exact: true }).click();
    await page.getByRole('option', { name: 'Норма', exact: true }).click();
    await page.getByLabel('Субъективные ощущения клиента после сеанса', { exact: true }).fill(draftSubjective);
    await page.getByLabel('Визуальная диагностика осанки', { exact: true }).fill(draftPosture);
    const resultField = page.getByLabel('Результат после сеанса', { exact: true });
    await resultField.fill(oldManualResult);
    await resultField.blur();
    await page.getByRole('button', { name: 'Заполнить результат', exact: true }).click();
    const replaceResultDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Заменить текст результата?' }).last();
    await expect(replaceResultDialog).toBeVisible();
    await expect(replaceResultDialog).toContainText('Текущий текст будет заменён сводкой по данным сеанса.');
    await replaceResultDialog.getByRole('button', { name: 'Отменить', exact: true }).click();
    await expect(replaceResultDialog).toBeHidden();
    await expect(resultField).toHaveValue(oldManualResult);
    await page.getByRole('button', { name: 'Заполнить результат', exact: true }).click();
    const confirmedReplaceResultDialog = page.locator('.fi-modal-window:visible').filter({ hasText: 'Заменить текст результата?' }).last();
    await expect(confirmedReplaceResultDialog).toBeVisible();
    const replaceResultResponse = page.waitForResponse((response) => response.request().method() === 'POST' && /\/livewire[^/]*\/update$/.test(new URL(response.url()).pathname) && response.ok());
    await confirmedReplaceResultDialog.getByRole('button', { name: 'Подтвердить', exact: true }).click();
    await replaceResultResponse;
    await expect(resultField).toHaveValue(new RegExp(`${draftPainName}: было 2, стало 8 из 10`));
    await expect(resultField).toHaveValue(new RegExp(`Субъективные ощущения: ${draftSubjective}`));
    await expect(resultField).toHaveValue(new RegExp(`Визуальная диагностика осанки: ${draftPosture}`));
    await expect(resultField).toHaveValue(/Нарушения по тестам: было 1, стало 0\./);
    await expect(resultField).not.toHaveValue(new RegExp(oldPainName));
    await expect(resultField).not.toHaveValue(new RegExp(oldSubjective));
    await expect(resultField).not.toHaveValue(new RegExp(oldPosture));
    const saveSessionButton = page.getByRole('button', { name: 'Сохранить', exact: true });
    await expect(saveSessionButton).toBeEnabled({ timeout: 30_000 });
    await saveSessionButton.click();

    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`), { timeout: 15_000 });
    const updatedSessionLink = page.getByRole('row').filter({ hasText: '18.08.2026' }).getByRole('link', { name: 'Открыть', exact: true }).last();
    await expect(updatedSessionLink).toBeVisible();
    await expect(updatedSessionLink).toHaveAttribute('href', /\/medical-sessions\/\d+$/);
    await updatedSessionLink.click({ force: true });
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/\\d+$`), { timeout: 30_000 });
    await expect(page.getByText(draftPainName, { exact: true })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(new RegExp(`${draftPainName}: было 2, стало 8 из 10`), { exact: false }).first()).toBeVisible();
    await expect(page.getByLabel('Особые жалобы').getByText(oldSubjective, { exact: true })).toBeVisible();
    await expect(page.getByText(draftSubjective, { exact: true })).toBeVisible();
    await expect(page.getByText(draftPosture, { exact: true })).toBeVisible();
    await expect(page.getByLabel('Протокол и результат сеанса').getByText(/Нарушения по тестам: было 1, стало 0\./, { exact: false }).first()).toBeVisible();
    if (test.info().project.name !== 'webkit') {
        await expect(page.getByText(fixture.attachmentFilename, { exact: true })).toBeVisible();
    }

    await page.getByRole('link', { name: 'К истории сеансов', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/clients/${fixture.clientId}/sessions$`));
});

test('staff can read per-pain history with a persisted zero after score', async ({ page }) => {
    test.setTimeout(60_000);
    const fixture = createCrmFixture({ medicalHistoryFlow: true });
    const historyPainName = fixture.medicalHistoryPainName;
    if (historyPainName === null) {
        throw new Error('Medical history fixture did not return a pain name.');
    }

    await login(page, fixture);
    await page.goto(`/admin/clients/${fixture.clientId}`);
    const historyLabel = page.getByText('История боли', { exact: true });
    await expect(historyLabel).toBeVisible();
    const historySection = historyLabel.locator('xpath=ancestor::section[1]');
    await expect(historySection).toContainText(historyPainName);
    await expect(historySection.getByText(historyPainName, { exact: true })).toHaveCount(2);
    await expect(historySection).toContainText('6');
    await expect(historySection).toContainText('0');
    await expect(historySection).toContainText('5');
    await expect(historySection).toContainText('4');
    await page.reload();
    await expect(page.getByText(historyPainName, { exact: true })).toHaveCount(2);
});

test('staff can maintain the clinical test catalog and use it in a new session', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture();
    const suffix = fixture.clientName.replace(/^CRM Клиент /, '');
    const testName = `E2E настроенный тест ${suffix}`;
    const causeName = `E2E настроенная причина ${suffix}`;

    await login(page, fixture);
    await page.goto('/admin/medical-clinical-catalog-configuration');
    await expect(page.getByRole('heading', { name: 'Клинические справочники', exact: true })).toBeVisible();

    const testNameInputs = page.getByLabel('Название теста*', { exact: true });
    const existingTestCount = await testNameInputs.count();
    await page.getByRole('button', { name: 'Добавить тест', exact: true }).click();
    await expect.poll(async () => testNameInputs.count(), { timeout: 15_000 }).toBeGreaterThanOrEqual(existingTestCount + 1);
    const testNameInput = page.getByLabel('Название теста*', { exact: true }).last();
    await expect(testNameInput).toBeVisible();
    await testNameInput.fill(testName);
    await testNameInput.press('Tab');
    const configuredTestItem = testNameInput.locator('xpath=ancestor::li[1]');
    const sectionInput = configuredTestItem.getByLabel('Раздел', { exact: true });
    await sectionInput.fill('E2E раздел');
    await sectionInput.press('Tab');
    await page.waitForLoadState('networkidle');
    const addValueButton = configuredTestItem.getByRole('button', { name: 'Добавить значение', exact: true });
    await expect(addValueButton).toBeVisible();
    const valueLabels = configuredTestItem.getByLabel('Название значения*', { exact: true });
    for (let attempt = 0; attempt < 3 && (await valueLabels.count()) < 2; attempt += 1) {
        await addValueButton.click();
        try {
            await expect.poll(async () => valueLabels.count(), { timeout: 5_000 }).toBeGreaterThanOrEqual(2);
        } catch (error) {
            if (attempt === 2) {
                throw error;
            }
        }
    }
    await expect.poll(async () => valueLabels.count(), { timeout: 15_000 }).toBeGreaterThanOrEqual(2);
    await valueLabels.nth(0).fill('Норма');
    await valueLabels.nth(0).press('Tab');
    await valueLabels.nth(1).fill('Отклонение');
    await valueLabels.nth(1).press('Tab');
    await configuredTestItem.getByLabel('Считать нарушением', { exact: true }).nth(1).check();
    const activeTestToggle = configuredTestItem.getByLabel('Активен', { exact: true });
    if (! await activeTestToggle.isChecked()) {
        await activeTestToggle.check();
    }
    const activeValueToggles = configuredTestItem.getByLabel('Активно', { exact: true });
    await expect(activeValueToggles).toHaveCount(2);
    for (const toggle of await activeValueToggles.all()) {
        if (! await toggle.isChecked()) {
            await toggle.check();
        }
    }
    await removeEmptyRepeaterItems(page, 'Название значения*');

    const causeNameInputs = page.getByLabel('Название причины*', { exact: true });
    const existingCauseCount = await causeNameInputs.count();
    await page.getByRole('button', { name: 'Добавить причину', exact: true }).click();
    await expect.poll(async () => causeNameInputs.count(), { timeout: 15_000 }).toBeGreaterThanOrEqual(existingCauseCount + 1);
    const causeNameInput = page.getByLabel('Название причины*', { exact: true }).last();
    await causeNameInput.fill(causeName);
    await causeNameInput.press('Tab');
    const configuredCauseItem = causeNameInput.locator('xpath=ancestor::li[1]');
    await configuredCauseItem.getByLabel('Группа', { exact: true }).fill('E2E причины');
    await configuredCauseItem.getByLabel('Группа', { exact: true }).press('Tab');
    const activeCauseToggle = configuredCauseItem.getByLabel('Активна', { exact: true });
    if (! await activeCauseToggle.isChecked()) {
        await activeCauseToggle.check();
    }
    await removeEmptyRepeaterItems(page, 'Название теста*');
    await removeEmptyRepeaterItems(page, 'Название причины*');
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Сохранить каталог', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Каталог сохранён', { timeout: 15_000 });

    await page.reload();
    await expect(page.getByLabel('Название теста*', { exact: true }).last()).toHaveValue(testName);
    await expect(page.getByLabel('Название причины*', { exact: true }).last()).toHaveValue(causeName);

    await page.goto(`/admin/clients/${fixture.clientId}/sessions/medical-sessions/create`);
    await expect(page.getByRole('heading', { name: 'Новый сеанс', exact: true })).toBeVisible();
    const beforeTestValue = page.getByRole('combobox', { name: 'До сеанса', exact: true }).last();
    await expect(beforeTestValue).toBeVisible();
    await beforeTestValue.click();
    const abnormalValueOption = page.getByRole('option').filter({ hasText: 'Отклонение' }).last();
    await expect(abnormalValueOption).toBeVisible();
    await abnormalValueOption.click();
});

test('staff can analyze several dimensions and download a filtered Excel report', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ financeFlow: true, painScoreFlow: true });

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();

    await page.locator('#form\\.period').selectOption('custom');
    await page.locator('#form\\.start_date').fill('2026-10-01');
    await page.locator('#form\\.end_date').fill('2026-10-31');
    const painBasis = page.locator('#form\\.cohort_pain_score_basis');
    await expect(painBasis).toBeVisible();
    await painBasis.selectOption('session_after');
    await page.locator('#form\\.cohort_has_pain_score').selectOption('1');
    await page.getByRole('combobox', { name: 'Страны', exact: true }).click();
    await page.getByRole('option', { name: fixture.painCountry as string, exact: true }).click();
    await expect(page.getByText('Источник оценки боли', { exact: true })).toBeVisible();

    const dimensions = [
        'Проблемы и динамика боли',
        'Источники клиентов',
        'Записи и визиты',
        'Финансы',
        'Удовлетворённость и обратная связь',
        'Цели и задачи',
        'Демография клиентов',
        'Услуги и визиты',
        'Типы покупок',
        'Статусы записей',
        'Категории клиентов',
    ];
    for (const dimension of dimensions) {
        const checkbox = page.getByRole('checkbox', { name: dimension, exact: true });
        await expect(checkbox).toBeVisible();
        if (! await checkbox.isChecked()) {
            await checkbox.check();
        }
    }

    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    for (const dimension of dimensions) {
        await expect(page.getByRole('heading', { name: dimension, exact: true })).toBeVisible();
    }
    const cohortSummary = page.getByText(/Найдено клиентов: \d+ ·/).first();
    await expect(cohortSummary).toBeVisible();
    const cohortCount = Number((await cohortSummary.textContent())?.match(/Найдено клиентов: (\d+)/)?.[1]);
    expect(cohortCount).toBe(1);
    const demographicsSection = page
        .getByRole('heading', { name: 'Демография клиентов', exact: true })
        .locator('xpath=ancestor::section[1]');
    const demographicsSummary = demographicsSection.locator('p').filter({ hasText: /^Клиентов: \d+$/ }).first();
    await expect(demographicsSummary).toBeVisible();
    const demographicsCount = Number((await demographicsSummary.textContent())?.match(/Клиентов: (\d+)/)?.[1]);
    expect(demographicsCount).toBe(cohortCount);

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Скачать Excel', exact: true }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath === null) {
        throw new Error('Analytics export did not produce a file.');
    }
    const workbookBytes = readFileSync(downloadPath);
    expect(workbookBytes.subarray(0, 2).toString()).toBe('PK');
    const workbookXml = execFileSync('unzip', ['-p', downloadPath, 'xl/sharedStrings.xml'], { encoding: 'utf8' });
    expect(workbookXml).toContain('Показатель');
    expect(workbookXml).toContain('Финансы');
    expect(workbookXml).toContain('Источник оценки боли');
    expect(workbookXml).toContain('После сеанса');
    expect(workbookXml).not.toContain('session_after');

    await page.reload();
    await expect(page.locator('#form\\.cohort_pain_score_basis')).toHaveValue('session_after');
    await page.goto('/admin/analytics-console?pain-basis=history');
    await page.goBack();
    await page.goForward();
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await page.locator('#form\\.cohort_pain_score_basis').selectOption('session_before');
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(page.getByText(/Найдено клиентов: 0 ·/).first()).toBeVisible();

    await page.setViewportSize({ width: 390, height: 900 });
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Финансы', exact: true })).toBeVisible();
    await assertNoHorizontalOverflow(page);
    await expect(page.getByRole('button', { name: 'Анализировать', exact: true })).toBeVisible();
});

test('analytics demographics show the known country total in the screen and Excel', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture({ countryCountFlow: true });
    if (fixture.countryCountClientNames === null) {
        throw new Error('Country-count fixture did not create the expected clients.');
    }

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await page.locator('#form\\.period').selectOption('custom');
    await page.locator('#form\\.start_date').fill('2026-10-01');
    await page.locator('#form\\.end_date').fill('2026-10-31');
    const clientSelect = page.getByRole('combobox', { name: 'Конкретные клиенты', exact: true });
    for (const clientName of Object.values(fixture.countryCountClientNames)) {
        await selectAnalyticsOption(page, clientSelect, clientName);
    }
    const demographicsDimension = page.getByRole('checkbox', { name: 'Демография клиентов', exact: true });
    if (! await demographicsDimension.isChecked()) {
        await demographicsDimension.check();
    }
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();

    const demographicsSection = page
        .getByRole('heading', { name: 'Демография клиентов', exact: true })
        .locator('xpath=ancestor::section[1]');
    await expect(demographicsSection.locator('p').filter({ hasText: 'Клиентов: 4' })).toBeVisible();
    await expect(demographicsSection.locator('p').filter({ hasText: 'Стран представлено: 2' })).toBeVisible();
    await expect(demographicsSection.locator('li').filter({ hasText: 'KZ' }).first()).toContainText('2 · 50%');
    await expect(demographicsSection.locator('li').filter({ hasText: 'RU' }).first()).toContainText('1 · 25%');
    await expect(demographicsSection.locator('li').filter({ hasText: 'Не указана' }).first()).toContainText('1 · 25%');

    const countries = page.getByRole('combobox', { name: 'Страны', exact: true });
    await selectAnalyticsOption(page, countries, 'KZ');
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(demographicsSection.locator('p').filter({ hasText: 'Клиентов: 2' })).toBeVisible();
    await expect(demographicsSection).toContainText('Стран представлено: 1');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Скачать Excel', exact: true }).click(),
    ]);
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath === null) {
        throw new Error('Country-count analytics export did not produce a file.');
    }
    const workbookXml = execFileSync('unzip', ['-p', downloadPath, 'xl/sharedStrings.xml'], { encoding: 'utf8' });
    expect(workbookXml).toContain('Всего представлено');
    expect(workbookXml).toContain('Стран представлено');

    await page.reload();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(demographicsSection).toContainText('Стран представлено: 1');
    await page.goto('/admin/analytics-console?country-count=1');
    await page.goBack();
    await page.goForward();
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Демография клиентов', exact: true }).locator('xpath=ancestor::section[1]')).toContainText('Стран представлено: 1');
});

test('analytics problem session axis keeps problem numbering separate from global numbering', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture({ problemAxisFlow: true });

    if (fixture.problemTagName === null) {
        throw new Error('Problem-axis fixture did not create a problem tag.');
    }

    const problemTagOptionName = `E2E ось сеансов: ${fixture.problemTagName}`;

    const selectReportState = async (): Promise<void> => {
        await page.locator('#form\\.period').selectOption('custom');
        await page.locator('#form\\.start_date').fill('2026-10-01');
        await page.locator('#form\\.end_date').fill('2026-10-31');
        const problemCategory = page.getByRole('combobox', { name: 'Категория проблемы', exact: true });
        await problemCategory.click();
        await page.getByRole('textbox', { name: 'Search' }).fill(problemTagOptionName);
        const problemOption = page.getByRole('option', { name: problemTagOptionName, exact: true });
        await expect(problemOption).toBeVisible({ timeout: 15_000 });
        await problemOption.click();
    };

    const painSection = page
        .getByRole('heading', { name: 'Проблемы и динамика боли', exact: true })
        .locator('xpath=ancestor::section[1]');

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await selectReportState();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(painSection).toContainText('Сеанс 1 / 2 / 3 после');
    await expect(painSection.getByRole('row').filter({ hasText: fixture.problemTagName })).toContainText('8 / 4 / 1');

    await page.goto('/admin/analytics-metric-settings');
    const sessionAxis = page.locator('#form\\.session_axis_semantics');
    await expect(sessionAxis).toHaveValue('problem_chronological');
    await sessionAxis.selectOption('client_chronological');
    await page.getByRole('button', { name: 'Сохранить метрики', exact: true }).click();
    await expect(page.getByText('Метрики сохранены', { exact: true })).toBeVisible();
    await page.reload();
    await expect(sessionAxis).toHaveValue('client_chronological');

    await page.goto('/admin/analytics-console');
    await selectReportState();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(painSection).toContainText('Сеанс 1 / 2 / 3 после');
    await expect(painSection.getByRole('row').filter({ hasText: fixture.problemTagName })).toContainText('— / — / —');

    await page.goto('/admin/analytics-metric-settings');
    await sessionAxis.selectOption('problem_chronological');
    await page.getByRole('button', { name: 'Сохранить метрики', exact: true }).click();
    await expect(page.getByText('Метрики сохранены', { exact: true })).toBeVisible();
    await page.reload();
    await expect(sessionAxis).toHaveValue('problem_chronological');

    await page.goto('/admin/analytics-console');
    await selectReportState();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(painSection).toContainText('Сеанс 1 / 2 / 3 после');
    await expect(painSection.getByRole('row').filter({ hasText: fixture.problemTagName })).toContainText('8 / 4 / 1');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Скачать Excel', exact: true }).click(),
    ]);
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath === null) {
        throw new Error('Problem-axis analytics export did not produce a file.');
    }
    const sharedStringsXml = execFileSync('unzip', ['-p', downloadPath, 'xl/sharedStrings.xml'], { encoding: 'utf8' });
    expect(sharedStringsXml).toContain('Порядок сеансов конкретной категории проблемы');
    expect(sharedStringsXml).toContain('problem-chronological-v1');
    const painSheetXml = execFileSync('unzip', ['-p', downloadPath, 'xl/worksheets/sheet4.xml'], { encoding: 'utf8' });
    expect(painSheetXml).toMatch(/<v>8(?:\.0)?<\/v>/);
    expect(painSheetXml).toMatch(/<v>4(?:\.0)?<\/v>/);
    expect(painSheetXml).toMatch(/<v>1(?:\.0)?<\/v>/);

    await page.setViewportSize({ width: 390, height: 900 });
    await assertNoHorizontalOverflow(page);
});

test('analytics service aggregate drilldown intersects the selected service cohort', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture({ aggregateDrilldownFlow: true });

    if (
        fixture.aggregateServiceAName === null
        || fixture.aggregateServiceBName === null
        || fixture.aggregateServiceClientAName === null
        || fixture.aggregateServiceClientBName === null
        || fixture.aggregateServiceClientBothName === null
    ) {
        throw new Error('Aggregate service fixture did not create the expected records.');
    }

    const selectServices = async (): Promise<void> => {
        const services = page.locator('[id="form.cohort_service_ids"]');
        for (const serviceName of [fixture.aggregateServiceAName, fixture.aggregateServiceBName]) {
            await selectAnalyticsOption(page, services, serviceName);
        }
    };

    const selectReport = async (): Promise<void> => {
        await page.locator('#form\\.period').selectOption('custom');
        await page.waitForLoadState('networkidle');
        await page.locator('#form\\.start_date').fill('2026-10-01');
        await page.locator('#form\\.end_date').fill('2026-10-31');
        await selectServices();
        const servicesDimension = page.getByRole('checkbox', { name: 'Услуги и визиты', exact: true });
        if (! await servicesDimension.isChecked()) {
            await servicesDimension.check();
        }
        await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    };

    const servicesSection = page
        .getByRole('heading', { name: 'Услуги и визиты', exact: true })
        .locator('xpath=ancestor::section[1]');
    const cohortSection = page.getByRole('region', { name: 'Клиенты когорты', exact: true });

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await selectReport();

    const serviceARow = servicesSection.locator('li').filter({ hasText: fixture.aggregateServiceAName }).first();
    await serviceARow.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    await expect(cohortSection).toContainText(`Строка отчёта: услуга «${fixture.aggregateServiceAName}».`);
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateServiceClientAName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateServiceClientBothName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateServiceClientBName, exact: true })).toHaveCount(0);

    await cohortSection.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    const serviceBRow = servicesSection.locator('li').filter({ hasText: fixture.aggregateServiceBName }).first();
    await serviceBRow.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    await expect(cohortSection).toContainText(`Строка отчёта: услуга «${fixture.aggregateServiceBName}».`);
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateServiceClientBName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateServiceClientBothName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateServiceClientAName, exact: true })).toHaveCount(0);

    await page.reload();
    await expect(page.getByRole('combobox', { name: 'Услуги', exact: true })).toContainText(fixture.aggregateServiceAName);
    await expect(page.getByRole('combobox', { name: 'Услуги', exact: true })).toContainText(fixture.aggregateServiceBName);
    await page.goto('/admin/analytics-console?aggregate-state=service');
    await page.goBack();
    await page.goForward();
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
});

test('analytics problem aggregate drilldown preserves any mode and intersects selected tags', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture({ aggregateDrilldownFlow: true });

    if (
        fixture.aggregateProblemTagName === null
        || fixture.aggregateBackTagName === null
        || fixture.aggregateProblemClientNeckName === null
        || fixture.aggregateProblemClientBackName === null
        || fixture.aggregateProblemClientBothName === null
    ) {
        throw new Error('Aggregate problem fixture did not create the expected records.');
    }

    const problemCategory = page.locator('[id="form.cohort_problem_tag_ids"]');
    const selectProblems = async (): Promise<void> => {
        for (const problemName of [fixture.aggregateProblemTagName, fixture.aggregateBackTagName]) {
            await selectAnalyticsOption(page, problemCategory, problemName);
        }
    };

    const selectReport = async (): Promise<void> => {
        await page.locator('#form\\.period').selectOption('custom');
        await page.waitForLoadState('networkidle');
        await page.locator('#form\\.start_date').fill('2026-10-01');
        await page.locator('#form\\.end_date').fill('2026-10-31');
        await selectProblems();
        await page.locator('#form\\.cohort_problem_tag_mode').selectOption('any');
        await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    };

    const painSection = page
        .getByRole('heading', { name: 'Проблемы и динамика боли', exact: true })
        .locator('xpath=ancestor::section[1]');
    const cohortSection = page.getByRole('region', { name: 'Клиенты когорты', exact: true });

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await selectReport();

    const neckRow = painSection.getByRole('row').filter({ hasText: fixture.aggregateProblemTagName }).first();
    await neckRow.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    await expect(cohortSection).toContainText(`Строка отчёта: Категория проблемы «${fixture.aggregateProblemTagName}».`);
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateProblemClientNeckName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateProblemClientBothName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateProblemClientBackName, exact: true })).toHaveCount(0);
    await expect(page.locator('#form\\.cohort_problem_tag_mode')).toHaveValue('any');
    await expect(problemCategory).toContainText(fixture.aggregateProblemTagName);
    await expect(problemCategory).toContainText(fixture.aggregateBackTagName);

    await cohortSection.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    const backRow = painSection.getByRole('row').filter({ hasText: fixture.aggregateBackTagName }).first();
    await backRow.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    await expect(cohortSection).toContainText(`Строка отчёта: Категория проблемы «${fixture.aggregateBackTagName}».`);
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateProblemClientBackName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateProblemClientBothName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateProblemClientNeckName, exact: true })).toHaveCount(0);
    await expect(page.locator('#form\\.cohort_problem_tag_mode')).toHaveValue('any');

    await page.reload();
    await expect(page.locator('#form\\.cohort_problem_tag_mode')).toHaveValue('any');
    await page.goto('/admin/analytics-console?aggregate-state=problem');
    await page.goBack();
    await page.goForward();
    await expect(page.locator('#form\\.cohort_problem_tag_mode')).toHaveValue('any');
});

test('analytics demographic missing country aggregate drilldown uses a typed unknown predicate', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture({ aggregateDrilldownFlow: true });

    if (fixture.aggregateMissingCountryClientName === null) {
        throw new Error('Aggregate missing-country fixture did not create the expected client.');
    }

    const selectTargetClient = async (): Promise<void> => {
        const clientSelect = page.getByRole('combobox', { name: 'Конкретные клиенты', exact: true });
        await selectAnalyticsOption(page, clientSelect, fixture.aggregateMissingCountryClientName as string);
    };

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await selectTargetClient();
    const demographicsDimension = page.getByRole('checkbox', { name: 'Демография клиентов', exact: true });
    if (! await demographicsDimension.isChecked()) {
        await demographicsDimension.check();
    }
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();

    const demographicsSection = page
        .getByRole('heading', { name: 'Демография клиентов', exact: true })
        .locator('xpath=ancestor::section[1]');
    await expect(demographicsSection.locator('p').filter({ hasText: 'Стран представлено: 0' })).toBeVisible();
    const countryItem = demographicsSection.locator('li').filter({ hasText: 'Не указана' }).first();
    await expect(countryItem).toBeVisible();
    await countryItem.getByRole('button', { name: 'Показать клиентов', exact: true }).click();

    const cohortSection = page.getByRole('region', { name: 'Клиенты когорты', exact: true });
    await expect(cohortSection).toContainText('Строка отчёта: страна «Не указана».');
    await expect(cohortSection.getByRole('link', { name: fixture.aggregateMissingCountryClientName, exact: true })).toBeVisible();
    await expect(cohortSection).not.toContainText('__unknown__');

    await page.reload();
    await expect(page.getByRole('button', { name: 'Анализировать', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(demographicsSection).toBeVisible();
    await page.setViewportSize({ width: 390, height: 900 });
    await assertNoHorizontalOverflow(page);
    await page.goto('/admin/analytics-console?aggregate-state=demographics');
    await page.goBack();
    await page.goForward();
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
});

test('analytics custom period rejects invalid dates without falling back to the default', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/analytics-console');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();

    const period = page.locator('#form\\.period');
    const startDate = page.locator('#form\\.start_date');
    const endDate = page.locator('#form\\.end_date');
    const analyze = page.getByRole('button', { name: 'Анализировать', exact: true });
    const fieldError = (message: string) => page.locator('.fi-fo-field-wrp-error-message:visible').filter({ hasText: message });

    await period.selectOption('custom');
    await startDate.fill('2026-10-01');
    await endDate.fill('');
    await analyze.click();
    await expect(fieldError('Укажите дату окончания произвольного периода.')).toBeVisible();
    await expect(page.getByText('validation.required_if', { exact: false })).toHaveCount(0);
    await expect(period).toHaveValue('custom');
    await expect(endDate).toHaveValue('');

    await startDate.fill('2026-10-10');
    await endDate.fill('2026-10-01');
    await analyze.click();
    await expect(fieldError('Дата окончания не может быть раньше даты начала.')).toBeVisible();
    await expect(period).toHaveValue('custom');
    await expect(startDate).toHaveValue('2026-10-10');
    await expect(endDate).toHaveValue('2026-10-01');

    await startDate.fill('2024-01-01');
    await endDate.fill('2025-01-02');
    await analyze.click();
    await expect(fieldError('Выберите период не более 366 дней.')).toBeVisible();
    await expect(period).toHaveValue('custom');
    await expect(startDate).toHaveValue('2024-01-01');
    await expect(endDate).toHaveValue('2025-01-02');

    await startDate.fill('2026-10-01');
    await endDate.fill('2026-10-31');
    await analyze.click();
    await expect(page.getByRole('heading', { name: 'Проблемы и динамика боли', exact: true })).toBeVisible();
    await expect(period).toHaveValue('custom');
    await expect(startDate).toHaveValue('2026-10-01');
    await expect(endDate).toHaveValue('2026-10-31');

    await page.reload();
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await expect(period).toHaveValue('custom');
    await expect(startDate).toHaveValue('2026-10-01');
    await expect(endDate).toHaveValue('2026-10-31');
    await analyze.click();
    await expect(page.getByRole('heading', { name: 'Проблемы и динамика боли', exact: true })).toBeVisible();

    const navigateHistory = async (direction: 'back' | 'forward', expectedUrl: RegExp): Promise<void> => {
        try {
            if (direction === 'back') {
                await page.goBack({ waitUntil: 'domcontentloaded' });
            } else {
                await page.goForward({ waitUntil: 'domcontentloaded' });
            }
        } catch (error) {
            if (!String(error).includes('ERR_ABORTED')) {
                throw error;
            }
        }
        await expect(page).toHaveURL(expectedUrl);
    };

    await page.goto('/admin');
    await navigateHistory('back', /\/admin\/analytics-console$/);
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await expect(period).toHaveValue('custom');
    await expect(startDate).toHaveValue('2026-10-01');
    await expect(endDate).toHaveValue('2026-10-31');
    await navigateHistory('forward', /\/admin$/);
    await navigateHistory('back', /\/admin\/analytics-console$/);
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await expect(period).toHaveValue('custom');
    await expect(startDate).toHaveValue('2026-10-01');
    await expect(endDate).toHaveValue('2026-10-31');
    await assertNoHorizontalOverflow(page);
});

test('owner can configure computed metrics and use them in analytics and Excel', async ({ page }) => {
    test.setTimeout(120_000);
    const fixture = createCrmFixture();
    const metricName = `Новые клиенты E2E ${fixture.clientName.slice(-8)}`;
    const renamedMetricName = `${metricName} обновлено`;
    const saveMetricsAndWait = async (): Promise<void> => {
        const saveResponse = page.waitForResponse((response) => response.request().method() === 'POST' && /\/livewire[^/]*\/update$/.test(new URL(response.url()).pathname) && response.ok());
        await page.getByRole('button', { name: 'Сохранить метрики', exact: true }).click();
        await saveResponse;
        await page.waitForTimeout(500);
    };

    await login(page, fixture);
    await page.goto('/admin/analytics-metric-settings');
    await expect(page.getByRole('heading', { name: 'Метрики аналитики', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Снижение боли, %', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Изменение LTV к предыдущему периоду, %', exact: true })).toBeVisible();
    await expect(page.getByText('Описание формулы владельца', { exact: true })).toHaveCount(0);
    await expect(page.getByText('база сравнения не определена', { exact: false })).toHaveCount(0);

    for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalOverflow(page);
        await expect(page.getByRole('button', { name: 'Сохранить метрики', exact: true })).toBeVisible();
    }

    const items = page.locator('.fi-fo-repeater-item');
    const initialItemCount = await items.count();
    await page.getByRole('button', { name: 'Добавить метрику', exact: true }).click();
    await expect.poll(async () => items.count()).toBe(initialItemCount + 1);
    await page.waitForTimeout(1500);
    const customItem = items.last();

    const choose = async (label: RegExp, option: string): Promise<void> => {
        const control = customItem.getByRole('combobox', { name: label }).first();
        if (await control.evaluate((element) => element.tagName === 'SELECT')) {
            await expect.poll(
                async () => (await control.locator('option').allTextContents()).map((text) => text.trim()),
                { timeout: 20_000 },
            ).toContain(option);
            await control.selectOption({ label: option });
            await page.waitForTimeout(750);
            return;
        }

        await control.click();
        const optionLocator = page.getByRole('option', { name: option, exact: true }).last();
        await expect(optionLocator).toBeVisible({ timeout: 20_000 });
        await optionLocator.click();
        await page.waitForTimeout(750);
    };

    await customItem.getByRole('textbox', { name: /^Название/ }).fill(metricName);
    await choose(/^Что считаем/, 'Новые клиенты');
    await choose(/^Агрегация/, 'Количество');
    await choose(/^Операция/, 'A − B');
    await choose(/^Основа сравнения/, 'Фиксированное целевое значение');
    const targetValue = customItem.locator('input[id$=".target_value"]');
    await expect(targetValue).toBeVisible();
    await targetValue.fill('1');
    await targetValue.press('Tab');
    await choose(/^Если данных нет/, 'Не рассчитывать');
    await choose(/^Если знаменатель равен 0/, 'Не рассчитывать');
    await choose(/^Единица/, 'Число');
    await choose(/^Округление/, '0 знаков');
    await expect(targetValue).toHaveValue('1');
    await customItem.getByRole('switch', { name: 'Активна', exact: true }).check();
    await saveMetricsAndWait();

    await page.reload();
    const persistedMetricHeading = page.getByRole('heading', { name: metricName, exact: true });
    await expect(persistedMetricHeading).toBeVisible();
    const persistedItem = persistedMetricHeading.locator('xpath=ancestor::li[contains(@class,"fi-fo-repeater-item")][1]');
    await expect(persistedItem.locator('input[id$=".target_value"]')).toHaveValue('1');

    await page.goto('/admin/analytics-console');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    await page.waitForLoadState('networkidle');
    const customMetricsDimension = page.getByRole('checkbox', { name: 'Вычисляемые метрики', exact: true });
    if (! await customMetricsDimension.isChecked()) {
        const dimensionsUpdate = page.waitForResponse((response) => response.request().method() === 'POST' && /\/livewire[^/]*\/update$/.test(new URL(response.url()).pathname) && response.ok());
        await customMetricsDimension.check();
        await dimensionsUpdate;
    }
    await expect(customMetricsDimension).toBeChecked();
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    const customMetricsSection = page.getByRole('heading', { name: 'Вычисляемые метрики', exact: true }).locator('xpath=ancestor::section[1]');
    await expect(customMetricsSection.getByText(metricName, { exact: true })).toBeVisible();
    await expect(customMetricsSection).toContainText('Число');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Скачать Excel', exact: true }).click(),
    ]);
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    if (downloadPath === null) {
        throw new Error('Computed metrics export did not produce a file.');
    }
    const workbookXml = execFileSync('unzip', ['-p', downloadPath, 'xl/sharedStrings.xml'], { encoding: 'utf8' });
    expect(workbookXml).toContain(metricName);
    expect(workbookXml).toContain('Число');

    await page.goto('/admin/analytics-metric-settings');
    const reopenedItem = page.locator('.fi-fo-repeater-item').last();
    await reopenedItem.getByRole('textbox', { name: /^Название/ }).fill(renamedMetricName);
    await saveMetricsAndWait();
    await page.reload();
    await expect(page.getByRole('heading', { name: renamedMetricName, exact: true })).toBeVisible();

    await page.goto('/admin/analytics-console');
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Вычисляемые метрики', exact: true }).locator('xpath=ancestor::section[1]').getByText(renamedMetricName, { exact: true })).toBeVisible();
    await expect(page.getByText(metricName, { exact: true })).toHaveCount(0);

    await page.goto('/admin/analytics-metric-settings');
    const renamedMetricItem = page.getByRole('heading', { name: renamedMetricName, exact: true }).locator('xpath=ancestor::li[contains(@class,"fi-fo-repeater-item")][1]');
    await renamedMetricItem.getByRole('button', { name: 'Удалить', exact: true }).click();
    await saveMetricsAndWait();
    await page.reload();
    await expect(page.getByRole('heading', { name: renamedMetricName, exact: true })).toHaveCount(0);
    await page.goto('/admin/analytics-console');
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Вычисляемые метрики', exact: true }).locator('xpath=ancestor::section[1]').getByText(renamedMetricName, { exact: true })).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 900 });
    await assertNoHorizontalOverflow(page);
    await page.setViewportSize({ width: 390, height: 900 });
    await assertNoHorizontalOverflow(page);
    await page.setViewportSize({ width: 768, height: 900 });
    await assertNoHorizontalOverflow(page);
    await page.setViewportSize({ width: 1440, height: 900 });
});

test('staff can maintain analytics taxonomy groups in CRM', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ taxonomyFlow: true });
    const groupName = `E2E группа ${fixture.clientName.replace(/^CRM Клиент /, '')}`;
    const renamedGroupName = `${groupName} обновлена`;

    await login(page, fixture);
    await page.goto('/admin/analytics-taxonomy-groups');
    await expect(page.getByRole('heading', { name: 'Группы аналитики', exact: true })).toBeVisible();

    const problemSection = page.locator('.fi-section').filter({ hasText: 'Проблемы и боли' }).first();
    const groupNameInputs = problemSection.getByRole('textbox', { name: 'Название группы*', exact: true });
    let groupIndex = -1;
    for (let index = 0; index < await groupNameInputs.count(); index += 1) {
        if ((await groupNameInputs.nth(index).inputValue()) === groupName) {
            groupIndex = index;
            break;
        }
    }
    expect(groupIndex).toBeGreaterThanOrEqual(0);
    await groupNameInputs.nth(groupIndex).fill(renamedGroupName);
    const activeToggle = problemSection.getByRole('switch', { name: 'Активна', exact: true }).nth(groupIndex);
    if (await activeToggle.isChecked()) {
        await activeToggle.uncheck();
    }
    await page.getByRole('button', { name: 'Сохранить группы', exact: true }).click();
    await expect(page.getByText('Группы аналитики сохранены', { exact: true })).toBeVisible();

    await page.reload();
    const reopenedProblemSection = page.locator('.fi-section').filter({ hasText: 'Проблемы и боли' }).first();
    const reopenedNameInputs = reopenedProblemSection.getByRole('textbox', { name: 'Название группы*', exact: true });
    let reopenedGroupIndex = -1;
    for (let index = 0; index < await reopenedNameInputs.count(); index += 1) {
        if ((await reopenedNameInputs.nth(index).inputValue()) === renamedGroupName) {
            reopenedGroupIndex = index;
            break;
        }
    }
    expect(reopenedGroupIndex).toBeGreaterThanOrEqual(0);
    await expect(reopenedProblemSection.getByRole('switch', { name: 'Активна', exact: true }).nth(reopenedGroupIndex)).not.toBeChecked();
    await expect(page.locator('html')).toBeVisible();
    await assertNoHorizontalOverflow(page);
});

test('staff can review synthetic analytics tag candidates in CRM', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ tagCandidateFlow: true });

    if (
        fixture.candidateLabel === null
        || fixture.staleCandidateLabel === null
        || fixture.tagCandidatePainName === null
        || fixture.tagCandidateUnrelatedClientName === null
    ) {
        throw new Error('Tag candidate fixture did not create the expected candidates.');
    }

    await login(page, fixture);
    await page.goto('/admin/analytics-tag-candidates');
    await expect(page.getByRole('heading', { name: 'Кандидаты тегов', exact: true })).toBeVisible();

    const candidateItemFor = async (label: string): Promise<Locator> => {
        const inputs = page.getByRole('textbox', { name: 'Предложение', exact: true });
        for (let attempt = 0; attempt < 60; attempt += 1) {
            for (let index = 0; index < await inputs.count(); index += 1) {
                if (await inputs.nth(index).inputValue() === label) {
                    return inputs.nth(index).locator('xpath=ancestor::li[1]');
                }
            }

            await page.waitForTimeout(250);
        }

        throw new Error(`Candidate ${label} was not rendered.`);
    };

    const candidateItem = await candidateItemFor(fixture.candidateLabel);
    await expect(candidateItem).toBeVisible();
    await expect(candidateItem.getByRole('textbox', { name: 'Категория', exact: true })).toHaveValue('Жалобы и проблемы');
    const decision = candidateItem.getByRole('combobox', { name: 'Решение', exact: true });
    await decision.selectOption('accept');
    const reviewedLabel = candidateItem.getByLabel('Итоговое название', { exact: true });
    await expect(reviewedLabel).toBeVisible();
    await reviewedLabel.fill(`${fixture.candidateLabel} принято`);
    await page.getByRole('button', { name: 'Сохранить решения', exact: true }).click();
    await expect(page.getByText('Решения по кандидатам сохранены', { exact: true })).toBeVisible();
    await expect.poll(async () => {
        const inputs = page.getByRole('textbox', { name: 'Предложение', exact: true });
        for (let index = 0; index < await inputs.count(); index += 1) {
            if (await inputs.nth(index).inputValue() === fixture.candidateLabel) {
                return true;
            }
        }

        return false;
    }).toBe(false);

    await page.goto(`/admin/clients/${fixture.clientId}`);
    await expect(page.getByRole('heading', { name: fixture.clientName, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Доступ к трекеру', exact: true }).click();
    await page.getByText('Принять категории боли', { exact: true }).click();
    const acceptPainDialog = page.locator('.fi-modal-window:visible').last();
    await expect(acceptPainDialog).toBeVisible();
    await acceptPainDialog.getByRole('combobox', { name: /^Боль клиента/ }).click();
    await page.getByRole('option', { name: fixture.tagCandidatePainName, exact: true }).click();
    await expect(acceptPainDialog.getByRole('combobox', { name: 'Категории проблемы', exact: true })).toContainText(`${fixture.candidateLabel} принято`);
    await acceptPainDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Категории боли сохранены', { exact: true })).toBeVisible();
    await page.reload();

    await page.goto('/admin/analytics-console');
    await expect(page.getByRole('heading', { name: 'Консоль аналитики', exact: true })).toBeVisible();
    const problemCategories = page.getByRole('combobox', { name: 'Категории проблем', exact: true });
    await problemCategories.click();
    await expect(page.getByRole('option', { name: `${fixture.candidateLabel} принято`, exact: true })).toBeVisible();
    await page.getByRole('option', { name: `${fixture.candidateLabel} принято`, exact: true }).click();
    const problemDimension = page.getByRole('checkbox', { name: 'Проблемы и динамика боли', exact: true });
    if (! await problemDimension.isChecked()) {
        await problemDimension.check();
    }
    await page.getByRole('button', { name: 'Анализировать', exact: true }).click();
    const cohortSection = page.getByRole('region', { name: 'Клиенты когорты', exact: true });
    await expect(cohortSection).toContainText('Найдено клиентов: 1');
    await cohortSection.getByRole('button', { name: 'Показать клиентов', exact: true }).click();
    await expect(cohortSection.getByRole('link', { name: fixture.clientName, exact: true })).toBeVisible();
    await expect(cohortSection.getByRole('link', { name: fixture.tagCandidateUnrelatedClientName, exact: true })).toHaveCount(0);

    await page.goto('/admin/analytics-tag-candidates');
    const staleItem = await candidateItemFor(fixture.staleCandidateLabel);
    await expect(staleItem).toBeVisible();
    const staleDecision = staleItem.getByRole('combobox', { name: 'Решение', exact: true });
    await staleDecision.selectOption('accept');
    await staleItem.getByLabel('Итоговое название', { exact: true }).fill(`${fixture.staleCandidateLabel} принято`);
    await page.getByRole('button', { name: 'Сохранить решения', exact: true }).click();
    await expect(page.getByText('Источник кандидата уже устарел. Запустите классификацию повторно.', { exact: true })).toBeVisible();
    await expect(staleItem.getByRole('textbox', { name: 'Предложение', exact: true })).toHaveValue(fixture.staleCandidateLabel);
    await page.setViewportSize({ width: 390, height: 900 });
    await assertNoHorizontalOverflow(page);
});

test('automatic analytics suggestions follow a real source save into NeedsReview', async ({ page }) => {
    test.setTimeout(120_000);
    const candidateLabelSuffix = `E2E Auto Suggest ${Date.now()}`;
    const providerStub = await startAnalyticsProviderStub(candidateLabelSuffix);
    let fixture: CrmFixture | null = null;

    const findPainInput = async (dialog: Locator, currentValue: string): Promise<Locator> => {
        const inputs = dialog.getByLabel('Название или описание боли', { exact: true });
        await expect(inputs).toHaveCount(2);
        for (let index = 0; index < await inputs.count(); index += 1) {
            if (await inputs.nth(index).inputValue() === currentValue) {
                return inputs.nth(index);
            }
        }

        throw new Error(`Medical profile pain ${currentValue} was not rendered.`);
    };

    const findPendingCandidate = async (label: string): Promise<Locator> => {
        const inputs = page.getByRole('textbox', { name: 'Предложение', exact: true });
        for (let attempt = 0; attempt < 60; attempt += 1) {
            for (let index = 0; index < await inputs.count(); index += 1) {
                if (await inputs.nth(index).inputValue() === label) {
                    return page.locator('.fi-fo-repeater-item').filter({ has: inputs.nth(index) }).first();
                }
            }

            await page.waitForTimeout(250);
        }

        throw new Error(`Automatic candidate ${label} was not rendered.`);
    };

    try {
        fixture = createCrmFixture();
        const automaticFixture = configureAutomaticAnalyticsFixture(fixture, analyticsProviderStubPort(providerStub), candidateLabelSuffix);
        const automaticSourceValue = `${automaticFixture.sourcePainName} changed through CRM`;
        const disabledSourceValue = `${automaticFixture.disabledSourcePainName} changed through CRM`;

        await login(page, fixture);
        await page.goto(`/admin/clients/${fixture.clientId}`);
        await page.getByRole('button', { name: 'Изменить медицинский профиль', exact: true }).click();
        const profileDialog = page.getByRole('dialog', { name: 'Изменить медицинский профиль' });
        await (await findPainInput(profileDialog, automaticFixture.sourcePainName)).fill(automaticSourceValue);
        await profileDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
        await expect(profileDialog).toBeHidden({ timeout: 15_000 });

        await runAnalyticsClassificationWorker();

        await page.goto('/admin/analytics-tag-candidates');
        const enabledState = readAutomaticAnalyticsState(automaticFixture.sourcePainId, candidateLabelSuffix);
        expect(enabledState.runStatus, JSON.stringify(enabledState)).toBe('needs_review');
        expect(enabledState.candidateStatus).toBe('needs_review');
        expect(enabledState.activeTagCount).toBe(0);
        await expect(page.getByRole('heading', { name: 'Кандидаты тегов', exact: true })).toBeVisible();
        await expect(page.getByText('Ожидают проверки', { exact: true })).toBeVisible();
        const pendingCandidate = await findPendingCandidate(candidateLabelSuffix);
        await expect(pendingCandidate).toBeVisible();
        await expect(pendingCandidate.getByRole('textbox', { name: 'Категория', exact: true })).toHaveValue('Жалобы и проблемы');
        await expect(pendingCandidate).toContainText('medical_profile_pain');
        await expect(pendingCandidate).toContainText(`ID ${automaticFixture.sourcePainId}`);
        await page.reload();
        await expect(page.getByText('Ожидают проверки', { exact: true })).toBeVisible();
        await expect(await findPendingCandidate(candidateLabelSuffix)).toBeVisible();

        setAutomaticAnalyticsSuggestions(false);
        await page.goto(`/admin/clients/${fixture.clientId}`);
        await page.getByRole('button', { name: 'Изменить медицинский профиль', exact: true }).click();
        const disabledProfileDialog = page.getByRole('dialog', { name: 'Изменить медицинский профиль' });
        await (await findPainInput(disabledProfileDialog, automaticFixture.disabledSourcePainName)).fill(disabledSourceValue);
        await disabledProfileDialog.getByRole('button', { name: 'Отправить', exact: true }).click();
        await expect(disabledProfileDialog).toBeHidden({ timeout: 15_000 });

        await runAnalyticsClassificationWorker();

        await page.goto('/admin/analytics-tag-candidates');
        await expect.poll(async () => {
            const items = page.locator('.fi-fo-repeater-item');
            for (let index = 0; index < await items.count(); index += 1) {
                const item = items.nth(index);
                const itemText = await item.innerText();
                if (await item.getByRole('textbox', { name: 'Предложение', exact: true }).inputValue() === candidateLabelSuffix
                    && itemText.includes('medical_profile_pain')
                    && itemText.includes(`ID ${automaticFixture.disabledSourcePainId}`)) {
                    return true;
                }
            }

            return false;
        }).toBe(false);
        const disabledState = readAutomaticAnalyticsState(automaticFixture.disabledSourcePainId, candidateLabelSuffix);
        expect(disabledState.runStatus).toBeNull();
        expect(disabledState.candidateStatus).toBeNull();
        expect(disabledState.activeTagCount).toBe(0);
    } finally {
        if (fixture !== null) {
            setAutomaticAnalyticsSuggestions(false);
        }
        await closeAnalyticsProviderStub(providerStub);
    }
});

test('staff can maintain saved client groups in CRM', async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = createCrmFixture({ savedGroupFlow: true });
    const seededGroupName = `E2E persisted group ${fixture.clientName.replace(/^CRM Клиент /, '')}`;
    const groupName = `E2E сохранённая группа ${fixture.clientName.replace(/^CRM Клиент /, '')}`;
    const renamedGroupName = `${groupName} обновлена`;

    await login(page, fixture);
    await page.goto('/admin/client-cohort-groups');
    await expect(page.getByRole('heading', { name: 'Группы клиентов', exact: true })).toBeVisible();

    const repeaterItems = page.locator('.fi-fo-repeater-item');
    await page.getByRole('button', { name: 'Добавить группу', exact: true }).click();
    await expect(repeaterItems).toHaveCount(2);
    const firstItem = repeaterItems.last();
    await firstItem.getByRole('combobox', { name: /^Тип/ }).selectOption('manual');
    await page.waitForTimeout(500);
    await firstItem.getByRole('textbox', { name: /^Название/ }).fill(groupName);
    await page.getByRole('button', { name: 'Сохранить группы', exact: true }).click();
    await expect(page.getByText('Группы клиентов сохранены', { exact: true })).toBeVisible();

    await page.reload();
    const groupItems = page.locator('.fi-fo-repeater-item');
    const groupItemFor = async (name: string): Promise<Locator> => {
        const names = page.getByRole('textbox', { name: /^Название/ });
        for (let index = 0; index < await names.count(); index += 1) {
            if (await names.nth(index).inputValue() === name) {
                return groupItems.nth(index);
            }
        }

        throw new Error(`Saved group ${name} was not rendered.`);
    };

    const createdItem = await groupItemFor(groupName);
    await createdItem.getByRole('textbox', { name: /^Название/ }).fill(renamedGroupName);
    await page.getByRole('button', { name: 'Сохранить группы', exact: true }).click();
    await expect(page.getByText('Группы клиентов сохранены', { exact: true })).toBeVisible();

    await page.reload();
    await expect(await groupItemFor(renamedGroupName)).toBeVisible();
    const seededItem = await groupItemFor(seededGroupName);
    await seededItem.getByRole('textbox', { name: /^Название/ }).fill(renamedGroupName);
    await page.getByRole('button', { name: 'Сохранить группы', exact: true }).click();
    await expect(page.getByText('В этой организации уже есть группа с таким названием.', { exact: true })).toBeVisible();
    await expect(await groupItemFor(renamedGroupName)).toBeVisible();

    await seededItem.getByRole('textbox', { name: /^Название/ }).fill(`${seededGroupName} исправлена`);
    await page.getByRole('button', { name: 'Сохранить группы', exact: true }).click();
    await expect(page.getByText('Группы клиентов сохранены', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('textbox', { name: /^Название/ })).toHaveCount(2);
    await expect(page.locator('html')).toBeVisible();
    await assertNoHorizontalOverflow(page);
});

test('staff can review human attribution defaults and aliases in CRM', async ({ page }) => {
    const fixture = createCrmFixture();

    await login(page, fixture);
    await page.goto('/admin/attribution-source-settings');
    await expect(page.getByRole('heading', { name: 'Источники клиентов', exact: true })).toBeVisible();
    await expect(page.getByText('Какие значения считать этим источником', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Например, для Instagram сюда можно добавить: instagram, ig, insta.', { exact: false }).first()).toBeVisible();

    const sourceInputs = page.getByRole('textbox', { name: 'Название*', exact: true });
    let instagramIndex = -1;
    for (let index = 0; index < await sourceInputs.count(); index += 1) {
        if ((await sourceInputs.nth(index).inputValue()) === 'Instagram') {
            instagramIndex = index;
            break;
        }
    }
    expect(instagramIndex).toBeGreaterThanOrEqual(0);
    const instagramItem = sourceInputs.nth(instagramIndex).locator('xpath=ancestor::li[1]');
    const aliasInputs = instagramItem.getByRole('textbox', { name: 'Вариант названия / ключ*', exact: true });
    await expect(aliasInputs).toHaveCount(3);
    await expect(aliasInputs.nth(0)).toHaveValue('instagram');
    await expect(aliasInputs.nth(1)).toHaveValue('ig');
    await expect(aliasInputs.nth(2)).toHaveValue('insta');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Источники клиентов', exact: true })).toBeVisible();
    await assertNoHorizontalOverflow(page);
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
            await expect(navigationSidebar).toHaveClass(/fi-sidebar-open/);
        }

        await expect(targetLink).toBeVisible();
        await expect(targetLink).toBeEnabled();
        await targetLink.scrollIntoViewIfNeeded();
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
