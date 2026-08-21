<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class CreateModelConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiProviderConfiguration $provider, array $data): AiModelConfiguration
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        if ((int) $provider->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('Provider configuration is outside the current organization.');
        }

        $modelName = trim((string) ($data['model_name'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        if ($modelName === '' || $displayName === '') {
            throw new InvalidArgumentException('Model name and display name are required.');
        }

        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: AiMoney::canonicalMinorUnits($data['input_cost_per_million'] ?? 0, 'input_cost_per_million'),
            outputCostPerMillionMinorUnits: AiMoney::canonicalMinorUnits($data['output_cost_per_million'] ?? 0, 'output_cost_per_million'),
            cacheReadInputCostPerMillionMinorUnits: self::optionalCost($data, 'cache_read_input_cost_per_million', 0),
            cacheWriteInputCostPerMillionMinorUnits: self::optionalCost($data, 'cache_write_input_cost_per_million', 0),
            reasoningCostPerMillionMinorUnits: self::optionalCost($data, 'reasoning_cost_per_million', 0),
            fixedRequestCostApplicable: (bool) ($data['fixed_request_cost_applicable'] ?? false),
            fixedRequestCostMinorUnits: self::optionalCost($data, 'fixed_request_cost_minor_units', 0),
            unsupportedMeters: self::unsupportedMeters($data['unsupported_meters'] ?? []),
        );
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => $modelName,
            'display_name' => $displayName,
            'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : false,
            'lifecycle_status' => 'preview',
            'capabilities' => array_values(array_map('strval', (array) ($data['capabilities'] ?? []))),
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => self::positiveInteger($data['failover_priority'] ?? 1, 'failover_priority'),
        ]);

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.model_config.created',
            targetType: AiModelConfiguration::class,
            targetId: (string) $model->id,
            metadata: [
                'model_name' => $model->model_name,
                'is_enabled' => false,
            ],
        );

        return $model;
    }

    /** @param array<string, mixed> $data */
    private static function optionalCost(array $data, string $key, ?int $default = null): ?int
    {
        return array_key_exists($key, $data)
            ? AiMoney::canonicalMinorUnits($data[$key], $key)
            : $default;
    }

    private static function positiveInteger(mixed $value, string $key): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            if ($value > PHP_INT_MAX) {
                throw new InvalidArgumentException("{$key} is outside the supported range.");
            }

            $value = (int) $value;
        }

        $value = AiMoney::canonicalMinorUnits($value, $key);

        if ($value < 1) {
            throw new InvalidArgumentException("{$key} must be at least 1.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function unsupportedMeters(mixed $value): array
    {
        $values = is_array($value)
            ? $value
            : (is_string($value) ? preg_split('/\\s*,\\s*/', $value) ?: [] : []);

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $meter): string => trim((string) $meter), $values),
            static fn (string $meter): bool => $meter !== '',
        )));
    }
}
