import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { expect, test, type Locator, type Page, type Response } from '@playwright/test';

type BookingFixture = {
    clientId: number;
    cookieName: string;
    cookieValue: string;
    serviceId: number;
    serviceName: string;
    specialistId: number;
    specialistName: string;
    alternateSpecialistName: string | null;
    firstWorkingLocationName: string | null;
    firstWorkingLocationAddress: string | null;
    secondWorkingLocationId: number | null;
    secondWorkingLocationName: string | null;
    date: string;
    homeVisitDate: string | null;
    bookingId: number | null;
};

type BookingFixtureOptions = {
    daysAhead?: number;
    confirmedName?: boolean;
    roadmap?: boolean;
    medicalService?: boolean;
    withBooking?: boolean;
    confirmedOfficeBooking?: boolean;
    bookingWithinCancellationCutoff?: boolean;
    onlineMeeting?: boolean;
    withCompanionMessages?: boolean;
    withCompanionFailure?: boolean;
    withServiceCredit?: boolean;
    withPartnerRewards?: boolean;
    withCompanionPending?: boolean;
    multipleChoices?: boolean;
    multipleLocations?: boolean;
    longServiceTitle?: boolean;
    homeVisit?: boolean;
    withServiceImage?: boolean;
    tracker?: 'empty' | 'task' | 'checkins';
    duplicateOfficeLocation?: boolean;
};

type FamilyAcceptanceFixture = {
    cookieName: string;
    cookieValue: string;
    patientId: number;
    adultClientId: number;
};

