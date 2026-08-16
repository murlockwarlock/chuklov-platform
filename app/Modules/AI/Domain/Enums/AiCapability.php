<?php

namespace App\Modules\AI\Domain\Enums;

enum AiCapability: string
{
    case ClinicalDocumentExtraction = 'clinical_document_extraction';
    case PostureAnalysis = 'posture_analysis';
    case ClinicalSynthesizer = 'clinical_synthesizer';
    case ClientCompanion = 'client_companion';
    case GeneralAssistant = 'general_assistant';

    public function label(): string
    {
        return match ($this) {
            self::ClinicalDocumentExtraction => 'Извлечение клинических документов',
            self::PostureAnalysis => 'Анализ осанки и фото',
            self::ClinicalSynthesizer => 'Клинический синтезатор и динамика',
            self::ClientCompanion => 'Клиентский компаньон',
            self::GeneralAssistant => 'Общий ассистент',
        };
    }
}
