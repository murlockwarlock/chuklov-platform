<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\AI\Application\Services\FindLatestReviewedAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionAttachment;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Support\SupportedLocale;
use Throwable;

final readonly class ListClientHealthOverview
{
    public function __construct(
        private ClientPortalContext $clientContext,
        private MedicalEncryptorInterface $encryptor,
        private FindLatestReviewedAiRun $reviewedRuns,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $organizationId = (int) $client->organization_id;
        $locale = SupportedLocale::normalize($client->language);
        $sessions = $this->sessions($organizationId, (int) $client->getKey(), $locale);

        return [
            'profile' => $this->profile($organizationId, (int) $client->getKey()),
            'history' => $sessions,
            'comparisons' => $this->comparisons($organizationId, (int) $client->getKey(), $locale),
            'postureProgress' => $this->postureProgress($organizationId, (int) $client->getKey(), $locale),
            'courseReport' => $this->courseReport($organizationId, (int) $client->getKey(), $locale),
            'hasData' => $sessions !== []
                || MedicalProfile::query()->where('organization_id', $organizationId)->where('client_id', $client->getKey())->exists()
                || SurveyComparison::query()->where('organization_id', $organizationId)->where('client_id', $client->getKey())->exists()
                || $this->hasReviewedClinicalData($organizationId, (int) $client->getKey()),
        ];
    }

    /** @return array{available: bool, updatedAt: string|null} */
    private function profile(int $organizationId, int $clientId): array
    {
        $profile = MedicalProfile::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->first(['updated_at']);

        return [
            'available' => $profile !== null,
            'updatedAt' => $profile?->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sessions(int $organizationId, int $clientId, string $locale): array
    {
        $sessions = MedicalSession::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->with([
                'specialist:id,organization_id,display_name',
                'booking:id,organization_id,service_id',
                'booking.service:id,organization_id,name,name_ru,name_en',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        $sessionIds = $sessions->modelKeys();
        $attachments = $sessionIds === []
            ? collect()
            : MedicalSessionAttachment::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $clientId)
                ->whereIn('medical_session_id', $sessionIds)
                ->with('attachment:id,organization_id,client_id,attachment_type,original_filename,mime_type,size_bytes,created_at')
                ->orderByDesc('id')
                ->get();

        return $sessions->map(function (MedicalSession $session) use ($organizationId, $locale, $attachments): array {
            $result = null;
            try {
                $result = $this->safeText($this->encryptor->decryptField(
                    $organizationId,
                    $session->getRawOriginal('result'),
                    (int) $session->encryption_key_version,
                ));
            } catch (Throwable) {
            }

            $booking = $session->booking;
            $service = $booking?->service;

            return [
                'id' => (int) $session->getKey(),
                'clientId' => (int) $session->client_id,
                'occurredAt' => $session->occurred_at?->toIso8601String(),
                'service' => $service === null ? null : $this->localizedServiceName($service, $locale),
                'specialist' => $session->specialist?->display_name,
                'result' => $result,
                'attachments' => $attachments
                    ->where('medical_session_id', $session->getKey())
                    ->filter(fn (MedicalSessionAttachment $link): bool => $link->attachment?->attachment_type === AttachmentType::PosturePhoto)
                    ->map(fn (MedicalSessionAttachment $link): array => [
                        'filename' => $link->attachment->original_filename,
                        'mimeType' => $link->attachment->mime_type,
                        'sizeBytes' => (int) $link->attachment->size_bytes,
                        'createdAt' => $link->attachment->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function comparisons(int $organizationId, int $clientId, string $locale): array
    {
        $comparisons = SurveyComparison::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        if ($comparisons->isEmpty()) {
            return [];
        }

        $attemptIds = $comparisons->flatMap(fn (SurveyComparison $comparison): array => [
            (int) $comparison->previous_attempt_id,
            (int) $comparison->current_attempt_id,
        ])->unique()->values();
        $attempts = SurveyAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->whereIn('id', $attemptIds)
            ->with('surveyVersion:id,title,title_en')
            ->get()
            ->keyBy('id');

        return $comparisons->map(function (SurveyComparison $comparison) use ($attempts, $locale): array {
            $current = $attempts->get($comparison->current_attempt_id);
            $metrics = [];
            $labels = $this->metricLabels($current?->scoring_snapshot['metrics'] ?? [], $locale);
            foreach ((array) ($comparison->comparison_snapshot['metrics'] ?? []) as $key => $metric) {
                if (! is_array($metric) || ! is_numeric($metric['before'] ?? null) || ! is_numeric($metric['after'] ?? null)) {
                    continue;
                }
                $metrics[] = [
                    'label' => $labels[$key] ?? (string) $key,
                    'before' => (float) $metric['before'],
                    'after' => (float) $metric['after'],
                    'change' => (float) ($metric['delta'] ?? ((float) $metric['after'] - (float) $metric['before'])),
                ];
            }

            return [
                'id' => (int) $comparison->getKey(),
                'title' => $this->localizedText($current?->surveyVersion?->title, $current?->surveyVersion?->title_en, $locale),
                'status' => (string) $comparison->status,
                'beforeDate' => $attempts->get($comparison->previous_attempt_id)?->completed_at?->toIso8601String(),
                'afterDate' => $current?->completed_at?->toIso8601String(),
                'metrics' => $metrics,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function postureProgress(int $organizationId, int $clientId, string $locale): ?array
    {
        $runs = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('capability', AiCapability::PostureAnalysis->value)
            ->where('status', AiRunStatus::Succeeded->value)
            ->whereIn('human_review_status', [HumanReviewStatus::Accepted->value, HumanReviewStatus::EditedAndAccepted->value])
            ->with('payload:id,organization_id,ai_run_id,encryption_key_version,encrypted_output_payload')
            ->orderBy('finished_at')
            ->orderBy('id')
            ->limit(2)
            ->get();
        if ($runs->count() < 2) {
            return null;
        }

        return [
            'before' => $this->posturePoint($runs->first(), $locale),
            'after' => $this->posturePoint($runs->last(), $locale),
        ];
    }

    /** @return array<string, mixed>|null */
    private function courseReport(int $organizationId, int $clientId, string $locale): ?array
    {
        $run = $this->reviewedRuns->handle(
            Client::query()->where('organization_id', $organizationId)->findOrFail($clientId),
            AiCapability::ClinicalSynthesizer,
            $organizationId,
            ClinicalSynthesizerWorkflow::CourseReport->value,
        );
        $payload = $run === null ? null : $this->payload($run);
        if ($run === null || $payload === null) {
            return null;
        }

        return [
            'reviewedAt' => ($run->finished_at ?? $run->created_at)?->toIso8601String(),
            'summary' => $this->localizedValue($payload['course_summary'] ?? null, $locale),
            'dynamics' => $this->localizedList($payload['observed_dynamics'] ?? null, $locale),
            'currentState' => $this->localizedValue($payload['current_state'] ?? null, $locale),
        ];
    }

    private function hasReviewedClinicalData(int $organizationId, int $clientId): bool
    {
        return AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('status', AiRunStatus::Succeeded->value)
            ->whereIn('human_review_status', [HumanReviewStatus::Accepted->value, HumanReviewStatus::EditedAndAccepted->value])
            ->whereIn('capability', [AiCapability::PostureAnalysis->value, AiCapability::ClinicalSynthesizer->value])
            ->exists();
    }

    /** @return array<string, mixed>|null */
    private function posturePoint(?AiRun $run, string $locale): ?array
    {
        if ($run === null) {
            return null;
        }

        $payload = $this->payload($run);
        if ($payload === null) {
            return null;
        }

        $observations = [];
        foreach ((array) ($payload['visual_findings'] ?? []) as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            foreach ((array) ($finding['observations'] ?? []) as $observation) {
                $text = $this->localizedValue($observation, $locale);
                if (is_string($text) && trim($text) !== '') {
                    $observations[] = trim($text);
                }
            }
        }

        return [
            'date' => ($run->finished_at ?? $run->created_at)?->toIso8601String(),
            'summary' => $observations,
        ];
    }

    /** @return array<string, mixed>|null */
    private function payload(AiRun $run): ?array
    {
        $payload = $run->payload;
        if (! $payload?->encrypted_output_payload) {
            return null;
        }

        try {
            $decoded = json_decode(
                $this->encryptor->decryptField(
                    (int) $run->organization_id,
                    $payload->encrypted_output_payload,
                    (int) $payload->encryption_key_version,
                ),
                true,
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<int, mixed> $metrics @return array<string, string> */
    private function metricLabels(array $metrics, string $locale): array
    {
        $labels = [];
        foreach ($metrics as $metric) {
            if (! is_array($metric) || ! is_string($metric['key'] ?? null)) {
                continue;
            }
            $label = $this->localizedValue($metric['label'] ?? null, $locale);
            if (is_string($label) && trim($label) !== '') {
                $labels[$metric['key']] = trim($label);
            }
        }

        return $labels;
    }

    /** @return list<string> */
    private function localizedList(mixed $value, string $locale): array
    {
        $items = [];
        foreach ((array) $value as $item) {
            $text = $this->localizedValue($item, $locale);
            if (is_string($text) && trim($text) !== '') {
                $items[] = trim($text);
            }
        }

        return array_values(array_slice($items, 0, 12));
    }

    private function localizedValue(mixed $value, string $locale): mixed
    {
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return null;
        }
        if (array_key_exists('ru', $value) || array_key_exists('en', $value)) {
            $primary = $locale === 'en' ? 'en' : 'ru';
            $secondary = $primary === 'en' ? 'ru' : 'en';

            return is_string($value[$primary] ?? null)
                ? $value[$primary]
                : ($value[$secondary] ?? null);
        }

        return null;
    }

    private function localizedText(?string $ru, ?string $en, string $locale): ?string
    {
        $value = $locale === 'en' ? ($en ?: $ru) : ($ru ?: $en);

        return $value === null ? null : trim($value);
    }

    private function localizedServiceName(object $service, string $locale): string
    {
        $primary = $locale === 'en' ? 'name_en' : 'name_ru';
        $secondary = $locale === 'en' ? 'name_ru' : 'name_en';

        foreach ([$primary, $secondary, 'name'] as $field) {
            $value = $service->getAttribute($field);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function safeText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 4000);
    }
}
