<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunProtectedTraceData;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use InvalidArgumentException;

final class ExportAiRun
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetAiRunProtectedTrace $trace,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, int $runId, string $format, string $identity): string
    {
        if (! in_array($format, ['json', 'txt'], true)) {
            throw new InvalidArgumentException('Unsupported AI run export format.');
        }

        if (! in_array($identity, ['identified', 'anonymized'], true)) {
            throw new InvalidArgumentException('Unsupported AI run export identity mode.');
        }

        $organization = $this->context->organization();
        $run = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($runId)
            ->with(['promptVersion.prompt', 'modelRelease', 'client.channelIdentities'])
            ->firstOrFail();
        $trace = $this->trace->handle($actor, $run->getKey());
        $client = $run->client;
        $data = $this->data($run, $trace, $client, $identity === 'identified');

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.run.exported',
            targetType: AiRun::class,
            targetId: (string) $run->getKey(),
            metadata: [
                'ai_run_id' => (string) $run->getKey(),
                'format' => $format,
                'identity' => $identity,
            ],
        );

        return $format === 'json' ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $this->toText($data);
    }

    /** @return array<string, mixed> */
    private function data(AiRun $run, AiRunProtectedTraceData $trace, ?Client $client, bool $identified): array
    {
        $data = [
            'schema_version' => 'ai_run_export_v1',
            'export_kind' => $identified ? 'identified' : 'anonymized',
            'generated_at' => now()->toIso8601String(),
            'run' => [
                'id' => $run->getKey(),
                'capability' => $run->capability->value,
                'workflow_key' => $run->workflow_key,
                'status' => $run->status->value,
                'origin' => $run->origin->value,
                'human_review_status' => $run->human_review_status->value,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ],
            'request' => [
                'user_input' => $trace->userPrompt,
                'input_references' => $trace->inputReferences,
            ],
            'input_sources' => [
                'context_provenance' => $trace->contextProvenance,
                'rag_references' => $trace->ragReferences,
            ],
            'prompt' => [
                'name' => $trace->promptName,
                'version' => $trace->promptVersion,
                'source_prompt' => $trace->sourcePrompt,
                'platform_safety_guardrails' => $trace->platformSafetyGuardrails,
                'rendered_system_prompt' => $trace->systemPrompt,
                'rendered_prompt_digest' => $run->rendered_prompt_digest,
            ],
            'model' => $trace->model,
            'context' => [
                'snapshot' => $trace->contextProvenance,
                'references' => $trace->inputReferences,
                'rag_sources' => $trace->ragReferences,
                'context_hash' => hash('sha256', json_encode([
                    $trace->contextProvenance,
                    $trace->inputReferences,
                    $trace->ragReferences,
                ], JSON_THROW_ON_ERROR)),
            ],
            'output' => [
                'structured' => $trace->outputPayload,
                'text' => $trace->outputText,
                'human_review_notes' => $trace->humanReviewNotes,
                'human_edited_output' => $trace->humanEditedOutput,
            ],
            'client' => $client === null ? null : [
                'id' => $client->getKey(),
                'full_name' => $client->full_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'telegram_usernames' => $client->channelIdentities
                    ->pluck('external_username')
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ];

        if (! $identified) {
            $directValues = $client === null ? [] : array_values(array_filter([
                $client->full_name,
                $client->email,
                $client->phone,
                ...$client->channelIdentities->pluck('external_username')->filter()->all(),
            ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
            $data = $this->anonymize($data, $directValues);
            $data['client'] = $client === null ? null : [
                'reference' => 'client_'.substr(hash('sha256', (string) $client->getKey()), 0, 12),
                'full_name' => '[АНОНИМИЗИРОВАНО]',
                'email' => '[АНОНИМИЗИРОВАНО]',
                'phone' => '[АНОНИМИЗИРОВАНО]',
                'telegram_usernames' => ['[АНОНИМИЗИРОВАНО]'],
            ];
        }

        return $data;
    }

    /** @param list<string> $directValues */
    private function anonymize(mixed $value, array $directValues, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $normalizedKey = (string) $childKey;
                if (in_array($normalizedKey, ['full_name', 'email', 'phone', 'telegram_usernames', 'external_username', 'external_id'], true)) {
                    $result[$childKey] = is_array($childValue)
                        ? array_fill(0, max(1, count($childValue)), '[АНОНИМИЗИРОВАНО]')
                        : '[АНОНИМИЗИРОВАНО]';

                    continue;
                }
                if ($normalizedKey === 'client_id') {
                    $result[$childKey] = 'client_reference';

                    continue;
                }
                if ($normalizedKey === 'id' && in_array($key, ['input_references', 'medical_attachment', 'companion_attachment', 'medical_session', 'survey_attempt', 'booking'], true)) {
                    $result[$childKey] = 'source_reference';

                    continue;
                }
                $result[$childKey] = $this->anonymize($childValue, $directValues, $normalizedKey);
            }

            return $result;
        }

        if (is_string($value)) {
            $result = $value;
            usort($directValues, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
            foreach ($directValues as $directValue) {
                $result = str_replace($directValue, '[АНОНИМИЗИРОВАНО]', $result);
            }

            return $result;
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function toText(array $data): string
    {
        $sections = [
            'AI-запуск' => [
                'Вид выгрузки' => $data['export_kind'] ?? '—',
                'Сформирован' => $data['generated_at'] ?? '—',
            ],
            'Запрос / ввод' => $data['request'] ?? [],
            'Источники ввода' => $data['input_sources'] ?? [],
            'Промпт' => $data['prompt'] ?? [],
            'Модель' => $data['model'] ?? [],
            'Контекст' => $data['context'] ?? [],
            'Результат' => $data['output'] ?? [],
            'Клиент' => $data['client'] ?? [],
        ];
        $lines = [];
        foreach ($sections as $title => $value) {
            $lines[] = $title;
            $lines[] = str_repeat('=', mb_strlen($title));
            $lines[] = is_array($value)
                ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : (string) $value;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
