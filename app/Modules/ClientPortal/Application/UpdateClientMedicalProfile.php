<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\MedicalProfiles\Application\ClinicalQuestionnaire;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdateClientMedicalProfile
{
    public function __construct(
        private ClientPortalContext $context,
        private UpdateMedicalProfile $update,
        private ClinicalQuestionnaire $questionnaire,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): void
    {
        $fields = Validator::make($data, [
            'expected_snapshot' => ['required', 'string', 'size:64'],
            'complaints' => ['nullable', 'string', 'max:10000'],
            'goals' => ['required', 'string', 'max:10000'],
            'chronic_conditions' => ['nullable', 'string', 'max:10000'],
            'operations' => ['nullable', 'string', 'max:10000'],
            'injuries' => ['nullable', 'string', 'max:10000'],
            'medicines' => ['nullable', 'string', 'max:10000'],
            'supplements' => ['nullable', 'string', 'max:10000'],
            'answers' => ['required', 'array'],
        ], [], [
            'complaints' => __('Жалобы'), 'goals' => __('Задачи по здоровью'), 'chronic_conditions' => __('Хронические заболевания'),
            'operations' => __('Операции'), 'injuries' => __('Травмы'), 'medicines' => __('Лекарственные препараты'), 'supplements' => __('Нутрицевтики и БАДы'),
        ])->validate();
        $answers = $this->questionnaire->validate($fields['answers']);
        if (trim((string) ($fields['complaints'] ?? '')) === '' && $answers['symptoms'] === []) {
            throw ValidationException::withMessages(['complaints' => 'Выберите симптомы или опишите жалобы.']);
        }
        foreach (['operations' => 'had_operations', 'injuries' => 'had_injuries'] as $field => $answer) {
            if ($answers[$answer] && trim((string) ($fields[$field] ?? '')) === '') {
                throw ValidationException::withMessages([$field => 'Добавьте описание.']);
            }
            if (! $answers[$answer]) {
                $fields[$field] = null;
                $answers[$field === 'operations' ? 'operation_year' : 'injury_year'] = null;
            }
        }
        if ($answers['no_chronic_conditions']) {
            $fields['chronic_conditions'] = null;
        }
        $this->update->handle($this->context->client(), $this->context->client(), new UpdateMedicalProfileCommand(
            expectedSnapshot: $fields['expected_snapshot'],
            complaints: $fields['complaints'] ?? null,
            goals: $fields['goals'],
            operations: $fields['operations'] ?? null,
            injuries: $fields['injuries'] ?? null,
            medicines: $fields['medicines'] ?? null,
            supplements: $fields['supplements'] ?? null,
            chronicConditions: $fields['chronic_conditions'] ?? null,
            clinicalAnswers: json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            anamnesisProvided: false,
            complaintsGoalsProvided: false,
            operationsInjuriesProvided: false,
            medicinesProvided: true,
            supplementsProvided: true,
            complaintsProvided: true,
            goalsProvided: true,
            operationsProvided: true,
            injuriesProvided: true,
            chronicConditionsProvided: true,
            clinicalAnswersProvided: true,
            confirmCurrentData: true,
        ));
    }
}
