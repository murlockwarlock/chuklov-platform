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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $this->authorization->assertRecipientStrategy($configuration->recipientStrategy);
        $this->assertTemplate($organization->getKey(), $configuration->templateVersionId, $configuration->purpose);

        return DB::transaction(function () use ($actor, $configuration, $organization): ScenarioRule {
            $this->assertTemplateLocked($organization->getKey(), $configuration->templateVersionId, $configuration->purpose);
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
                    'max_occurrences' => $configuration->maxOccurrences,
                    'repeat_interval_value' => $configuration->repeatIntervalValue,
                    'repeat_interval_unit' => $configuration->repeatIntervalUnit?->value,
                    'recipient_type' => $configuration->recipientStrategy->type->value,
                    'recipient_count' => count($configuration->recipientStrategy->values),
                    'channel_count' => count($configuration->channelPriority->channels),
                ],
            );

            return $rule->refresh();
        });
    }

    private function assertTemplate(int $organizationId, int $templateVersionId, ScenarioRulePurpose $purpose): void
    {
        if (! NotificationTemplateVersion::query()
            ->where('organization_id', $organizationId)
            ->whereKey($templateVersionId)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->where('purpose', $purpose->value))
            ->exists()) {
            throw ValidationException::withMessages(['template_version_id' => 'Выбранный шаблон не соответствует назначению правила.']);
        }
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