function createBookingFixture(options: BookingFixtureOptions | boolean = false): BookingFixture {
    const normalizedOptions = typeof options === 'boolean' ? { withBooking: options } : options;
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        $suffix = \\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(12));
        $multipleChoices = getenv('PLAYWRIGHT_MULTIPLE_CHOICES') === '1';
        $multipleLocations = getenv('PLAYWRIGHT_MULTIPLE_LOCATIONS') === '1';
        $onlineMeeting = getenv('PLAYWRIGHT_ONLINE_MEETING') === '1';
        $confirmedOfficeBooking = getenv('PLAYWRIGHT_CONFIRMED_OFFICE_BOOKING') === '1';
        $longServiceTitle = getenv('PLAYWRIGHT_LONG_SERVICE_TITLE') === '1';
        $homeVisit = getenv('PLAYWRIGHT_HOME_VISIT') === '1';
        $withServiceImage = getenv('PLAYWRIGHT_WITH_SERVICE_IMAGE') === '1';
        $withCompanionMessages = getenv('PLAYWRIGHT_WITH_COMPANION_MESSAGES') === '1';
        $withPartnerRewards = getenv('PLAYWRIGHT_WITH_PARTNER_REWARDS') === '1';
        $withServiceCredit = getenv('PLAYWRIGHT_WITH_SERVICE_CREDIT') === '1';
        $withCompanionPending = getenv('PLAYWRIGHT_WITH_COMPANION_PENDING') === '1';
        $withCompanionFailure = getenv('PLAYWRIGHT_WITH_COMPANION_FAILURE') === '1';
        $confirmedName = getenv('PLAYWRIGHT_CONFIRMED_NAME') === '1';
        $roadmap = getenv('PLAYWRIGHT_ROADMAP') === '1';
        \\App\\Modules\\Organizations\\Domain\\Models\\OrganizationFeatureFlag::query()->upsert([
            [
                'organization_id' => $organization->getKey(),
                'feature_key' => 'service_catalog',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organization_id' => $organization->getKey(),
                'feature_key' => 'client_records',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['organization_id', 'feature_key'], ['enabled', 'updated_at']);
        foreach ([
            'offer' => 'offer_consent',
            'privacy' => 'privacy_consent',
            'medical_disclaimer' => 'medical_consent',
        ] as $documentType => $purpose) {
            $publishedDocumentExists = \\App\\Modules\\Identity\\Domain\\Models\\LegalDocument::query()
                ->where('organization_id', $organization->getKey())
                ->where('document_type', $documentType)
                ->where('status', \\App\\Modules\\Identity\\Domain\\Enums\\LegalDocumentStatus::Published)
                ->exists();
            if (! $publishedDocumentExists) {
                app(\\App\\Modules\\Identity\\Application\\PublishLegalDocument::class)->handle(
                    app(\\App\\Modules\\Identity\\Application\\CreatePlatformLegalDocumentDraft::class)->handle(
                        organization: $organization,
                        documentType: $documentType,
                        purpose: $purpose,
                        locale: 'ru',
                        version: '2026-09-03-'.$documentType,
                        content: 'Synthetic legal fixture.',
                        isRequired: true,
                    ),
                );
            }
        }
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Playwright Client '.$suffix,
            'email' => 'playwright-'.$suffix.'@example.test',
            'first_name' => 'Playwright',
            'last_name' => 'Client '.$suffix,
            'gender' => 'MALE',
            'birth_date' => '1990-02-28',
            'country' => 'Казахстан',
            'city' => 'Алматы',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        if ($confirmedName) {
            $client->forceFill(['name_confirmed_at' => now()])->save();
        }
        if ($roadmap) {
            $run = new \\App\\Modules\\AI\\Domain\\Models\\AiRun;
            $run->forceFill([
                'organization_id' => $organization->getKey(),
                'capability' => \\App\\Modules\\AI\\Domain\\Enums\\AiCapability::ClinicalSynthesizer,
                'workflow_key' => \\App\\Modules\\AI\\Domain\\Enums\\ClinicalSynthesizerWorkflow::CourseReport->value,
                'origin' => \\App\\Modules\\AI\\Domain\\Enums\\AiRunOrigin::User,
                'execution_mode' => \\App\\Modules\\AI\\Domain\\Enums\\AiExecutionMode::Async,
                'client_id' => $client->getKey(),
                'status' => \\App\\Modules\\AI\\Domain\\Enums\\AiRunStatus::Succeeded,
                'human_review_status' => \\App\\Modules\\AI\\Domain\\Enums\\HumanReviewStatus::Accepted,
                'input_references' => [],
                'context_provenance' => [],
                'token_usage' => [],
                'finished_at' => now(),
            ]);
            $run->save();
            $reportPayload = json_encode(['course_summary' => 'Synthetic accepted report fixture.'], JSON_THROW_ON_ERROR);
            $payload = new \\App\\Modules\\AI\\Domain\\Models\\AiRunPayload;
            $payload->forceFill([
                'organization_id' => $organization->getKey(),
                'ai_run_id' => $run->getKey(),
                'encryption_key_version' => 1,
                'encrypted_output_payload' => app(\\App\\Modules\\MedicalProfiles\\Domain\\Contracts\\MedicalEncryptorInterface::class)
                    ->encryptField($organization->getKey(), $reportPayload, 1),
            ]);
            $payload->save();

            $surveyDefinition = new \\App\\Modules\\Surveys\\Domain\\Models\\SurveyDefinition;
            $surveyDefinition->forceFill([
                'organization_id' => $organization->getKey(),
                'definition_key' => 'playwright-before-after-'.$suffix,
                'title' => 'Synthetic comparison',
                'is_available' => true,
            ]);
            $surveyDefinition->save();
            $surveyVersion = new \\App\\Modules\\Surveys\\Domain\\Models\\SurveyVersion;
            $surveyVersion->forceFill([
                'organization_id' => $organization->getKey(),
                'survey_definition_id' => $surveyDefinition->getKey(),
                'version' => 1,
                'status' => \\App\\Modules\\Surveys\\Domain\\Enums\\SurveyVersionStatus::Published,
                'title' => 'Сравнение До и После',
                'definition' => [],
                'scoring' => ['metrics' => [[
                    'key' => 'pain',
                    'label' => ['ru' => 'Боль', 'en' => 'Pain'],
                    'direction' => 'lower_is_better',
                ]]],
                'source' => 'platform_default',
                'approval_status' => 'approved',
                'published_at' => now()->subDays(30),
            ]);
            $surveyVersion->save();
            $attempts = [];
            foreach ([7, 3] as $index => $pain) {
                $completedAt = $index === 0 ? now()->subDays(30) : now();
                $attempt = new \\App\\Modules\\Surveys\\Domain\\Models\\SurveyAttempt;
                $attempt->forceFill([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'survey_definition_id' => $surveyDefinition->getKey(),
                    'survey_version_id' => $surveyVersion->getKey(),
                    'status' => \\App\\Modules\\Surveys\\Domain\\Enums\\SurveyAttemptStatus::Completed,
                    'definition_snapshot' => [],
                    'answers_snapshot' => [],
                    'scoring_snapshot' => ['metrics' => [[
                        'key' => 'pain',
                        'label' => ['ru' => 'Боль', 'en' => 'Pain'],
                        'direction' => 'lower_is_better',
                        'value' => $pain,
                    ]]],
                    'result_snapshot' => [],
                    'started_at' => $completedAt->copy()->subMinutes(10),
                    'completed_at' => $completedAt,
                ]);
                $attempt->save();
                $attempts[] = $attempt;
            }
            $comparison = new \\App\\Modules\\Surveys\\Domain\\Models\\SurveyComparison;
            $comparison->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'previous_attempt_id' => $attempts[0]->getKey(),
                'current_attempt_id' => $attempts[1]->getKey(),
                'status' => 'improved',
                'comparison_snapshot' => [
                    'configuration' => ['operator' => 'numeric_value'],
                    'metrics' => ['pain' => ['before' => 7, 'after' => 3, 'delta' => -4]],
                ],
            ]);
            $comparison->save();
        }
        if (getenv('PLAYWRIGHT_TRACKER') !== '') {
            \\Illuminate\\Support\\Facades\\Queue::fake();
            app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
            $trackerAdmin = \\App\\Models\\User::factory()->forOrganization($organization)->create();
            $trackerVersion = app(\\App\\Modules\\Tracker\\Application\\SaveTrackerPlan::class)->handle(
                $trackerAdmin, null, 'Synthetic Tracker '.$suffix, true, true, '10.00', 'USD', 30, null, true, 1,
            );
            app(\\App\\Modules\\Tracker\\Application\\GrantPaidTrackerAccess::class)->handle($organization, $client, $trackerVersion);
            if (getenv('PLAYWRIGHT_TRACKER') === 'task') {
                app(\\App\\Modules\\Tracker\\Application\\AssignTrackerTask::class)->handle(
                    $trackerAdmin, $client, 'Практика QA',
                    \\App\\Modules\\Tracker\\Domain\\Enums\\TrackerTaskType::Practice,
                    \\App\\Modules\\Tracker\\Domain\\Enums\\TrackerTaskFrequency::Daily,
                    \\Carbon\\CarbonImmutable::now('UTC')->startOfDay(),
                );
            }
            if (getenv('PLAYWRIGHT_TRACKER') === 'checkins') {
                $checkIn = new \\App\\Modules\\Tracker\\Domain\\Models\\TrackerCheckIn;
                $checkIn->forceFill([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'occurred_at' => now()->subDay(),
                    'note' => 'Синтетическая заметка о самочувствии.',
                    'source' => 'portal',
                ]);
                $checkIn->save();
            }
        }
        $specialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Playwright Specialist '.$suffix,
            'timezone' => 'UTC',
        ]);
        if ($withPartnerRewards || $withServiceCredit) {
            $rewardAdmin = \\App\\Models\\User::factory()->forOrganization($organization)->create();
            app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
            app(\\App\\Modules\\Finance\\Application\\SaveCurrencyConfiguration::class)->handle($rewardAdmin, [
                'base_currency' => 'USD',
                'display_currency' => 'USD',
                'allowed_currencies' => ['USD'],
                'force_single_currency' => true,
                'rounding_mode' => 'half_up',
            ]);
            app(\\App\\Modules\\Referrals\\Application\\SaveReferralRewardProgram::class)->handle(
                actor: $rewardAdmin,
                enabled: true,
                qualificationRule: 'first_settled_payment',
                formula: 'fixed_amount',
                fixedAmount: '10.00',
                fixedCurrency: 'USD',
                percentage: null,
                effectiveAt: \\Carbon\\CarbonImmutable::now()->subMinute(),
            );
            if ($withPartnerRewards) {
                app(\\App\\Modules\\Referrals\\Application\\ActivateReferralPartner::class)->handle($client, 'crm', $rewardAdmin);
            }
            $referred = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
                'full_name' => 'Playwright Referred '.$suffix,
                'email' => 'playwright-referred-'.$suffix.'@example.test',
                'language' => 'ru',
                'timezone' => 'UTC',
            ]);
            $relationship = new \\App\\Modules\\Referrals\\Domain\\Models\\ReferralRelationship;
            $relationship->forceFill([
                'organization_id' => $organization->getKey(),
                'referrer_client_id' => $client->getKey(),
                'referred_client_id' => $referred->getKey(),
                'establishment_method' => 'manual_crm',
                'registered_at' => now(),
            ]);
            $relationship->save();
            $amountMinor = 10000;
            $rewardService = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
                'name' => 'Playwright Reward Service '.$suffix,
                'formats' => ['office'],
                'price_minor' => $amountMinor,
                'price_currency' => 'USD',
            ]);
            $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
                ->forOrganization($organization)
                ->forClient($referred)
                ->forSpecialist($specialist)
                ->forService($rewardService)
                ->create();
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
                'service_id' => $rewardService->getKey(),
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
                'creation_key' => 'playwright-partner-reward-'.$suffix,
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
                'idempotency_key' => 'playwright-partner-reward-entry-'.$suffix,
                'created_at' => now(),
            ]);
            $entry->save();
            app(\\App\\Modules\\Finance\\Application\\RecordFinancialSettlementEvent::class)->handle($obligation, $entry, $entry->occurred_at);
            $event = \\App\\Modules\\Integration\\Domain\\Models\\IntegrationEvent::query()
                ->where('organization_id', $organization->getKey())
                ->where('aggregate_id', $obligation->getKey())
                ->firstOrFail();
            app(\\App\\Modules\\Referrals\\Application\\ConsumeFinanceSettlementEvent::class)->handle($event->getKey());
        }
        $alternateSpecialist = null;
        if ($multipleChoices) {
            $alternateSpecialist = \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::factory()->forOrganization($organization)->create([
                'display_name' => 'Playwright Alternate Specialist '.$suffix,
                'timezone' => 'UTC',
            ]);
        }
        if ($withCompanionMessages || $withCompanionPending || $withCompanionFailure) {
            config()->set('medical.keys.1', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
            app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
            if ($withCompanionFailure) {
                \\Illuminate\\Support\\Facades\\Queue::fake();
                $failedTurn = app(\\App\\Modules\\ClientCompanion\\Application\\Actions\\AcceptCompanionMessage::class)->handle(
                    client: $client,
                    channel: 'portal',
                    body: 'Повторите исходный запрос без копирования.',
                    idempotencyKey: 'playwright-failure-'.$suffix,
                    originExternalId: 'portal:playwright-failure-'.$suffix,
                    locale: 'ru',
                );
                $conversation = $failedTurn->conversation()->firstOrFail();
                $failureMessage = app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class)->handle(
                    organizationId: $organization->getKey(),
                    client: $client,
                    conversation: $conversation,
                    channel: 'portal',
                    direction: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Outbound,
                    authorType: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Ai,
                    body: 'Ответ не удалось проверить. Попробуйте ещё раз или позовите специалиста.',
                    contextEpoch: $failedTurn->context_epoch,
                    metadata: [
                        'message_type' => 'terminal_failure',
                        'locale' => 'ru',
                        'transport' => 'portal',
                        'safe_actions' => 'retry_failed_turn,request_human',
                    ],
                );
                $failedTurn->update([
                    'status' => \\App\\Modules\\ClientCompanion\\Domain\\Enums\\CompanionTurnStatus::Failed,
                    'failure_code' => \\App\\Modules\\ClientCompanion\\Domain\\Enums\\CompanionFailureCode::InvalidOutput,
                    'outbound_message_id' => $failureMessage->getKey(),
                    'failed_at' => now(),
                ]);
                \\App\\Modules\\ClientCompanion\\Domain\\Models\\CompanionTurnAttempt::query()->create([
                    'organization_id' => $organization->getKey(),
                    'turn_id' => $failedTurn->getKey(),
                    'attempt_number' => 1,
                    'execution_key' => 'playwright-failure-'.$suffix,
                    'status' => \\App\\Modules\\ClientCompanion\\Domain\\Enums\\CompanionTurnAttemptStatus::Failed,
                    'failure_code' => \\App\\Modules\\ClientCompanion\\Domain\\Enums\\CompanionFailureCode::InvalidOutput,
                    'output_message_id' => $failureMessage->getKey(),
                    'completed_at' => now(),
                ]);
            } else {
                $conversation = \\App\\Modules\\Conversations\\Domain\\Models\\Conversation::factory()
                    ->forOrganization($organization)
                    ->forClient($client)
                    ->create([
                        'conversation_type' => \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationType::ClientCompanion,
                    ]);
            }
            $fence = str_repeat(chr(96), 3);
            $body = "# Безопасный ответ\n\n**Важная информация** и _пояснение_.\n\n- Первый пункт\n- Второй пункт\n\n[Безопасная HTTPS ссылка](https://example.test/secure)\n[HTTP ссылка не должна быть активной](http://example.test/insecure)\n[javascript ссылка не должна быть активной](javascript:alert(1))\n[data ссылка не должна быть активной](data:text/html,unsafe)\n[file ссылка не должна быть активной](file:///tmp/unsafe)\n[Относительная ссылка не должна быть активной](//example.test/insecure)\n[userinfo ссылка не должна быть активной](https://user:pass@example.test/insecure)\n\n".$fence."\n".str_repeat('TOKEN', 1400)."\n".$fence."\n\n<script>alert('unsafe')</script> https://example.test/".str_repeat('long-segment-', 80);
            if ($withCompanionMessages) {
                app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class)->handle(
                    organizationId: $organization->getKey(),
                    client: $client,
                    conversation: $conversation,
                    channel: 'portal',
                    direction: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Outbound,
                    authorType: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Ai,
                    body: $body,
                );
            }
            if ($withCompanionPending) {
                foreach (range(1, 8) as $historyIndex) {
                    app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class)->handle(
                        organizationId: $organization->getKey(),
                        client: $client,
                        conversation: $conversation,
                        channel: 'portal',
                        direction: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Outbound,
                        authorType: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Ai,
                        body: str_repeat('Synthetic history message '.$historyIndex.' remains visible while scrolling. ', 12),
                    );
                }
                $pendingInbound = app(\\App\\Modules\\Conversations\\Application\\RecordCompanionMessage::class)->handle(
                    organizationId: $organization->getKey(),
                    client: $client,
                    conversation: $conversation,
                    channel: 'portal',
                    direction: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationDirection::Inbound,
                    authorType: \\App\\Modules\\Conversations\\Domain\\Enums\\ConversationAuthorType::Client,
                    body: 'Synthetic pending companion message',
                    metadata: ['locale' => 'ru', 'transport' => 'portal'],
                );
                \\App\\Modules\\ClientCompanion\\Domain\\Models\\CompanionTurn::query()->create([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'conversation_id' => $conversation->getKey(),
                    'sequence' => ((int) \\App\\Modules\\ClientCompanion\\Domain\\Models\\CompanionTurn::query()->where('conversation_id', $conversation->getKey())->max('sequence')) + 1,
                    'context_epoch' => $conversation->context_epoch,
                    'inbound_message_id' => $pendingInbound->getKey(),
                    'origin_channel' => 'portal',
                    'idempotency_key' => 'playwright-pending-'.$suffix,
                    'request_hash' => hash('sha256', $suffix),
                    'status' => 'pending',
                    'input_modality' => 'text',
                    'image_reference_mode' => 'none',
                    'accepted_at' => now(),
                ]);
            }
        }
        $service = \\App\\Modules\\Services\\Domain\\Models\\Service::factory()->forOrganization($organization)->create([
            'name' => $longServiceTitle
                ? 'Индивидуальная консультация по глубоким и устойчивым изменениям в жизни '.$suffix
                : 'Playwright Service '.$suffix,
            'formats' => $homeVisit ? ['home'] : (($multipleChoices || $onlineMeeting) ? ['office', 'online'] : ['office']),
            'image_path' => $withServiceImage ? 'brand/chuklov-designer-logo-ru.jpg' : null,
            'requires_medical_profile' => getenv('PLAYWRIGHT_MEDICAL_SERVICE') === '1',
        ]);
        \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistServiceAssignment::factory()
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        if ($alternateSpecialist !== null) {
            \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistServiceAssignment::factory()
                ->forSpecialist($alternateSpecialist)
                ->forService($service)
                ->create();
        }
        foreach (array_filter([$specialist, $alternateSpecialist]) as $availableSpecialist) {
            foreach (range(1, 7) as $weekday) {
                \\App\\Modules\\Scheduling\\Domain\\Models\\SpecialistWorkingHour::factory()
                    ->forSpecialist($availableSpecialist)
                    ->create([
                        'weekday' => $weekday,
                        'start_time' => '09:00',
                        'end_time' => $homeVisit ? '20:00' : '12:00',
                    ]);
            }
        }
        $homeVisitDate = null;
        if ($homeVisit) {
            $homeVisitDate = \\Carbon\\CarbonImmutable::now('UTC')->addDays(3)->toDateString();
            \\App\\Modules\\Scheduling\\Domain\\Models\\LocationDay::factory()
                ->forOrganization($organization)
                ->forDate($homeVisitDate)
                ->create([
                    'start_time' => '09:00',
                    'end_time' => '20:00',
                    'timezone' => 'UTC',
                ]);
        }
        $defaultLocation = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_default_office', true)
            ->first();
        if ($defaultLocation === null) {
            $defaultLocation = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::factory()
                ->forOrganization($organization)
                ->defaultOffice()
                ->create([
                    'name' => 'Кабинет Алматы '.$suffix,
                    'address' => 'ул. Абая, 10',
                    'timezone' => 'UTC',
                ]);
        } else {
            $defaultLocation->update(['is_active' => true]);
        }
        \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::query()
            ->where('organization_id', $organization->getKey())
            ->whereKeyNot($defaultLocation->getKey())
            ->update(['is_active' => false]);
        $workingLocations = [$defaultLocation];
        if ($multipleLocations) {
            $workingLocations[] = \\App\\Modules\\Scheduling\\Domain\\Models\\WorkingLocation::factory()
                ->forOrganization($organization)
                ->create([
                    'name' => 'Berlin Mitte '.$suffix,
                    'address' => 'Invalidenstraße 1, Berlin',
                    'timezone' => 'Europe/Berlin',
                ]);
        }
        $date = \\Carbon\\CarbonImmutable::now('UTC')->addDays((int) getenv('PLAYWRIGHT_BOOKING_DAYS_AHEAD'))->toDateString();
        $booking = null;
        if (getenv('PLAYWRIGHT_WITH_BOOKING') === '1') {
            app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
            if ($onlineMeeting) {
                $start = \\Carbon\\CarbonImmutable::now('UTC')->addDays(3)->setTime(9, 0);
                $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
                    ->forOrganization($organization)
                    ->forClient($client)
                    ->forSpecialist($specialist)
                    ->forService($service)
                    ->create([
                        'status' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingStatus::Confirmed->value,
                        'source' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingSource::Portal->value,
                        'visit_format' => \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Online->value,
                        'starts_at' => $start,
                        'ends_at' => $start->addHour(),
                        'blocking_ends_at' => $start->addHour(),
                        'schedule_timezone' => 'UTC',
                        'client_timezone' => 'UTC',
                        'meeting_link_mode' => \\App\\Modules\\Scheduling\\Domain\\Enums\\MeetingLinkMode::Manual->value,
                        'meeting_url' => 'https://example.invalid/qa-meeting',
                        'requested_at' => now(),
                    ]);
            } elseif (getenv('PLAYWRIGHT_BOOKING_WITHIN_CANCELLATION_CUTOFF') === '1') {
                $cutoffMinutes = (int) (\\Illuminate\\Support\\Facades\\DB::table('organization_settings')
                    ->where('organization_id', $organization->getKey())
                    ->where('setting_key', 'booking_cancellation_cutoff_minutes')
                    ->value('integer_value') ?? config('scheduling.booking_cancellation_cutoff_minutes'));
                $start = \\Carbon\\CarbonImmutable::now('UTC')->addMinutes(max(1, $cutoffMinutes - 60));
                $booking = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::factory()
                    ->forOrganization($organization)
                    ->forClient($client)
                    ->forSpecialist($specialist)
                    ->forService($service)
                    ->create([
                        'status' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingStatus::Requested->value,
                        'source' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingSource::Portal->value,
                        'visit_format' => \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office->value,
                        'starts_at' => $start,
                        'ends_at' => $start->addHour(),
                        'blocking_ends_at' => $start->addHour(),
                        'schedule_timezone' => 'UTC',
                        'client_timezone' => 'UTC',
                        'requested_at' => now(),
                    ]);
            } else {
                $date = \\Carbon\\CarbonImmutable::now('UTC')->addDays(3)->toDateString();
                $booking = app(\\App\\Modules\\Scheduling\\Application\\CreateBooking::class)->handle(
                    actor: $client,
                    client: $client,
                    specialist: $specialist,
                    service: $service,
                    startsAt: \\Carbon\\CarbonImmutable::parse($date.' 09:00:00', 'UTC'),
                    format: \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office,
                    idempotencyKey: 'playwright-'.$suffix,
                );
                if ($confirmedOfficeBooking) {
                    $booking->forceFill([
                        'status' => \\App\\Modules\\Scheduling\\Domain\\Enums\\BookingStatus::Confirmed->value,
                    ])->save();
                }
            }
        }
        if ($booking !== null && getenv('PLAYWRIGHT_DUPLICATE_OFFICE_LOCATION') === '1') {
            $booking->forceFill([
                'visit_format' => \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office->value,
                'location' => 'Bangkok',
                'location_snapshot' => [
                    'type' => \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office->value,
                    'name' => 'Бангкок',
                    'address' => 'Bangkok',
                    'timezone' => 'Asia/Bangkok',
                ],
            ])->save();
        }
        $sessionId = \\Illuminate\\Support\\Str::random(40);
        $sessionData = json_encode([
            '_token' => \\Illuminate\\Support\\Str::random(40),
            'client_portal' => ['client_id' => $client->getKey()],
        ], JSON_THROW_ON_ERROR);
        $sessionPayload = config('session.encrypt')
            ? app('encrypter')->encrypt($sessionData)
            : $sessionData;
        \\Illuminate\\Support\\Facades\\DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Playwright',
            'payload' => base64_encode($sessionPayload),
            'last_activity' => time(),
        ]);
        $cookieName = (string) config('session.cookie');
        $encrypter = app('encrypter');
        $cookieValue = $encrypter->encrypt(
            \\Illuminate\\Cookie\\CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId,
            false,
        );
        echo json_encode([
            'cookieName' => $cookieName,
            'cookieValue' => $cookieValue,
            'clientId' => $client->getKey(),
            'serviceId' => $service->getKey(),
            'serviceName' => $service->name,
            'specialistId' => $specialist->getKey(),
            'specialistName' => $specialist->display_name,
            'alternateSpecialistName' => $alternateSpecialist?->display_name,
            'firstWorkingLocationName' => ($workingLocations[0] ?? null)?->name,
            'firstWorkingLocationAddress' => ($workingLocations[0] ?? null)?->address,
            'secondWorkingLocationId' => ($workingLocations[1] ?? null)?->getKey(),
            'secondWorkingLocationName' => ($workingLocations[1] ?? null)?->name,
            'date' => $date,
            'homeVisitDate' => $homeVisitDate,
            'bookingId' => $booking?->getKey(),
        ]);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-${process.pid}`;
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
                PLAYWRIGHT_CONFIRMED_NAME: normalizedOptions.confirmedName ? '1' : '0',
                PLAYWRIGHT_ROADMAP: normalizedOptions.roadmap ? '1' : '0',
                PLAYWRIGHT_WITH_BOOKING: normalizedOptions.withBooking ? '1' : '0',
                PLAYWRIGHT_CONFIRMED_OFFICE_BOOKING: normalizedOptions.confirmedOfficeBooking ? '1' : '0',
                PLAYWRIGHT_ONLINE_MEETING: normalizedOptions.onlineMeeting ? '1' : '0',
                PLAYWRIGHT_BOOKING_WITHIN_CANCELLATION_CUTOFF: normalizedOptions.bookingWithinCancellationCutoff ? '1' : '0',
                PLAYWRIGHT_MEDICAL_SERVICE: normalizedOptions.medicalService ? '1' : '0',
                PLAYWRIGHT_BOOKING_DAYS_AHEAD: String(normalizedOptions.daysAhead ?? 1),
                PLAYWRIGHT_TRACKER: normalizedOptions.tracker ?? '',
                PLAYWRIGHT_WITH_COMPANION_MESSAGES: normalizedOptions.withCompanionMessages ? '1' : '0',
                PLAYWRIGHT_WITH_PARTNER_REWARDS: normalizedOptions.withPartnerRewards ? '1' : '0',
                PLAYWRIGHT_WITH_COMPANION_PENDING: normalizedOptions.withCompanionPending ? '1' : '0',
                PLAYWRIGHT_WITH_COMPANION_FAILURE: normalizedOptions.withCompanionFailure ? '1' : '0',
                PLAYWRIGHT_WITH_SERVICE_CREDIT: normalizedOptions.withServiceCredit ? '1' : '0',
                PLAYWRIGHT_MULTIPLE_CHOICES: normalizedOptions.multipleChoices ? '1' : '0',
                PLAYWRIGHT_MULTIPLE_LOCATIONS: normalizedOptions.multipleLocations ? '1' : '0',
                PLAYWRIGHT_LONG_SERVICE_TITLE: normalizedOptions.longServiceTitle ? '1' : '0',
                PLAYWRIGHT_HOME_VISIT: normalizedOptions.homeVisit ? '1' : '0',
                PLAYWRIGHT_WITH_SERVICE_IMAGE: normalizedOptions.withServiceImage ? '1' : '0',
                PLAYWRIGHT_DUPLICATE_OFFICE_LOCATION: normalizedOptions.duplicateOfficeLocation ? '1' : '0',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (error) {
        const details = error as { stderr?: Buffer; stdout?: Buffer };
        throw new Error(`${details.stderr?.toString() ?? ''}${details.stdout?.toString() ?? ''}`);
    }
    const json = output.trim().split('\n').at(-1) ?? '';

    return JSON.parse(json) as BookingFixture;
}

function createFamilyAcceptanceFixture(ownerClientId: number): FamilyAcceptanceFixture {
    const php = `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $owner = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()
            ->where('organization_id', $organization->getKey())->findOrFail(${ownerClientId});
        $patient = \\App\\Modules\\Identity\\Domain\\Models\\PatientProfile::query()
            ->where('organization_id', $organization->getKey())->where('owner_client_id', $owner->getKey())
            ->where('relationship', 'CHILD')->latest('id')->firstOrFail();
        $invitation = \\App\\Modules\\Identity\\Domain\\Models\\PatientInvitation::query()
            ->where('organization_id', $organization->getKey())->where('patient_profile_id', $patient->getKey())
            ->whereNull('revoked_at')->whereNull('accepted_at')->latest('id')->firstOrFail();
        $adult = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create([
            'full_name' => $patient->clinicalClient->full_name,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'gender' => $patient->gender,
            'birth_date' => $patient->birth_date->toDateString(),
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        $invitation = app(\\App\\Modules\\Identity\\Application\\BindPatientInvitationToAccount::class)
            ->handle($invitation->encrypted_token, $adult);
        $sessionId = \\Illuminate\\Support\\Str::random(40);
        $sessionData = json_encode([
            '_token' => \\Illuminate\\Support\\Str::random(40),
            'client_portal' => ['client_id' => $adult->getKey()],
            'patient_invitation_id' => $invitation->getKey(),
        ], JSON_THROW_ON_ERROR);
        $sessionPayload = config('session.encrypt')
            ? app('encrypter')->encrypt($sessionData)
            : $sessionData;
        \\Illuminate\\Support\\Facades\\DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Playwright Family QA',
            'payload' => base64_encode($sessionPayload),
            'last_activity' => time(),
        ]);
        $cookieName = (string) config('session.cookie');
        $cookieValue = app('encrypter')->encrypt(
            \\Illuminate\\Cookie\\CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$sessionId,
            false,
        );
        echo json_encode([
            'cookieName' => $cookieName,
            'cookieValue' => $cookieValue,
            'patientId' => $patient->getKey(),
            'adultClientId' => $adult->getKey(),
        ]);
    `;
    const psyshConfigDirectory = `/tmp/chuklov-playwright-family-${process.pid}`;
    mkdirSync(psyshConfigDirectory, { recursive: true });
    const output = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        encoding: 'utf8',
        env: {
            ...process.env,
            APP_ENV: 'e2e',
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
    const json = output.trim().split('\n').at(-1) ?? '';

    return JSON.parse(json) as FamilyAcceptanceFixture;
}

async function assertNoHorizontalOverflow(page: Page): Promise<void> {
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function assertHealthActionHeadingLayout(healthAction: Locator): Promise<void> {
    const metrics = await healthAction.evaluate((element) => {
        const heading = element.querySelector<HTMLElement>('.portal-health-action__heading');
        const icon = heading?.children.item(0);
        const title = heading?.querySelector<HTMLElement>('.portal-health-action__title');
        const badge = heading?.querySelector<HTMLElement>('.portal-health-action__badge');

        if (!heading || !icon || !title || !badge) {
            return null;
        }

        const bounds = (target: Element) => {
            const rect = target.getBoundingClientRect();

            return { left: rect.left, right: rect.right, top: rect.top };
        };

        return {
            card: bounds(element),
            heading: bounds(heading),
            icon: bounds(icon),
            title: bounds(title),
            badge: bounds(badge),
        };
    });

    if (!metrics) {
        throw new Error('The Health Action heading is missing its icon, title or badge.');
    }

    expect(metrics.icon.left).toBeGreaterThanOrEqual(metrics.card.left + 15);
    expect(metrics.icon.right).toBeLessThan(metrics.title.left);
    expect(metrics.title.right).toBeLessThanOrEqual(metrics.badge.left);
    expect(metrics.badge.right).toBeLessThanOrEqual(metrics.heading.right + 1);
    expect(metrics.badge.top).toBeLessThanOrEqual(metrics.heading.top + 2);
}

async function assertPortalResponseAccepted(responsePromise: Promise<Response>, action: string): Promise<void> {
    const response = await responsePromise;

    if (response.status() < 400) {
        return;
    }

    const body = await response.text();
    let details = body.replace(/\s+/g, ' ').trim().slice(0, 400);

    try {
        const payload = JSON.parse(body) as { errors?: unknown; message?: unknown };
        const errorFields = payload.errors !== null && typeof payload.errors === 'object'
            ? Object.keys(payload.errors as Record<string, unknown>)
            : [];
        const message = typeof payload.message === 'string' ? payload.message : null;
        details = JSON.stringify({ message, errorFields });
    } catch (error) {
        if (!(error instanceof SyntaxError)) {
            throw error;
        }
    }

    throw new Error(`${action} returned HTTP ${response.status()} (${response.url()}): ${details}`);
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

async function acceptRequiredConsents(page: Page): Promise<void> {
    const checkbox = page.getByRole('checkbox', {
        name: /ознакомился.*обязательными документами/i,
    });

    await expect(checkbox).toHaveCount(1);
    await checkbox.check();
}

async function assertReadableProgress(page: Page, expectedCount: number): Promise<void> {
    const labels = page.locator('.portal-booking-progress__label');

    await expect(labels).toHaveCount(expectedCount);
    expect(await labels.evaluateAll((elements) => elements.every((element) => {
        const styles = window.getComputedStyle(element);
        const bounds = element.getBoundingClientRect();

        return element.textContent?.trim() !== ''
            && element.scrollWidth <= element.clientWidth + 1
            && element.scrollHeight <= element.clientHeight + 1
            && bounds.left >= -1
            && bounds.right <= window.innerWidth + 1
            && styles.overflowX !== 'hidden';
    }))).toBe(true);
}

async function assertFullSelectedServiceTitle(page: Page, serviceName: string, testId = 'booking-selection-service'): Promise<void> {
    const service = page.getByTestId(testId);

    await expect(service).toHaveText(serviceName);
    expect(await service.evaluate((element) => {
        const styles = window.getComputedStyle(element);

        return element.scrollWidth <= element.clientWidth + 1
            && styles.whiteSpace !== 'nowrap'
            && styles.textOverflow !== 'ellipsis';
    })).toBe(true);
}

test('client portal shell is responsive', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'CHUKLOV' })).toBeVisible();
    await expect(page.getByRole('img', { name: 'CHUKLOV' })).toHaveAttribute('src', '/brand/chuklov-designer-logo-ru.jpg');
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toBeVisible();
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Получить код' })).toBeVisible();
    await expect(page.getByText(/Личный кабинет|Вы вошли как|Продолжить/)).toHaveCount(0);
});

test('successful email verification enters the authenticated portal without a manual reload', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#portal-email')).toBeVisible();

    const initialPage = await page.locator('script[type="application/json"]').evaluate((element) =>
        JSON.parse(element.textContent ?? '{}') as {
            component: string;
            props: Record<string, unknown>;
            url: string;
            version: string | null;
        },
    );
    const entryProps = {
        ...initialPage.props,
        auth: {
            ...(initialPage.props.auth as Record<string, unknown>),
            emailCodeSent: true,
        },
    };
    const entryPage = { ...initialPage, props: entryProps };
    const authenticatedHome = {
        component: 'Portal/Home',
        props: {
            portal: {
                authenticated: true,
                clientName: 'QA Client',
                confirmedFirstName: null,
                availableBonus: null,
                bonusCurrencyUnavailable: false,
                isPartner: false,
                locale: 'ru',
                localeUrl: '/portal/locale',
                urls: {
                    home: '/',
                    services: '/portal/services',
                    bookings: '/portal/bookings',
                    health: '/portal/health',
                    more: '/portal/more',
                    profile: '/portal/profile',
                    medicalProfile: '/portal/medical-profile',
                    communities: '/portal/family',
                    finance: '/portal/finance',
                    giftCertificates: '/portal/gift-certificates',
                    surveys: '/portal/surveys',
                    companion: '/portal/companion',
                    tracker: '/portal/tracker',
                    referrals: '/portal/referrals',
                    feedback: '/portal/feedback',
                    attribution: '/portal/attribution',
                    booking: '/portal/bookings/create',
                    b2b: '/portal/b2b',
                },
            },
            upcomingBooking: null,
            healthAction: null,
            stories: [],
        },
        url: '/',
        version: null,
    };
    let authenticatedHomeVisits = 0;
    let releaseEmailRequest!: () => void;
    let emailRequestArrived!: () => void;
    const emailRequestReady = new Promise<void>((resolve) => {
        emailRequestArrived = resolve;
    });
    const emailRequestBarrier = new Promise<void>((resolve) => {
        releaseEmailRequest = resolve;
    });
    let releaseEmailVerification!: () => void;
    let emailVerificationArrived!: () => void;
    const emailVerificationReady = new Promise<void>((resolve) => {
        emailVerificationArrived = resolve;
    });
    const emailVerificationBarrier = new Promise<void>((resolve) => {
        releaseEmailVerification = resolve;
    });

    await page.route('**/portal/auth/email/request', async (route) => {
        emailRequestArrived();
        await emailRequestBarrier;
        await route.fulfill({
            status: 200,
            headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' },
            body: JSON.stringify(entryPage),
        });
    });
    await page.route('**/portal/auth/email/verify', async (route) => {
        emailVerificationArrived();
        await emailVerificationBarrier;
        await route.fulfill({
            status: 200,
            headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' },
            body: JSON.stringify(entryPage),
        });
    });
    await page.route('**/', async (route) => {
        if (route.request().method() !== 'GET' || route.request().headers()['x-inertia'] !== 'true') {
            await route.continue();
            return;
        }

        authenticatedHomeVisits += 1;
        await route.fulfill({
            status: 200,
            headers: { 'Content-Type': 'application/json', 'X-Inertia': 'true' },
            body: JSON.stringify(authenticatedHome),
        });
    });

    await page.getByLabel('Email').fill('portal-login@example.test');
    const requestButton = page.locator('form').filter({ has: page.locator('#portal-email') }).getByRole('button');
    const requestClick = requestButton.click();
    await emailRequestReady;
    try {
        await expect(requestButton).toBeDisabled();
        await expect(requestButton).toHaveText('Отправляем код…');
        await expect(requestButton).toHaveAttribute('aria-busy', 'true');
    } finally {
        releaseEmailRequest();
    }
    await requestClick;
    await expect(page.locator('#portal-email-code')).toBeVisible();
    await page.locator('#portal-email-code').fill('123456');
    const verifyButton = page.locator('form').filter({ has: page.locator('#portal-email-code') }).getByRole('button');
    const verifyClick = verifyButton.click();
    await emailVerificationReady;
    try {
        await expect(verifyButton).toBeDisabled();
        await expect(verifyButton).toHaveText('Проверяем код…');
        await expect(verifyButton).toHaveAttribute('aria-busy', 'true');
    } finally {
        releaseEmailVerification();
    }
    await verifyClick;

    await expect(page.getByText('Добро пожаловать в пространство здоровья!')).toBeVisible();
    await expect(page.locator('#portal-email')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticatedHomeVisits).toBe(1);
});

test('Story placeholders are hidden when no playable videos are available', async ({ page }) => {
    const fixture = createBookingFixture();
    execFileSync('php', ['artisan', 'tinker', '--execute', `
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()->findOrFail(${fixture.clientId});
        foreach (array_keys(\\App\\Modules\\Content\\Application\\GetClientEntryMedia::STORIES) as $key) {
            \\App\\Modules\\Content\\Domain\\Models\\ContentSection::factory()->create([
                'organization_id' => $client->organization_id,
                'section_key' => $key, 'locale' => 'ru', 'is_visible' => true, 'delivery_mode' => 'mini_app',
                'media' => [],
            ]);
        }
    `], { env: { ...process.env, APP_ENV: 'e2e' }, stdio: ['ignore', 'pipe', 'pipe'] });
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/');
    await expect(page.getByTestId('home-empty-schedule')).toBeVisible();
    await expect(page.locator('.portal-stories')).toHaveCount(0);
    await expect(page.locator('.portal-story')).toHaveCount(0);
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalOverflow(page);
    }
});

test('portrait Story previews stay inside their frames and player navigation works', async ({ page }) => {
    const fixture = createBookingFixture();
    execFileSync('php', ['artisan', 'tinker', '--execute', `
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()->findOrFail(${fixture.clientId});
        foreach (array_keys(\\App\\Modules\\Content\\Application\\GetClientEntryMedia::STORIES) as $key) {
            \\App\\Modules\\Content\\Domain\\Models\\ContentSection::factory()->create([
                'organization_id' => $client->organization_id,
                'section_key' => $key, 'locale' => 'ru', 'is_visible' => true, 'delivery_mode' => 'mini_app',
                'media' => [
                    'image' => 'content/'.$client->organization_id.'/'.\\Illuminate\\Support\\Str::uuid().'.png',
                    'video' => 'content/'.$client->organization_id.'/'.\\Illuminate\\Support\\Str::uuid().'.mp4',
                    'subtitles' => $key === 'story_author' ? 'https://qa-subtitles.invalid/story.vtt' : null,
                ],
            ]);
        }
    `], { env: { ...process.env, APP_ENV: 'e2e' }, stdio: ['ignore', 'pipe', 'pipe'] });
    let subtitleRequests = 0;
    await page.route('https://qa-subtitles.invalid/story.vtt', async (route) => {
        subtitleRequests += 1;
        await route.fulfill({
            status: 200,
            contentType: 'text/vtt',
            headers: { 'Access-Control-Allow-Origin': '*' },
            body: 'WEBVTT\n\n00:00:00.000 --> 00:00:03.000\nQA subtitles verification\n',
        });
    });
    await page.route('**/storage/content/**/*.png', (route) => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="640"><rect width="360" height="640" fill="#b88059"/></svg>',
    }));
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/');
    const cards = page.locator('.portal-story');
    await expect(cards).toHaveCount(4);
    const expectedStoryTitles = [
        'Кто такой Евгений Чуклов (видео)',
        'История мастера (видео)',
        'Авторский метод (видео)',
        'Подробно о методике (видео)',
    ];
    await expect.poll(() => cards.evaluateAll((elements) => elements.map((element) => element.querySelector('.portal-story__title')?.textContent?.trim() ?? ''))).toEqual(expectedStoryTitles);
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect.poll(() => cards.evaluateAll((elements) => elements.every((element) => {
            const frame = element.querySelector('.portal-story__preview') as HTMLElement;
            const image = frame.querySelector('img') as HTMLImageElement;
            const title = element.querySelector('.portal-story__title') as HTMLElement;
            const bounds = frame.getBoundingClientRect();
            const imageBounds = image.getBoundingClientRect();
            const titleBounds = title.getBoundingClientRect();
            return image.complete && image.naturalHeight === 640
                && imageBounds.left >= bounds.left && imageBounds.right <= bounds.right
                && imageBounds.top >= bounds.top && imageBounds.bottom <= bounds.bottom
                && titleBounds.top >= bounds.bottom
                && element.getBoundingClientRect().bottom >= titleBounds.bottom
                && title.scrollHeight <= title.clientHeight + 1
                && title.textContent?.trim() === element.getAttribute('aria-label');
        }))).toBe(true);
        await expect.poll(() => page.locator('.portal-stories').evaluate((element) => element.getBoundingClientRect().height)).toBeLessThanOrEqual(90);
        await assertNoHorizontalOverflow(page);
        if (width < 768) {
            const labels = page.locator('.portal-bottom-nav__label');
            await expect(labels).toHaveCount(5);
            const geometry = await labels.evaluateAll((elements) => elements.map((element) => {
                const range = document.createRange();
                range.selectNodeContents(element);
                return { label: element.textContent, scrollWidth: element.scrollWidth, clientWidth: element.clientWidth, lines: range.getClientRects().length };
            }));
            for (const label of geometry) {
                expect(label.scrollWidth, `${label.label} at ${width}px`).toBeLessThanOrEqual(label.clientWidth);
                expect(label.lines, `${label.label} at ${width}px`).toBe(1);
            }
        }
    }
    await cards.first().click();
    const dialog = page.locator('.portal-story-player');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('strong')).toHaveText('Кто такой Евгений Чуклов (видео)');
    const subtitles = dialog.locator('track[kind="subtitles"]');
    await expect.poll(() => subtitles.evaluate((element) => {
        const track = element as HTMLTrackElement;
        return track.readyState === 2 ? track.track.cues?.[0]?.text ?? '' : '';
    })).toBe('QA subtitles verification');
    expect(subtitleRequests).toBe(1);
    await dialog.getByRole('button', { name: 'Далее', exact: true }).click();
    await expect(dialog.locator('strong')).toHaveText('История мастера (видео)');
    await dialog.getByRole('button', { name: 'Назад', exact: true }).click();
    await expect(dialog.locator('strong')).toHaveText('Кто такой Евгений Чуклов (видео)');
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await cards.last().click();
    await expect(dialog.locator('strong')).toHaveText('Подробно о методике (видео)');
    await dialog.getByRole('button', { name: 'Закрыть видео', exact: true }).click();
    await expect(dialog).not.toBeVisible();
});

test('Telegram Mini App submits initData automatically without a second login action', async ({ page }) => {
    let authenticationRequests = 0;

    await page.route('https://telegram.org/js/telegram-web-app.js', async (route) => {
        await route.fulfill({
            contentType: 'application/javascript',
            body: 'window.Telegram = { WebApp: { initData: "verified-init-data", ready() {} } };',
        });
    });
    await page.route('**/portal/telegram/auth', async (route) => {
        authenticationRequests += 1;
        await route.fulfill({
            status: 200,
            headers: {
                'Content-Type': 'application/json',
                'Vary': 'Accept',
                'X-Inertia': 'true',
            },
            body: JSON.stringify({
                component: 'Portal/Home',
                props: {
                    services: [],
                    upcomingBooking: null,
                    isPartner: false,
                    attribution: {
                        needsManualSource: false,
                    },
                    portal: {
                        authenticated: true,
                        clientName: 'Telegram Client',
                        locale: 'ru',
                        localeUrl: '/portal/locale',
                        urls: {
                            home: '/',
                            services: '/portal/services',
                            bookings: '/portal/bookings',
                            finance: '/portal/finance',
                            surveys: '/portal/surveys',
                            companion: '/portal/companion',
                            profile: '/portal/profile',
                            referrals: '/portal/referrals',
                            feedback: '/portal/feedback',
                            attribution: '/portal/attribution',
                            booking: '/portal/bookings/create',
                            b2b: '/portal/b2b',
                        },
                    },
                },
                url: '/',
                version: null,
            }),
        });
    });

    await page.goto('/');

    await expect(page.getByText('Добро пожаловать в пространство здоровья!')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticationRequests).toBe(1);
});

test('Telegram Mini App B2B launch authenticates before showing the requested destination', async ({ page }) => {
    let authenticationRequests = 0;

    await page.route('https://telegram.org/js/telegram-web-app.js', async (route) => {
        await route.fulfill({
            contentType: 'application/javascript',
            body: 'window.Telegram = { WebApp: { initData: "verified-init-data", ready() {} } };',
        });
    });
    await page.route('**/portal/telegram/auth', async (route) => {
        authenticationRequests += 1;
        expect(route.request().postDataJSON().launchEntry).toBe('b2b');
        await route.fulfill({
            status: 200,
            headers: {
                'Content-Type': 'application/json',
                'Vary': 'Accept',
                'X-Inertia': 'true',
            },
            body: JSON.stringify({
                component: 'Portal/B2b',
                props: {
                    portal: {
                        authenticated: true,
                        clientName: 'Telegram Client',
                        locale: 'ru',
                        localeUrl: '/portal/locale',
                        urls: {
                            home: '/',
                            services: '/portal/services',
                            bookings: '/portal/bookings',
                            finance: '/portal/finance',
                            surveys: '/portal/surveys',
                            companion: '/portal/companion',
                            profile: '/portal/profile',
                            referrals: '/portal/referrals',
                            feedback: '/portal/feedback',
                            attribution: '/portal/attribution',
                            booking: '/portal/bookings/create',
                            b2b: '/portal/b2b',
                        },
                    },
                    authenticated: true,
                    b2bSpecialistAnswer: 'yes',
                    content: [],
                    specialists: [],
                    selectedSpecialistId: null,
                    availability: null,
                    availabilityRange: null,
                    configurationReady: false,
                    configurationIssue: null,
                    urls: {
                        answer: '/portal/profile/b2b-answer',
                        page: '/portal/b2b',
                        submit: '/portal/b2b/leads',
                        login: '/',
                    },
                },
                url: '/portal/b2b',
                version: null,
            }),
        });
    });

    await page.goto('/portal/telegram/launch/b2b');

    await expect(page.getByRole('heading', { name: 'Развить бизнес с CHUKLOV' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticationRequests).toBe(1);
});

test('Telegram Mini App partner launch authenticates and displays the partner cabinet', async ({ page }) => {
    let authenticationRequests = 0;

    await page.route('https://telegram.org/js/telegram-web-app.js', async (route) => {
        await route.fulfill({
            contentType: 'application/javascript',
            body: 'window.Telegram = { WebApp: { initData: "verified-init-data", ready() {} } };',
        });
    });
    await page.route('**/portal/telegram/auth', async (route) => {
        authenticationRequests += 1;
        expect(route.request().postDataJSON()).toMatchObject({
            initData: 'verified-init-data',
            launchEntry: 'partner_cabinet',
        });
        await route.fulfill({
            status: 200,
            headers: {
                'Content-Type': 'application/json',
                'Vary': 'Accept',
                'X-Inertia': 'true',
            },
            body: JSON.stringify({
                component: 'Portal/Referrals',
                props: {
                    portal: {
                        authenticated: true,
                        clientName: 'Telegram Partner',
                        locale: 'ru',
                        localeUrl: '/portal/locale',
                        urls: {
                            home: '/',
                            services: '/portal/services',
                            bookings: '/portal/bookings',
                            finance: '/portal/finance',
                            surveys: '/portal/surveys',
                            companion: '/portal/companion',
                            profile: '/portal/profile',
                            referrals: '/portal/referrals',
                            feedback: '/portal/feedback',
                            attribution: '/portal/attribution',
                            booking: '/portal/bookings/create',
                            b2b: '/portal/b2b',
                        },
                    },
                    referrals: {
                        isPartner: true,
                        status: 'active',
                        activatedAt: null,
                        link: 'https://t.me/chuklov_test_bot?start=ref_legacy-referral-code',
                        createLinkUrl: '/portal/referrals/links',
                        stats: {
                            visits: 0,
                            registrations: 0,
                            paidClients: 0,
                            visitToRegistrationRate: null,
                            registrationToPaidClientRate: null,
                            rewardEarned: [],
                        },
                        links: [],
                        referredClientsCount: 0,
                        registrations: [],
                        rewards: {
                            balances: [],
                            history: [],
                            payouts: [],
                            requestUrl: '/portal/referrals/payouts',
                        },
                    },
                },
                url: '/portal/referrals',
                version: null,
            }),
        });
    });

    await page.goto('/portal/telegram/launch/partner_cabinet');

    await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
    await expect(page.getByTestId('partner-summary')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Войти через Telegram' })).toHaveCount(0);
    expect(authenticationRequests).toBe(1);
});

test('authenticated client gets the CHUKLOV navigation and can persist RU/EN', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать в пространство здоровья!' })).toBeVisible();
    await expect(page.getByRole('dialog').filter({ hasText: /имя|как вас зовут/i })).toHaveCount(0);
    await expect(page.getByLabel('Имя', { exact: true })).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Добро пожаловать в пространство здоровья!' })).toBeVisible();
    const russianTrigger = page.getByRole('button', { name: 'Русский', exact: true });
    await expect(russianTrigger).toBeVisible();
    await expect(page.getByRole('menu')).toHaveCount(0);

    const navigation = (page.viewportSize()?.width ?? 0) >= 768
        ? page.locator('.portal-header__navigation .portal-header__link')
        : page.locator('.portal-bottom-nav__link');
    await expect(navigation).toHaveText(['Главная', 'Записаться', 'Здоровье', 'Чат', 'Кабинет']);

    await russianTrigger.click();
    await expect(page.getByRole('menuitemradio', { name: 'Русский', exact: true })).toHaveAttribute('aria-checked', 'true');
    await page.getByRole('menuitemradio', { name: 'English', exact: true }).click();
    await expect(page.locator('h1')).toHaveText('Welcome to your space for health!');
    await expect(page.getByRole('button', { name: 'English', exact: true })).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('img', { name: 'CHUKLOV' })).toHaveAttribute('src', '/brand/chuklov-designer-logo-en.jpg');
    await expect(navigation).toHaveText(['Home', 'Book', 'Health', 'Chat', 'Cabinet']);
    await navigation.nth(1).click();
    await expect(page.getByRole('heading', { name: 'Book', exact: true })).toBeVisible();
    const serviceCard = page.locator('.portal-service-card').first();
    await expect(serviceCard).toHaveAttribute('href', /service_id=/);
    await serviceCard.click();
    await expect(page).toHaveURL(/\/portal\/bookings\/create\?[^#]*service_id=/);
    await expect(page.getByTestId('booking-selection-service')).toBeVisible();
    const profileResponse = page.waitForResponse((response) =>
        response.url().endsWith('/portal/profile') && response.request().method() === 'GET' && response.status() === 200,
    );
    await page.getByRole('link', { name: 'Cabinet' }).last().click();
    await expect(page).toHaveURL(/\/portal\/more$/);
    await page.getByRole('link', { name: 'Personal data', exact: true }).click();
    await profileResponse;
    await expect(page).toHaveURL(/\/portal\/profile$/);
    await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();
    await expect(page.getByText('Manage your contact details and preferences when you need to.')).toHaveCount(0);
});

test('confirmed client greeting follows the saved locale after reload', async ({ page }) => {
    const fixture = createBookingFixture({ confirmedName: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать, Playwright!', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Русский', exact: true }).click();
    await page.getByRole('menuitemradio', { name: 'English', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Welcome, Playwright!', exact: true })).toBeVisible();

    await page.reload();
    await expect(page.getByRole('heading', { name: 'Welcome, Playwright!', exact: true })).toBeVisible();
});

test('feedback keeps the selected score visible and the portal within mobile bounds', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [390, 760]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/portal/feedback');
        const selectedScore = page.getByRole('radio', { name: '6', exact: true });
        await selectedScore.click();
        await expect(selectedScore).toHaveAttribute('aria-checked', 'true');
        await expect(selectedScore).toHaveAttribute('aria-pressed', 'true');
        await expect(selectedScore).toHaveClass(/portal-score-option--selected/);
        await expect(page.getByRole('radio', { name: '5', exact: true })).not.toHaveClass(/portal-score-option--selected/);
        await assertNoHorizontalOverflow(page);
    }
});

test('home keeps one primary booking action and ordinary referrals remain available', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [1440, 1280, 1024, 768, 390, 360, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/');
        await expect(page.getByTestId('home-booking-cta')).toHaveCount(1);
        await expect(page.getByTestId('home-booking-cta')).toHaveText('Выбрать удобное время');
        await expect(page.getByTestId('home-booking-cta').locator('svg')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Ваш график восстановления', exact: true })).toBeVisible();
        await expect(page.getByText('У вас пока нет запланированных сеансов. Самое время разгрузить тело и снять накопленное напряжение.', { exact: true })).toBeVisible();
        await assertNoHorizontalOverflow(page);

        if (width < 768) {
            await expect(page.locator('.portal-bottom-nav')).toBeVisible();

            const navigationItems = page.locator('.portal-bottom-nav__link');
            expect(await navigationItems.count()).toBe(5);
            expect(await navigationItems.evaluateAll((items) => {
                const widths = items.map((item) => item.getBoundingClientRect().width);
                const labels = items.map((item) => item.querySelector('.portal-bottom-nav__label'));
                const widthsMatchLayout = window.innerWidth <= 360
                    ? Math.abs(widths[1] / widths[0] - 1.15) <= 0.04
                        && Math.max(widths[0], widths[2], widths[3], widths[4])
                            - Math.min(widths[0], widths[2], widths[3], widths[4]) <= 1
                    : Math.max(...widths) - Math.min(...widths) <= 1;

                return widthsMatchLayout
                    && labels.every((label) => label !== null && label.getBoundingClientRect().height >= 24);
            })).toBe(true);
        }

        await page.screenshot({ path: `/tmp/chuklov-portal-home-${width}.png`, fullPage: true });
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/');
    await page.getByTestId('home-booking-cta').click();
    await expect(page).toHaveURL(/\/portal\/services$/);
    await expect(page.getByRole('heading', { name: 'Записаться', exact: true })).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('heading', { name: 'Ваш график восстановления', exact: true })).toBeVisible();

    await page.goto('/portal/referrals');
    await expect(page).toHaveURL(/\/portal\/referrals$/);
    await expect(page.getByTestId('ordinary-referral').getByRole('heading', { name: 'Пригласить друга', exact: true })).toBeVisible();
    await expect(page.getByTestId('ordinary-referral')).toBeVisible();
    await expect(page.getByTestId('partner-activate')).toHaveCount(0);
    await expect(page.getByTestId('partner-links')).toHaveCount(0);
});

test('client sees available bonus in the header and can open the service list from its explanation', async ({ page }) => {
    const fixture = createBookingFixture({ withServiceCredit: true, withServiceImage: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    const bonusButton = page.getByRole('button', { name: 'Доступные бонусы', exact: true });

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/');
        await expect(bonusButton).toBeVisible();
        await expect(bonusButton).toContainText('10.00 USD');
        expect(await bonusButton.evaluate((element) => element.closest('.portal-header-actions') !== null)).toBe(true);
        await assertRenderedViewportGeometry(page, ['.portal-header-actions button[aria-label="Доступные бонусы"]'], ['.portal-header']);

        if (width === 320) {
            await bonusButton.click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await expect(dialog).toContainText('Вы можете частично или полностью оплатить ими любую услугу.');
            await expect(dialog.getByRole('link', { name: 'Записаться', exact: true })).toBeVisible();
            await assertRenderedViewportGeometry(page, ['.portal-bonus-dialog a.portal-button'], ['.portal-bonus-dialog']);
            await dialog.getByRole('button', { name: 'Закрыть видео', exact: true }).click();
            await expect(dialog).toBeHidden();
        }
    }

    await bonusButton.click();
    await page.getByRole('dialog').getByRole('link', { name: 'Записаться', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/services$/);
    await expect(page.getByRole('heading', { name: 'Записаться', exact: true })).toBeVisible();
    const imageCard = page.locator('.portal-service-card--with-image').filter({ hasText: fixture.serviceName });
    await expect(imageCard).toBeVisible();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(imageCard.locator('.portal-service-card__image')).toBeVisible();
        await assertNoHorizontalOverflow(page);
        if (width < 768) {
            const mediaBottom = await imageCard.locator('.portal-service-card__media').evaluate((element) => element.getBoundingClientRect().bottom);
            const bodyTop = await imageCard.locator('.portal-service-card__body').evaluate((element) => element.getBoundingClientRect().top);
            expect(bodyTop - mediaBottom).toBeGreaterThanOrEqual(12);
        }
    }
    await expect(page.getByRole('dialog')).toBeHidden();

    await page.reload();
    await expect(page.getByRole('heading', { name: 'Записаться', exact: true })).toBeVisible();
    await expect(bonusButton).toContainText('10.00 USD');
    await page.goBack();
    await expect(page.getByRole('heading', { name: 'Добро пожаловать в пространство здоровья!', exact: true })).toBeVisible();
    await expect(bonusButton).toContainText('10.00 USD');
});

test('ordinary referral copy reports clipboard failure and recovers on retry', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    let referralPosts = 0;
    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/portal/referrals') {
            referralPosts += 1;
        }
    });

    await page.goto('/portal/referrals');
    const referral = page.getByTestId('ordinary-referral');
    const copyButton = referral.getByTestId('ordinary-referral-copy');
    const copyError = referral.getByRole('alert');

    await page.evaluate(() => {
        Object.defineProperty(Navigator.prototype, 'clipboard', {
            configurable: true,
            get: () => ({ writeText: async () => { throw new DOMException('blocked', 'NotAllowedError'); } }),
        });
        Object.defineProperty(document, 'execCommand', { configurable: true, value: () => false });
    });
    await copyButton.click();
    await expect(copyError).toHaveText('Скопируйте ссылку вручную из текста выше.');
    await expect(copyButton).toHaveText('Скопировать');

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(copyError).toBeVisible();
        await assertNoHorizontalOverflow(page);
    }

    await page.evaluate(() => {
        Object.defineProperty(Navigator.prototype, 'clipboard', {
            configurable: true,
            get: () => ({ writeText: async () => { document.documentElement.dataset.clipboardWrite = 'done'; } }),
        });
    });
    await copyButton.click();
    await expect(copyButton).toHaveText('Ссылка скопирована');
    await expect(copyError).toHaveCount(0);
    await expect(page.locator('html')).toHaveAttribute('data-clipboard-write', 'done');
    await expect.poll(() => referralPosts).toBe(0);

    await page.reload();
    await expect(copyButton).toHaveText('Скопировать');
    await expect(copyError).toHaveCount(0);
    await expect.poll(() => referralPosts).toBe(0);
});

test('new client can open personal diagnostics and gets a clear empty tests result', async ({ page }) => {
    const fixture = createBookingFixture();
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/');

    const healthAction = page.getByTestId('home-health-action');
    await expect(healthAction.getByText('Персональная диагностика', { exact: true })).toBeVisible();
    await expect(healthAction.getByText('Экспресс-скрининг 9 систем организма. ИИ выявит первопричины боли за 3 минуты.', { exact: true })).toBeVisible();
    await expect(healthAction.getByText('Новое', { exact: true })).toBeVisible();
    await expect(healthAction.getByText('ДЕЙСТВИЕ ПО ЗДОРОВЬЮ', { exact: true })).toHaveCount(0);

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(healthAction.getByRole('link', { name: 'Пройти тест →', exact: true })).toBeVisible();
        await assertHealthActionHeadingLayout(healthAction);
        await assertNoHorizontalOverflow(page);
    }

    await healthAction.getByRole('link', { name: 'Пройти тест →', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/surveys$/);
    const emptyState = page.getByTestId('surveys-empty-state');
    await expect(emptyState.getByText('Пока нет доступных тестов. Вернитесь позже.', { exact: true })).toBeVisible();
    await expect(emptyState.getByRole('link', { name: 'На главную', exact: true })).toHaveAttribute('href', '/');
    await emptyState.getByRole('link', { name: 'На главную', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByTestId('home-health-action')).toBeVisible();
});

test('reviewed synthetic course report appears as the Road Map and opens from Home', async ({ page }) => {
    const fixture = createBookingFixture({ roadmap: true });
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/');

    const healthAction = page.getByTestId('home-health-action');
    await expect(healthAction.getByText('Ваша Дорожная карта', { exact: true })).toBeVisible();
    await expect(healthAction.getByText('Индивидуальные рекомендации и план восстановления готовы к просмотру.', { exact: true })).toBeVisible();
    await expect(healthAction.getByText('Готово', { exact: true })).toBeVisible();
    await expect(healthAction.getByText('ДЕЙСТВИЕ ПО ЗДОРОВЬЮ', { exact: true })).toHaveCount(0);

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(healthAction.getByRole('link', { name: 'Открыть план', exact: true })).toBeVisible();
        await assertHealthActionHeadingLayout(healthAction);
        await assertNoHorizontalOverflow(page);
    }

    await healthAction.getByRole('link', { name: 'Открыть план', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/health#course-report$/);
    const courseReport = page.locator('#course-report').locator('xpath=..');
    await expect(courseReport).toBeVisible();
    await expect(courseReport.getByText('Synthetic accepted report fixture.', { exact: true })).toBeVisible();
    const comparison = page.locator('article.portal-panel').filter({ has: page.getByRole('heading', { name: 'Сравнение До и После', exact: true }) });
    await expect(comparison.getByText('Боль', { exact: true })).toBeVisible();
    await expect(comparison.getByText('7 → 3', { exact: false })).toBeVisible();
    await expect(comparison.getByText('Есть улучшение', { exact: false })).toBeVisible();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(comparison.getByText('7 → 3', { exact: false })).toBeVisible();
        await assertNoHorizontalOverflow(page);
    }
    await page.reload();
    await expect(page).toHaveURL(/\/portal\/health#course-report$/);
    await expect(courseReport.getByText('Synthetic accepted report fixture.', { exact: true })).toBeVisible();
    await expect(comparison.getByText('7 → 3', { exact: false })).toBeVisible();
    await page.goBack();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByTestId('home-health-action').getByText('Ваша Дорожная карта', { exact: true })).toBeVisible();
    await page.goForward();
    await expect(page).toHaveURL(/\/portal\/health#course-report$/);
    await expect(comparison.getByText('7 → 3', { exact: false })).toBeVisible();
    await expect(page.locator('#course-report').locator('xpath=..')).toContainText('Synthetic accepted report fixture.');
});

test('home changes the booking action when an upcoming booking is shown', async ({ page }) => {
    const fixture = createBookingFixture({ withBooking: true, confirmedOfficeBooking: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    const upcomingBooking = page.locator('.portal-booking-card');
    await expect(upcomingBooking).toBeVisible();
    await expect(upcomingBooking.getByText('Ближайшая запись', { exact: true })).toBeVisible();
    await expect(upcomingBooking.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    await expect(upcomingBooking.getByText(fixture.specialistName, { exact: true })).toBeVisible();
    await expect(upcomingBooking.getByText('В клинике', { exact: true })).toBeVisible();
    await expect(upcomingBooking.getByText('Оплата не внесена', { exact: true })).toBeVisible();
    if (fixture.firstWorkingLocationAddress === null) {
        throw new Error('The booking fixture did not provide its office address.');
    }
    await expect(upcomingBooking.getByText(fixture.firstWorkingLocationAddress, { exact: true })).toBeVisible();
    await expect(page.getByTestId('home-booking-cta')).toHaveText('Записаться ещё');
    await upcomingBooking.getByRole('link', { name: 'Подробнее', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/portal/bookings/${fixture.bookingId}$`));
    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
});

