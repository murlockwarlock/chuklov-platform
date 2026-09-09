<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Support\Facades\DB;

final class EnsureOperationalNotificationDefaults
{
    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            $organization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $templates = [
                'companion-handoff' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'companion-handoff',
                    name: 'Запрос специалиста из AI-компаньона',
                    body: 'Клиент {{ client.full_name }} запросил специалиста в AI-компаньоне.',
                    variables: ['client.full_name'],
                ),
                'referral-payout-request' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'referral-payout-request',
                    name: 'Запрос выплаты партнёра',
                    body: 'Партнёр {{ client.full_name }} запросил выплату {{ payout.amount }}.',
                    variables: ['client.full_name', 'payout.amount'],
                ),
                'referral-payout-status' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'referral-payout-status',
                    name: 'Статус выплаты партнёра',
                    body: 'Статус вашей выплаты {{ payout.amount }}: {{ payout.status_label }}.',
                    variables: ['payout.amount', 'payout.status_label'],
                ),
            ];

            foreach ([
                [
                    'key' => 'companion-handoff-database',
                    'name' => 'Запрос специалиста — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'companion-handoff',
                    'roles' => ['owner', 'administrator', 'staff'],
                    'event' => ScenarioEventType::CompanionRequestedSpecialist->value,
                    'enabled' => true,
                ],
                [
                    'key' => 'companion-handoff-telegram',
                    'name' => 'Запрос специалиста — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'companion-handoff',
                    'roles' => ['owner', 'administrator', 'staff'],
                    'event' => ScenarioEventType::CompanionRequestedSpecialist->value,
                    'enabled' => true,
                ],
                [
                    'key' => 'referral-payout-request-database',
                    'name' => 'Запрос выплаты — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'referral-payout-request',
                    'roles' => ['owner', 'administrator', 'staff'],
                    'event' => ScenarioEventType::PayoutRequested->value,
                    'enabled' => true,
                ],
                [
                    'key' => 'referral-payout-status-client-telegram',
                    'name' => 'Статус выплаты — Telegram партнёра',
                    'channel' => 'telegram',
                    'template' => 'referral-payout-status',
                    'roles' => [],
                    'event' => ScenarioEventType::PayoutStatusChanged->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                ],
            ] as $definition) {
                if (ScenarioRule::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('rule_key', $definition['key'])
                    ->exists()) {
                    continue;
                }

                $rule = new ScenarioRule;
                $rule->forceFill([
                    'organization_id' => $organization->getKey(),
                    'rule_key' => $definition['key'],
                    'name' => $definition['name'],
                    'trigger_event' => $definition['event'],
                    'is_enabled' => $definition['enabled'],
                    'system_managed' => false,
                    'delay_value' => 0,
                    'delay_unit' => 'minutes',
                    'purpose' => ScenarioRulePurpose::Transactional->value,
                    'conditions' => [],
                    'recipient_strategy' => $definition['recipient'] ?? ['type' => 'roles', 'roles' => $definition['roles']],
                    'channel_priority' => [$definition['channel']],
                    'template_version_id' => $templates[$definition['template']]->getKey(),
                    'max_occurrences' => 1,
                    'repeat_interval_value' => null,
                    'repeat_interval_unit' => null,
                    'version' => 1,
                ])->save();
            }
        });
    }

    /** @param list<string> $variables */
    private function ensureTemplate(
        Organization $organization,
        string $key,
        string $name,
        string $body,
        array $variables,
    ): NotificationTemplateVersion {
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', $key)
            ->where('locale', 'ru')
            ->first();

        if ($template === null) {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => $key,
                'name' => $name,
                'locale' => 'ru',
                'purpose' => ScenarioRulePurpose::Transactional->value,
                'is_active' => true,
            ])->save();
        }

        $version = $template->versions()->orderByDesc('version')->first();
        if ($version instanceof NotificationTemplateVersion) {
            return $version;
        }

        $version = new NotificationTemplateVersion;
        $version->forceFill([
            'organization_id' => $organization->getKey(),
            'template_id' => $template->getKey(),
            'version' => 1,
            'status' => NotificationTemplateStatus::Published->value,
            'body' => $body,
            'variables' => $variables,
            'published_at' => now(),
        ])->save();

        return $version;
    }
}
