<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\Data\PromptBundle;
use App\Modules\AI\Application\Validation\EvalInputPrivacyValidator;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use App\Modules\AI\Infrastructure\Output\JsonSchemaOutputValidator;
use Tests\TestCase;

final class SourceBackedAgentContractTest extends TestCase
{
    private AiEvaluationAssertionRegistry $assertions;

    private JsonSchemaOutputValidator $schemaValidator;

    private EvalInputPrivacyValidator $privacyValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertions = app(AiEvaluationAssertionRegistry::class);
        $this->schemaValidator = app(JsonSchemaOutputValidator::class);
        $this->privacyValidator = app(EvalInputPrivacyValidator::class);
    }

    public function test_source_backed_contracts_are_registered_and_validate(): void
    {
        $agentCapabilities = [
            AiCapability::ClinicalDocumentExtraction,
            AiCapability::PostureAnalysis,
            AiCapability::ClinicalSynthesizer,
        ];

        foreach ($agentCapabilities as $capability) {
            $schema = AiCapabilityRegistry::get($capability)->defaultOutputSchema;

            self::assertIsArray($schema);
            $this->assertions->validateSchema($schema);
        }

        $documentPayload = [
            'exam_type' => 'Synthetic radiology report',
            'anatomical_region' => 'lumbar region',
            'key_findings' => [[
                'location' => 'synthetic location',
                'pathology' => 'explicit finding',
                'size_mm' => 5.4,
                'impact' => 'requires review',
            ]],
            'structural_deformations' => [],
            'critical_flags' => [],
            'plain_summary' => 'Synthetic summary',
        ];
        $documentSchema = AiCapabilityRegistry::get(AiCapability::ClinicalDocumentExtraction)->defaultOutputSchema;
        self::assertIsArray($documentSchema);

        self::assertTrue($this->schemaValidator->validate($documentPayload, $documentSchema));
        self::assertTrue($this->schemaValidator->validate([
            ...$documentPayload,
            'key_findings' => [[
                'location' => 'synthetic location',
                'pathology' => 'finding without dimension',
                'size_mm' => null,
                'impact' => 'not stated',
            ]],
        ], $documentSchema));
        self::assertFalse($this->schemaValidator->validate([
            ...$documentPayload,
            'key_findings' => [[
                'location' => 'synthetic location',
                'pathology' => 'invalid string measurement',
                'size_mm' => '5.4',
                'impact' => 'not stated',
            ]],
        ], $documentSchema));
        self::assertFalse($this->schemaValidator->validate('```json\n'.json_encode($documentPayload, JSON_THROW_ON_ERROR).'\n```', $documentSchema));

        $postureSchema = AiCapabilityRegistry::get(AiCapability::PostureAnalysis)->defaultOutputSchema;
        self::assertIsArray($postureSchema);
        self::assertArrayNotHasKey('vector_shifts', $postureSchema['properties']);
        self::assertArrayNotHasKey('angles', $postureSchema['properties']);
        self::assertTrue($this->schemaValidator->validate([
            'visual_findings' => [
                ['plane' => 'front', 'observations' => ['synthetic observation']],
                ['plane' => 'side', 'observations' => ['synthetic observation']],
                ['plane' => 'back', 'observations' => ['synthetic observation']],
            ],
            'leading_compensatory_patterns' => ['synthetic pattern'],
            'practitioner_focus' => ['synthetic focus'],
            'limitations' => ['No validated angle measurement was supplied.'],
        ], $postureSchema));

        $synthesizerSchema = AiCapabilityRegistry::get(AiCapability::ClinicalSynthesizer)->defaultOutputSchema;
        self::assertIsArray($synthesizerSchema);
        self::assertTrue($this->schemaValidator->validate([
            'client_summary' => null,
            'main_request' => 'Synthetic request',
            'source_facts' => ['Synthetic fact'],
            'hypotheses' => [[
                'statement' => 'Synthetic hypothesis',
                'supporting_facts' => ['Synthetic fact'],
                'uncertainty' => 'Requires practitioner confirmation',
            ]],
            'critical_limitations_risks' => ['Synthetic limitation'],
            'blind_spots_questions' => ['Synthetic question'],
            'recommended_first_session_focus' => ['Synthetic focus'],
            'missing_information' => ['Survey result'],
        ], $synthesizerSchema));
    }

    public function test_prompt_drafts_match_registered_contracts_and_have_no_model_authority(): void
    {
        $files = glob(base_path('docs/product/source-pack/ai-prompt-bundles/*.json'));

        self::assertIsArray($files);
        self::assertCount(4, $files);

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $bundle = PromptBundle::fromArray($data);

            self::assertSame(
                AiCapabilityRegistry::get($bundle->capability)->defaultOutputSchema,
                $bundle->outputSchema,
            );
            self::assertStringContainsString('[SOURCE TEXT]', $bundle->systemPrompt);
            self::assertStringContainsString('[CURRENT PLATFORM SAFETY GUARDRAILS]', $bundle->systemPrompt);
            self::assertStringContainsString('[CURRENT RUNTIME CONTRACT]', $bundle->systemPrompt);
            self::assertGreaterThan(1500, mb_strlen($bundle->systemPrompt));
            self::assertArrayNotHasKey('model', $data);
            self::assertArrayNotHasKey('provider', $data);

            $sourceFragment = match ($bundle->capability) {
                AiCapability::ClientCompanion => 'официальный ИИ-ассистент',
                AiCapability::ClinicalDocumentExtraction => 'узкоспециализированный медицинский эксперт',
                AiCapability::ClinicalSynthesizer => 'персональный AI-ассистент остеопата',
                AiCapability::PostureAnalysis => 'биомеханик и остеопатический аналитик',
                default => null,
            };
            self::assertIsString($sourceFragment);
            self::assertStringContainsString($sourceFragment, $bundle->systemPrompt);
        }
    }

    public function test_source_backed_evaluation_manifest_is_synthetic_and_uses_existing_assertions(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('evals/source-backed/source-backed-agent-suites.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertCount(4, $manifest['suites']);

        foreach ($manifest['suites'] as $suite) {
            $schema = $suite['expected_output_schema'];
            $this->assertions->validateSchema($schema);

            foreach ($suite['cases'] as $case) {
                self::assertTrue($case['is_synthetic']);
                self::assertFalse($case['is_deidentified']);
                if (isset($case['test_inputs'])) {
                    $this->privacyValidator->validate($case['test_inputs']);
                }
                $this->privacyValidator->validate($case['expected_assertions']);
                $this->assertions->normalize($case['expected_assertions']);
                if (isset($case['expected_output_example'])) {
                    self::assertTrue($this->schemaValidator->validate($case['expected_output_example'], $schema));
                }
            }
        }

        $postureSuite = null;
        foreach ($manifest['suites'] as $suite) {
            if (($suite['capability'] ?? null) === AiCapability::PostureAnalysis->value) {
                $postureSuite = $suite;
                break;
            }
        }
        self::assertIsArray($postureSuite);
        self::assertSame('implemented_with_controlled_synthetic_fixture', $postureSuite['execution_status']);
        self::assertSame(['front', 'side', 'back'], $postureSuite['required_attachment_roles']);

        $caseCounts = [];
        foreach ($manifest['suites'] as $suite) {
            $caseCounts[(string) $suite['capability']] = count($suite['cases']);
        }
        self::assertGreaterThanOrEqual(8, $caseCounts[AiCapability::ClinicalDocumentExtraction->value] ?? 0);
        self::assertGreaterThanOrEqual(9, $caseCounts[AiCapability::PostureAnalysis->value] ?? 0);
        self::assertGreaterThanOrEqual(9, $caseCounts[AiCapability::ClinicalSynthesizer->value] ?? 0);
        self::assertGreaterThanOrEqual(16, $caseCounts[AiCapability::ClientCompanion->value] ?? 0);
    }

    public function test_output_schema_privacy_allows_safe_summary_field_but_rejects_production_reference(): void
    {
        $this->privacyValidator->validateOutputSchema([
            'type' => 'object',
            'properties' => [
                'client_summary' => ['type' => 'string'],
                'recommended_first_session_focus' => ['type' => 'array'],
            ],
        ]);

        $this->expectExceptionMessage('Production reference');
        $this->privacyValidator->validateOutputSchema([
            'type' => 'object',
            'properties' => [
                'client_id' => ['type' => 'integer'],
            ],
        ]);
    }
}
