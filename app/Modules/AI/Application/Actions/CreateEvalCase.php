<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Validation\EvalInputPrivacyValidator;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Services\AiEvaluationAssertionRegistry;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;

class CreateEvalCase
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly EvalInputPrivacyValidator $privacyValidator,
        private readonly AiEvaluationAssertionRegistry $assertionRegistry,
        private readonly RecordAuditEvent $audit,
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
        if ((int) $organization->getKey() !== $this->context->id()) {
            throw new AuthorizationException('Evaluation case is outside the current organization.');
        }

        $organization = $this->context->organization();
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

        $case = AiEvalCase::create([
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

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.evaluation_case.created',
            targetType: AiEvalCase::class,
            targetId: (string) $case->getKey(),
            metadata: [
                'eval_suite_id' => (string) $suite->getKey(),
                'is_synthetic' => $isSynthetic,
            ],
        );

        return $case;
    }

    /** @param array<int|string, mixed> $testInputs */
    public function assertNoProductionPatientReferences(int $organizationId, array $testInputs): void
    {
        $this->privacyValidator->validate($testInputs);
    }

    public function validateClassification(bool $isSynthetic, bool $isDeidentified): void
    {
        $this->privacyValidator->validateClassification($isSynthetic, $isDeidentified);
    }
}