test('booking details use one office label and a consistent dotted date', async ({ page }) => {
    const fixture = createBookingFixture({
        withBooking: true,
        confirmedOfficeBooking: true,
        duplicateOfficeLocation: true,
    });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/${fixture.bookingId}`);
    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    const bookingTime = page.locator('[aria-labelledby="booking-time-heading"] time');
    await expect(bookingTime).toHaveText(/^\d{2}\.\d{2}\.\d{4}$/);
    await expect(page.locator('.portal-booking-location-panel').getByText('Бангкок', { exact: true })).toBeVisible();
    await expect(page.locator('.portal-booking-location-panel').getByText('Bangkok', { exact: true })).toHaveCount(0);
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(bookingTime).toHaveText(/^\d{2}\.\d{2}\.\d{4}$/);
        await assertNoHorizontalOverflow(page);
    }
    await page.reload();
    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    await expect(bookingTime).toHaveText(/^\d{2}\.\d{2}\.\d{4}$/);
    await expect(page.locator('.portal-booking-location-panel').getByText('Бангкок', { exact: true })).toBeVisible();
});

test('requested booking stays in My bookings while Home keeps the empty schedule state', async ({ page }) => {
    const fixture = createBookingFixture({ withBooking: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    const emptySchedule = page.getByTestId('home-empty-schedule');
    await expect(emptySchedule).toBeVisible();
    await expect(emptySchedule.getByRole('heading', { name: 'Ваш график восстановления', exact: true })).toBeVisible();
    await expect(emptySchedule.getByText('У вас пока нет запланированных сеансов. Самое время разгрузить тело и снять накопленное напряжение.', { exact: true })).toBeVisible();
    await expect(emptySchedule.getByTestId('home-booking-cta')).toHaveText('Выбрать удобное время');
    await expect(page.locator('.portal-booking-card')).toHaveCount(0);

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalOverflow(page);
    }

    await page.reload();
    await expect(page.getByTestId('home-empty-schedule')).toBeVisible();
    await page.getByTestId('home-booking-cta').click();
    await expect(page).toHaveURL(/\/portal\/services$/);

    await page.goto('/portal/bookings');
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
});

test('home shows the confirmed online meeting link on the upcoming booking', async ({ page }) => {
    const fixture = createBookingFixture({ withBooking: true, onlineMeeting: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    const bookingCard = page.locator('.portal-booking-card');
    const meetingLink = bookingCard.getByRole('link', { name: 'Подключиться к встрече', exact: true });

    await expect(bookingCard).toBeVisible();
    await expect(meetingLink).toHaveAttribute('href', 'https://example.invalid/qa-meeting');
    await expect(meetingLink).toHaveAttribute('target', '_blank');
    await expect(meetingLink).toHaveAttribute('rel', 'noopener noreferrer');
    await expect(bookingCard.getByText('Оплата не внесена', { exact: true })).toBeVisible();
});

test('CRM-assigned partner can manage multiple campaign links', async ({ page }) => {
    const fixture = createBookingFixture({ withPartnerRewards: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);
    await page.goto('/portal/referrals');
    await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
    await expect(page.getByTestId('partner-links')).toBeVisible();
    await expect(page.getByTestId('invite-friend')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Ссылки', exact: true })).toBeVisible();

    await page.getByLabel('Название', { exact: true }).fill('Instagram — шапка профиля');
    await page.getByRole('combobox', { name: 'Канал', exact: true }).selectOption('instagram');
    await page.getByTestId('partner-create-link').click();
    const instagramLink = page.getByTestId('partner-link-1');
    await expect(instagramLink).toContainText('Instagram — шапка профиля');
    await expect(instagramLink).toContainText('Instagram');

    await page.getByLabel('Название', { exact: true }).fill('Telegram — мой канал');
    await page.getByRole('combobox', { name: 'Канал', exact: true }).selectOption('telegram');
    await page.getByTestId('partner-create-link').click();
    const telegramLink = page.getByTestId('partner-link-2');
    await expect(telegramLink).toContainText('Telegram — мой канал');
    await expect(telegramLink).toContainText('Telegram');

    for (const link of [instagramLink, telegramLink]) {
        await expect(link.locator('code')).toHaveText(/https:\/\/t\.me\/[^?]+\?start=ref_[A-Za-z0-9_-]{16,128}/);
        await expect(link.getByRole('button', { name: 'Скопировать', exact: true })).toBeVisible();
        await expect(link.getByRole('button', { name: 'Поделиться', exact: true })).toBeVisible();
        await expect(link.getByText('Переходы', { exact: true })).toBeVisible();
        await expect(link.getByText('Регистрации', { exact: true })).toBeVisible();
        await expect(link.getByText('Оплатили', { exact: true })).toBeVisible();
        await expect(link.getByText('Начислено', { exact: true })).toBeVisible();
        await expect(link.getByRole('button', { name: 'Отключить', exact: true })).toHaveCount(0);
        await expect(link.getByRole('button', { name: 'Включить', exact: true })).toHaveCount(0);
        await expect(link.getByText('Активна', { exact: true })).toHaveCount(0);
        await expect(link.getByText('Отключена', { exact: true })).toHaveCount(0);
        await expect(link.getByText('0', { exact: true })).toHaveCount(3);
        await expect(link.getByText('—', { exact: true })).toHaveCount(1);
    }

    const instagramCopy = instagramLink.getByRole('button', { name: 'Скопировать', exact: true });
    await page.evaluate(() => {
        Object.defineProperty(Navigator.prototype, 'clipboard', {
            configurable: true,
            get: () => ({ writeText: async () => { throw new DOMException('blocked', 'NotAllowedError'); } }),
        });
        Object.defineProperty(document, 'execCommand', { configurable: true, value: () => false });
    });
    await instagramCopy.click();
    await expect(instagramLink.getByRole('alert')).toHaveText('Скопируйте ссылку вручную из текста выше.');
    await page.evaluate(() => {
        Object.defineProperty(Navigator.prototype, 'clipboard', {
            configurable: true,
            get: () => ({ writeText: async () => { document.documentElement.dataset.clipboardWrite = 'done'; } }),
        });
    });
    await instagramCopy.click();
    await expect(instagramLink.getByRole('button', { name: 'Ссылка скопирована', exact: true })).toBeVisible();
    await expect(instagramLink.getByRole('alert')).toHaveCount(0);
    await instagramLink.getByRole('button', { name: 'Поделиться', exact: true }).click();

    for (const width of [1440, 1280, 1024, 768, 390, 360, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/portal/referrals');
        await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
        await expect(page.getByText('Instagram — шапка профиля', { exact: true })).toBeVisible();
        await expect(page.getByText('Telegram — мой канал', { exact: true })).toBeVisible();
        await assertRenderedViewportGeometry(page, [
            '[data-testid="partner-create-link"]',
            '[data-testid^="partner-link-copy-"]',
            '[data-testid^="partner-link-share-"]',
        ]);
    }
});

test('partner can request and cancel a payout from the cabinet', async ({ page }) => {
    const fixture = createBookingFixture({ withPartnerRewards: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/referrals');
    await expect(page.getByRole('heading', { name: 'Партнёрский кабинет', exact: true })).toBeVisible();
    await expect(page.getByTestId('invite-friend')).toHaveCount(0);
    await expect(page.getByTestId('partner-balance-USD')).toContainText(/10[,.]00.*(?:\$|USD)/);

    await page.getByRole('button', { name: 'Выплаты', exact: true }).click();
    await page.getByLabel('Сумма', { exact: true }).fill('2.00');
    let requestStarted!: () => void;
    let releaseRequest!: () => void;
    const payoutRequestStarted = new Promise<void>((resolve) => {
        requestStarted = resolve;
    });
    const payoutRequestRelease = new Promise<void>((resolve) => {
        releaseRequest = resolve;
    });
    let payoutIdempotencyKey = '';
    await page.route('**/portal/referrals/payouts', async (route) => {
        if (route.request().method() === 'POST') {
            payoutIdempotencyKey = route.request().postDataJSON().idempotency_key;
            requestStarted();
            await payoutRequestRelease;
        }

        await route.continue();
    });

    const payoutSubmit = page.getByTestId('payout-submit');
    await payoutSubmit.click();
    await payoutRequestStarted;
    await expect(payoutSubmit).toBeDisabled();
    await expect(payoutSubmit).toHaveAttribute('aria-busy', 'true');
    releaseRequest();
    await expect(page.getByTestId('payout-feedback')).toBeVisible();
    await expect(page.getByTestId('payout-feedback')).toContainText('Заявка на выплату отправлена');
    await expect(page.getByTestId('payout-feedback')).toContainText(/2[,.]00\s*(?:\$|USD)/);
    await expect(page.getByTestId('payout-feedback')).toContainText('Запрошена');
    expect(payoutIdempotencyKey).toMatch(/^referral-payout-/);

    await page.getByRole('button', { name: 'История', exact: true }).click();
    const payout = page.getByTestId('partner-payout-0');
    await expect(payout).toBeVisible();
    await expect(payout).toContainText(/2[,.]00\s*(?:\$|USD)/);
    await expect(payout).toContainText('Запрошена');
    await payout.getByRole('button', { name: 'Отменить запрос', exact: true }).click();
    await expect(payout).toContainText('Отменена');
    await page.reload();
    await page.getByRole('button', { name: 'История', exact: true }).click();
    await expect(page.getByTestId('partner-payout-0')).toContainText('Отменена');
    await expect(page.getByTestId('partner-balance-USD')).toContainText(/10[,.]00.*(?:\$|USD)/);

    await page.getByRole('button', { name: 'Выплаты', exact: true }).click();
    await page.getByLabel('Сумма', { exact: true }).fill('2.00');
    await page.unroute('**/portal/referrals/payouts');
    await page.route('**/portal/referrals/payouts', async (route) => {
        if (route.request().method() === 'POST') {
            const payload = route.request().postDataJSON();
            await route.continue({
                postData: JSON.stringify({ ...payload, idempotency_key: payoutIdempotencyKey }),
            });

            return;
        }

        await route.continue();
    });
    await payoutSubmit.click();
    await expect(page.getByTestId('payout-feedback')).toContainText('Эта заявка уже существует. Проверьте её статус в истории выплат.');
    await expect(page.getByTestId('payout-feedback')).toContainText('Отменена');
    await page.getByRole('button', { name: 'История', exact: true }).click();
    await expect(page.locator('[data-testid^="partner-payout-"]')).toHaveCount(1);
    await expect(page.getByTestId('partner-payout-0')).toContainText('Отменена');

    await page.getByRole('button', { name: 'Выплаты', exact: true }).click();
    await page.getByLabel('Сумма', { exact: true }).fill('3.00');
    await payoutSubmit.click();
    await expect(page.getByTestId('payout-error')).toContainText('Этот ключ уже использован для другой суммы или валюты.');
    await page.unroute('**/portal/referrals/payouts');
    await page.reload();

    await page.getByRole('button', { name: 'Выплаты', exact: true }).click();
    await page.getByLabel('Сумма', { exact: true }).fill('999.00');
    await payoutSubmit.click();
    await expect(page.getByTestId('payout-error')).toBeVisible();
    await expect(page.getByTestId('payout-error')).toContainText('Сумма превышает доступный остаток.');
});

test('B2B answer stays in one journey and Profile shows the same compact classification', async ({ page }) => {
    const fixture = createBookingFixture({ multipleChoices: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/b2b');
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toBeVisible();
    await page.getByRole('radio', { name: 'Да', exact: true }).check();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page).toHaveURL(/\/portal\/b2b$/);
    await expect(page.getByRole('heading', { name: 'Запросить разговор о бизнесе' })).toBeVisible();
    await expect(page.getByText('Вы указали: специалист по работе с телом.')).toBeVisible();
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toHaveCount(0);
    await expect(page.locator('#b2b-specialist')).toBeVisible();
    const specialistOptions = await page.locator('#b2b-specialist option').allTextContents();
    expect(specialistOptions).toContain('Выберите специалиста');
    expect(specialistOptions).not.toContain('specialist_id');

    await page.getByRole('link', { name: 'Кабинет' }).last().click();
    await expect(page).toHaveURL(/\/portal\/more$/);
    await page.getByRole('link', { name: 'Личные данные', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/profile$/);
    await expect(page.getByRole('heading', { name: 'Профессиональный профиль' })).toBeVisible();
    await expect(page.getByText('Специалист по работе с телом: Да')).toBeVisible();
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toHaveCount(0);
});

test('B2B no answer explains the next step without showing the sales-call form', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/b2b');
    await page.getByRole('radio', { name: 'Нет', exact: true }).check();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Что дальше' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Запросить разговор о бизнесе' })).toHaveCount(0);
    await expect(page.getByText('Являетесь ли вы массажистом / специалистом по работе с телом?')).toHaveCount(0);
});

test('assistant uses an attachment icon and portal selects keep human option labels', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/companion');
    await expect(page.getByText('Помощник отвечает', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Прикрепить изображения' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Начать новый диалог' })).toBeVisible();
    await expect(page.getByText('Добавить изображения', { exact: true })).toHaveCount(0);
    await assertNoHorizontalOverflow(page);

    await page.goto('/portal/attribution');
    const sourceOptions = await page.locator('select option').allTextContents();
    expect(sourceOptions).toContain('Выберите вариант');
    expect(sourceOptions).toContain('По рекомендации знакомых');
    expect(sourceOptions).not.toContain('friend');
    expect(sourceOptions).not.toContain('social');
});

test('companion safely renders rich long messages without viewport overflow', async ({ page }) => {
    const fixture = createBookingFixture({ withCompanionMessages: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [1440, 1280, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/portal/companion');
        await expect(page.getByRole('heading', { name: 'Чат', exact: true })).toBeVisible();
        await expect(page.locator('.portal-companion__help')).toHaveCount(0);
        await expect(page.getByRole('heading', { name: 'Безопасный ответ' })).toBeVisible();
        await expect(page.locator('.portal-rich-text strong')).toHaveText('Важная информация');
        await expect(page.locator('.portal-rich-text__code')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Безопасная HTTPS ссылка' })).toHaveAttribute('href', 'https://example.test/secure');
        for (const label of [
            'HTTP ссылка не должна быть активной',
            'javascript ссылка не должна быть активной',
            'data ссылка не должна быть активной',
            'file ссылка не должна быть активной',
            'Относительная ссылка не должна быть активной',
            'userinfo ссылка не должна быть активной',
        ]) {
            await expect(page.getByRole('link', { name: label, exact: true })).toHaveCount(0);
            const renderedParagraph = page.locator('.portal-rich-text p').filter({ hasText: label });
            await expect(renderedParagraph).toHaveCount(1);
            await expect(renderedParagraph).toBeVisible();
        }
        await expect(page.locator('.portal-rich-text script')).toHaveCount(0);
        await assertRenderedViewportGeometry(
            page,
            ['.portal-companion__composer button[type="submit"]'],
            ['.portal-companion__history', '.portal-companion__composer'],
        );
        expect(await page.locator('.portal-companion__message').evaluateAll((messages) => messages.every((message) => {
            const bounds = message.getBoundingClientRect();

            return bounds.left >= -1 && bounds.right <= document.documentElement.clientWidth + 1;
        }))).toBe(true);
    }
});

test('portal retries a failed Companion turn without duplicating the client message', async ({ page }) => {
    const fixture = createBookingFixture({ withCompanionFailure: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [1440, 1024, 768, 390, 360, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto('/portal/companion');
        await expect(page.getByRole('heading', { name: 'Чат', exact: true })).toBeVisible();
        await expect(page.getByText('Ответ не удалось проверить. Попробуйте ещё раз или позовите специалиста.', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Повторить', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Позвать специалиста', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Полезно', exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Не помогло', exact: true })).toHaveCount(0);
        await expect(page.getByText('· Портал', { exact: true })).toHaveCount(0);
        await expect(page.locator('.portal-companion__message--client')).toHaveCount(1);
        await expect(page.getByText('Повторите исходный запрос без копирования.', { exact: true })).toHaveCount(1);
        await assertNoHorizontalOverflow(page);
        await assertRenderedViewportGeometry(
            page,
            ['.portal-companion__message--client', '.portal-companion__message--ai button'],
            ['.portal-companion__history', '.portal-companion__composer'],
        );
    }

    const retryResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && response.url().includes('/portal/companion/retry/'));
    const retryRefresh = page.waitForResponse((response) => response.request().method() === 'GET'
        && new URL(response.url()).pathname === '/portal/companion'
        && response.request().headers()['x-inertia-partial-data']?.includes('companion'));
    let retryRequested = false;
    let feedbackRequested = false;
    await page.route('**/portal/companion**', async (route) => {
        const request = route.request();
        if (request.method() !== 'GET' || request.headers()['x-inertia'] !== 'true') {
            await route.continue();
            return;
        }

        const response = await route.fetch();
        const payload = await response.json() as {
            props?: {
                notices?: { retry?: string | null };
                companion?: { pending?: boolean; messages?: Array<Record<string, unknown>> };
            };
        };
        const companion = payload.props?.companion;
        if (retryRequested && companion !== undefined) {
            companion.pending = false;
            const messages = companion.messages ?? [];
            const retryMessage = {
                    type: 'message',
                    id: 'synthetic-retry-success',
                    role: 'ai',
                    roleLabel: 'Чат',
                    content: 'Ответ после повтора.',
                    occurredAt: new Date().toISOString(),
                    transportLabel: null,
                    feedback: null,
                    feedbackAvailable: true,
                    attachmentCount: 0,
                    traceUrl: null,
                    safeActions: [],
                };
            const existingMessage = messages.find((message) => message.id === retryMessage.id);
            if (existingMessage === undefined) {
                messages.push(retryMessage);
            }
            if (feedbackRequested) {
                const savedMessage = messages.find((message) => message.id === retryMessage.id);
                if (savedMessage !== undefined) {
                    savedMessage.feedback = 'not_helpful';
                }
            }
            companion.messages = messages;
        }
        await route.fulfill({ response, body: JSON.stringify(payload) });
    });
    await page.route('**/portal/companion/feedback/**', async (route) => {
        feedbackRequested = true;
        await route.fulfill({ status: 303, headers: { location: '/portal/companion' } });
    });
    retryRequested = true;
    await page.getByRole('button', { name: 'Повторить', exact: true }).click();
    await assertPortalResponseAccepted(retryResponse, 'Companion retry');
    await expect(page.getByText('Повторный запрос запущен.', { exact: true })).toBeVisible();
    expect((await retryRefresh).status()).toBe(200);
    await expect(page.locator('.portal-companion__message--client')).toHaveCount(1);
    await expect(page.getByText('Повторите исходный запрос без копирования.', { exact: true })).toHaveCount(1);
    await expect(page.getByText('Ответ после повтора.', { exact: true })).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('Повторный запрос запущен.', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Полезно', exact: true })).toBeVisible();
    const feedbackResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && response.url().includes('/portal/companion/feedback/'));
    await page.getByRole('button', { name: 'Не помогло', exact: true }).click();
    expect((await feedbackResponse).status()).toBe(303);
    await expect(page.getByRole('button', { name: 'Полезно', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Не помогло', exact: true })).toHaveCount(0);
    await expect(page.getByRole('status').filter({ hasText: 'Спасибо, учтём ваш отзыв.' })).toBeVisible();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(page.getByRole('status').filter({ hasText: 'Спасибо, учтём ваш отзыв.' })).toBeVisible();
        await assertNoHorizontalOverflow(page);
    }
});

test('companion shows accessible typing feedback and respects intentional history scrolling', async ({ page }) => {
    const fixture = createBookingFixture({ withCompanionMessages: true, withCompanionPending: true });

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.setViewportSize({ width: 390, height: 844 });
    let continuePollingResponse: () => void = () => {};
    let signalPollIntercepted: () => void = () => {};
    const userScrolledAway = new Promise<void>((resolve) => {
        continuePollingResponse = resolve;
    });
    const pollIntercepted = new Promise<void>((resolve) => {
        signalPollIntercepted = resolve;
    });
    await page.route('**/portal/companion**', async (route) => {
        if (route.request().headers()['x-inertia'] !== 'true') {
            await route.continue();
            return;
        }

        const response = await route.fetch();
        const payload = await response.json() as {
            props?: { companion?: { pending?: boolean; messages?: Array<Record<string, unknown>> } };
        };
        const companion = payload.props?.companion;
        if (companion !== undefined) {
            companion.pending = false;
            companion.messages = [
                ...(companion.messages ?? []),
                {
                    type: 'message',
                    id: 'synthetic-new-message',
                    role: 'ai',
                    roleLabel: 'AI-помощник',
                    content: 'Новый синтетический ответ.',
                    occurredAt: new Date().toISOString(),
                    transportLabel: null,
                    feedback: null,
                    feedbackAvailable: true,
                    attachmentCount: 0,
                    traceUrl: null,
                },
            ];
        }
        signalPollIntercepted();
        await userScrolledAway;
        await route.fulfill({ response, body: JSON.stringify(payload) });
    });
    const pollResponsePromise = page.waitForResponse((response) => response.url().includes('/portal/companion')
        && response.request().method() === 'GET'
        && response.request().headers()['x-inertia'] === 'true');
    await page.goto('/portal/companion');
    await expect(page.getByRole('status').filter({ hasText: 'печатает…' })).toBeVisible();
    await expect(page.locator('.portal-companion__typing-dots i')).toHaveCount(3);
    await expect(page.getByText('Чат формирует ответ', { exact: true })).toHaveCount(1);

    const history = page.locator('[data-testid="companion-history"]');
    await expect.poll(() => history.evaluate((element) => element.scrollHeight - element.clientHeight)).toBeGreaterThan(96);
    await expect.poll(() => history.evaluate((element) => element.scrollHeight - element.scrollTop - element.clientHeight)).toBeLessThanOrEqual(96);
    await pollIntercepted;
    await history.locator('.portal-companion__message').first().scrollIntoViewIfNeeded();
    await expect.poll(() => history.evaluate((element) => element.scrollHeight - element.scrollTop - element.clientHeight)).toBeGreaterThan(96);
    continuePollingResponse();

    const pollResponse = await pollResponsePromise;
    expect(pollResponse.status()).toBe(200);
    const pollPayload = await pollResponse.json() as {
        props?: { companion?: { pending?: boolean; messages?: Array<{ id?: string }> } };
    };
    expect(pollPayload.props?.companion?.pending).toBe(false);
    expect(pollPayload.props?.companion?.messages?.some((message) => message.id === 'synthetic-new-message')).toBe(true);
    await expect(page.getByTestId('companion-new-messages')).toBeVisible();
    await expect(page.getByText('Новый синтетический ответ.', { exact: true })).toBeVisible();
    await expect.poll(() => page.locator('[data-testid="companion-history"]').evaluate((element) => {
        return element.scrollHeight - element.scrollTop - element.clientHeight;
    })).toBeGreaterThan(96);

    await page.getByTestId('companion-new-messages').click();
    await expect.poll(() => page.locator('[data-testid="companion-history"]').evaluate((element) => {
        return element.scrollHeight - element.scrollTop - element.clientHeight;
    })).toBeLessThanOrEqual(96);
    await expect(page.getByTestId('companion-new-messages')).toHaveCount(0);
});

test('authenticated client can complete the booking journey', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    const response = await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    expect(response?.status()).toBe(200);

    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
    await expect(page.locator('#booking-specialist')).toHaveCount(0);
    await expect(page.getByTestId('booking-selection-specialist')).toContainText(fixture.specialistName);
    await expect(page.locator('input[name="date_from"], input[name="date_to"]')).toHaveCount(0);
    await expect(page.locator('input[name="idempotency_key"], input[name="client_timezone"], select[name="meeting_link_mode"]')).toHaveCount(0);
    const firstSlot = page.getByTestId('availability-slot').first();
    await expect(firstSlot).toBeVisible();
    await firstSlot.click();
    await expect(page.getByRole('button', { name: 'Продолжить' })).toBeEnabled();
    await page.getByRole('button', { name: 'Продолжить' }).click();
    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись' }).click();

    await expect(page.getByRole('heading', { name: 'Запись создана.' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toHaveCount(0);
    await expect(page.getByTestId('availability-slot')).toHaveCount(0);
    await page.getByRole('link', { name: 'Мои записи' }).last().click();
    await expect(page.getByText(/Playwright Service/)).toHaveCount(1);
});

test('September 26 confirmed profile persists and the medical editor saves then reopens', async ({ page }) => {
    test.setTimeout(60_000);
    const fixture = createBookingFixture();
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    const profileResponses = { failNextSave: false };
    await page.route('**/portal/profile', async (route) => {
        const request = route.request();
        if (profileResponses.failNextSave && request.method() === 'POST') {
            profileResponses.failNextSave = false;
            await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable.' }) });
            return;
        }
        await route.continue();
    });
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать в пространство здоровья!' })).toBeVisible();
    await page.getByTestId('home-booking-cta').click();
    await expect(page).toHaveURL(/\/portal\/services/);
    await page.goto('/portal/more');
    await page.getByRole('link', { name: 'Личные данные', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Telegram', exact: true })).toHaveCount(0);
    await expect(page.getByText('Подключён', { exact: true })).toHaveCount(0);
    await page.getByLabel('Фамилия', { exact: true }).fill('Солдатов');
    await page.getByLabel('Имя', { exact: true }).fill('Денис');
    await page.getByLabel('Отчество', { exact: true }).fill('Сергеевич');
    await page.getByText('Мужской', { exact: true }).click();
    await expect(page.getByLabel('Мужской', { exact: true })).toBeChecked();
    await page.getByLabel('Мужской', { exact: true }).press('ArrowRight');
    await expect(page.getByLabel('Женский', { exact: true })).toBeChecked();
    await page.getByText('Мужской', { exact: true }).click();
    await expect(page.getByLabel('Мужской', { exact: true })).toBeChecked();
    const profileBirthDate = page.getByLabel('Дата рождения', { exact: true });
    await profileBirthDate.fill('1990-02-28');
    await profileBirthDate.press('Enter');
    await expect(profileBirthDate).toHaveValue('1990-02-28');
    await page.getByLabel('Страна', { exact: true }).fill('Каз');
    await expect(page.locator('#profile-country-options [role="option"]')).toHaveCount(1);
    await expect(page.locator('#profile-country-options [role="option"]')).toHaveText('Казахстан');
    await page.getByRole('option', { name: 'Казахстан', exact: true }).click();
    await expect(page.getByLabel('Страна', { exact: true })).toHaveValue('Казахстан');
    await page.getByLabel('Страна', { exact: true }).fill('Каз');
    await page.getByLabel('Страна', { exact: true }).press('ArrowUp');
    await page.getByLabel('Страна', { exact: true }).press('Enter');
    await expect(page.getByLabel('Страна', { exact: true })).toHaveValue('Казахстан');
    await page.getByLabel('Город', { exact: true }).fill('Алматы');
    await page.getByLabel('Телефон', { exact: true }).fill('+77001234567');
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.getByLabel('Страна', { exact: true }).click();
        await expect(page.getByRole('option', { name: 'Казахстан', exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
        await page.getByLabel('Страна', { exact: true }).press('Escape');
    }
    const profileForm = page.locator('form').filter({ has: page.getByLabel('Фамилия', { exact: true }) });
    await profileForm.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByText('Изменения сохранены.', { exact: true })).toBeVisible();
    await page.getByLabel('Город', { exact: true }).fill('Астана');
    profileResponses.failNextSave = true;
    await profileForm.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(profileForm.getByRole('alert')).toHaveText('Не удалось сохранить данные. Проверьте соединение и повторите попытку.');
    await page.reload();
    await expect(page.getByLabel('Город', { exact: true })).toHaveValue('Алматы');
    await page.getByLabel('Город', { exact: true }).fill('Астана');
    await profileForm.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByText('Изменения сохранены.', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Фамилия', { exact: true })).toHaveValue('Солдатов');
    await expect(page.getByLabel('Город', { exact: true })).toHaveValue('Астана');
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Добро пожаловать, Денис!' })).toBeVisible();
    await page.goto('/portal/more');
    await page.getByRole('link', { name: 'Медицинские данные', exact: true }).click();
    await expect(page.getByLabel('Подробно опишите жалобы', { exact: true })).toBeVisible();
    const complaintsGroup = page.locator('fieldset[data-medical-field="complaints"]');
    const complaintsLegend = await complaintsGroup.locator('legend').boundingBox();
    const complaintsChoices = await complaintsGroup.locator('.medical-profile__choice-grid').boundingBox();
    if (!complaintsLegend || !complaintsChoices) throw new Error('Medical complaint choices are not positioned');
    expect(complaintsChoices.y - (complaintsLegend.y + complaintsLegend.height)).toBeGreaterThanOrEqual(8);
    await expect(page.getByLabel('Шкала интенсивности боли (ВАШ):', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Задачи по здоровью', { exact: true })).toHaveAttribute('placeholder', 'Опишите конкретные результаты и задачи, которые вы хотите решить с Евгением (например: 1. Убрать тянущую боль в пояснице при наклонах. 2. Избавиться от утренней скованности и отеков)');
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    await expect(page.getByText('Опишите, каких результатов вы хотите достичь.', { exact: true })).toBeVisible();
    await expect(page.getByText('Выберите хотя бы один симптом или опишите жалобу.', { exact: true })).toBeVisible();
    await expect(page.getByText('Выберите «Да» или «Нет».', { exact: true })).toHaveCount(2);

    for (const symptom of ['Головные боли', 'Боль в шее', 'Боль в пояснице', 'Суставы', 'Отечность', 'Проблемы ЖКТ', 'Сонливость/Слабость', 'Бессонница', 'Стресс/Тревожность']) {
        const chip = page.getByLabel(symptom, { exact: true });
        await chip.check();
        await expect(chip).toBeChecked();
        await chip.uncheck();
        await expect(chip).not.toBeChecked();
    }
    await page.getByLabel('Боль в шее', { exact: true }).check();
    await page.getByLabel('Боль в пояснице', { exact: true }).check();
    await page.getByLabel('Боль в шее', { exact: true }).uncheck();
    await expect(page.getByLabel('Боль в пояснице', { exact: true })).toBeChecked();
    await page.getByLabel('Боль в шее', { exact: true }).check();
    await page.getByLabel('Подробно опишите жалобы', { exact: true }).fill('Synthetic symptoms');
    await page.getByLabel('Задачи по здоровью', { exact: true }).fill('Synthetic goals');
    await page.getByLabel('Хронических заболеваний нет', { exact: true }).check();
    await expect(page.getByLabel('Хронические заболевания', { exact: true })).toHaveCount(0);
    await page.getByLabel('Хронических заболеваний нет', { exact: true }).uncheck();
    await expect(page.getByLabel('Хронические заболевания', { exact: true })).toBeVisible();
    await page.getByLabel('Хронических заболеваний нет', { exact: true }).check();
    await page.locator('input[name="operations"][value="true"]').check();
    await expect(page.getByLabel('Год операции', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Описание операции или рубца (где находится шрам)', { exact: true })).toBeVisible();
    await page.locator('input[name="operations"][value="false"]').check();
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveCount(0);
    await page.locator('input[name="operations"][value="true"]').check();
    await page.getByLabel('Год операции', { exact: true }).fill('1899');
    await page.getByLabel('Описание операции или рубца (где находится шрам)', { exact: true }).fill('Synthetic scar location');
    await page.locator('input[name="injuries"][value="true"]').check();
    await expect(page.getByLabel('Год травмы', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('type', 'text');
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('inputmode', 'numeric');
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('placeholder', 'Например, 2020');
    await page.locator('input[name="injuries"][value="false"]').check();
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveCount(0);
    await page.locator('input[name="injuries"][value="true"]').check();
    await page.getByLabel('Характер травмы', { exact: true }).fill('Synthetic injury description');
    await page.getByLabel('Год операции', { exact: true }).fill('');
    await page.getByLabel('Год травмы', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    await expect(page.locator('#medical-operations-year-error')).toHaveText('Укажите год.');
    await expect(page.locator('#medical-injuries-year-error')).toHaveText('Укажите год.');
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveAttribute('aria-describedby', 'medical-operations-year-error');
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('aria-describedby', 'medical-injuries-year-error');
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await page.getByLabel('Год операции', { exact: true }).fill('1899');
    await page.getByLabel('Год травмы', { exact: true }).fill('2027');
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    await expect(page.locator('#medical-operations-year-error')).toHaveText(`Укажите год с 1900 по ${new Date().getFullYear()}.`);
    await expect(page.locator('#medical-injuries-year-error')).toHaveText(`Укажите год с 1900 по ${new Date().getFullYear()}.`);
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveAttribute('aria-describedby', 'medical-operations-year-error');
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('aria-describedby', 'medical-injuries-year-error');
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Год травмы', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveValue('1899');
    await expect(page.getByLabel('Описание операции или рубца (где находится шрам)', { exact: true })).toHaveValue('Synthetic scar location');
    await page.getByLabel('Год операции', { exact: true }).fill(String(new Date().getFullYear()));
    await page.getByLabel('Год травмы', { exact: true }).fill('2021');
    await expect(page.locator('#medical-operations-year-error')).toHaveCount(0);
    await expect(page.locator('#medical-injuries-year-error')).toHaveCount(0);
    const painSlider = page.locator('#medical-pain');
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await painSlider.scrollIntoViewIfNeeded();
        await expect(painSlider).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
        const sliderBounds = await painSlider.boundingBox();
        if (!sliderBounds) throw new Error(`Medical pain slider is not positioned at ${width}px`);
        expect(sliderBounds.x).toBeGreaterThanOrEqual(0);
        expect(sliderBounds.x + sliderBounds.width).toBeLessThanOrEqual(width);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await painSlider.scrollIntoViewIfNeeded();
    if (test.info().project.name === 'mobile') {
        const sliderBounds = await painSlider.boundingBox();
        if (!sliderBounds) throw new Error('Medical pain slider is not positioned for touch input');
        await page.touchscreen.tap(sliderBounds.x + sliderBounds.width - 2, sliderBounds.y + sliderBounds.height / 2);
        await expect(painSlider).toHaveValue('10');
        await expect(page.getByText('10 / 10', { exact: true })).toBeVisible();
    }
    await painSlider.focus();
    await painSlider.press('Home');
    await expect(page.getByText('0 / 10', { exact: true })).toBeVisible();
    for (let step = 0; step < 8; step++) {
        await painSlider.press('ArrowRight');
    }
    await expect(painSlider).toHaveValue('8');
    await expect(page.getByText('8 / 10', { exact: true })).toBeVisible();
    await painSlider.press('End');
    let failFirstSave = true;
    await page.route('**/portal/medical-profile*', async (route) => {
        if (route.request().method() === 'POST') {
            if (failFirstSave) {
                failFirstSave = false;
                await route.abort('internetdisconnected');
                return;
            }
            await new Promise((resolve) => setTimeout(resolve, 300));
        }
        await route.continue();
    });
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    await expect(page.getByText('Не удалось сохранить данные из-за проблем с соединением. Проверьте интернет и повторите попытку.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Подтвердить данные', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    const savingStatus = page.locator('[role="status"]').filter({ hasText: 'Сохраняем…' });
    await expect(savingStatus).toHaveText('Сохраняем…');
    await expect(page.getByRole('button', { name: 'Сохраняем…', exact: true })).toBeDisabled();
    await expect(page.getByText('Данные сохранены', { exact: true })).toBeVisible();
    await page.unroute('**/portal/medical-profile*');
    await expect(page.getByText('10 / 10', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByText('Synthetic symptoms', { exact: true })).toBeVisible();
    await expect(page.getByText('Synthetic scar location', { exact: true })).toBeVisible();
    const chronicValue = page.getByText('Хронические заболевания', { exact: true }).locator('..').locator('dd');
    await expect(chronicValue).toHaveText('Хронических заболеваний нет');
    await page.getByRole('button', { name: 'Редактировать данные', exact: true }).click();
    await expect(page.getByLabel('Подробно опишите жалобы', { exact: true })).toHaveValue('Synthetic symptoms');
    await expect(page.getByLabel('Хронических заболеваний нет', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Год операции', { exact: true })).toHaveValue(String(new Date().getFullYear()));
    await expect(page.getByLabel('Характер травмы', { exact: true })).toHaveValue('Synthetic injury description');
    await page.locator('#medical-pain').focus();
    await page.locator('#medical-pain').press('Home');
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), `Medical editor at ${width}px`).toBe(true);
    }
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    await expect(page.getByText('0 / 10', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Редактировать данные', exact: true })).toBeVisible();
    const fileChooserEvent = page.waitForEvent('filechooser');
    await page.getByRole('button', { name: 'Выбрать файлы', exact: true }).click();
    const fileChooser = await fileChooserEvent;
    await fileChooser.setFiles([
        { name: 'synthetic-pending.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n(Synthetic pending report)\nendobj\n%%EOF\n') },
    ]);
    await expect(page.getByRole('button', { name: 'Выбрать файлы', exact: true })).toBeVisible();
    await expect(page.locator('#medical-files')).toHaveCSS('clip-path', 'inset(50%)');
    await expect(page.getByText('Файлы не выбраны', { exact: false })).toHaveCount(0);
    await page.locator('#medical-files').setInputFiles([
        { name: 'synthetic-invalid.exe', mimeType: 'application/x-msdownload', buffer: Buffer.from('not a medical report') },
    ]);
    await expect(page.getByText('Поддерживаются только файлы JPG, PNG и PDF.', { exact: true })).toBeVisible();
    await expect(page.getByText('synthetic-pending.pdf · Не загружен', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Загрузить файлы', exact: true })).toBeDisabled();
    await page.locator('#medical-files').setInputFiles(Array.from({ length: 6 }, (_, index) => ({
        name: `synthetic-too-many-${index}.pdf`,
        mimeType: 'application/pdf',
        buffer: Buffer.from(`%PDF-1.4\n(${index})\n%%EOF\n`),
    })));
    await expect(page.getByText('Выберите не более 5 файлов за одну загрузку.', { exact: true })).toBeVisible();
    const maximumFileBytes = 20 * 1024 * 1024;
    const oversizedPdf = Buffer.alloc(maximumFileBytes + 1, 32);
    oversizedPdf.write('%PDF-1.4\n');
    oversizedPdf.write('%%EOF\n', maximumFileBytes - Buffer.byteLength('%%EOF\n'));
    await page.locator('#medical-files').setInputFiles([
        { name: 'synthetic-too-large.pdf', mimeType: 'application/pdf', buffer: oversizedPdf },
    ]);
    await expect(page.getByText('Размер файла не должен превышать 20 МБ. Выберите файл меньшего размера.', { exact: true })).toBeVisible();
    await page.locator('#medical-files').setInputFiles([
        { name: 'synthetic-maximum-size.pdf', mimeType: 'application/pdf', buffer: oversizedPdf.subarray(0, maximumFileBytes) },
    ]);
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Загрузить файлы', exact: true })).toBeEnabled();

    await page.locator('#medical-files').setInputFiles([
        { name: 'synthetic-maximum-size.pdf', mimeType: 'application/pdf', buffer: oversizedPdf.subarray(0, maximumFileBytes) },
        { name: 'synthetic-report-two.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n(Synthetic report two)\nendobj\n%%EOF\n') },
        { name: 'synthetic-report-three.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n(Synthetic report three)\nendobj\n%%EOF\n') },
        { name: 'synthetic-report-photo.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ5e4KDhIWGh4iJipKTlJWWl5iZmqKjpKWmpqipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/jp//aAAwDAQACEQMRAD8A+VKKKKAP/9k=', 'base64') },
        { name: 'synthetic-report-scan.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC', 'base64') },
    ]);
    await expect(page.getByRole('alert')).toHaveCount(0);
    let uploadFailureStep = 0;
    await page.route('**/portal/medical-attachments*', async (route) => {
        if (route.request().method() === 'POST') {
            if (uploadFailureStep === 0) {
                uploadFailureStep++;
                await route.abort('internetdisconnected');
                return;
            }
            if (uploadFailureStep === 1) {
                uploadFailureStep++;
                await route.fulfill({ status: 503, contentType: 'text/plain', body: 'Temporary upload failure.' });
                return;
            }
            await new Promise((resolve) => setTimeout(resolve, 250));
        }
        await route.continue();
    });
    await page.getByRole('button', { name: 'Загрузить файлы', exact: true }).click();
    await expect(page.getByText('Не удалось загрузить файл из-за проблем с соединением. Проверьте интернет и повторите загрузку.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Загрузить файлы', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Загрузить файлы', exact: true }).click();
    await expect(page.getByText('Не удалось загрузить файл. Повторите загрузку.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Загрузить файлы', exact: true })).toBeEnabled();
    await page.getByRole('button', { name: 'Загрузить файлы', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'synthetic-maximum-size.pdf' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Скачать файл: synthetic-maximum-size.pdf', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Скачать файл: synthetic-report-two.pdf', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Скачать файл: synthetic-report-three.pdf', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Скачать файл: synthetic-report-photo.jpg', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Скачать файл: synthetic-report-scan.png', exact: true })).toBeVisible();
    await expect(page.locator('#medical-files')).toBeDisabled();
    await expect(page.getByRole('status').filter({ hasText: 'В медицинском профиле уже загружено пять файлов.' })).toBeVisible();
    const attachmentLink = page.getByRole('link', { name: 'Скачать файл: synthetic-report-two.pdf', exact: true });
    await expect(attachmentLink).toHaveAttribute('download', 'synthetic-report-two.pdf');
    const attachmentUrl = await attachmentLink.getAttribute('href');
    if (!attachmentUrl) throw new Error('Medical attachment download link is missing its URL');
    const downloadEvent = page.waitForEvent('download');
    await attachmentLink.click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toBe('synthetic-report-two.pdf');
    expect(await download.failure()).toBeNull();
    await page.unroute('**/portal/medical-attachments*');
    await page.reload();
    await expect(page.getByRole('link', { name: 'Скачать файл: synthetic-report-two.pdf', exact: true })).toBeVisible();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), `Medical profile at ${width}px`).toBe(true);
    }
    expect(await page.evaluate(() => JSON.stringify({
        history: window.history.state,
        local: { ...window.localStorage },
        session: { ...window.sessionStorage },
    }))).not.toContain('Synthetic symptoms');
    await page.context().clearCookies({ name: fixture.cookieName });
    const unauthorizedAttachmentResponse = await page.request.get(attachmentUrl, { headers: { Accept: 'application/json' } });
    expect(unauthorizedAttachmentResponse.status()).toBe(401);
    const unauthorizedAttachmentPayload = await unauthorizedAttachmentResponse.json() as { message?: unknown };
    expect(unauthorizedAttachmentPayload.message).toMatch(/войдите|sign in/i);
    expect(JSON.stringify(unauthorizedAttachmentPayload)).not.toContain('Synthetic report two');
    const guestResponse = await page.goto('/');
    expect(guestResponse?.headers()['cache-control']).toContain('no-store');
    await expect(page.getByText('Synthetic symptoms', { exact: true })).toHaveCount(0);
    const deniedMedicalResponse = page.waitForResponse((response) =>
        response.url().endsWith('/portal/medical-profile') && response.status() === 401,
    );
    await page.goBack();
    await deniedMedicalResponse;
    await expect(page.getByText('Synthetic symptoms', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.body.textContent)).not.toContain('Synthetic symptoms');
});

test('September 26 paid focus stays on home through completion and correction and empty focus opens the tracker', async ({ page }) => {
    const active = createBookingFixture({ tracker: 'task' });
    await page.context().addCookies([{ name: active.cookieName, value: active.cookieValue, url: 'http://127.0.0.1:8000' }]);
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.name));
    await page.goto('/');
    await expect(page.getByText('Фокус дня', { exact: true })).toBeVisible();
    await expect(page.getByText('Практика QA', { exact: true })).toBeVisible();
    const healthAction = page.getByTestId('home-health-action');
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertHealthActionHeadingLayout(healthAction);
        await assertNoHorizontalOverflow(page);
    }
    const completed = page.getByRole('checkbox', { name: 'Выполнено', exact: true });

    await page.route('**/portal/tracker/tasks/*/entry', async (route) => {
        if (route.request().method() === 'POST') {
            await route.continue({
                postData: JSON.stringify({ status: 'unknown' }),
                headers: { ...route.request().headers(), 'content-type': 'application/json' },
            });

            return;
        }

        await route.continue();
    });
    const invalidStatus = page.waitForResponse((response) => response.request().method() === 'POST'
        && response.url().includes('/portal/tracker/tasks/')
        && response.status() >= 300 && response.status() < 400);
    await completed.click();
    await invalidStatus;
    await expect(page.getByRole('alert')).toHaveText('Не удалось определить отметку задачи. Выберите состояние и попробуйте снова.');
    await expect(completed).not.toBeChecked();
    await page.unroute('**/portal/tracker/tasks/*/entry');

    await page.route('**/portal/tracker/tasks/*/entry', async (route) => {
        if (route.request().method() === 'POST') {
            await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Not found' }) });

            return;
        }

        await route.continue();
    });
    await completed.click();
    await expect(page.getByRole('alert')).toHaveText('Эта задача больше недоступна. Мы обновили её статус.');
    await expect(completed).not.toBeChecked();
    await expect(page.getByRole('status').filter({ hasText: 'Статус обновлён.' })).toBeVisible();
    await page.unroute('**/portal/tracker/tasks/*/entry');

    await page.route('**/portal/tracker/tasks/*/entry', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();

            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
        await route.fulfill({ status: 503, contentType: 'text/plain', body: 'Unavailable' });
    });
    await completed.click();
    await expect(page.getByRole('status').filter({ hasText: 'Сохраняем отметку…' })).toBeVisible();
    await expect(completed).toBeDisabled();
    await expect(page.getByRole('alert')).toHaveText('Сервер не сохранил отметку. Проверьте актуальный статус задачи и повторите действие.');
    await expect(completed).not.toBeChecked();
    await expect(page.getByRole('status').filter({ hasText: 'Статус обновлён.' })).toBeVisible();
    await page.unroute('**/portal/tracker/tasks/*/entry');

    await page.route('**/portal/tracker/tasks/*/entry', async (route) => {
        if (route.request().method() === 'POST') {
            await route.abort('failed');

            return;
        }

        await route.continue();
    });
    await completed.click();
    await expect(page.getByRole('alert')).toHaveText('Не удалось связаться с сервером. Проверьте соединение; мы сверим статус, затем можно повторить отметку.');
    await expect(completed).not.toBeChecked();
    await expect(page.getByRole('status').filter({ hasText: 'Статус обновлён.' })).toBeVisible();
    expect(pageErrors).toEqual([]);
    await page.unroute('**/portal/tracker/tasks/*/entry');

    const completion = page.waitForResponse((response) => response.request().method() === 'POST' && /\/portal\/tracker\/tasks\/\d+\/entry$/.test(response.url()));
    await completed.check();
    const completionResponse = await completion;
    expect(completionResponse.status()).toBe(302);
    expect(new URL(completionResponse.headers().location, completionResponse.url()).pathname).toBe('/');
    await expect(completed).toBeEnabled();
    await expect(completed).toBeChecked();
    await expect(page.getByRole('status').filter({ hasText: 'Отметка сохранена: задача выполнена.' })).toBeVisible();
    await expect(page).toHaveURL(/\/$/);
    await page.reload();
    await expect(completed).toBeChecked();
    const correction = page.waitForResponse((response) => response.request().method() === 'POST' && /\/portal\/tracker\/tasks\/\d+\/entry$/.test(response.url()));
    await completed.uncheck();
    const correctionResponse = await correction;
    expect(correctionResponse.status()).toBe(302);
    expect(new URL(correctionResponse.headers().location, correctionResponse.url()).pathname).toBe('/');
    await expect(completed).toBeEnabled();
    await expect(page.getByRole('status').filter({ hasText: 'Отметка снята. Задача снова отмечена как невыполненная.' })).toBeVisible();
    await page.reload();
    await expect(completed).not.toBeChecked();
    await page.getByRole('link', { name: 'Открыть трекер', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/tracker$/);
    await expect(page.getByText('Практика QA', { exact: true })).toBeVisible();
    await expect(page.getByRole('paragraph').filter({ hasText: /^Не выполнено$/ })).toBeVisible();

    const empty = createBookingFixture({ tracker: 'empty' });
    await page.context().addCookies([{ name: empty.cookieName, value: empty.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/');
    await expect(page.getByText('Фокус дня', { exact: true })).toBeVisible();
    await expect(page.getByText('На сегодня практик нет. Откройте трекер, чтобы посмотреть свой план.', { exact: true })).toBeVisible();
    await expect(completed).toHaveCount(0);
    await page.getByRole('link', { name: 'Открыть трекер', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/tracker$/);
    await expect(page.getByText('На сегодня задач нет.', { exact: true })).toBeVisible();
});

test('tracker history separates programme marks from wellbeing notes and keeps the Russian date format', async ({ page }) => {
    const fixture = createBookingFixture({ tracker: 'checkins' });
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);

    await page.goto('/portal/tracker');
    await page.getByRole('button', { name: 'История', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Самочувствие', exact: true })).toBeVisible();
    await expect(page.getByText('Синтетическая заметка о самочувствии.', { exact: true })).toBeVisible();
    await expect(page.getByText('Отметок по программе пока нет.', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Записей пока нет.', { exact: true })).toHaveCount(0);
    await expect(page.locator('.portal-tracker-history-note time')).toHaveText(/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/);
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(page.getByText('Синтетическая заметка о самочувствии.', { exact: true })).toBeVisible();
        await assertNoHorizontalOverflow(page);
    }
});

test('finance page explains an unavailable payment and hides internal test controls', async ({ page }) => {
    const fixture = createBookingFixture();
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.route('**/portal/finance*', async (route) => {
        if (route.request().method() !== 'GET' || route.request().headers()['x-inertia'] !== 'true') {
            await route.continue();
            return;
        }

        const response = await route.fetch();
        const payload = await response.json() as { props?: Record<string, unknown> };
        if (payload.props !== undefined) {
            payload.props.obligations = [{
                available: true,
                serviceName: 'Индивидуальная консультация',
                bookingUrl: null,
                completedAt: '26.10.2026 11:00',
                obligationMinor: 1500000,
                paidMinor: 500000,
                outstandingMinor: 1000000,
                displayCurrency: 'EUR',
                originalCurrency: 'EUR',
                status: 'partially_paid',
                statusLabel: 'Оплачено частично',
                history: [{
                    available: true,
                    amountMinor: 500000,
                    currency: 'EUR',
                    occurredAt: '26.10.2026 10:00',
                    methodLabel: 'Наличные',
                    receiptUrl: null,
                }],
                referralCredit: null,
                demoPayment: {
                    stateLabel: 'Демо-оплата готова к запуску',
                    canStart: true,
                    canSucceed: false,
                    canFail: false,
                    canRefund: false,
                    startUrl: '/internal-test-payment',
                    successUrl: null,
                    failUrl: null,
                    refundUrl: null,
                },
                lavaPayment: {
                    stateLabel: 'Онлайн-оплата недоступна',
                    canStart: false,
                    canContinue: false,
                    checkoutUrl: null,
                    startUrl: '/online-payment',
                    poll: false,
                },
                purchaseFulfillment: null,
            }];
            payload.props.totals = [{ amountMinor: 1000000, currency: 'EUR' }];
            payload.props.hasUnavailableObligations = false;
        }
        await route.fulfill({ response, body: JSON.stringify(payload) });
    });

    await page.goto('/portal/more');
    await page.getByRole('link', { name: 'Оплата', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Оплата и задолженность', exact: true })).toBeVisible();
    await expect(page.locator('.portal-finance-status--partially_paid')).toHaveText('Оплачено частично');
    await expect(page.getByRole('status').filter({ hasText: 'Чтобы оплатить остаток, свяжитесь с клиникой.' })).toBeVisible();
    await expect(page.getByText(/Демо-оплата|Создать операцию|Вернуть оплату/)).toHaveCount(0);
    const history = page.locator('.portal-finance-history');
    await expect(history).toHaveJSProperty('open', false);
    await history.locator('summary').click();
    await expect(history.getByText('Наличные', { exact: true })).toBeVisible();
    await expect(history).toContainText('26.10.2026 10:00');
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(page.locator('.portal-finance-status--partially_paid')).toBeVisible();
        await assertNoHorizontalOverflow(page);
    }
});

test('September 26 client selects multiple sessions before the medical editor and can cancel one independently', async ({ page }) => {
    const fixture = createBookingFixture({ medicalService: true, daysAhead: 3 });
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    await page.getByRole('button', { name: fixture.date, exact: true }).click();
    await page.getByTestId('availability-slot').first().click();
    const firstSlotText = await page.getByTestId('availability-slot').first().textContent();
    await page.getByTestId('booking-add-session').click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(1);
    await expect(page.getByRole('heading', { name: 'Медицинские данные', exact: true })).toHaveCount(0);
    expect(await page.getByTestId('availability-slot').first().textContent()).not.toBe(firstSlotText);
    await page.getByTestId('availability-slot').first().click();
    await page.getByTestId('booking-add-session').click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(2);
    await page.getByTestId('queued-booking-session').last().getByRole('button', { name: 'Убрать сеанс' }).click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(1);
    await page.getByTestId('availability-slot').first().click();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    }
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await expect(page.locator('.portal-booking-summary time')).toHaveCount(4);
    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/medical-profile\?booking_id=\d+&edit=1/);
    await expect(page.getByRole('heading', { name: 'Медицинские данные', exact: true })).toBeVisible();
    const repeatedMedicalNavigation = page.waitForRequest((request) =>
        request.method() === 'GET' && new URL(request.url()).pathname === '/portal/medical-profile',
    { timeout: 2000 }).catch(() => null);
    await page.goBack();
    await expect(page).toHaveURL(/\/portal\/bookings\/create\?/);
    await expect(page.locator('#booking-success-heading')).toBeVisible();
    await expect(page.locator('.portal-booking-summary time')).toHaveCount(4);
    expect(await repeatedMedicalNavigation).toBeNull();
    await page.goForward();
    await expect(page).toHaveURL(/\/portal\/medical-profile\?booking_id=\d+&edit=1/);
    await expect(page.getByRole('heading', { name: 'Медицинские данные', exact: true })).toBeVisible();
    const result = execFileSync('php', ['artisan', 'tinker', '--execute', `
        $bookings = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::query()->where('client_id', ${fixture.clientId})->orderBy('starts_at')->get();
        echo json_encode($bookings->map(fn ($booking) => ['id' => $booking->getKey(), 'source' => $booking->source->value, 'client_id' => $booking->client_id])->all());
    `], { encoding: 'utf8' }).trim();
    const bookings = JSON.parse(result) as Array<{ id: number; source: string; client_id: number }>;
    expect(bookings).toHaveLength(2);
    expect(bookings.every((booking) => booking.source === 'portal' && booking.client_id === fixture.clientId)).toBe(true);
    await page.getByRole('link', { name: 'Мои записи' }).last().click();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toHaveCount(2);
    await page.reload();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toHaveCount(2);
    const detailResponse = page.waitForResponse((response) => /\/portal\/bookings\/\d+$/.test(new URL(response.url()).pathname)
        && response.request().method() === 'GET');
    await page.getByRole('link', { name: new RegExp(fixture.serviceName) }).first().click();
    const detail = await detailResponse;
    expect(detail.status()).toBe(200);
    expect(detail.request().headers()['x-inertia']).toBe('true');
    const detailPage = await detail.json() as { component: string; props: { booking: { canCancel: boolean; startsAt: string; statusLabel: string }; portal: { locale: string } } };
    expect(detailPage.component).toBe('Portal/BookingShow');
    expect(detailPage.props.portal.locale).toBe('ru');
    expect(detailPage.props.booking.canCancel, JSON.stringify({ startsAt: detailPage.props.booking.startsAt, expectedDate: fixture.date, status: detailPage.props.booking.statusLabel })).toBe(true);
    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Отменить', exact: true }).first().click();
    await expect(page.getByText('Отменена', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('button', { name: 'Отменить', exact: true })).toHaveCount(0);
    await page.getByRole('link', { name: 'Мои записи' }).last().click();
    await page.getByRole('link', { name: new RegExp(fixture.serviceName) }).first().click();
    await expect(page.getByRole('button', { name: 'Отменить', exact: true })).toBeVisible();
});

test('September 26 multi-session booking explains a stale slot and succeeds after replacement', async ({ page }) => {
    const fixture = createBookingFixture({ medicalService: true, daysAhead: 3 });
    const dateAtOffset = (days: number): string => {
        const date = new Date(`${fixture.date}T00:00:00.000Z`);
        date.setUTCDate(date.getUTCDate() + days);
        return date.toISOString().slice(0, 10);
    };
    const finalDate = dateAtOffset(3);
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${finalDate}&format=office`);
    await page.getByRole('button', { name: fixture.date, exact: true }).click();
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Добавить ещё сеанс', exact: true }).click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(1);

    const secondDate = dateAtOffset(1);
    await page.getByRole('button', { name: secondDate, exact: true }).click();
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Добавить ещё сеанс', exact: true }).click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(2);

    const conflictStart = await page.getByTestId('queued-booking-session').first().locator('time').getAttribute('datetime');
    if (conflictStart === null) {
        throw new Error('The selected multi-session slot did not expose its canonical start instant.');
    }

    await page.getByTestId('queued-booking-session').last().getByRole('button', { name: 'Убрать сеанс' }).click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(1);

    const thirdDate = dateAtOffset(2);
    await page.getByRole('button', { name: thirdDate, exact: true }).click();
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await acceptRequiredConsents(page);

    execFileSync('php', ['artisan', 'tinker', '--execute', `
        $organization = \\App\\Modules\\Organizations\\Domain\\Models\\Organization::query()->where('slug', 'chuklov')->firstOrFail();
        app(\\App\\Modules\\Organizations\\Application\\OrganizationContext::class)->set($organization);
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        app(\\App\\Modules\\Scheduling\\Application\\CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: \\App\\Modules\\Specialists\\Domain\\Models\\Specialist::query()->findOrFail(${fixture.specialistId}),
            service: \\App\\Modules\\Services\\Domain\\Models\\Service::query()->findOrFail(${fixture.serviceId}),
            startsAt: \\Carbon\\CarbonImmutable::parse(${JSON.stringify(conflictStart)}),
            format: \\App\\Modules\\Scheduling\\Domain\\Enums\\VisitFormat::Office,
            idempotencyKey: 'playwright-multi-session-conflict-' . \\Illuminate\\Support\\Str::uuid(),
        );
        echo 'reserved';
    `], { encoding: 'utf8', env: { ...process.env, APP_ENV: 'e2e' }, stdio: ['ignore', 'pipe', 'pipe'] });

    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    const staleSlotError = page.getByRole('alert').filter({ hasText: 'больше недоступен' });
    await expect(staleSlotError).toContainText('Выберите другое время');
    await expect(page).toHaveURL(/\/portal\/bookings\/create/);
    await expect(page.getByRole('heading', { name: 'Медицинские данные', exact: true })).toHaveCount(0);

    await page.locator('.portal-booking-confirmation').getByRole('button', { name: 'Изменить дату и время', exact: true }).click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(1);
    await expect(page.getByRole('button', { name: thirdDate, exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-testid="availability-slot"][aria-pressed="true"]')).toHaveCount(1);
    await page.getByTestId('queued-booking-session').first().getByRole('button', { name: 'Убрать сеанс' }).click();
    await expect(staleSlotError).toHaveCount(0);
    await page.getByRole('button', { name: 'Добавить ещё сеанс', exact: true }).click();
    await expect(page.getByTestId('queued-booking-session')).toHaveCount(1);

    await page.getByRole('button', { name: finalDate, exact: true }).click();
    await page.getByTestId('availability-slot').first().click();
    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalOverflow(page);
    }

    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/medical-profile\?booking_id=\d+&edit=1/);

    const result = execFileSync('php', ['artisan', 'tinker', '--execute', `
        $bookings = \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::query()->where('client_id', ${fixture.clientId})->orderBy('starts_at')->get();
        echo json_encode($bookings->map(fn ($booking) => ['id' => $booking->getKey(), 'source' => $booking->source->value, 'client_id' => $booking->client_id])->all());
    `], { encoding: 'utf8' }).trim();
    const bookings = JSON.parse(result) as Array<{ id: number; source: string; client_id: number }>;
    expect(bookings).toHaveLength(2);
    expect(bookings.every((booking) => booking.source === 'portal' && booking.client_id === fixture.clientId)).toBe(true);
});

