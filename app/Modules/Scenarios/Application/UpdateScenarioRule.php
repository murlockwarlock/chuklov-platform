<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRuleConfiguration;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class UpdateScenarioRule
{
    public function __construct(
        private readonly ScenarioAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
        private readonly ConditionEvaluatorRegistry $conditions,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, ScenarioRule $rule, array $data): ScenarioRule
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($rule);
        $configuration = ScenarioRuleConfiguration::from($data);
        $this->conditions->validate($configuration->conditions);

        abort_unless(
            NotificationTemplateVersion::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($configuration->templateVersionId)
                ->where('status', NotificationTemplateStatus::Published->value)
                ->whereHas('template', fn ($query) => $query->where('is_active', true))
                ->exists(),
            422,
            'The selected notification template version is not available.',
        );

        return DB::transaction(function () use ($actor, $configuration, $organization, $rule): ScenarioRule {
            $lockedRule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRule->forceFill([
                ...$configuration->attributes(),
                'version' => $lockedRule->version + 1,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'scenario.rule.updated',
                targetType: ScenarioRule::class,
                targetId: (string) $lockedRule->getKey(),
                metadata: [
                    'rule_key' => $lockedRule->rule_key,
                    'trigger_event' => $configuration->triggerEvent->value,
                    'delay_value' => $configuration->delayValue,
                    'delay_unit' => $configuration->delayUnit->value,
                    'enabled' => $configuration->isEnabled,
                ],
            );

            return $lockedRule->refresh();
        });
    }
}
