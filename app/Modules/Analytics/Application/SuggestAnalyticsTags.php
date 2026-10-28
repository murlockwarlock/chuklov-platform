<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Analytics\Application\Data\ClassificationResultData;
use App\Modules\Analytics\Domain\Enums\ClassificationRunStatus;
use App\Modules\Analytics\Domain\Models\AnalyticsClassificationRun;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SuggestAnalyticsTags
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private AiWorkflowEngine $workflowEngine,
        private RecordAuditEvent $audit,
    ) {}

    /** @param list<array{id: int, label: string}> $availableTags */
    public function handle(
        User $actor,
        string $taxonomy,
        string $sourceType,
        int $sourceId,
        ?int $clientId,
        string $sourceText,
        array $availableTags,
    ): ClassificationResultData {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $taxonomy = trim($taxonomy);
        $sourceType = trim($sourceType);
        $sourceText = trim($sourceText);
        if ($taxonomy === '' || $sourceType === '' || $sourceId < 1 || $sourceText === '') {
            return $this->failedResult(0, 'empty_source');
        }

        $availableTags = $this->normalizeTags($availableTags);
        if ($availableTags === []) {
            return $this->failedResult(0, 'taxonomy_not_configured');
        }
        $organizationId = (int) $organization->getKey();
        $digest = hash('sha256', json_encode([
            'taxonomy' => $taxonomy,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_text' => mb_substr($sourceText, 0, 4000),
            'tags' => $availableTags,
        ], JSON_THROW_ON_ERROR));
        [$run, $shouldExecute] = $this->claim($organizationId, $taxonomy, $sourceType, $sourceId, $clientId, $digest);
        if (! $shouldExecute) {
            return $this->result($run);
        }

        try {
            $prompt = AiPrompt::query()
                ->where('organization_id', $organizationId)
                ->where('key', 'analytics_classification')
                ->where('capability', AiCapability::GeneralAssistant)
                ->whereNotNull('active_version_id')
                ->first();
            if ($prompt === null || $prompt->active_version_id === null) {
                return $this->fail($run, 'configuration_required', 'Классификатор аналитики не настроен.');
            }

            $result = $this->workflowEngine->run($organizationId, new AiRunRequest(
                capability: AiCapability::GeneralAssistant,
                workflowKey: 'analytics_classification',
                origin: AiRunOrigin::User,
                executionMode: AiExecutionMode::Sync,
                initiatedByUserId: $actor->getKey(),
                clientId: $clientId,
                promptVersionId: $prompt->active_version_id,
                inputVariables: [
                    'classification_taxonomy' => $taxonomy,
                    'source_text' => mb_substr($sourceText, 0, 4000),
                    'allowed_tags' => array_values(array_map(static fn (array $tag): string => $tag['label'], $availableTags)),
                ],
                idempotencyKey: 'analytics-classification:'.$organizationId.':'.$taxonomy.':'.$sourceType.':'.$sourceId.':'.$digest,
                actor: $actor,
            ));
            $suggestions = $this->validateOutput($result->outputPayload, $availableTags);
            if (! $result->isSuccess() || $suggestions === null) {
                return $this->fail($run, $result->isSuccess() ? 'invalid_output' : 'ai_failed', $result->errorMessageSanitized);
            }

            $run->forceFill([
                'status' => ClassificationRunStatus::NeedsReview,
                'ai_run_id' => $result->runId,
                'suggestions' => $suggestions,
                'error_code' => null,
                'error_message_sanitized' => null,
                'updated_at' => now(),
            ])->save();
            $this->audit->handle($organization, $actor, 'analytics.classification.suggested', $run::class, (string) $run->getKey(), [
                'taxonomy' => $taxonomy,
                'source_type' => $sourceType,
                'tag_count' => count($suggestions),
            ]);

            return $this->result($run->refresh());
        } catch (Throwable $exception) {
            return $this->fail($run, 'ai_failed', 'Классификация не выполнена.');
        }
    }

    /** @return array{0: AnalyticsClassificationRun, 1: bool} */
    private function claim(int $organizationId, string $taxonomy, string $sourceType, int $sourceId, ?int $clientId, string $digest): array
    {
        try {
            return DB::transaction(function () use ($organizationId, $taxonomy, $sourceType, $sourceId, $clientId, $digest): array {
                $run = AnalyticsClassificationRun::query()
                    ->where('organization_id', $organizationId)
                    ->where('taxonomy', $taxonomy)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('source_digest', $digest)
                    ->lockForUpdate()
                    ->first();
                if ($run instanceof AnalyticsClassificationRun) {
                    if ($run->status !== ClassificationRunStatus::Failed) {
                        return [$run, false];
                    }
                    $run->forceFill([
                        'status' => ClassificationRunStatus::Processing,
                        'attempt_count' => $run->attempt_count + 1,
                        'error_code' => null,
                        'error_message_sanitized' => null,
                        'updated_at' => now(),
                    ])->save();

                    return [$run, true];
                }

                $run = new AnalyticsClassificationRun;
                $run->forceFill([
                    'organization_id' => $organizationId,
                    'client_id' => $clientId,
                    'taxonomy' => $taxonomy,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_digest' => $digest,
                    'status' => ClassificationRunStatus::Processing,
                    'attempt_count' => 1,
                ])->save();

                return [$run, true];
            });
        } catch (UniqueConstraintViolationException) {
            $run = AnalyticsClassificationRun::query()
                ->where('organization_id', $organizationId)
                ->where('taxonomy', $taxonomy)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('source_digest', $digest)
                ->firstOrFail();

            return [$run, false];
        }
    }

    /** @param list<array{id: int, label: string}> $availableTags
     * @return list<array{id: int, label: string}>
     */
    private function validateOutput(?array $payload, array $availableTags): ?array
    {
        $values = $payload['tags'] ?? null;
        if (! is_array($values) || count($values) > 20) {
            return null;
        }
        $known = collect($availableTags)->mapWithKeys(static fn (array $tag): array => [mb_strtolower($tag['label']) => $tag])->all();
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            $key = mb_strtolower(trim($value));
            if (! isset($known[$key]) || isset($result[$known[$key]['id']])) {
                return null;
            }
            $result[$known[$key]['id']] = $known[$key];
        }

        return array_values($result);
    }

    /** @param list<array{id: int, label: string}> $availableTags
     * @return list<array{id: int, label: string}>
     */
    private function normalizeTags(array $availableTags): array
    {
        return array_values(array_filter(array_map(static function (array $tag): ?array {
            $id = (int) ($tag['id'] ?? 0);
            $label = is_string($tag['label'] ?? null) ? trim($tag['label']) : '';

            return $id > 0 && $label !== '' ? ['id' => $id, 'label' => mb_substr($label, 0, 255)] : null;
        }, $availableTags)));
    }

    private function fail(AnalyticsClassificationRun $run, string $code, ?string $message): ClassificationResultData
    {
        $run->forceFill([
            'status' => ClassificationRunStatus::Failed,
            'error_code' => $code,
            'error_message_sanitized' => $message !== null ? mb_substr($message, 0, 500) : null,
            'updated_at' => now(),
        ])->save();

        return $this->result($run->refresh());
    }

    private function failedResult(int $runId, string $errorCode): ClassificationResultData
    {
        return new ClassificationResultData($runId, ClassificationRunStatus::Failed, [], $errorCode);
    }

    private function result(AnalyticsClassificationRun $run): ClassificationResultData
    {
        return new ClassificationResultData(
            runId: (int) $run->getKey(),
            status: $run->status,
            suggestions: is_array($run->suggestions) ? $run->suggestions : [],
            errorCode: $run->error_code,
        );
    }
}
