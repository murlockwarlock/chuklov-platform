<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Validation\ValidationException;

final readonly class GenerateSessionResult
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private GetSession $getSession,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client): string
    {
        $this->authorization->authorizeManageSession($actor, $session, $client);
        $data = $this->getSession->handle($actor, $session, $client);
        if ($data === null) {
            throw ValidationException::withMessages(['session' => 'Медицинский сеанс не найден.']);
        }

        $lines = [];
        foreach ($data->pains as $pain) {
            if ($pain->beforeScore === null && $pain->afterScore === null) {
                continue;
            }
            $before = $pain->beforeScore === null ? 'нет данных' : (string) $pain->beforeScore;
            $after = $pain->afterScore === null ? 'нет данных' : (string) $pain->afterScore;
            $lines[] = $pain->name.': было '.$before.', стало '.$after.' из 10';
        }

        $summary = ['Боли: '.($lines === [] ? 'нет заполненных оценок' : implode('; ', $lines))];
        if (filled($data->subjectiveFeelings)) {
            $summary[] = 'Субъективные ощущения: '.$data->subjectiveFeelings;
        }
        if (filled($data->visualPostureDiagnostics)) {
            $summary[] = 'Визуальная диагностика осанки: '.$data->visualPostureDiagnostics;
        }
        if ($data->testResults !== []) {
            $beforeAbnormal = count(array_filter($data->testResults, static fn ($test): bool => $test->beforeAbnormal));
            $afterAbnormal = count(array_filter($data->testResults, static fn ($test): bool => $test->afterAbnormal));
            $summary[] = 'Нарушения по тестам: было '.$beforeAbnormal.', стало '.$afterAbnormal.'.';
        }

        return implode("\n", $summary);
    }
}
