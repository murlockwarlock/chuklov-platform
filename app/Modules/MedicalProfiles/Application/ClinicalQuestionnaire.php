<?php

namespace App\Modules\MedicalProfiles\Application;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ClinicalQuestionnaire
{
    public const SYMPTOMS = ['headache', 'neck', 'back', 'joints', 'swelling', 'digestion', 'weakness', 'insomnia', 'stress'];

    /** @param array<string, mixed> $answers @return array<string, mixed> */
    public function validate(array $answers): array
    {
        $validated = Validator::make(['answers' => $answers], [
            'answers' => ['required', 'array:symptoms,pain_vas,no_chronic_conditions,had_operations,operation_year,had_injuries,injury_year'],
            'answers.symptoms' => ['present', 'array', 'max:9'],
            'answers.symptoms.*' => ['string', Rule::in(self::SYMPTOMS), 'distinct'],
            'answers.pain_vas' => ['present', 'nullable', 'integer', 'between:0,10'],
            'answers.no_chronic_conditions' => ['required', 'boolean'],
            'answers.had_operations' => ['required', 'boolean'],
            'answers.operation_year' => ['nullable', 'integer', 'between:1900,'.now()->year, 'required_if:answers.had_operations,true'],
            'answers.had_injuries' => ['required', 'boolean'],
            'answers.injury_year' => ['nullable', 'integer', 'between:1900,'.now()->year, 'required_if:answers.had_injuries,true'],
        ])->validate()['answers'];
        $validated['pain_vas'] = $validated['pain_vas'] === null ? null : (int) $validated['pain_vas'];
        foreach (['no_chronic_conditions', 'had_operations', 'had_injuries'] as $field) {
            $validated[$field] = (bool) $validated[$field];
        }
        foreach (['operation_year', 'injury_year'] as $field) {
            $validated[$field] = isset($validated[$field]) ? (int) $validated[$field] : null;
        }
        $validated['symptoms'] = array_values($validated['symptoms']);

        return $validated;
    }
}
