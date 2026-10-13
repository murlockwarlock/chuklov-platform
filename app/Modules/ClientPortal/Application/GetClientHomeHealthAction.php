<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\AI\Application\Services\FindLatestReviewedAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Tracker\Application\ResolveTrackerAccess;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;

final readonly class GetClientHomeHealthAction
{
    public function __construct(private ResolveTrackerAccess $trackerAccess, private FindLatestReviewedAiRun $reports, private MedicalEncryptorInterface $encryptor) {}

    /**
     * @param  array<string, mixed>  $tracker
     * @return array<string, mixed>|null
     */
    public function handle(Client $client, array $tracker): ?array
    {
        $access = $this->trackerAccess->handle($client);
        $today = $tracker['today'][0] ?? null;
        if ($access->allowed() && $access->entitlement?->source === TrackerEntitlementSource::PaidPurchase && is_array($today)) {
            return [
                'kind' => 'focus', 'titleKey' => 'home.focus', 'summary' => (string) $today['title'],
                'url' => route('portal.tracker'), 'actionKey' => 'home.openTracker',
                'badgeKey' => 'home.active', 'taskStatus' => (string) $today['status'],
                'taskEntryUrl' => route('portal.tracker.task-entry', ['taskId' => $today['id']]),
            ];
        }
        $report = $this->reports->handle($client, AiCapability::ClinicalSynthesizer, (int) $client->organization_id, ClinicalSynthesizerWorkflow::CourseReport->value);
        if ($report !== null && $report->payload !== null) {
            try {
                $decoded = json_decode($this->encryptor->decryptField((int) $client->organization_id, $report->payload->encrypted_output_payload, (int) $report->payload->encryption_key_version) ?? '', true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
            if (! is_array($decoded) || empty($decoded['course_summary'])) {
                return null;
            }

            return [
                'kind' => 'roadmap', 'titleKey' => 'home.roadmap', 'summaryKey' => 'home.roadmapDescription',
                'url' => route('portal.health').'#course-report', 'actionKey' => 'home.openPlan', 'badgeKey' => 'home.ready',
            ];
        }

        return null;
    }
}