test('September 26 booking explains when self-service changes are closed', async ({ page }) => {
    const fixture = createBookingFixture({ withBooking: true, bookingWithinCancellationCutoff: true });
    if (fixture.bookingId === null) {
        throw new Error('The cancellation cutoff fixture did not create a booking.');
    }

    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto(`/portal/bookings/${fixture.bookingId}`);

    const guidance = page.getByRole('status').filter({
        hasText: 'Сейчас запись уже нельзя изменить или отменить самостоятельно. Свяжитесь с нами — поможем.',
    });

    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    await expect(guidance).toBeVisible();
    await expect(page.getByRole('button', { name: 'Отменить', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Перенести', exact: true })).toHaveCount(0);

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(guidance).toBeVisible();
        await assertNoHorizontalOverflow(page);
    }

    await page.reload();
    await expect(guidance).toBeVisible();
});

test('September 26 medical service opens the saved-profile onboarding after the slot is created', async ({ page }) => {
    const fixture = createBookingFixture({ medicalService: true });
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/medical-profile\?booking_id=\d+&edit=1/);
    const bookingId = new URL(page.url()).searchParams.get('booking_id');
    expect(bookingId).toMatch(/^\d+$/);
    await page.getByLabel('Головные боли', { exact: true }).check();
    await page.locator('#medical-goals').fill('Проверка завершения записи.');
    await page.getByLabel('Хронических заболеваний нет', { exact: true }).check();
    await page.locator('input[name="operations"][value="false"]').check();
    await page.locator('input[name="injuries"][value="false"]').check();
    await page.getByRole('button', { name: 'Подтвердить данные', exact: true }).click();
    await expect(page).toHaveURL(/\/portal\/bookings\/\d+$/);
    await expect(page.getByRole('status').filter({ hasText: 'Медицинские данные подтверждены' })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true }).first()).toBeVisible();
    await page.reload();
    await expect(page.getByRole('status').filter({ hasText: 'Медицинские данные подтверждены' })).toHaveCount(0);
    await page.goto(`/portal/medical-profile?booking_id=${bookingId}`);
    await expect(page.getByText('Проверка завершения записи.', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByText('Проверка завершения записи.', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'К записи', exact: true }).click();
    await expect(page).toHaveURL(`/portal/bookings/${bookingId}`);
    await expect(page.getByText(fixture.serviceName, { exact: true }).first()).toBeVisible();
});

