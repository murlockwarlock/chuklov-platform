<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\ResolvePatientClinicalClient;
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
        private ResolvePatientClinicalClient $patients,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, ?int $patientId = null): void
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
            'pains' => ['sometimes', 'array', 'max:50'],
            'history_entries' => ['sometimes', 'array', 'max:100'],
            'history_entries.*' => ['array'],
        ], [], [
            'complaints' => __('Жалобы'), 'goals' => __('Задачи по здоровью'), 'chronic_conditions' => __('Хронические заболевания'),
            'operations' => __('Операции'), 'injuries' => __('Травмы'), 'medicines' => __('Лекарственные препараты'), 'supplements' => __('Нутрицевтики и БАДы'),
        ])->validate();
        $answers = $this->questionnaire->validate($fields['answers'], false);
        if (trim((string) ($fields['complaints'] ?? '')) === '' && $answers['symptoms'] === []) {
            throw ValidationException::withMessages(['complaints' => 'Выберите симптомы или опишите жалобы.']);
        }
        if (! $answers['had_operations']) {
            $answers['operation_year'] = null;
        }
        if (! $answers['had_injuries']) {
            $answers['injury_year'] = null;
        }
        if ($answers['no_chronic_conditions']) {
            $fields['chronic_conditions'] = null;
        }
        $subject = $this->patients->handle($this->context->client(), $patientId);
        $historyEntriesProvided = array_key_exists('history_entries', $fields);
        $this->update->handle($this->context->client(), $subject, new UpdateMedicalProfileCommand(
            expectedSnapshot: $fields['expected_snapshot'],
            complaints: $fields['complaints'] ?? null,
            goals: $fields['goals'],
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
            chronicConditionsProvided: true,
            clinicalAnswersProvided: true,
            confirmCurrentData: true,
            pains: array_key_exists('pains', $fields) ? $fields['pains'] : null,
            painsProvided: array_key_exists('pains', $fields),
            historyEntries: $historyEntriesProvided ? $fields['history_entries'] : null,
            historyEntriesProvided: $historyEntriesProvided,
        ));
    }
}
