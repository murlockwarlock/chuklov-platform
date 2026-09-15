<?php

namespace App\Modules\AI\Application\Services;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetClinicalAiResult;
use App\Modules\AI\Application\Data\ClinicalCourseReportInput;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Surveys\Application\SurveyAuthorization;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class BuildClinicalCourseReportInput
{
    private const MAX_SESSIONS = 24;

    private const MAX_SURVEY_DEFINITIONS = 20;

    private const MAX_CONTEXT_LENGTH = 12000;

    public function __construct(
        private GetMedicalProfile $getMedicalProfile,
        private ClinicalSynthesizerMedicalProfileContext $medicalProfileContext,
        private FindLatestReviewedAiRun $findLatestReviewedAiRun,
        private GetClinicalAiResult $resultReader,
        private MedicalSessionAuthorization $sessionAuthorization,
        private GetSession $getSession,
        private SurveyAuthorization $surveyAuthorization,
        private OrganizationContext $context,
    ) {}

    public function handle(
        User $actor,
        Client $client,
        CarbonInterface $courseStart,
        CarbonInterface $courseEnd,
    ): ClinicalCourseReportInput {
        $profile = $this->getMedicalProfile->handle($actor, $client);
        $documentRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::ClinicalDocumentExtraction,
            (int) $client->organization_id,
        );
        $postureRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::PostureAnalysis,
            (int) $client->organization_id,
        );
        $documentResult = $documentRun === null
            ? null
            : $this->resultReader->handle($actor, (int) $documentRun->getKey(), (int) $client->getKey());
        $postureResult = $postureRun === null
            ? null
            : $this->resultReader->handle($actor, (int) $postureRun->getKey(), (int) $client->getKey());

        [$sessionsContext, $sessionReferences] = $this->sessions(
            $actor,
            $client,
            $courseStart,
            $courseEnd,
        );
        [$surveysContext, $surveyReferences] = $this->surveys(
            $actor,
            $client,
            $courseStart,
            $courseEnd,
        );

        $inputVariables = [
            'client_name' => $this->boundedText($client->full_name ?: 'Клиент', 200),
            'anamnesis' => $this->medicalProfileContext->build($profile),
            'complaints_goals' => $this->boundedText($profile?->complaintsGoals, 700),
            'course_period' => $this->coursePeriod($courseStart, $courseEnd),
            'course_sessions' => $sessionsContext,
            'course_surveys' => $surveysContext,
            'agent_one_result' => $this->documentContext($documentResult?->outputPayload),
            'agent_two_result' => $this->postureContext($postureResult?->outputPayload),
        ];

        $references = [new AiInputReference('client', (int) $client->getKey())];
        if ($documentRun instanceof AiRun) {
            $references[] = new AiInputReference('ai_run', (int) $documentRun->getKey(), 'agent_one');
        }
        if ($postureRun instanceof AiRun) {
            $references[] = new AiInputReference('ai_run', (int) $postureRun->getKey(), 'agent_two');
        }
        $references = [...$references, ...$sessionReferences, ...$surveyReferences];

        $sourceDigest = hash('sha256', json_encode([
            'workflow' => ClinicalSynthesizerWorkflow::CourseReport->value,
            'course_start' => $courseStart->toIso8601String(),
            'course_end' => $courseEnd->toIso8601String(),
            'variables' => $inputVariables,
            'references' => array_map(
                static fn (AiInputReference $reference): array => $reference->toArray(),
                $references,
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return new ClinicalCourseReportInput(
            inputVariables: $inputVariables,
            inputReferences: $references,
            sourceDigest: $sourceDigest,
        );
    }

    /** @return array{0: string, 1: list<AiInputReference>} */
    private function sessions(
        User $actor,
        Client $client,
        CarbonInterface $courseStart,
        CarbonInterface $courseEnd,
    ): array {
        $query = MedicalSession::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('occurred_at', '>=', $courseStart)
            ->where('occurred_at', '<=', $courseEnd);
        $total = (clone $query)->count();
        $sessions = $this->boundedSessions($query, $total);
        $contexts = [];
        $references = [];

        foreach ($sessions as $session) {
            $data = $this->getSession->handle($actor, $session, $client);
            if ($data === null) {
                continue;
            }

            $contexts[] = $this->sessionContext($data->toArray());
            $references[] = new AiInputReference('medical_session', (int) $session->getKey(), 'course');
        }

        if ($contexts === []) {
            return [
                'Сеансы за выбранный период отсутствуют.',
                $references,
            ];
        }

        $selection = $total > self::MAX_SESSIONS
            ? ' В контекст включены первые и последние записи.'
            : '';

        return [
            $this->boundedText(
                'Всего сеансов за выбранный период: '.$total.'. В контекст включено: '.count($contexts).'.'.$selection."\n".implode("\n", $contexts),
                self::MAX_CONTEXT_LENGTH,
            ),
            $references,
        ];
    }

    /** @return list<MedicalSession> */
    private function boundedSessions(Builder $query, int $total): array
    {
        $columns = ['id', 'organization_id', 'client_id', 'occurred_at'];
        if ($total <= self::MAX_SESSIONS) {
            return $query
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(self::MAX_SESSIONS)
                ->get($columns)
                ->all();
        }

        $headCount = intdiv(self::MAX_SESSIONS, 2);
        $head = (clone $query)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($headCount)
            ->get($columns);
        $tail = (clone $query)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::MAX_SESSIONS - $headCount)
            ->get($columns);

        return $head
            ->concat($tail)
            ->unique(fn (MedicalSession $session): int => (int) $session->getKey())
            ->sort(function (MedicalSession $left, MedicalSession $right): int {
                $occurredAtComparison = $left->occurred_at->getTimestamp() <=> $right->occurred_at->getTimestamp();

                return $occurredAtComparison !== 0
                    ? $occurredAtComparison
                    : ((int) $left->getKey() <=> (int) $right->getKey());
            })
            ->values()
            ->all();
    }

    /** @return array{0: string, 1: list<AiInputReference>} */
    private function surveys(
        User $actor,
        Client $client,
        CarbonInterface $courseStart,
        CarbonInterface $courseEnd,
    ): array {
        if (! $this->surveyAuthorization->allowsView($actor, $client)) {
            return ['Завершённые опросы недоступны для текущего специалиста.', []];
        }

        $query = SurveyAttempt::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('status', SurveyAttemptStatus::Completed)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $courseStart)
            ->where('completed_at', '<=', $courseEnd);
        $total = (clone $query)->count();
        $definitionIds = (clone $query)
            ->select('survey_definition_id')
            ->distinct()
            ->orderBy('survey_definition_id')
            ->limit(self::MAX_SURVEY_DEFINITIONS)
            ->pluck('survey_definition_id');
        $attempts = collect();

        foreach ($definitionIds as $definitionId) {
            $first = (clone $query)
                ->where('survey_definition_id', $definitionId)
                ->orderBy('completed_at')
                ->orderBy('id')
                ->first();
            $latest = (clone $query)
                ->where('survey_definition_id', $definitionId)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->first();

            if ($first instanceof SurveyAttempt) {
                $attempts->push($first);
            }
            if ($latest instanceof SurveyAttempt && (! $first instanceof SurveyAttempt || $latest->getKey() !== $first->getKey())) {
                $attempts->push($latest);
            }
        }

        $contexts = $attempts
            ->map(fn (SurveyAttempt $attempt): string => $this->surveyContext($attempt))
            ->filter()
            ->values()
            ->all();
        $references = $attempts
            ->map(fn (SurveyAttempt $attempt): AiInputReference => new AiInputReference('survey_attempt', (int) $attempt->getKey(), 'course'))
            ->unique(fn (AiInputReference $reference): int => $reference->id)
            ->values()
            ->all();

        if ($contexts === []) {
            return ['Завершённые опросы за выбранный период отсутствуют.', $references];
        }

        $selection = $total > count($references)
            ? ' Выбраны первый и последний результат по доступным методикам.'
            : '';

        return [
            $this->boundedText(
                'Всего завершённых результатов опросов за выбранный период: '.$total.'. В контекст включено: '.count($references).'.'.$selection."\n".implode("\n", $contexts),
                self::MAX_CONTEXT_LENGTH,
            ),
            $references,
        ];
    }

    private function surveyContext(SurveyAttempt $attempt): string
    {
        $result = is_array($attempt->result_snapshot) ? $attempt->result_snapshot : [];
        $title = $this->localizedText(data_get($result, 'survey.title'), 100);
        $title = $title !== '' ? $title : 'Результат опроса';
        $date = $attempt->completed_at?->setTimezone($this->context->defaultTimezone())->format('d.m.Y H:i');
        $lines = [$title.' · '.($date ?? 'дата не указана')];
        $summary = $this->localizedText(data_get($result, 'summary.short'), 220);
        if ($summary !== '') {
            $lines[] = 'Итог: '.$summary;
        }

        $areas = [];
        foreach (array_slice((array) ($result['attention_areas'] ?? []), 0, 3) as $area) {
            if (! is_array($area)) {
                continue;
            }
            $label = $this->localizedText($area['label'] ?? null, 80);
            $status = $this->localizedText($area['status'] ?? null, 80);
            $reason = $this->localizedText($area['reason'] ?? null, 120);
            $parts = array_filter([$label, $status, $reason]);
            if ($parts !== []) {
                $areas[] = implode(' · ', $parts);
            }
        }
        if ($areas !== []) {
            $lines[] = 'Зоны внимания: '.implode('; ', $areas);
        }

        $comparisonMessage = $this->localizedText(data_get($result, 'comparison.message'), 180);
        if ($comparisonMessage !== '') {
            $lines[] = 'Сравнение: '.$comparisonMessage;
        }

        return $this->boundedText(implode("\n", $lines), 650);
    }

    private function coursePeriod(CarbonInterface $courseStart, CarbonInterface $courseEnd): string
    {
        $timezone = $this->context->defaultTimezone();

        return 'Начало курса: '.$courseStart->copy()->setTimezone($timezone)->format('d.m.Y').'; конец отчётного периода: '.$courseEnd->copy()->setTimezone($timezone)->format('d.m.Y H:i').'.';
    }

    /** @param array<string, mixed>|null $result */
    private function documentContext(?array $result): string
    {
        if ($result === null) {
            return 'Проверенный анализ медицинского документа отсутствует.';
        }

        return $this->boundedText(implode("\n", array_filter([
            'Исследование: '.$this->boundedText($result['exam_type'] ?? '', 100),
            'Область: '.$this->boundedText($result['anatomical_region'] ?? '', 100),
            'Резюме: '.$this->boundedText($result['plain_summary'] ?? '', 300),
            'Наблюдения: '.implode('; ', $this->textList($result['key_findings'] ?? [], 3, 160)),
            'Ограничения: '.implode('; ', $this->textList($result['critical_flags'] ?? [], 3, 120)),
        ], static fn (string $line): bool => ! str_ends_with($line, ': '))), 1200);
    }

    /** @param array<string, mixed>|null $result */
    private function postureContext(?array $result): string
    {
        if ($result === null) {
            return 'Проверенный анализ осанки отсутствует.';
        }

        $findings = [];
        foreach (array_slice((array) ($result['visual_findings'] ?? []), 0, 3) as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $plane = $this->boundedText($finding['plane'] ?? '', 40);
            $observations = $this->textList($finding['observations'] ?? [], 3, 130);
            if ($observations !== []) {
                $findings[] = ($plane !== '' ? $plane.': ' : '').implode('; ', $observations);
            }
        }

        return $this->boundedText(implode("\n", array_filter([
            $findings === [] ? null : 'Наблюдения: '.implode(' | ', $findings),
            'Паттерны: '.implode('; ', $this->textList($result['leading_compensatory_patterns'] ?? [], 3, 130)),
            'Фокус специалиста: '.implode('; ', $this->textList($result['practitioner_focus'] ?? [], 3, 130)),
            'Ограничения: '.implode('; ', $this->textList($result['limitations'] ?? [], 3, 130)),
        ], static fn (?string $line): bool => is_string($line) && ! str_ends_with($line, ': '))), 1200);
    }

    /** @param array<string, mixed> $session */
    private function sessionContext(array $session): string
    {
        $occurredAt = isset($session['occurred_at'])
            ? $this->boundedText($session['occurred_at'], 40)
            : '';
        $parts = array_filter([
            $occurredAt,
            $this->fieldLabel('Жалобы', $session['pain'] ?? null, 180),
            $this->fieldLabel('Тесты', $session['tests'] ?? null, 180),
            $this->fieldLabel('Наблюдения', $session['observations'] ?? null, 180),
            $this->fieldLabel('Гипотеза', $session['root_cause_hypothesis'] ?? null, 180),
            $this->fieldLabel('Протокол', $session['protocol'] ?? null, 180),
            $this->fieldLabel('Результат', $session['result'] ?? null, 180),
        ]);

        return $this->boundedText(implode(' · ', $parts), 900);
    }

    private function fieldLabel(string $label, mixed $value, int $limit): ?string
    {
        $value = $this->boundedText($value, $limit);

        return $value === '' ? null : $label.': '.$value;
    }

    /** @return list<string> */
    private function textList(mixed $values, int $limit, int $itemLength): array
    {
        $items = [];
        foreach (array_slice((array) $values, 0, $limit) as $value) {
            if (is_array($value)) {
                $value = $value['pathology'] ?? $value['statement'] ?? $value['label'] ?? null;
            }
            $text = $this->localizedText($value, $itemLength);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    private function localizedText(mixed $value, int $limit): string
    {
        if (is_array($value)) {
            $value = $value['ru'] ?? $value['en'] ?? reset($value) ?: '';
        }

        return $this->boundedText($value, $limit);
    }

    private function boundedText(mixed $value, int $limit): string
    {
        $text = trim((string) $value);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