test('September 26 non-medical service skips onboarding and reopens the booking confirmation', async ({ page }) => {
    const fixture = createBookingFixture({ medicalService: false });
    await page.context().addCookies([{ name: fixture.cookieName, value: fixture.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();

    await expect(page).toHaveURL(/\/portal\/bookings\/create\?.*booking_id=\d+/);
    await expect(page).not.toHaveURL(/\/portal\/medical-profile/);
    await expect(page.getByRole('heading', { name: 'Запись создана.', exact: true })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Мои записи', exact: true })).toBeVisible();

    const confirmationUrl = page.url();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Запись создана.', exact: true })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true }).first()).toBeVisible();
    await expect(page).not.toHaveURL(/\/portal\/medical-profile/);

    await page.goto(confirmationUrl);
    await expect(page.getByRole('heading', { name: 'Запись создана.', exact: true })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true }).first()).toBeVisible();
});

test('client can change display timezone and choose a physical office', async ({ page }) => {
    const fixture = createBookingFixture({ multipleLocations: true });

    expect(fixture.firstWorkingLocationName).not.toBeNull();
    expect(fixture.secondWorkingLocationId).not.toBeNull();
    expect(fixture.secondWorkingLocationName).not.toBeNull();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);

    const locationSelect = page.getByTestId('booking-working-location-select');
    await expect(locationSelect).toBeVisible();
    await expect(locationSelect).toHaveValue('');
    const calendarTimezone = page.locator('.portal-booking-calendar__timezone');
    await expect(calendarTimezone).toContainText('UTC+00:00');
    await expect(calendarTimezone).not.toContainText(/UTC\d{4}-\d{2}-\d{2}T/);
    await locationSelect.selectOption(String(fixture.secondWorkingLocationId));
    await expect(locationSelect).toHaveValue(String(fixture.secondWorkingLocationId));
    await expect(page.getByTestId('booking-context-summary')).toContainText(fixture.secondWorkingLocationName as string);
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();

    await expect(page.getByTestId('booking-context-summary')).toBeVisible();
    await expect(page.getByTestId('booking-client-timezone-select')).toHaveCount(0);
    await page.getByTestId('booking-client-timezone-edit').click();
    const timezoneSelect = page.getByTestId('booking-client-timezone-select');
    await expect(timezoneSelect).toBeVisible();
    await expect(timezoneSelect).toHaveValue('UTC');
    await timezoneSelect.selectOption('Europe/Berlin');
    await expect(page.getByTestId('booking-client-timezone')).toHaveText('Europe/Berlin');
    await expect(page.getByTestId('booking-client-timezone-select')).toHaveCount(0);
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();
});

test('home visit selection highlights the nearest configured date and keeps the flow compact', async ({ page }) => {
    const fixture = createBookingFixture({ homeVisit: true });
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 7);
    const dateTo = dateToValue.toISOString().slice(0, 10);

    expect(fixture.homeVisitDate).not.toBeNull();
    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${dateTo}&format=home`);

    const areaSelect = page.getByTestId('booking-location-area-select');
    await expect(areaSelect).toBeVisible();
    await areaSelect.selectOption('Bang Tao');
    await expect(areaSelect).toHaveValue('Bang Tao');
    await expect(page.getByTestId('booking-context-summary')).toBeVisible();
    await expect(page.getByText(/Выезды в Bang Tao доступны/)).toBeVisible();

    const nearestDates = page.getByTestId('booking-nearest-dates');
    await expect(nearestDates).toBeVisible();
    await expect(nearestDates.locator('button')).toHaveCount(1);
    await expect(nearestDates.locator('button').first()).toHaveAttribute('data-date', fixture.homeVisitDate as string);
    await expect(nearestDates.locator('button').first()).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();
    await expect(page.getByText('На этот день свободного времени нет.', { exact: true })).toHaveCount(0);
    await assertNoHorizontalOverflow(page);

    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();
    await acceptRequiredConsents(page);
    await page.locator('#booking-location').fill('Bang Tao, Villa 1');
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Заявка отправлена. Мы подтвердим время отдельно.' })).toBeVisible();
});

test('booking empty search renders one range-level empty state', async ({ page }) => {
    const fixture = createBookingFixture({ homeVisit: true });
    const emptyDateValue = new Date(`${fixture.date}T00:00:00Z`);
    emptyDateValue.setUTCMonth(emptyDateValue.getUTCMonth() + 1);
    const emptyDate = emptyDateValue.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${emptyDate}&date_to=${emptyDate}&format=home`);
    await page.getByTestId('booking-location-area-select').selectOption('Bang Tao');
    await expect(page.getByTestId('booking-range-empty')).toHaveCount(1);
    await expect(page.getByTestId('booking-day-empty')).toHaveCount(0);
    await expect(page.getByText('Для выезда в выбранный район пока нет свободного времени. Проверьте другой район или следующую дату.', { exact: true })).toHaveCount(1);
    await assertNoHorizontalOverflow(page);
});

