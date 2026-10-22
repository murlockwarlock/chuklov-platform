<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\AI\Application\Services\FindLatestReviewedAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class GetClientHomeHealthAction
{
    public function __construct(private ResolveTrackerAccess $trackerAccess, private FindLatestReviewedAiRun $reports, private MedicalEncryptorInterface $encryptor) {}

    /**
     * @param  array<string, mixed>  $tracker
     * @return array<string, mixed>
     */
    public function handle(Client $client, array $tracker): array
    {
        $diagnostics = [
            'kind' => 'diagnostics',
            'titleKey' => 'home.diagnostics',
            'summaryKey' => 'home.diagnosticsDescription',
            'url' => route('portal.surveys.index'),
            'actionKey' => 'home.startTest',
            'badgeKey' => 'home.new',
        ];
        $access = $this->trackerAccess->handle($client);
        $today = $tracker['today'][0] ?? null;
        if ($access->allowed() && $access->entitlement?->source === TrackerEntitlementSource::PaidPurchase) {
            $focus = [
                'kind' => 'focus', 'titleKey' => 'home.focus',
                'url' => route('portal.tracker'), 'actionKey' => 'home.openTracker',
                'badgeKey' => 'home.active',
            ];
            if (is_array($today)) {
                return [...$focus, 'summary' => (string) $today['title'],
                    'taskStatus' => (string) $today['status'],
                    'taskEntryUrl' => route('portal.tracker.task-entry', ['taskId' => $today['id']])];
            }
            $practice = $access->monthlyPractice();

            return $practice !== null && trim($practice) !== ''
                ? [...$focus, 'summary' => $practice]
                : [...$focus, 'summaryKey' => 'home.focusEmpty'];
        }
        $report = $this->reports->handle($client, AiCapability::ClinicalSynthesizer, (int) $client->organization_id, ClinicalSynthesizerWorkflow::CourseReport->value);
        if ($report !== null && $report->payload !== null) {
            try {
                $decoded = json_decode($this->encryptor->decryptField((int) $client->organization_id, $report->payload->encrypted_output_payload, (int) $report->payload->encryption_key_version) ?? '', true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $this->recordUnavailableReport($client, (int) $report->getKey(), 'decrypt_or_decode_failed', $exception);

                return $diagnostics;
            }
            if (! is_array($decoded) || empty($decoded['course_summary'])) {
                $this->recordUnavailableReport($client, (int) $report->getKey(), 'course_summary_missing');

                return $diagnostics;
            }

            return [
                'kind' => 'roadmap', 'titleKey' => 'home.roadmap', 'summaryKey' => 'home.roadmapDescription',
                'url' => route('portal.health').'#course-report', 'actionKey' => 'home.openPlan', 'badgeKey' => 'home.ready',
            ];
        }

        if ($report !== null) {
            $this->recordUnavailableReport($client, (int) $report->getKey(), 'payload_missing');
        }

        return $diagnostics;
    }

    private function recordUnavailableReport(Client $client, int $runId, string $errorCode, ?Throwable $exception = null): void
    {
        Log::warning('portal.home.health_action.report_unavailable', [
            'operation' => 'project_course_report',
            'organization_id' => $client->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $client->getKey(),
            'entity_type' => 'ai_run',
            'entity_id' => $runId,
            'safe_error_code' => $errorCode,
            'exception_class' => $exception === null ? null : $exception::class,
            'outcome' => 'fallback_diagnostics',
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);
    }
}
