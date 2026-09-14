<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalProfileData;

final readonly class ClinicalSynthesizerMedicalProfileContext
{
    private const MAX_CONTEXT_LENGTH = 1500;

    public function build(?MedicalProfileData $profile): string
    {
        $sections = array_filter([
            $this->section('Анамнез', $profile?->anamnesis, 400),
            $this->section('Операции и травмы', $profile?->operationsInjuries, 350),
            $this->section('Лекарства', $profile?->medicines, 300),
            $this->section('БАДы', $profile?->supplements, 300),
        ]);

        if ($sections === []) {
            return 'Данные медицинского профиля не заполнены.';
        }

        return $this->boundedText(implode("\n\n", $sections), self::MAX_CONTEXT_LENGTH);
    }

    private function section(string $label, ?string $value, int $limit): ?string
    {
        $value = $this->boundedText($value, $limit);

        return $value === '' ? null : $label.":\n".$value;
    }

    private function boundedText(?string $value, int $limit): string
    {
        $text = trim((string) $value);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
