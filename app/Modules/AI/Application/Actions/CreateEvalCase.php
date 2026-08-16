<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class CreateEvalCase
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    /**
     * @param  array<string, mixed>  $testInputs
     * @param  array<string, mixed>  $expectedAssertions
     * @param  array<string, mixed>|null  $expectedOutputSchema
     */
    public function execute(
        User $actor,
        Organization $organization,
        int $suiteId,
        string $name,
        array $testInputs,
        array $expectedAssertions,
        ?array $expectedOutputSchema = null,
        bool $isSynthetic = true,
        bool $isDeidentified = false,
    ): AiEvalCase {
        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageAiPrompts)) {
            throw new AuthorizationException('Unauthorized to manage evaluation test cases.');
        }

        if (! $isSynthetic && ! $isDeidentified) {
            throw new InvalidArgumentException('Evaluation cases must be explicitly classified as synthetic or de-identified.');
        }

        // Validate that test_inputs does not contain references to real production patient records
        $this->assertNoProductionPatientReferences($organization->id, $testInputs);

        $suite = AiEvalSuite::query()
            ->where('organization_id', $organization->id)
            ->where('id', $suiteId)
            ->firstOrFail();

        return AiEvalCase::create([
            'organization_id' => $organization->id,
            'eval_suite_id' => $suite->id,
            'name' => trim($name),
            'is_synthetic' => $isSynthetic,
            'is_deidentified' => $isDeidentified,
            'test_inputs' => $testInputs,
            'expected_output_schema' => $expectedOutputSchema,
            'expected_assertions' => $expectedAssertions,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $testInputs
     */
    private function assertNoProductionPatientReferences(int $organizationId, array $testInputs): void
    {
        // 1. Direct client_id check
        if (isset($testInputs['client_id'])) {
            $clientId = (int) $testInputs['client_id'];
            if ($clientId > 0 && Client::query()->where('organization_id', $organizationId)->where('id', $clientId)->exists()) {
                throw new InvalidArgumentException('Real production client IDs are prohibited in evaluation cases. Use synthetic identifiers.');
            }
        }

        // 2. Direct medical_session_id check
        if (isset($testInputs['session_id']) || isset($testInputs['medical_session_id'])) {
            $sessionId = (int) ($testInputs['session_id'] ?? $testInputs['medical_session_id']);
            if ($sessionId > 0 && MedicalSession::query()->where('organization_id', $organizationId)->where('id', $sessionId)->exists()) {
                throw new InvalidArgumentException('Real production medical session IDs are prohibited in evaluation cases.');
            }
        }

        // 3. Scan string fields for obvious raw emails / phone patterns
        $encoded = json_encode($testInputs) ?: '';
        if (preg_match('/[a-zA-Z0-9._%+-]+@(?!example\.com|test\.com|synthetic\.org)[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $encoded) === 1) {
            throw new InvalidArgumentException('Real email addresses detected in evaluation input. Use synthetic domains (e.g. @example.com).');
        }

        if (preg_match('/\+?[0-9]{11,15}/', $encoded) === 1) {
            throw new InvalidArgumentException('Raw phone numbers detected in evaluation input. Please de-identify or mask.');
        }
    }
}
