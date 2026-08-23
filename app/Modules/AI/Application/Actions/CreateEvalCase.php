<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Validation\EvalInputPrivacyValidator;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

class CreateEvalCase
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly EvalInputPrivacyValidator $privacyValidator,
        private readonly AiEvaluationAssertionRegistry $assertionRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $testInputs
     * @param  array<int|string, mixed>  $expectedAssertions
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
        bool $isSynthetic = false,
        bool $isDeidentified = false,
    ): AiEvalCase {
        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageAiPrompts)) {
            throw new AuthorizationException('Unauthorized to manage evaluation test cases.');
        }

        $this->privacyValidator->validateClassification($isSynthetic, $isDeidentified);
        $this->privacyValidator->validate($testInputs);
        $this->privacyValidator->validate($expectedAssertions);
        $expectedAssertions = $this->assertionRegistry->normalize($expectedAssertions);
        if ($expectedOutputSchema !== null) {
            $this->privacyValidator->validate($expectedOutputSchema);
            $this->assertionRegistry->validateSchema($expectedOutputSchema);
        }

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
    public function assertNoProductionPatientReferences(int $organizationId, array $testInputs): void
    {
        $this->privacyValidator->validate($testInputs);
    }

    public function validateClassification(bool $isSynthetic, bool $isDeidentified): void
    {
        $this->privacyValidator->validateClassification($isSynthetic, $isDeidentified);
    }
}
