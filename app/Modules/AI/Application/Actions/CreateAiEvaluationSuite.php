<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\ValueObjects\AiTechnicalKey;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use InvalidArgumentException;

final class CreateAiEvaluationSuite
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): AiEvalSuite
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        $key = AiTechnicalKey::normalize($data['key'] ?? null, 'evaluation key');
        $name = self::name($data['name'] ?? null);
        $capability = self::capability($data['capability'] ?? null);
        $promptId = self::promptId($data['prompt_id'] ?? null);
        self::assertPrompt($organization->getKey(), $promptId, $capability);

        if (AiEvalSuite::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', $key)
            ->exists()) {
            throw new InvalidArgumentException('An evaluation with this key already exists in the organization.');
        }

        $suite = AiEvalSuite::create([
            'organization_id' => $organization->getKey(),
            'key' => $key,
            'name' => $name,
            'description' => self::description($data['description'] ?? null),
            'capability' => $capability,
            'prompt_id' => $promptId,
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.evaluation_suite.created',
            targetType: AiEvalSuite::class,
            targetId: (string) $suite->getKey(),
            metadata: [
                'evaluation_key' => $suite->key,
                'capability' => $suite->capability->value,
            ],
        );

        return $suite;
    }

    public static function promptId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        throw new InvalidArgumentException('The linked prompt is invalid.');
    }

    public static function assertPrompt(int $organizationId, ?int $promptId, AiCapability $capability): void
    {
        if ($promptId === null) {
            return;
        }

        $prompt = AiPrompt::query()
            ->where('organization_id', $organizationId)
            ->whereKey($promptId)
            ->first();

        if ($prompt === null || $prompt->capability !== $capability) {
            throw new InvalidArgumentException('The linked prompt must belong to the current organization and capability.');
        }
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
