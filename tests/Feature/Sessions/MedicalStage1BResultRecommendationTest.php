<?php

namespace Tests\Feature\Sessions;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CalculateSessionEffectiveness;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GenerateSessionResult;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\ListClientSessionRecommendations;
use App\Modules\Sessions\Application\SaveSessionRecommendationDraft;
use App\Modules\Sessions\Application\SendSessionRecommendation;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use App\Modules\Sessions\Domain\Models\MedicalTestValue;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class MedicalStage1BResultRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_session_result_generation_is_deterministic_and_shows_five_source_hints(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 09:00:00', 'UTC'),
            pains: [['name' => 'Поясница', 'before_score' => 8, 'after_score' => 4]],
            subjectiveFeelings: 'Двигаться стало легче',
            visualPostureDiagnostics: 'Плечи стали ровнее',
        ));

        $first = app(GenerateSessionResult::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);
        $second = app(GenerateSessionResult::class)->handle($admin, MedicalSession::findOrFail($session->id), $client);

        self::assertSame($first, $second);
        self::assertStringContainsString('Поясница: было 8, стало 4 из 10', $first);
        self::assertStringContainsString('Субъективные ощущения: Двигаться стало легче', $first);
        self::assertStringContainsString('Визуальная диагностика осанки: Плечи стали ровнее', $first);
        self::assertStringContainsString('Боль', $first);
        self::assertStringContainsString('Подвижность', $first);
        self::assertStringContainsString('Визуальный баланс', $first);
        self::assertStringContainsString('Дискомфорт, энергия, сон, аппетит, переживания', $first);
        self::assertStringContainsString('Результаты тестов', $first);
        self::assertSame($organization->getKey(), $client->organization_id);
    }

    public function test_collect_persists_result_and_requires_confirmation_before_replacing_manual_text(): void
    {
        [, $admin, $client, $specialist] = $this->fixture();

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 10:00:00', 'UTC'),
            pains: [['name' => 'Шея', 'before_score' => 6, 'after_score' => 2]],
        ));

        $collected = app(GenerateSessionResult::class)->collect(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        );
        self::assertSame($collected->summary, app(GetSession::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        )?->result);

        app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($session->id), UpdateSessionCommand::fromArray([
            'pain' => null,
            'tests' => null,
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => 'Ручной текст специалиста',
        ]), $client);

        try {
            app(GenerateSessionResult::class)->collect(
                $admin,
                MedicalSession::findOrFail($session->id),
                $client,
            );
            self::fail('A manual result was replaced without confirmation.');
        } catch (ValidationException $exception) {
            self::assertSame('Результат изменён вручную. Подтвердите замену новым собранным результатом.', $exception->errors()['result'][0]);
        }

        $confirmed = app(GenerateSessionResult::class)->collect(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
            confirmOverwrite: true,
        );
        self::assertSame($collected->summary, $confirmed->summary);
        self::assertNotSame('Ручной текст специалиста', $confirmed->summary);
    }

    public function test_root_cause_can_be_selected_from_the_active_global_cause_directory(): void
    {
        [, $admin, $client, $specialist] = $this->fixture();
        $cause = MedicalCauseDefinition::query()->create([
            'organization_id' => $client->organization_id,
            'name' => 'Глобальная первопричина',
            'is_active' => true,
            'moderation_status' => MedicalCauseDefinition::MODERATION_ACTIVE,
            'sort_order' => 1,
        ]);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 10:30:00', 'UTC'),
            rootCauses: [[
                'cause_id' => $cause->getKey(),
                'is_primary' => true,
            ]],
        ));

        $reloaded = app(GetSession::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        );

        self::assertNotNull($reloaded);
        self::assertSame((int) $cause->getKey(), $reloaded->rootCauses[0]->causeId);
        self::assertTrue($reloaded->rootCauses[0]->isPrimary);
    }

    public function test_effectiveness_counts_pain_change_test_deterioration_resolved_rows_and_unique_causes(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $resolved = $this->test_definition($organization, 'resolved', 'Тест восстановленного движения');
        $new = $this->test_definition($organization, 'new', 'Тест нового отклонения');
        $unchanged = $this->test_definition($organization, 'unchanged', 'Тест сохраняющегося отклонения');
        $causeOne = MedicalCauseDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Причина один',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $causeTwo = MedicalCauseDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Причина два',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        [$resolvedAbnormal, $resolvedNormal] = $this->testValues($organization, $resolved, 'abnormal', 'Отклонение', 'normal', 'Норма');
        [$newNormal, $newAbnormal] = $this->testValues($organization, $new, 'normal', 'Норма', 'abnormal', 'Отклонение');
        [$unchangedAbnormal, $unchangedAfter] = $this->testValues($organization, $unchanged, 'abnormal', 'Отклонение', 'still_abnormal', 'Отклонение сохраняется');

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 11:00:00', 'UTC'),
            pains: [
                ['name' => 'Плечо', 'before_score' => 8, 'after_score' => 4],
                ['name' => 'Колено', 'before_score' => 5, 'after_score' => 7],
                ['name' => 'Запястье', 'before_score' => 6, 'after_score' => 0],
                ['name' => 'Стопа', 'before_score' => null, 'after_score' => 3],
            ],
            testResults: [
                [
                    'test_id' => $resolved->getKey(),
                    'before_value_id' => $resolvedAbnormal->getKey(),
                    'after_value_id' => $resolvedNormal->getKey(),
                    'cause_ids' => [$causeOne->getKey()],
                ],
                [
                    'test_id' => $new->getKey(),
                    'before_value_id' => $newNormal->getKey(),
                    'after_value_id' => $newAbnormal->getKey(),
                    'cause_ids' => [$causeOne->getKey(), $causeTwo->getKey()],
                ],
                [
                    'test_id' => $unchanged->getKey(),
                    'before_value_id' => $unchangedAbnormal->getKey(),
                    'after_value_id' => $unchangedAfter->getKey(),
                    'cause_ids' => [$causeTwo->getKey()],
                ],
            ],
        ));

        $effectiveness = app(CalculateSessionEffectiveness::class)->handle($session);

        self::assertEqualsWithDelta(36.67, $effectiveness->averagePainReductionPercentage, 0.01);
        self::assertSame(1, $effectiveness->fullyResolvedComplaints);
        self::assertSame(2, $effectiveness->violationsBefore);
        self::assertSame(2, $effectiveness->violationsAfter);
        self::assertSame(1, $effectiveness->resolvedTests);
        self::assertSame(1, $effectiveness->newViolations);
        self::assertSame(1, $effectiveness->testsBecameZero);
        self::assertSame(2, $effectiveness->uniqueCauseCount);
        self::assertArrayNotHasKey('overall_percentage', $effectiveness->toArray());
    }

    public function test_recommendation_is_not_sent_until_approval_and_duplicate_approval_is_idempotent(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
        $channel = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'),
        ));

        app(SaveSessionRecommendationDraft::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
            'Текст рекомендации подготовлен специалистом.',
        );
        self::assertSame(0, count($channel->messages));
        self::assertSame(0, DB::table('medical_session_recommendation_deliveries')->count());

        $first = app(SendSessionRecommendation::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        );
        $second = app(SendSessionRecommendation::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        );

        self::assertSame('delivered', $first->status);
        self::assertNotNull($first->deliveredAt);
        self::assertSame($first->id, $second->id);
        self::assertSame('delivered', $second->status);
        self::assertCount(1, $channel->messages);
        self::assertSame('Текст рекомендации подготовлен специалистом.', $channel->messages[0]->body);
        self::assertSame(1, DB::table('medical_session_recommendation_deliveries')->count());

        $history = app(ListClientSessionRecommendations::class)->handle($admin, $client);
        self::assertCount(1, $history);
        self::assertSame('Текст рекомендации подготовлен специалистом.', $history[0]->draft);
        self::assertSame('delivered', $history[0]->deliveries[0]->status);
    }

    public function test_unknown_recommendation_delivery_never_claims_success(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
        $channel = new RecordingNotificationChannel(
            'telegram',
            NotificationDeliveryResult::unknown('delivery_outcome_unknown'),
        );
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([$channel]));

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-11-15 13:00:00', 'UTC'),
        ));
        app(SaveSessionRecommendationDraft::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
            'Текст рекомендации с неизвестным исходом.',
        );

        $delivery = app(SendSessionRecommendation::class)->handle(
            $admin,
            MedicalSession::findOrFail($session->id),
            $client,
        );

        self::assertSame('unknown', $delivery->status);
        self::assertNull($delivery->deliveredAt);
        self::assertNotNull($delivery->failedAt);
        self::assertSame(NotificationDeliveryOutcome::Unknown->value, $delivery->outcome);
    }

    /** @return array{Organization, User, Client, Specialist} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist];
    }

    private function test_definition(Organization $organization, string $key, string $name): MedicalTestDefinition
    {
        return MedicalTestDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'key' => $key,
            'name' => $name,
            'section' => 'Проверка',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /** @return array{MedicalTestValue, MedicalTestValue} */
    private function test_values(
        Organization $organization,
        MedicalTestDefinition $definition,
        string $beforeKey,
        string $beforeLabel,
        string $afterKey,
        string $afterLabel,
    ): array {
        $before = MedicalTestValue::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $definition->getKey(),
            'key' => $beforeKey,
            'label' => $beforeLabel,
            'is_abnormal' => $beforeKey !== 'normal',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $after = MedicalTestValue::query()->create([
            'organization_id' => $organization->getKey(),
            'medical_test_definition_id' => $definition->getKey(),
            'key' => $afterKey,
            'label' => $afterLabel,
            'is_abnormal' => $afterKey !== 'normal',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        return [$before, $after];
    }
}
