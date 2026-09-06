<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ClinicalAiConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_clinical_review_actions_create_ordered_auditable_steps(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Clinical review concurrency requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $reviewer = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $run = AiRun::query()->create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => 'clinical_synthesizer',
            'origin' => AiRunOrigin::User,
            'initiated_by_user_id' => $reviewer->getKey(),
            'status' => AiRunStatus::Succeeded,
            'execution_mode' => AiExecutionMode::Async,
            'human_review_status' => 'pending_review',
        ]);

        $results = Concurrency::driver('process')->run([
            fn (): array => self::review($organization->getKey(), $reviewer->getKey(), $run->getKey()),
            fn (): array => self::review($organization->getKey(), $reviewer->getKey(), $run->getKey()),
        ]);

        self::assertSame([], array_values(array_filter($results, static fn (array $result): bool => isset($result['error']))));
        $persistedRun = AiRun::query()->whereKey($run->getKey())->firstOrFail();
        self::assertSame([1, 2], $persistedRun
            ->humanReviews()
            ->orderBy('review_step')
            ->pluck('review_step')
            ->all());
        self::assertSame('accepted', $persistedRun->human_review_status->value);
    }

    /** @return array{review_step?: int, error?: string} */
    private static function review(int $organizationId, int $reviewerId, int $runId): array
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            config()->set('tenancy.default_organization_id', $organizationId);
            app(OrganizationContext::class)->set($organization);

            $review = app(ReviewAiRun::class)->handle(
                actor: User::query()->findOrFail($reviewerId),
                runId: $runId,
                decision: HumanReviewDecision::Accepted,
                safeReasonCode: 'specialist_confirmed',
            );

            return ['review_step' => $review->review_step];
        } catch (\Throwable $exception) {
            return ['error' => $exception::class];
        }
    }
}
