<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRuleConfiguration;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $this->authorization->assertRecipientStrategy($configuration->recipientStrategy);

        if (! NotificationTemplateVersion::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($configuration->templateVersionId)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn ($query) => $query
                ->where('organization_id', $organization->getKey())
                ->where('is_active', true)
                ->where('purpose', $configuration->purpose->value))
            ->exists()) {
            throw ValidationException::withMessages(['template_version_id' => 'Выбранный шаблон не соответствует назначению правила.']);
        }

        return DB::transaction(function () use ($actor, $configuration, $organization, $rule): ScenarioRule {
            $lockedRule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTemplateLocked($organization->getKey(), $configuration->templateVersionId, $configuration->purpose);

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
                    'max_occurrences' => $configuration->maxOccurrences,
                    'repeat_interval_value' => $configuration->repeatIntervalValue,
                    'repeat_interval_unit' => $configuration->repeatIntervalUnit?->value,
                    'recipient_type' => $configuration->recipientStrategy->type->value,
                    'recipient_count' => count($configuration->recipientStrategy->values),
                    'channel_count' => count($configuration->channelPriority->channels),
                ],
            );

            return $lockedRule->refresh();
        });
    }

    private function assertTemplateLocked(int $organizationId, int $templateVersionId, ScenarioRulePurpose $purpose): void
    {
        $version = NotificationTemplateVersion::query()
            ->where('organization_id', $organizationId)
            ->whereKey($templateVersionId)
            ->first();
        $template = $version === null
            ? null
            : NotificationTemplate::query()
                ->where('organization_id', $organizationId)
                ->whereKey($version->template_id)
                ->lockForUpdate()
                ->first();
        $lockedVersion = $version === null
            ? null
            : NotificationTemplateVersion::query()
                ->where('organization_id', $organizationId)
                ->whereKey($templateVersionId)
                ->lockForUpdate()
                ->first();

        if ($lockedVersion === null
            || $template === null
            || $lockedVersion->status !== NotificationTemplateStatus::Published
            || ! $template->is_active
            || $template->purpose !== $purpose->value) {
            throw ValidationException::withMessages(['template_version_id' => 'Выбранный шаблон не соответствует назначению правила.']);
        }
    }
}
