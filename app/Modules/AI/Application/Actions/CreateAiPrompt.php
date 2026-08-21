<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\ValueObjects\AiTechnicalKey;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use InvalidArgumentException;

final class CreateAiPrompt
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): AiPrompt
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        $key = AiTechnicalKey::normalize($data['key'] ?? null, 'prompt key');
        $name = self::name($data['name'] ?? null);
        $capability = self::capability($data['capability'] ?? null);

        if (AiPrompt::query()
            ->where('organization_id', $organization->getKey())
            ->where('key', $key)
            ->exists()) {
            throw new InvalidArgumentException('A prompt with this key already exists in the organization.');
        }

        $prompt = AiPrompt::create([
            'organization_id' => $organization->getKey(),
            'key' => $key,
            'name' => $name,
            'description' => self::description($data['description'] ?? null),
            'capability' => $capability,
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.prompt.created',
            targetType: AiPrompt::class,
            targetId: (string) $prompt->getKey(),
            metadata: [
                'prompt_key' => $prompt->key,
                'capability' => $prompt->capability->value,
            ],
        );

        return $prompt;
    }

    private static function name(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Prompt name is required.');
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200) {
            throw new InvalidArgumentException('Prompt name is invalid.');
        }

        return $value;
    }

    private static function capability(mixed $value): AiCapability
    {
        if (! is_string($value) || ($capability = AiCapability::tryFrom($value)) === null) {
            throw new InvalidArgumentException('Prompt capability is invalid.');
        }

        return $capability;
    }

    private static function description(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Prompt description is invalid.');
        }

        return trim($value);
    }
}
