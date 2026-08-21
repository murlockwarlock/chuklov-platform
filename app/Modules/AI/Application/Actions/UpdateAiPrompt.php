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
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class UpdateAiPrompt
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiPrompt $prompt, array $data): AiPrompt
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        if ((int) $prompt->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Prompt is outside the current organization.');
        }

        if (array_key_exists('key', $data)) {
            $key = AiTechnicalKey::normalize($data['key'], 'prompt key');
            if ($key !== $prompt->key) {
                throw new InvalidArgumentException('Prompt key cannot be changed after creation.');
            }
        }

        $prompt->update([
            'name' => array_key_exists('name', $data) ? self::name($data['name']) : $prompt->name,
            'description' => array_key_exists('description', $data)
                ? self::description($data['description'])
                : $prompt->description,
            'capability' => array_key_exists('capability', $data)
                ? self::capability($data['capability'])
                : $prompt->capability,
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.prompt.updated',
            targetType: AiPrompt::class,
            targetId: (string) $prompt->getKey(),
            metadata: [
                'prompt_key' => $prompt->key,
                'capability' => $prompt->capability->value,
            ],
        );

        return $prompt->refresh();
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
