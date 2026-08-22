<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Services\AiTechnicalKeyAllocator;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateAiPrompt
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly AiTechnicalKeyAllocator $keyAllocator,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): AiPrompt
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiPrompts);

        $name = self::name($data['name'] ?? null);
        $capability = self::capability($data['capability'] ?? null);
        $requestedKey = $data['key'] ?? null;
        $generatedKey = $requestedKey === null || $requestedKey === '';

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $capability, $data, $generatedKey, $name, $organization, $requestedKey): AiPrompt {
                    $key = $this->keyAllocator->prompt(
                        organizationId: (int) $organization->getKey(),
                        name: $name,
                        requestedKey: $generatedKey ? null : $requestedKey,
                    );

                    if (! $generatedKey && AiPrompt::query()
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
                });
            } catch (QueryException $exception) {
                if (! $generatedKey || ! self::isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw new InvalidArgumentException('Не удалось подобрать уникальное техническое имя промпта. Повторите попытку.');
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

    private static function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'ai_prompts');
    }
}
