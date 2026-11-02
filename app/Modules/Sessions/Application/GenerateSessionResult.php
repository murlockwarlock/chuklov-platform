<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalPainInput;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Validation\ValidationException;

final readonly class GenerateSessionResult
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private GetSession $getSession,
        private MedicalClinicalCatalog $clinicalCatalog,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client): string
    {
        $this->authorization->authorizeManageSession($actor, $session, $client);
        $data = $this->getSession->handle($actor, $session, $client);
        if ($data === null) {
            throw ValidationException::withMessages(['session' => 'Медицинский сеанс не найден.']);
        }

        return $this->summary(
            pains: array_map(static fn ($pain): array => [
                'name' => $pain->name,
                'before_score' => $pain->beforeScore,
                'after_score' => $pain->afterScore,
            ], $data->pains),
            subjectiveFeelings: $data->subjectiveFeelings,
            posture: $data->visualPostureDiagnostics,
            testCounts: [
                'before' => count(array_filter($data->testResults, static fn ($test): bool => $test->beforeAbnormal)),
                'after' => count(array_filter($data->testResults, static fn ($test): bool => $test->afterAbnormal)),
            ],
        );
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
        );
    }

    /** @param list<array<string, mixed>> $pains
     * @param  array{before: int, after: int}|null  $testCounts
     */
    private function summary(array $pains, ?string $subjectiveFeelings, ?string $posture, ?array $testCounts): string
    {
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
        }

        return implode("\n", $summary);
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
