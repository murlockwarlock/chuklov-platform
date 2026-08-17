<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;

final class UpdateAiSafetyControl
{
    /** @var list<string> */
    private const WRITABLE_KEYS = [
        'is_ai_globally_enabled',
        'disabled_capabilities',
        'disabled_providers',
        'disabled_tools',
        'max_tokens_per_run',
        'max_daily_spend_minor_units',
        'max_runs_per_minute',
        'max_tool_calls_per_run',
        'default_timeout_seconds',
        'max_failover_attempts',
    ];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $values */
    public function handle(User $actor, array $values): AiOrganizationSafetyControl
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageAiProviders);

        $control = AiOrganizationSafetyControl::query()
            ->where('organization_id', $organization->getKey())
            ->first();
        $defaults = new AiOrganizationSafetyControl;
        $current = $control ?? $defaults;
        $attributes = ['organization_id' => $organization->getKey()];

        foreach (self::WRITABLE_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $attributes[$key] = $values[$key];
            } elseif ($control !== null) {
                $attributes[$key] = $current->{$key};
            }
        }

        AiRuntimeLimits::validateOrganizationValues($attributes);

        if ($control === null) {
            $control = AiOrganizationSafetyControl::create($attributes);
        } else {
            $control->update($attributes);
        }

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'ai.safety_control.updated',
            targetType: AiOrganizationSafetyControl::class,
            targetId: (string) $control->getKey(),
            metadata: [
                'is_ai_globally_enabled' => (bool) $control->is_ai_globally_enabled,
                'limits_updated' => count(array_intersect(array_keys($values), array_diff(self::WRITABLE_KEYS, ['is_ai_globally_enabled']))) > 0,
            ],
        );

        return $control->refresh();
    }
}
