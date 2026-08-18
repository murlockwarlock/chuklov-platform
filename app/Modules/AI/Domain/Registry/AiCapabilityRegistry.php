<?php

namespace App\Modules\AI\Domain\Registry;

use App\Modules\AI\Domain\Enums\AiCapability;
use InvalidArgumentException;

class AiCapabilityRegistry
{
    /** @var array<string, AiCapabilityDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, AiCapabilityDefinition> */
    public static function all(): array
    {
        if (self::$definitions === null) {
            self::$definitions = [
                AiCapability::ClinicalDocumentExtraction->value => new AiCapabilityDefinition(
                    capability: AiCapability::ClinicalDocumentExtraction,
                    displayName: 'Извлечение данных из медицинских документов',
                    description: 'Анализ выписок, заключений и лабораторных анализов с извлечением структурированных параметров.',
                    allowedInputReferenceTypes: ['client', 'medical_attachment'],
                    supportsRag: false,
                    allowedTools: [],
                    defaultTimeoutSeconds: 45,
                    maxTimeoutSeconds: 90,
                    defaultMaxTokens: 4096,
                    maxInputTokens: 8192,
                    maxRagContextTokens: 4096,
                    maxOutputTokens: 4096,
                    maxToolCalls: 0,
                    maxProviderSteps: 1,
                    requiresHumanReview: true,
                    defaultOutputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'document_type' => ['type' => 'string'],
                            'extracted_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['document_type', 'extracted_facts', 'summary'],
                    ],
                ),
                AiCapability::PostureAnalysis->value => new AiCapabilityDefinition(
                    capability: AiCapability::PostureAnalysis,
                    displayName: 'Анализ осанки и фотоматериалов',
                    description: 'Оценка симметрии, положения плеч, таза и позвоночника по загруженным фотографиям осанки.',
                    allowedInputReferenceTypes: ['client', 'medical_attachment'],
                    supportsRag: false,
                    allowedTools: [],
                    defaultTimeoutSeconds: 45,
                    maxTimeoutSeconds: 90,
                    defaultMaxTokens: 4096,
                    maxInputTokens: 8192,
                    maxRagContextTokens: 4096,
                    maxOutputTokens: 4096,
                    maxToolCalls: 0,
                    maxProviderSteps: 1,
                    requiresHumanReview: true,
                    defaultOutputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'symmetry_observations' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'posture_type' => ['type' => 'string'],
                            'recommendations' => ['type' => 'string'],
                        ],
                        'required' => ['symmetry_observations', 'posture_type'],
                    ],
                ),
                AiCapability::ClinicalSynthesizer->value => new AiCapabilityDefinition(
                    capability: AiCapability::ClinicalSynthesizer,
                    displayName: 'Клинический синтезатор и динамика',
                    description: 'Синтез динамики состояния пациента между сессиями на основе анамнеза и подтвержденных протоколов.',
                    allowedInputReferenceTypes: ['client', 'medical_session', 'medical_attachment', 'survey_attempt', 'knowledge_source'],
                    supportsRag: true,
                    allowedTools: ['search_knowledge_base'],
                    defaultTimeoutSeconds: 60,
                    maxTimeoutSeconds: 120,
                    defaultMaxTokens: 4096,
                    maxInputTokens: 8192,
                    maxRagContextTokens: 4096,
                    maxOutputTokens: 4096,
                    maxToolCalls: 5,
                    maxProviderSteps: 6,
                    requiresHumanReview: true,
                    defaultOutputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'symptom_progression' => ['type' => 'string'],
                            'proposed_adjustments' => ['type' => 'string'],
                            'specialist_notes' => ['type' => 'string'],
                        ],
                        'required' => ['symptom_progression', 'proposed_adjustments'],
                    ],
                ),
                AiCapability::ClientCompanion->value => new AiCapabilityDefinition(
                    capability: AiCapability::ClientCompanion,
                    displayName: 'Клиентский компаньон',
                    description: 'Сопровождение клиента, разъяснение упражнений и ответы на организационные вопросы.',
                    allowedInputReferenceTypes: ['client', 'knowledge_source'],
                    supportsRag: true,
                    allowedTools: ['search_knowledge_base'],
                    defaultTimeoutSeconds: 30,
                    maxTimeoutSeconds: 60,
                    defaultMaxTokens: 2048,
                    maxInputTokens: 8192,
                    maxRagContextTokens: 4096,
                    maxOutputTokens: 2048,
                    maxToolCalls: 5,
                    maxProviderSteps: 6,
                    requiresHumanReview: false,
                ),
                AiCapability::GeneralAssistant->value => new AiCapabilityDefinition(
                    capability: AiCapability::GeneralAssistant,
                    displayName: 'Общий ассистент',
                    description: 'Вспомогательный ассистент для подготовки черновиков сообщений и структурирования заметок.',
                    allowedInputReferenceTypes: ['client', 'booking'],
                    supportsRag: true,
                    allowedTools: ['search_knowledge_base'],
                    defaultTimeoutSeconds: 30,
                    maxTimeoutSeconds: 60,
                    defaultMaxTokens: 2048,
                    maxInputTokens: 8192,
                    maxRagContextTokens: 4096,
                    maxOutputTokens: 2048,
                    maxToolCalls: 5,
                    maxProviderSteps: 6,
                    requiresHumanReview: false,
                ),
            ];
        }

        return self::$definitions;
    }

    public static function get(AiCapability|string $capability): AiCapabilityDefinition
    {
        $key = $capability instanceof AiCapability ? $capability->value : $capability;
        $all = self::all();

        if (! isset($all[$key])) {
            throw new InvalidArgumentException("Unknown AI capability: {$key}");
        }

        return $all[$key];
    }
}