test('booking keeps its state while reviewing grouped legal documents', async ({ page }) => {
    const fixture = createBookingFixture();

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    const requiredCheckbox = page.getByRole('checkbox', {
        name: 'Я ознакомился с обязательными документами и принимаю их условия',
        exact: true,
    });
    const documentLinks = page.locator('.portal-legal-document-links button');
    const dialog = page.getByRole('dialog');

    await expect(documentLinks).toHaveCount(3);
    await expect(requiredCheckbox).not.toBeChecked();
    const documentTitle = (await documentLinks.first().innerText()).trim();
    expect(documentTitle).not.toBe('');
    await documentLinks.first().click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: documentTitle, exact: true })).toBeVisible();
    await expect(dialog.locator('.portal-legal-modal__content')).toContainText(/\S/);
    await dialog.getByRole('button', { name: 'Закрыть' }).first().click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page.getByText('Подтвердите ознакомление с обязательными документами.', { exact: true })).toHaveCount(1);
    await requiredCheckbox.check();
    await expect(requiredCheckbox).toBeChecked();
    await assertNoHorizontalOverflow(page);
});

test('booking denial stays visible while city suggestions are requested', async ({ page }) => {
    const fixture = createBookingFixture();
    execFileSync('php', ['artisan', 'tinker', '--execute', `
        $client = \\App\\Modules\\Identity\\Domain\\Models\\Client::query()->findOrFail(${fixture.clientId});
        $admin = \\App\\Models\\User::factory()->forOrganization($client->organization)->create();
        app(\\App\\Modules\\Identity\\Application\\BlockClientBlacklist::class)->handle(
            $admin,
            $client,
            'Playwright synthetic booking denial.',
        );
    `], { encoding: 'utf8' });
    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    let cityLookupRequests = 0;
    page.on('request', (request) => {
        if (new URL(request.url()).pathname === '/portal/profile/cities') cityLookupRequests++;
    });
    await page.goto(`/portal/bookings/create?service_id=${fixture.serviceId}&date_from=${fixture.date}&date_to=${fixture.date}&format=office`);
    await page.getByRole('button', { name: fixture.date, exact: true }).click();
    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    const cityInput = page.getByRole('combobox', { name: 'Город', exact: true });
    const confirm = page.getByRole('button', { name: 'Подтвердить запись', exact: true });
    await expect(cityInput).toBeVisible();
    await page.waitForTimeout(350);
    expect(cityLookupRequests).toBe(0);

    const cityResponsePromise = page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/portal/profile/cities');
    await cityInput.focus();
    await expect(confirm).toBeDisabled();
    await expect(page.getByRole('status').filter({ hasText: 'Ищем города…' })).toBeVisible();
    expect((await cityResponsePromise).status()).toBe(200);
    await expect(confirm).toBeEnabled();

    await acceptRequiredConsents(page);
    const bookingResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/portal/bookings');
    await confirm.click();
    expect((await bookingResponse).status()).toBe(302);
    await expect(page.getByRole('alert')).toHaveText(
        'Для этого профиля самостоятельная запись недоступна. Свяжитесь с нами, и мы поможем подобрать вариант.',
    );

    const result = execFileSync('php', ['artisan', 'tinker', '--execute', `
        echo json_encode([
            'bookings' => \\App\\Modules\\Scheduling\\Domain\\Models\\Booking::query()->where('client_id', ${fixture.clientId})->count(),
            'idempotencyKeys' => \\Illuminate\\Support\\Facades\\DB::table('booking_idempotency_keys')
                ->where('actor_scope', 'client:${fixture.clientId}')
                ->count(),
        ], JSON_THROW_ON_ERROR);
    `], { encoding: 'utf8' }).trim();
    expect(JSON.parse(result)).toEqual({ bookings: 0, idempotencyKeys: 0 });
});

