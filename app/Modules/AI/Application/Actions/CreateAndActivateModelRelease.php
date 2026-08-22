<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateAndActivateModelRelease
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, AiModelConfiguration $modelConfig, array $data): AiModelRelease
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ActivateAiReleases);

        $providerManagementKeys = [
            'model_name',
            'display_name',
            'capabilities',
            'pricing_snapshot',
            'input_cost_per_million',
            'output_cost_per_million',
            'cache_read_input_cost_per_million',
            'cache_write_input_cost_per_million',
            'reasoning_cost_per_million',
            'fixed_request_cost_applicable',
            'fixed_request_cost_minor_units',
            'unsupported_meters',
            'failover_priority',
            'is_enabled',
        ];
        if (array_intersect($providerManagementKeys, array_keys($data)) !== []) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);
        }

        return DB::transaction(function () use ($organization, $actor, $modelConfig, $data, $providerManagementKeys): AiModelRelease {
            $lockedConfig = AiModelConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($modelConfig->getKey())
                ->with('providerConfiguration')
                ->lockForUpdate()
                ->first();

            if ($lockedConfig === null) {
                throw new AuthorizationException('Model configuration is outside the current organization.');
            }

            $providerConfig = $lockedConfig->providerConfiguration;
            if ($providerConfig === null || (int) $providerConfig->organization_id !== (int) $organization->getKey()) {
                throw new AuthorizationException('Model provider configuration is outside the current organization.');
            }

            $pricing = array_key_exists('pricing_snapshot', $data)
                ? self::canonicalPricingSnapshot($data['pricing_snapshot'])
                : (array) $lockedConfig->pricing_snapshot;
            if (array_intersect([
                'input_cost_per_million',
                'output_cost_per_million',
                'cache_read_input_cost_per_million',
                'cache_write_input_cost_per_million',
                'reasoning_cost_per_million',
                'fixed_request_cost_applicable',
                'fixed_request_cost_minor_units',
                'unsupported_meters',
            ], array_keys($data)) !== []) {
                $existingPricing = AiPricingSnapshot::fromArray($pricing);
                $pricing = (new AiPricingSnapshot(
                    currency: $existingPricing->currency,
                    inputCostPerMillionMinorUnits: array_key_exists('input_cost_per_million', $data)
                        ? AiMoney::canonicalMinorUnits($data['input_cost_per_million'], 'input_cost_per_million')
                        : $existingPricing->inputCostPerMillionMinorUnits,
                    outputCostPerMillionMinorUnits: array_key_exists('output_cost_per_million', $data)
                        ? AiMoney::canonicalMinorUnits($data['output_cost_per_million'], 'output_cost_per_million')
                        : $existingPricing->outputCostPerMillionMinorUnits,
                    cacheReadInputCostPerMillionMinorUnits: array_key_exists('cache_read_input_cost_per_million', $data)
                        ? self::optionalCost($data, 'cache_read_input_cost_per_million')
                        : $existingPricing->cacheReadInputCostPerMillionMinorUnits,
                    cacheWriteInputCostPerMillionMinorUnits: array_key_exists('cache_write_input_cost_per_million', $data)
                        ? self::optionalCost($data, 'cache_write_input_cost_per_million')
                        : $existingPricing->cacheWriteInputCostPerMillionMinorUnits,
                    reasoningCostPerMillionMinorUnits: array_key_exists('reasoning_cost_per_million', $data)
                        ? self::optionalCost($data, 'reasoning_cost_per_million')
                        : $existingPricing->reasoningCostPerMillionMinorUnits,
                    fixedRequestCostApplicable: array_key_exists('fixed_request_cost_applicable', $data)
                        ? (bool) $data['fixed_request_cost_applicable']
                        : $existingPricing->fixedRequestCostApplicable,
                    fixedRequestCostMinorUnits: array_key_exists('fixed_request_cost_minor_units', $data)
                        ? self::optionalCost($data, 'fixed_request_cost_minor_units')
                        : $existingPricing->fixedRequestCostMinorUnits,
                    unsupportedMeters: array_key_exists('unsupported_meters', $data)
                        ? self::unsupportedMeters($data['unsupported_meters'])
                        : $existingPricing->unsupportedMeters,
                ))->toArray();
            }

            $pricingSnapshot = AiPricingSnapshot::fromArray($pricing);
            $pricingSnapshot->assertComplete();
            $pricing = $pricingSnapshot->toArray();

            $modelName = (string) ($data['model_name'] ?? $lockedConfig->model_name);
            $capabilities = array_values(array_map('strval', (array) ($data['capabilities'] ?? $lockedConfig->capabilities ?? [])));
            $releaseNumber = ((int) AiModelRelease::query()
                ->where('organization_id', $organization->getKey())
                ->where('model_config_id', $lockedConfig->getKey())
                ->max('release_number')) + 1;

            $release = AiModelRelease::create([
                'organization_id' => $organization->getKey(),
                'model_config_id' => $lockedConfig->getKey(),
                'release_number' => $releaseNumber,
                'status' => 'active',
                'provider_name' => $providerConfig->provider_name,
                'model_name' => $modelName,
                'capabilities' => $capabilities,
                'pricing_snapshot' => $pricing,
                'activated_at' => Carbon::now(),
                'activated_by_user_id' => $actor->getKey(),
            ]);

            AiModelRelease::query()
                ->where('organization_id', $organization->getKey())
                ->where('model_config_id', $lockedConfig->getKey())
                ->where('id', '!=', $release->getKey())
                ->update(['status' => 'retired']);

            $configUpdates = [
                'active_release_id' => $release->getKey(),
                'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : true,
                'lifecycle_status' => 'active',
            ];
            if (array_intersect($providerManagementKeys, array_keys($data)) !== []) {
                $configUpdates = [
                    ...$configUpdates,
                    'model_name' => $modelName,
                    'display_name' => (string) ($data['display_name'] ?? $lockedConfig->display_name),
                    'capabilities' => $capabilities,
                    'pricing_snapshot' => $pricing,
                    'failover_priority' => self::positiveInteger(
                        $data['failover_priority'] ?? $lockedConfig->failover_priority,
                        'failover_priority',
                    ),
                ];
            }
            $lockedConfig->update($configUpdates);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'ai.model_release.activated',
                targetType: AiModelRelease::class,
                targetId: (string) $release->getKey(),
                metadata: [
                    'model_name' => $release->model_name,
                    'release_number' => (string) $release->release_number,
                ],
            );

            return $release;
        });
    }

    /** @param array<string, mixed> $data */
    private static function optionalCost(array $data, string $key, ?int $default = null): ?int
    {
        return array_key_exists($key, $data)
            ? AiMoney::canonicalMinorUnits($data[$key], $key)
            : $default;
    }

    /** @return array<string, mixed> */
    private static function canonicalPricingSnapshot(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The pricing snapshot must be a canonical array.');
        }

        $snapshot = $value;
        foreach ([
            'input_cost_per_million_minor_units',
            'output_cost_per_million_minor_units',
            'cache_read_input_cost_per_million_minor_units',
            'cache_write_input_cost_per_million_minor_units',
            'reasoning_cost_per_million_minor_units',
        ] as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new InvalidArgumentException("The pricing snapshot is missing {$key}.");
            }

            $snapshot[$key] = AiMoney::canonicalMinorUnits($snapshot[$key], $key);
        }

        foreach (['fixed_request_cost_minor_units'] as $key) {
            if (array_key_exists($key, $snapshot) && $snapshot[$key] !== null) {
                $snapshot[$key] = AiMoney::canonicalMinorUnits($snapshot[$key], $key);
            }
        }

        return $snapshot;
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
