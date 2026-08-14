<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRuleConfiguration;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateScenarioRule
{
    public function __construct(
        private readonly ScenarioAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
        private readonly ConditionEvaluatorRegistry $conditions,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): ScenarioRule
    {
        $organization = $this->authorization->authorizeManage($actor);
        $data['rule_key'] ??= 'rule-'.Str::uuid()->toString();
        $configuration = ScenarioRuleConfiguration::from($data);
        $this->conditions->validate($configuration->conditions);
        $this->assertTemplate($organization->getKey(), $configuration->templateVersionId);

        return DB::transaction(function () use ($actor, $configuration, $organization): ScenarioRule {
            $rule = new ScenarioRule;
            $rule->forceFill([
                'organization_id' => $organization->getKey(),
                ...$configuration->attributes(),
                'version' => 1,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);
            $rule->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'scenario.rule.created',
                targetType: ScenarioRule::class,
                targetId: (string) $rule->getKey(),
                metadata: [
                    'rule_key' => $configuration->ruleKey,
                    'trigger_event' => $configuration->triggerEvent->value,
                    'delay_value' => $configuration->delayValue,
                    'delay_unit' => $configuration->delayUnit->value,
                    'enabled' => $configuration->isEnabled,
                ],
            );

            return $rule->refresh();
        });
    }

    private function assertTemplate(int $organizationId, int $templateVersionId): void
    {
        abort_unless(
            NotificationTemplateVersion::query()
                ->where('organization_id', $organizationId)
                ->whereKey($templateVersionId)
                ->where('status', NotificationTemplateStatus::Published->value)
                ->whereHas('template', fn ($query) => $query->where('is_active', true))
                ->exists(),
            422,
            'The selected notification template version is not available.',
        );
    }
}
