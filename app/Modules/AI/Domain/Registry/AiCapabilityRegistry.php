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
                            'exam_type' => ['type' => 'string'],
                            'anatomical_region' => ['type' => 'string'],
                            'key_findings' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'location' => ['type' => 'string'],
                                        'pathology' => ['type' => 'string'],
                                        'size_mm' => [
                                            'anyOf' => [
                                                ['type' => 'number'],
                                                ['type' => 'null'],
                                            ],
                                        ],
                                        'impact' => ['type' => 'string'],
                                    ],
                                    'required' => ['location', 'pathology', 'size_mm', 'impact'],
                                ],
                            ],
                            'structural_deformations' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'critical_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'plain_summary' => ['type' => 'string'],
                        ],
                        'required' => [
                            'exam_type',
                            'anatomical_region',
                            'key_findings',
                            'structural_deformations',
                            'critical_flags',
                            'plain_summary',
                        ],
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
                            'visual_findings' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'plane' => ['type' => 'string', 'enum' => ['front', 'side', 'back']],
                                        'observations' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    ],
                                    'required' => ['plane', 'observations'],
                                ],
                            ],
                            'leading_compensatory_patterns' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'practitioner_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'limitations' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => [
                            'visual_findings',
                            'leading_compensatory_patterns',
                            'practitioner_focus',
                            'limitations',
                        ],
                    ],
                ),
                AiCapability::ClinicalSynthesizer->value => new AiCapabilityDefinition(
                    capability: AiCapability::ClinicalSynthesizer,
                    displayName: 'Клинический синтезатор и динамика',
                    description: 'Синтез динамики состояния пациента между сессиями на основе анамнеза и подтвержденных протоколов.',
                    allowedInputReferenceTypes: ['client', 'medical_session', 'medical_attachment', 'survey_attempt', 'knowledge_source', 'ai_run'],
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
                            'client_summary' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                            'main_request' => [
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'null'],
                                ],
                            ],
                            'source_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'hypotheses' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'statement' => ['type' => 'string'],
                                        'supporting_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'uncertainty' => ['type' => 'string'],
                                    ],
                                    'required' => ['statement', 'supporting_facts', 'uncertainty'],
                                ],
                            ],
                            'critical_limitations_risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'blind_spots_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'recommended_first_session_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'missing_information' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => [
                            'client_summary',
                            'main_request',
                            'source_facts',
                            'hypotheses',
                            'critical_limitations_risks',
                            'blind_spots_questions',
                            'recommended_first_session_focus',
                            'missing_information',
                        ],
                    ],
                ),
                AiCapability::ClientCompanion->value => new AiCapabilityDefinition(
                    capability: AiCapability::ClientCompanion,
                    displayName: 'Клиентский компаньон',
                    description: 'Сопровождение клиента, разъяснение упражнений и ответы на организационные вопросы.',
                    allowedInputReferenceTypes: ['client', 'companion_attachment', 'knowledge_source'],
                    supportsRag: true,
                    allowedTools: ['search_knowledge_base'],
                    defaultTimeoutSeconds: 30,
                    maxTimeoutSeconds: 60,
                    defaultMaxTokens: 4096,
                    maxInputTokens: 8192,
                    maxRagContextTokens: 4096,
                    maxOutputTokens: 4096,
                    maxToolCalls: 5,
                    maxProviderSteps: 6,
                    requiresHumanReview: false,
                    defaultOutputSchema: [
                        'type' => 'object',
                        'properties' => [
                            'decision' => ['type' => 'string', 'enum' => ['reply', 'handoff_required']],
                            'reply' => ['type' => 'string'],
                            'handoff_reason' => ['type' => 'string'],
                            'suggested_safe_actions' => [
                                'type' => 'array',
                                'maxItems' => 4,
                                'items' => [
                                    'type' => 'string',
                                    'enum' => ['request_human', 'open_portal', 'feedback_helpful', 'feedback_not_helpful'],
                                ],
                            ],
                        ],
                        'required' => ['decision', 'reply', 'handoff_reason'],
                    ],
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
