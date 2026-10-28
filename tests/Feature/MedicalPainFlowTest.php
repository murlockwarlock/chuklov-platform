<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\MedicalFrequencyPeriodCatalog;
use App\Modules\MedicalProfiles\Application\SaveMedicalFrequencyPeriods;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class MedicalPainFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_pains_initialize_first_session_and_carry_forward_with_previous_after(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            pains: [
                ['name' => 'Шея', 'score' => 7],
                ['name' => 'Поясница', 'score' => 3],
            ],
            painsProvided: true,
        ));

        $profile = app(GetMedicalProfile::class)->handle($admin, $client);
        self::assertNotNull($profile);
        self::assertSame(['Шея', 'Поясница'], array_map(static fn ($pain): string => $pain->name, $profile->pains));
        self::assertSame([7, 3], array_map(static fn ($pain): ?int => $pain->score, $profile->pains));

        $first = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-01 09:00:00', 'UTC'),
        ));
        self::assertSame([7, 3], array_map(static fn ($pain): ?int => $pain->beforeScore, $first->pains));

        $firstPainRows = array_map(static function ($pain): array {
            $row = $pain->toArray();
            $row['frequency_mode'] = 'count';
            $row['frequency_count'] = 2;
            $row['frequency_period_unit'] = 'week';
            $row['time_of_day'] = ['morning', 'evening'];
            $row['before_score'] = $pain->beforeScore;
            $row['after_score'] = $pain->name === 'Шея' ? 0 : 2;

            return $row;
        }, $first->pains);
        app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($first->id), UpdateSessionCommand::fromArray([
            'pain' => null,
            'tests' => null,
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => null,
            'pains' => $firstPainRows,
        ]));

        $next = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-08 09:00:00', 'UTC'),
        ));
        self::assertSame(['Шея', 'Поясница'], array_map(static fn ($pain): string => $pain->name, $next->pains));
        self::assertSame([null, null], array_map(static fn ($pain): ?int => $pain->beforeScore, $next->pains));
        self::assertSame([0, 2], array_map(static fn ($pain): ?int => $pain->previousAfterScore, $next->pains));
        self::assertSame([['morning', 'evening'], ['morning', 'evening']], array_map(static fn ($pain): array => $pain->timeOfDay, $next->pains));

        $reloaded = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($next->id), $client);
        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->pains);
        self::assertSame(0, $reloaded->pains[0]->previousAfterScore);
        self::assertSame($organization->getKey(), $client->organization_id);
    }

    public function test_frequency_periods_are_owner_editable_and_used_by_session_pains(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        app(SaveMedicalFrequencyPeriods::class)->handle($admin, [
            ['key' => 'week', 'label' => 'Неделя', 'is_active' => true, 'sort_order' => 1],
            ['key' => 'fortnight', 'label' => 'Две недели', 'is_active' => true, 'sort_order' => 2],
            ['key' => 'year', 'label' => 'Год', 'is_active' => false, 'sort_order' => 3],
        ]);

        $options = app(MedicalFrequencyPeriodCatalog::class)->options((int) $organization->getKey());
        self::assertSame('Неделя', $options['week']);
        self::assertSame('Две недели', $options['fortnight']);
        self::assertArrayNotHasKey('year', $options);

        $session = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-10-20 09:00:00', 'UTC'),
            pains: [[
                'name' => 'Плечо',
                'frequency_mode' => 'count',
                'frequency_count' => 2,
                'frequency_period_unit' => 'fortnight',
                'time_of_day' => ['day'],
                'before_score' => 6,
            ]],
        ));

        self::assertSame('fortnight', $session->pains[0]->frequencyPeriodUnit);
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
}