test('booking uses a service step and selected-day calendar before confirmation', async ({ page }) => {
    const fixture = createBookingFixture();
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);
    const nextMonth = new Date(`${fixture.date.slice(0, 7)}-01T00:00:00Z`);
    nextMonth.setUTCMonth(nextMonth.getUTCMonth() + 1);
    const nextMonthFrom = nextMonth.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
    await expect(page.getByRole('heading', { name: 'Выберите услугу' }).first()).toBeVisible();
    await expect(page.locator('select[name="service_id"]')).toHaveCount(0);
    await expect(page.locator('input[name="date_from"], input[name="date_to"]')).toHaveCount(0);
    await page.locator('.portal-booking-option').filter({ hasText: fixture.serviceName }).click();
    await page.getByRole('button', { name: 'Продолжить' }).click();

    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
    await expect(page.locator('.portal-slot-day')).toHaveCount(0);
    expect(await page.locator('.portal-calendar-card__day').count()).toBeGreaterThan(7);
    const outsideMonthDays = page.locator('.portal-calendar-card__day--outside');
    expect(await outsideMonthDays.count()).toBeGreaterThan(0);
    await expect(outsideMonthDays.first()).toBeDisabled();
    expect(await outsideMonthDays.first().evaluate((element) => window.getComputedStyle(element).pointerEvents)).not.toBe('none');
    await expect(page.locator('.portal-calendar-card__day--outside.portal-calendar-card__day--available')).toHaveCount(0);

    await page.getByRole('button', { name: 'Следующий месяц' }).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('date_from')).toBe(nextMonthFrom);
    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();

    const availableDays = page.locator('.portal-calendar-card__day--available');
    expect(await availableDays.count()).toBeGreaterThan(1);
    await availableDays.first().click();
    await expect(availableDays.first()).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.portal-time-card')).toHaveCount(1);

    const time = page.getByTestId('availability-slot').first();
    await expect(page.getByTestId('availability-slot')).toHaveCount(3);
    await expect(time).toHaveText(/^\d{2}:\d{2}$/);
    await expect(time).toHaveAttribute('aria-label', /UTC[+-]\d{2}:\d{2}/);
    await expect(page.getByText(/Ваш часовой пояс: UTC[+-]\d{2}:\d{2}/)).toBeVisible();
    await time.click();
    await expect(time).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Продолжить' }).click();
    await expect(page.getByRole('heading', { name: 'Проверьте запись' })).toBeVisible();
    await expect(page.getByText(/Playwright Service/)).toBeVisible();
    await expect(page.getByText(/Playwright Specialist/)).toBeVisible();
    const partySize = page.getByLabel('Количество человек', { exact: true });
    await expect(partySize).toHaveValue('1');
    await partySize.fill('3');
    await expect(partySize).toHaveValue('3');
    await expect(page.getByRole('button', { name: 'Изменить дату и время' })).toBeVisible();

    await acceptRequiredConsents(page);
    const bookingResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && response.url().endsWith('/portal/bookings'));
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await assertPortalResponseAccepted(bookingResponse, 'Create portal booking');
    await expect(page.getByRole('heading', { name: 'Запись создана.' })).toBeVisible();

    await page.getByRole('link', { name: 'Мои записи' }).last().click();
    await expect(page.getByRole('heading', { name: 'Мои записи' })).toBeVisible();
    const createdBookings = page.locator('a.portal-card').filter({ hasText: fixture.serviceName });
    await expect(createdBookings).toHaveCount(1);
    await createdBookings.first().click();
    await expect(page.getByRole('heading', { name: fixture.serviceName, exact: true })).toBeVisible();
    await expect(page.getByText('Количество человек: 3', { exact: true })).toBeVisible();
});

