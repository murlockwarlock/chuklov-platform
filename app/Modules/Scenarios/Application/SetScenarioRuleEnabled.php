<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetScenarioRuleEnabled
{
    public function __construct(
        private readonly ScenarioAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, ScenarioRule $rule, bool $isEnabled): ScenarioRule
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($rule);

        return DB::transaction(function () use ($actor, $organization, $rule, $isEnabled): ScenarioRule {
            $locked = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($rule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($isEnabled) {
                $templateValid = NotificationTemplateVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($locked->template_version_id)
                    ->where('status', NotificationTemplateStatus::Published->value)
                    ->whereHas('template', fn ($query) => $query
                        ->where('organization_id', $organization->getKey())
                        ->where('is_active', true)
                        ->where('purpose', $locked->purpose->value))
                    ->exists();

                if (! $templateValid) {
                    throw ValidationException::withMessages([
                        'rule' => __('Сначала включите выбранный шаблон сообщения.'),
                    ]);
                }
            }

            if ((bool) $locked->is_enabled !== $isEnabled) {
                $locked->forceFill([
                    'is_enabled' => $isEnabled,
                    'version' => (int) $locked->version + 1,
                    'updated_by_user_id' => $actor->getKey(),
                ])->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'scenario.rule.updated',
                    targetType: ScenarioRule::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'rule_key' => $locked->rule_key,
                        'trigger_event' => $locked->trigger_event->value,
                        'delay_value' => $locked->delay_value,
                        'delay_unit' => $locked->delay_unit->value,
                        'enabled' => $isEnabled,
                    ],
                );
            }

            return $locked->refresh();
        });
    }
}
