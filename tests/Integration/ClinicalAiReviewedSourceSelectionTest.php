<?php

namespace Tests\Integration;

use App\Modules\AI\Application\Services\FindLatestReviewedAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ClinicalAiReviewedSourceSelectionTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_latest_reviewed_source_ignores_newer_pending_and_rejected_runs_and_uses_finished_at_then_id(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Reviewed source ordering requires PostgreSQL verification.');
        }

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $selector = app(FindLatestReviewedAiRun::class);

        $firstAccepted = $this->createRun($organization, $client, HumanReviewStatus::Accepted, '2026-09-13 10:00:00');
        $secondAccepted = $this->createRun($organization, $client, HumanReviewStatus::Accepted, '2026-09-13 10:00:00');
        $this->createRun($organization, $client, HumanReviewStatus::PendingReview, '2026-09-14 10:00:00');
        $this->createRun($organization, $client, HumanReviewStatus::Rejected, '2026-09-15 10:00:00');

        $selected = $selector->handle($client, AiCapability::ClinicalSynthesizer, (int) $organization->getKey());

        self::assertNotSame($firstAccepted->getKey(), $selected?->getKey());
        self::assertSame($secondAccepted->getKey(), $selected?->getKey());
    }

    public function test_reviewed_source_selector_does_not_cross_the_organization_boundary(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Reviewed source tenant isolation requires PostgreSQL verification.');
        }

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $foreignRun = $this->createRun($foreignOrganization, $foreignClient, HumanReviewStatus::Accepted, '2026-09-15 10:00:00');
        $selector = app(FindLatestReviewedAiRun::class);

        self::assertNull($selector->handle($client, AiCapability::ClinicalSynthesizer, (int) $foreignOrganization->getKey()));
        self::assertNull($selector->handle($foreignClient, AiCapability::ClinicalSynthesizer, (int) $organization->getKey()));
        self::assertSame($foreignRun->getKey(), $selector
            ->handle($foreignClient, AiCapability::ClinicalSynthesizer, (int) $foreignOrganization->getKey())
            ?->getKey());
    }

    private function createRun(
        Organization $organization,
        Client $client,
        HumanReviewStatus $reviewStatus,
        string $finishedAt,
    ): AiRun {
        return AiRun::query()->create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => AiCapability::ClinicalSynthesizer->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->getKey(),
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => $reviewStatus,
            'finished_at' => Carbon::parse($finishedAt, 'UTC'),
            'input_references' => [['type' => 'client', 'id' => $client->getKey()]],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
    }
}
