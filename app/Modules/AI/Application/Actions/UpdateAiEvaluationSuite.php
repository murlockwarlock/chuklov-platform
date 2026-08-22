<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\ValueObjects\AiTechnicalKey;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class UpdateAiEvaluationSuite
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiEvalSuite $suite, array $data): AiEvalSuite
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        if ((int) $suite->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Evaluation is outside the current organization.');
        }

        if (array_key_exists('key', $data)) {
            $key = AiTechnicalKey::normalize($data['key'], 'evaluation key');
            if ($key !== $suite->key) {
                throw new InvalidArgumentException('Evaluation key cannot be changed after creation.');
            }
        }

        $capability = array_key_exists('capability', $data)
            ? self::capability($data['capability'])
            : $suite->capability;
        $promptId = array_key_exists('prompt_id', $data)
            ? CreateAiEvaluationSuite::promptId($data['prompt_id'])
            : $suite->prompt_id;
        CreateAiEvaluationSuite::assertPrompt($organization->getKey(), $promptId, $capability);

        $suite->update([
            'name' => array_key_exists('name', $data) ? self::name($data['name']) : $suite->name,
            'description' => array_key_exists('description', $data)
                ? self::description($data['description'])
                : $suite->description,
            'capability' => $capability,
            'prompt_id' => $promptId,
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.evaluation_suite.updated',
            targetType: AiEvalSuite::class,
            targetId: (string) $suite->getKey(),
            metadata: [
                'evaluation_key' => $suite->key,
                'capability' => $suite->capability->value,
            ],
        );

        return $suite->refresh();
    }

    private static function name(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Evaluation name is required.');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200) {
            throw new InvalidArgumentException('Evaluation name is invalid.');
        }

        return $value;
    }

    private static function capability(mixed $value): AiCapability
    {
        if (! is_string($value) || ($capability = AiCapability::tryFrom($value)) === null) {
            throw new InvalidArgumentException('Evaluation capability is invalid.');
        }

        return $capability;
    }

    private static function description(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Evaluation description is invalid.');
        }

        return trim($value);
    }
}
