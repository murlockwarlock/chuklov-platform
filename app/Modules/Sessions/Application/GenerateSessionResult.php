<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalPainInput;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\MedicalSessionEffectivenessData;
use App\Modules\Sessions\Application\DTOs\MedicalSessionResultData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class GenerateSessionResult
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private GetSession $getSession,
        private MedicalClinicalCatalog $clinicalCatalog,
        private CalculateSessionEffectiveness $effectiveness,
        private MedicalEncryptorInterface $encryptor,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client): string
    {
        $this->authorization->authorizeManageSession($actor, $session, $client);
        $data = $this->getSession->handle($actor, $session, $client);
        if ($data === null) {
            throw ValidationException::withMessages(['session' => 'Медицинский сеанс не найден.']);
        }

        return $this->assemble($data)->summary;
    }

    public function collect(
        User $actor,
        MedicalSession $session,
        Client $client,
        bool $confirmOverwrite = false,
    ): MedicalSessionResultData {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);
        $orgId = (int) $organization->getKey();
        $sessionId = (int) $session->getKey();

        $result = DB::transaction(function () use ($actor, $client, $organization, $confirmOverwrite, $orgId, $sessionId): MedicalSessionResultData {
            /** @var MedicalSession|null $persisted */
            $persisted = MedicalSession::query()
                ->where('organization_id', $orgId)
                ->where('client_id', $client->getKey())
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->first();

            if ($persisted === null) {
                throw ValidationException::withMessages(['session' => 'Медицинский сеанс не найден.']);
            }

            $this->getSession->invalidate($actor->getKey(), $orgId, $sessionId);
            $data = $this->getSession->handle($actor, $persisted, $client);
            if ($data === null) {
                throw ValidationException::withMessages(['session' => 'Медицинский сеанс не найден.']);
            }

            $assembled = $this->assemble($data);
            $keyVersion = max(1, (int) $persisted->getAttribute('encryption_key_version'));
            $existingResult = $this->decryptString(
                $orgId,
                $persisted->getRawOriginal('result'),
                $keyVersion,
            );

            if (filled($existingResult)
                && trim((string) $existingResult) !== $assembled->summary
                && ! $confirmOverwrite) {
                throw ValidationException::withMessages([
                    'result' => 'Результат изменён вручную. Подтвердите замену новым собранным результатом.',
                ]);
            }

            $persisted->forceFill([
                'result' => $this->encryptor->encryptField($orgId, $assembled->summary, $keyVersion),
            ]);
            if ($persisted->status?->value === 'completed') {
                $persisted->forceFill(['edited_at' => now()]);
            }
            $persisted->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.updated',
                targetType: MedicalSession::class,
                targetId: (string) $persisted->getKey(),
                metadata: [
                    'source' => 'crm',
                    'key_version' => $keyVersion,
                    'updated_fields' => 'result',
                ],
            );

            return $assembled;
        });

        $this->getSession->invalidate($actor->getKey(), $orgId, $sessionId);

        return $result;
    }

    /** @param array<string, mixed> $draft */
    public function handleDraft(User $actor, MedicalSession $session, Client $client, array $draft): string
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);

        return $this->summaryFromDraft($draft, (int) $organization->getKey());
    }

    /** @param array<string, mixed> $draft */
    public function handleCreateDraft(User $actor, Client $client, array $draft): string
    {
        $organization = $this->authorization->authorizeManage($actor, $client);

        return $this->summaryFromDraft($draft, (int) $organization->getKey());
    }

    private function assemble(MedicalSessionData $data): MedicalSessionResultData
    {
        $effectiveness = $this->effectiveness->handle($data);

        return new MedicalSessionResultData(
            summary: $this->summary(
                pains: array_map(static fn ($pain): array => [
                    'name' => $pain->name,
                    'before_score' => $pain->beforeScore,
                    'after_score' => $pain->afterScore,
                ], $data->pains),
                subjectiveFeelings: $data->subjectiveFeelings,
                posture: $data->visualPostureDiagnostics,
                testCounts: [
                    'before' => $effectiveness->violationsBefore,
                    'after' => $effectiveness->violationsAfter,
                ],
                effectiveness: $effectiveness,
            ),
            effectiveness: $effectiveness,
        );
    }

    /** @param array<string, mixed> $draft */
    private function summaryFromDraft(array $draft, int $organizationId): string
    {
        $rawPains = is_array($draft['pains'] ?? null) ? $draft['pains'] : [];
        $pains = [];
        foreach ($rawPains as $rawPain) {
            if (! is_array($rawPain) || MedicalPainInput::isEmptyRow($rawPain)) {
                continue;
            }
            $pains[] = [
                'name' => is_string($rawPain['name'] ?? null) ? trim($rawPain['name']) : '',
                'before_score' => self::score($rawPain['before_score'] ?? null),
                'after_score' => self::score($rawPain['after_score'] ?? null),
            ];
        }

        $rawTests = is_array($draft['test_results'] ?? null) ? $draft['test_results'] : [];

        return $this->summary(
            pains: $pains,
            subjectiveFeelings: self::optionalString($draft['subjective_feelings'] ?? null),
            posture: self::optionalString($draft['visual_posture_diagnostics'] ?? null),
            testCounts: $rawTests === [] ? null : $this->clinicalCatalog->abnormalCounts($organizationId, $rawTests),
            afterVitals: self::optionalString($draft['after_vitals'] ?? $draft['after_vitals_text'] ?? null),
        );
    }

    /** @param list<array<string, mixed>> $pains
     * @param  array{before: int, after: int}|null  $testCounts
     */
    private function summary(
        array $pains,
        ?string $subjectiveFeelings,
        ?string $posture,
        ?array $testCounts,
        ?MedicalSessionEffectivenessData $effectiveness = null,
        ?string $afterVitals = null,
    ): string {
        $lines = [];
        foreach ($pains as $pain) {
            $before = self::score($pain['before_score'] ?? null);
            $after = self::score($pain['after_score'] ?? null);
            $lines[] = (string) ($pain['name'] ?? '').': было '.($before === null ? 'нет данных' : $before).', стало '.($after === null ? 'нет данных' : $after).' из 10';
        }

        $summary = ['Боли: '.($lines === [] ? 'нет заполненных оценок' : implode('; ', $lines))];
        if (filled($subjectiveFeelings)) {
            $summary[] = 'Субъективные ощущения: '.$subjectiveFeelings;
        }
        if (filled($posture)) {
            $summary[] = 'Визуальная диагностика осанки: '.$posture;
        }
        if ($testCounts !== null) {
            $summary[] = 'Нарушения по тестам: было '.$testCounts['before'].', стало '.$testCounts['after'].'.';
            if ($effectiveness !== null) {
                $summary[] = 'По тестам: устранено '.$effectiveness->resolvedTests
                    .', тестов стало 0: '.$effectiveness->testsBecameZero
                    .', новых нарушений: '.$effectiveness->newViolations.'.';
            }
        }
        if (filled($afterVitals)) {
            $summary[] = 'Витальные показатели после сеанса: '.$afterVitals;
        }

        $summary[] = 'Подсказки для описания результата:';
        foreach (MedicalSessionResultData::SOURCE_HINTS as $hint) {
            $summary[] = '- '.$hint;
        }

        return implode("\n", $summary);
    }

    private function decryptString(int $organizationId, mixed $ciphertext, int $keyVersion): ?string
    {
        if ($ciphertext === null) {
            return null;
        }

        if (! is_string($ciphertext)) {
            throw ValidationException::withMessages(['result' => 'Сохранённый результат имеет неверный формат.']);
        }

        return $this->encryptor->decryptField($organizationId, $ciphertext, $keyVersion);
    }

    private static function score(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value !== (float) (int) $value || (int) $value < 0 || (int) $value > 10) {
            return null;
        }

        return (int) $value;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
