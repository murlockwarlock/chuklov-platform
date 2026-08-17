<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class UpdateEvalCase
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CreateEvalCase $createAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, AiEvalCase $case, array $data): AiEvalCase
    {
        $organization = $case->organization;
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
            ? (is_string($data['test_inputs']) ? (json_decode($data['test_inputs'], true) ?: ['query' => $data['test_inputs']]) : (array) $data['test_inputs'])
            : (array) $case->test_inputs;

        $this->createAction->assertNoProductionPatientReferences($organization->id, $testInputs);

        $expectedAssertions = isset($data['expected_assertions'])
            ? (is_string($data['expected_assertions']) ? (json_decode($data['expected_assertions'], true) ?: []) : (array) $data['expected_assertions'])
            : (array) $case->expected_assertions;

        $case->update([
            'name' => isset($data['name']) ? trim((string) $data['name']) : $case->name,
            'is_synthetic' => $isSynthetic,
            'is_deidentified' => $isDeidentified,
            'test_inputs' => $testInputs,
            'expected_assertions' => $expectedAssertions,
            'expected_output_schema' => $data['expected_output_schema'] ?? $case->expected_output_schema,
            'is_active' => (bool) ($data['is_active'] ?? $case->is_active),
        ]);

        return $case;
    }
}