test('booking shell stays readable at narrow Mini App widths', async ({ page }) => {
    const fixture = createBookingFixture();
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [320, 360]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
        await expect(page.getByRole('heading', { name: 'Выберите услугу' }).first()).toBeVisible();
        await expect(page.locator('.portal-booking-progress__label')).toHaveCount(4);
        await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        await page.locator('.portal-booking-option').filter({ hasText: fixture.serviceName }).click();
        await page.getByRole('button', { name: 'Продолжить' }).click();
        await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
        await expect(page.locator('.portal-calendar-card__weekdays span')).toHaveCount(7);
        await expect(page.getByTestId('availability-slot').first()).toBeVisible();
        await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
});

test('booking primary action stays inside every acceptance viewport', async ({ page }) => {
    const fixture = createBookingFixture();
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    for (const width of [1440, 1280, 1024, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
        await expect(page.getByRole('heading', { name: 'Выберите услугу' }).first()).toBeVisible();
        await assertRenderedViewportGeometry(
            page,
            ['.portal-booking-choice > .portal-button--primary, .portal-booking-flow__cta'],
            ['.portal-booking-flow'],
        );
    }
});

test('long multi-specialist and multi-format booking stays fully readable at 320px', async ({ page }) => {
    const fixture = createBookingFixture({
        multipleChoices: true,
        longServiceTitle: true,
    });
    const dateToValue = new Date(`${fixture.date}T00:00:00Z`);
    dateToValue.setUTCDate(dateToValue.getUTCDate() + 1);
    const dateTo = dateToValue.toISOString().slice(0, 10);

    expect(fixture.alternateSpecialistName).not.toBeNull();
    await page.setViewportSize({ width: 320, height: 844 });
    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto(`/portal/bookings/create?date_from=${fixture.date}&date_to=${dateTo}`);
    await expect(page.getByRole('heading', { name: 'Выберите услугу' })).toBeVisible();
    await assertNoHorizontalOverflow(page);

    await page.locator('.portal-booking-option').filter({ hasText: fixture.serviceName }).click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Выберите специалиста' })).toBeVisible();
    await assertReadableProgress(page, 4);
    await assertFullSelectedServiceTitle(page, fixture.serviceName, 'booking-choice-service');
    await assertNoHorizontalOverflow(page);

    await page.locator('.portal-booking-option').filter({ hasText: fixture.specialistName }).click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Выберите формат' })).toBeVisible();
    await assertReadableProgress(page, 4);
    await assertFullSelectedServiceTitle(page, fixture.serviceName, 'booking-choice-service');
    await expect(page.getByTestId('booking-choice-specialist')).toHaveText(fixture.specialistName);
    await assertNoHorizontalOverflow(page);

    await page.getByRole('button', { name: 'В клинике', exact: true }).click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Выберите дату и время' })).toBeVisible();
    await assertReadableProgress(page, 4);
    await assertFullSelectedServiceTitle(page, fixture.serviceName);
    await expect(page.getByTestId('booking-selection-specialist')).toHaveText(fixture.specialistName);
    await expect(page.getByTestId('booking-selection-format')).toHaveText('В клинике');
    await expect(page.locator('.portal-calendar-card__weekdays span')).toHaveCount(7);
    await expect(page.getByTestId('availability-slot').first()).toBeVisible();
    await assertNoHorizontalOverflow(page);

    await page.getByTestId('availability-slot').first().click();
    await page.getByRole('button', { name: 'Продолжить', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Проверьте запись' })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await assertReadableProgress(page, 4);
    await assertNoHorizontalOverflow(page);

    await acceptRequiredConsents(page);
    await page.getByRole('button', { name: 'Подтвердить запись', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Запись создана.' })).toBeVisible();
    await expect(page.getByText(fixture.serviceName, { exact: true })).toBeVisible();
    await assertNoHorizontalOverflow(page);
});

test('authenticated client can manage an upcoming booking from My bookings', async ({ page }) => {
    const fixture = createBookingFixture(true);
    const earlierDate = new Date(`${fixture.date}T00:00:00Z`);
    earlierDate.setUTCDate(earlierDate.getUTCDate() - 1);
    const earlierDateValue = earlierDate.toISOString().slice(0, 10);

    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/');
    await expect(page.getByText('Перенести', { exact: true })).toHaveCount(0);

    await page.goto('/portal/bookings');
    await expect(page.getByRole('heading', { name: 'Мои записи' })).toBeVisible();
    await expect(page.getByText(/Playwright Service/).first()).toBeVisible();

    await page.getByRole('link', { name: /Playwright Service/ }).first().click();
    await expect(page.getByRole('heading', { name: /Playwright Service/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Часовой пояс' })).toHaveCount(0);
    await expect(page.getByTestId('availability-slot')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Перенести', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Перенести', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Выберите новое время' })).toBeVisible();
    const earlierDateButton = page.getByRole('button', { name: earlierDateValue, exact: true });
    await expect(earlierDateButton).toBeEnabled();
    await earlierDateButton.click();
    const alternateSlot = page.getByTestId('availability-slot').first();
    await expect(alternateSlot).toBeVisible();
    await expect(page.getByRole('button', { name: 'Перенести запись', exact: true })).toBeDisabled();
    await alternateSlot.click();
    const firstRescheduleResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && /\/portal\/bookings\/\d+\/reschedule$/.test(new URL(response.url()).pathname));
    await page.getByRole('button', { name: 'Перенести запись', exact: true }).click();
    await assertPortalResponseAccepted(firstRescheduleResponse, 'First portal reschedule');
    await expect(page.getByRole('heading', { name: 'История' })).toBeVisible();
    await expect(page.getByText('Запись перенесена')).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: 'Перенести', exact: true }).click();
    const originalDateButton = page.getByRole('button', { name: fixture.date, exact: true });
    await expect(originalDateButton).toBeEnabled();
    await originalDateButton.click();
    const secondAlternateSlot = page.getByTestId('availability-slot').nth(1);
    await expect(secondAlternateSlot).toBeVisible();
    await secondAlternateSlot.click();
    const secondRescheduleResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && /\/portal\/bookings\/\d+\/reschedule$/.test(new URL(response.url()).pathname));
    await page.getByRole('button', { name: 'Перенести запись', exact: true }).click();
    await assertPortalResponseAccepted(secondRescheduleResponse, 'Second portal reschedule');
    await expect(page.getByRole('heading', { name: 'История' })).toBeVisible();
    await expect(page.getByText('Запись перенесена')).toHaveCount(2, { timeout: 15_000 });

    const cancelButton = page.getByRole('button', { name: 'Отменить', exact: true });
    const cancelRoute = `**/portal/bookings/${fixture.bookingId}/cancel`;
    const failedResponses = [
        { status: 401, message: 'Сеанс входа завершился. Войдите снова, чтобы отменить запись.' },
        { status: 403, message: 'Эту запись нельзя отменить из текущего профиля.' },
        { status: 404, message: 'Запись больше не найдена. Обновите список записей.' },
        { status: 409, message: 'Статус записи изменился. Обновите страницу, чтобы проверить актуальный статус.' },
        { status: 503, message: 'Не удалось подтвердить отмену. Обновите страницу, чтобы проверить статус записи, затем повторите попытку.' },
    ];
    for (const failure of failedResponses) {
        await page.route(cancelRoute, (route) => route.fulfill({
            status: failure.status,
            contentType: 'text/html',
            body: 'Request could not be completed',
        }));
        await cancelButton.click();
        await expect(page.getByRole('alert')).toHaveText(failure.message);
        await expect(cancelButton).toBeEnabled();
        await page.unroute(cancelRoute);
    }

    await page.route(cancelRoute, (route) => route.abort('failed'));
    await cancelButton.click();
    await expect(page.getByRole('alert')).toHaveText('Связь с сервером прервалась. Обновите страницу, чтобы проверить статус записи; если она ещё активна, повторите отмену.');
    await expect(cancelButton).toBeEnabled();
    await expect(page.getByText('Отменена', { exact: true })).toHaveCount(0);
    await page.unroute(cancelRoute);

    await page.reload();
    await expect(cancelButton).toBeVisible();
    await cancelButton.click();
    await expect(page.getByText('Отменена', { exact: true })).toBeVisible();
    await expect(page.locator('li').filter({ hasText: 'Запись отменена' })).toBeVisible();
});

test('booking details stay readable at 320px Mini App width', async ({ page }) => {
    const fixture = createBookingFixture(true);

    await page.setViewportSize({ width: 320, height: 844 });
    await page.context().addCookies([{
        name: fixture.cookieName,
        value: fixture.cookieValue,
        url: 'http://127.0.0.1:8000',
    }]);

    await page.goto('/portal/bookings');
    await page.getByRole('link', { name: /Playwright Service/ }).first().click();
    await expect(page.getByRole('heading', { name: /Playwright Service/ })).toBeVisible();
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await page.getByRole('button', { name: 'Перенести', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Выберите новое время' })).toBeVisible();
    await expect(page.locator('.portal-calendar-card__weekdays span')).toHaveCount(7);
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('family profiles persist, invitations retry safely, and linked patients can be booked then unlinked', async ({ page }) => {
    await page.addInitScript(() => {
        const capture = window as unknown as { __familyBrowserShareCalls: Array<[string, string, string]> };
        capture.__familyBrowserShareCalls = [];
        window.open = ((url?: string | URL, target?: string, features?: string) => {
            capture.__familyBrowserShareCalls.push([String(url ?? ''), target ?? '', features ?? '']);

            return null;
        }) as typeof window.open;
    });
    const owner = createBookingFixture();
    await page.context().addCookies([{ name: owner.cookieName, value: owner.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/portal/family');
    await expect(page.getByRole('heading', { name: 'Члены семьи' })).toBeVisible();
    await expect(page.getByText('Медицинские данные доступны только самому пациенту.', { exact: true })).toBeVisible();
    await expect(page.getByText(/Доступ для представителя не подключён/)).toHaveCount(0);
    const addFamilyButton = page.getByRole('button', { name: 'Добавить члена семьи' });
    const createDialog = page.getByRole('dialog');

    for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalOverflow(page);
        await addFamilyButton.click();
        await expect(createDialog).toBeVisible();
        await assertNoHorizontalOverflow(page);
        await page.keyboard.press('Escape');
        await expect(createDialog).not.toBeVisible();
    }
    await page.setViewportSize({ width: 390, height: 844 });

    await addFamilyButton.click();
    await expect(createDialog).toBeVisible();
    await expect(createDialog.locator('form')).toHaveJSProperty('noValidate', true);
    await createDialog.getByLabel('Имя').focus();
    await expect(createDialog.getByLabel('Имя')).toBeFocused();
    await createDialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(createDialog.getByText('Укажите имя и фамилию пациента.', { exact: true }).first()).toBeVisible();
    await expect(createDialog.getByLabel('Имя')).toHaveAttribute('aria-invalid', 'true');

    await createDialog.getByLabel('Фамилия').fill('QA Family');
    await createDialog.getByLabel('Имя').fill('Анна');
    await createDialog.getByRole('radio', { name: 'Женский' }).check({ force: true });
    const genderSegments = createDialog.locator('.portal-profile-gender');
    await expect(genderSegments).toHaveCSS('display', 'grid');
    expect(await genderSegments.evaluate((element) => getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length)).toBe(2);
    await createDialog.getByLabel('Дата рождения').fill('1990-01-02');
    await createDialog.getByLabel('Дата рождения').press('Enter');
    await expect(createDialog.getByLabel('Дата рождения')).toHaveValue('1990-01-02');
    await expect(createDialog).toBeVisible();
    await createDialog.getByLabel('Кем приходится').selectOption('CHILD');
    await createDialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Анна/ })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Член семьи добавлен.' })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: /Анна/ })).toBeVisible();

    const patientCard = page.locator('article').filter({ hasText: /Анна/ });
    await patientCard.getByRole('button', { name: 'Редактировать данные' }).click();
    const editDialog = page.getByRole('dialog');
    await editDialog.getByLabel('Имя').fill('Анна обновлена');
    await editDialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Анна обновлена/ })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Данные пациента сохранены.' })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: /Анна обновлена/ })).toBeVisible();

    const updatedPatientCard = page.locator('article').filter({ hasText: /Анна обновлена/ });
    const invitePath = '**/portal/family/*/invite';
    let inviteAttempts = 0;
    const inviteRequestKeys: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && /\/portal\/family\/\d+\/invite$/.test(new URL(request.url()).pathname)) {
            inviteRequestKeys.push((request.postDataJSON() as { request_key: string }).request_key);
        }
    });
    await page.route(invitePath, async (route) => {
        inviteAttempts += 1;
        if (inviteAttempts === 1) {
            await route.fulfill({
                status: 503,
                contentType: 'application/json',
                body: JSON.stringify({ safe_error_code: 'telegram_bot_configuration_missing', message: 'Safe QA provider failure' }),
            });
            return;
        }

        await route.continue();
    });
    await updatedPatientCard.getByRole('button', { name: 'Выслать приглашение' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Создание Telegram-приглашений сейчас недоступно.' })).toBeVisible();
    await expect(updatedPatientCard.getByRole('button', { name: 'Выслать приглашение' })).toBeEnabled();
    await updatedPatientCard.getByRole('button', { name: 'Выслать приглашение' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Приглашение создано.' })).toBeVisible();
    const invitationLinkIsValid = await page.locator('#family-invitation-url').evaluate((element) => {
        try {
            const invitation = new URL((element as HTMLInputElement).value);

            return invitation.origin === 'https://t.me'
                && invitation.pathname === '/chuklov_test_bot'
                && invitation.searchParams.get('start')?.startsWith('family_') === true;
        } catch {
            return false;
        }
    });
    expect(invitationLinkIsValid).toBe(true);
    expect(inviteRequestKeys).toHaveLength(2);
    expect(inviteRequestKeys[1]).toBe(inviteRequestKeys[0]);
    await page.unroute(invitePath);

    const shareLink = await page.locator('#family-invitation-url').inputValue();
    const telegramBridgeAvailable = await page.evaluate(() => {
        const capture = window as unknown as {
            __familyTelegramShareCalls: string[];
            Telegram?: { WebApp?: { openTelegramLink?: (url: string) => void } };
        };
        capture.__familyTelegramShareCalls = [];
        const webApp = capture.Telegram?.WebApp;
        if (typeof webApp?.openTelegramLink === 'function') {
            webApp.openTelegramLink = (url: string): void => capture.__familyTelegramShareCalls.push(String(url));

            return true;
        }

        return false;
    });
    await page.getByRole('button', { name: 'Отправить в Telegram' }).click();
    const shareCalls = await page.evaluate(() => {
        const capture = window as unknown as {
            __familyBrowserShareCalls: Array<[string, string, string]>;
            __familyTelegramShareCalls?: string[];
        };

        return {
            browser: capture.__familyBrowserShareCalls,
            telegram: capture.__familyTelegramShareCalls ?? [],
        };
    });
    expect(shareCalls.browser.length + shareCalls.telegram.length).toBe(1);
    const transport = telegramBridgeAvailable ? shareCalls.telegram[0] : shareCalls.browser[0]?.[0];
    let sharePayloadIsCorrect = false;
    if (typeof transport === 'string') {
        try {
            const shareUrl = new URL(transport);
            sharePayloadIsCorrect = shareUrl.origin === 'https://t.me'
                && shareUrl.pathname === '/share/url'
                && shareUrl.searchParams.get('url') === shareLink;
        } catch {
            sharePayloadIsCorrect = false;
        }
    }
    expect(sharePayloadIsCorrect).toBe(true);
    if (!telegramBridgeAvailable) {
        const [, target, features] = shareCalls.browser[0] ?? [];
        expect(target === '_blank' && features === 'noopener,noreferrer').toBe(true);
    }

    const recipient = createFamilyAcceptanceFixture(owner.clientId);
    await page.context().addCookies([{ name: recipient.cookieName, value: recipient.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/portal/family/invitation');
    await expect(page.getByRole('heading', { name: 'Приглашение в семью' })).toBeVisible();
    const confirm = page.getByRole('checkbox', { name: 'Подтверждаю, что это мои данные' });
    await page.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Подтвердите, что указанные данные относятся к вам.' })).toBeVisible();
    await expect(confirm).toHaveAttribute('aria-invalid', 'true');
    await confirm.check();
    await page.getByRole('button', { name: 'Принять приглашение' }).click();
    await expect(page.getByText('Приглашение принято', { exact: true })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Приглашение принято.' })).toBeVisible();
    await page.reload();
    await expect(page.getByText('Приглашение принято', { exact: true })).toBeVisible();

    await page.getByRole('link', { name: 'Члены семьи' }).click();
    const linkedPatient = page.locator('article')
        .filter({ hasText: /Анна обновлена/ })
        .filter({ hasText: 'Дочь' });
    await expect(linkedPatient).toHaveCount(1);
    await expect(linkedPatient).toBeVisible();
    const bookingLink = linkedPatient.getByRole('link', { name: /^Записать на приём:/ });
    await expect(bookingLink).toHaveAccessibleName(/Анна обновлена/);
    await bookingLink.click();
    const bookingPatient = page.getByLabel('Кого записываем?');
    await expect(bookingPatient).toHaveValue(String(recipient.patientId));
    await bookingPatient.selectOption('');
    await expect(bookingPatient).toHaveValue('');
    await bookingPatient.selectOption(String(recipient.patientId));
    await expect(bookingPatient).toHaveValue(String(recipient.patientId));
    await page.reload();
    await expect(bookingPatient).toHaveValue(String(recipient.patientId));
    await assertNoHorizontalOverflow(page);
    await page.goBack();
    await expect(page.getByRole('heading', { name: 'Члены семьи' })).toBeVisible();
    await page.goForward();
    await expect(bookingPatient).toHaveValue(String(recipient.patientId));

    await page.context().addCookies([{ name: owner.cookieName, value: owner.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/portal/family');
    const ownerPatient = page.locator('article').filter({ hasText: /Анна обновлена/ });
    await ownerPatient.getByRole('button', { name: 'Отвязать' }).click();
    const unlinkDialog = page.getByRole('dialog');
    await expect(unlinkDialog).toBeVisible();
    await unlinkDialog.getByRole('button', { name: 'Отвязать' }).click();
    await expect(page.getByText('Семейная связь отозвана', { exact: true })).toBeVisible();
    await expect(page.getByRole('status').filter({ hasText: 'Семейная связь отозвана.' })).toBeVisible();
    await page.reload();
    await expect(page.getByText('Семейная связь отозвана', { exact: true })).toBeVisible();

    await page.context().addCookies([{ name: recipient.cookieName, value: recipient.cookieValue, url: 'http://127.0.0.1:8000' }]);
    await page.goto('/portal/family/invitation');
    await expect(page.getByRole('alert').filter({ hasText: 'Приглашение отозвано. Попросите отправить новое.' })).toBeVisible();
    await page.goto('/portal/bookings/create');
    await expect(page.getByLabel('Кого записываем?').locator('option')).toHaveCount(1);
});
