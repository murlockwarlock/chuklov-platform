<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\FeedbackAnalytics;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FeedbackAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_analytics_can_filter_to_one_theme(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $recovery = FeedbackTheme::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Восстановление',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $communication = FeedbackTheme::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Коммуникация',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $first = $this->submission($organization, $client, 9, '2026-10-01 09:00:00');
        $first->themes()->syncWithPivotValues([$recovery->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
        ]);
        $second = $this->submission($organization, $client, 3, '2026-10-02 09:00:00');
        $second->themes()->syncWithPivotValues([$communication->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
        ]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $data = app(FeedbackAnalytics::class)->handle($admin, $period, themeId: (int) $recovery->getKey());

        self::assertSame(1, $data->submissionCount);
        self::assertSame(9.0, $data->averageScore);
        self::assertCount(1, $data->themes);
        self::assertSame('Восстановление', $data->themes[0]['name']);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }

    private function submission(Organization $organization, Client $client, int $score, string $submittedAt): FeedbackSubmission
    {
        $submission = new FeedbackSubmission;
        $submission->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'score' => $score,
            'source' => 'crm',
            'idempotency_key' => 'analytics-'.uniqid('', true),
            'request_hash' => hash('sha256', $submittedAt.$score),
            'submitted_at' => CarbonImmutable::parse($submittedAt, 'UTC'),
        ])->save();

        return $submission;
    }
}
