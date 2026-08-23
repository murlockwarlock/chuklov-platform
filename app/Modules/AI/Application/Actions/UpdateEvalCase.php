<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use JsonException;

class UpdateEvalCase
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly CreateEvalCase $createAction,
        private readonly AiEvaluationAssertionRegistry $assertionRegistry,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, AiEvalCase $case, array $data): AiEvalCase
    {
        $organization = $this->context->organization();
        if ((int) $case->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Evaluation case is outside the current organization.');
        }

        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageAiPrompts)) {
            throw new AuthorizationException('Unauthorized to manage evaluation test cases.');
        }

        if (! array_key_exists('is_synthetic', $data) || ! array_key_exists('is_deidentified', $data)) {
            throw new InvalidArgumentException('Evaluation case classification must be explicitly submitted on every update.');
        }

        $isSynthetic = (bool) $data['is_synthetic'];
        $isDeidentified = (bool) $data['is_deidentified'];

        $this->createAction->validateClassification($isSynthetic, $isDeidentified);
        $testInputs = isset($data['test_inputs'])
            ? $this->inputValue($data['test_inputs'])
            : (array) $case->test_inputs;

        $this->createAction->assertNoProductionPatientReferences($organization->id, $testInputs);

        $expectedAssertions = isset($data['expected_assertions'])
            ? $this->arrayValue($data['expected_assertions'], 'Evaluation assertions')
            : (array) $case->expected_assertions;
        $this->createAction->assertNoProductionPatientReferences($organization->id, $expectedAssertions);
        $expectedAssertions = $this->assertionRegistry->normalize($expectedAssertions);

        $expectedOutputSchema = array_key_exists('expected_output_schema', $data)
            ? ($data['expected_output_schema'] === null ? null : $this->schemaValue($data['expected_output_schema']))
            : $case->expected_output_schema;
        if ($expectedOutputSchema !== null) {
            $this->createAction->assertNoProductionPatientReferences($organization->id, $expectedOutputSchema);
            $this->assertionRegistry->validateSchema($expectedOutputSchema);
        }

        $case->update([
            'name' => isset($data['name']) ? trim((string) $data['name']) : $case->name,
            'is_synthetic' => $isSynthetic,
            'is_deidentified' => $isDeidentified,
            'test_inputs' => $testInputs,
            'expected_assertions' => $expectedAssertions,
            'expected_output_schema' => $expectedOutputSchema,
            'is_active' => (bool) ($data['is_active'] ?? $case->is_active),
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.evaluation_case.updated',
            targetType: AiEvalCase::class,
            targetId: (string) $case->getKey(),
            metadata: [
                'eval_suite_id' => (string) $case->eval_suite_id,
                'is_active' => (bool) $case->is_active,
            ],
        );

        return $case;
    }

    /** @return array<int|string, mixed> */
    private function arrayValue(mixed $value, string $label): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be an array or valid JSON object.");
        }

        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("{$label} must be valid JSON.");
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$label} must be an array or valid JSON object.");
        }

        return $decoded;
    }

    /** @return array<string|int, mixed> */
    private function inputValue(mixed $value): array
    {
        if (is_array($value)) {
            return $this->stringKeyedValue($value, 'Evaluation input');
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Evaluation input must be an array or text.');
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
                throw new InvalidArgumentException('Evaluation input must be valid JSON.');
            }

            return ['query' => $value];
        }

        return is_array($decoded) ? $this->stringKeyedValue($decoded, 'Evaluation input') : ['query' => $value];
    }

    /** @return array<string, mixed> */
    private function schemaValue(mixed $value): array
    {
        $value = $this->arrayValue($value, 'Evaluation output schema');

        return $this->stringKeyedValue($value, 'Evaluation output schema');
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedValue(array $value, string $label): array
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException("{$label} must be a JSON object.");
        }

        $result = [];
        foreach ($value as $key => $nested) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("{$label} must use string keys.");
            }

            $result[$key] = $nested;
        }

        return $result;
    }
}
