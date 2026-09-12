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
                    subject: 'Запрос специалиста',
                ),
                'referral-payout-request' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'referral-payout-request',
                    name: 'Запрос выплаты партнёра',
                    body: 'Партнёр {{ client.full_name }} запросил выплату {{ payout.amount }}.',
                    variables: ['client.full_name', 'payout.amount'],
                    subject: 'Запрос выплаты',
                ),
                'referral-payout-status' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'referral-payout-status',
                    name: 'Статус выплаты партнёра',
                    body: 'Статус вашей выплаты {{ payout.amount }}: {{ payout.status_label }}.',
                    variables: ['payout.amount', 'payout.status_label'],
                    subject: 'Статус выплаты',
                ),
                'referral-payout-status-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'referral-payout-status-crm',
                    name: 'Изменение статуса выплаты партнёра',
                    body: 'У партнёра {{ client.full_name }} изменился статус выплаты: {{ payout.status_label }}.',
                    variables: ['client.full_name', 'payout.status_label'],
                    subject: 'Статус выплаты изменён',
                ),
                'tracker-task-daily' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'tracker-task-daily',
                    name: 'Ежедневная задача трекера',
                    body: 'Напоминание о задаче: {{ tracker.task_title }}.',
                    variables: ['tracker.task_title'],
                    subject: 'Задача трекера',
                ),
                'tracker-task-weekly' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'tracker-task-weekly',
                    name: 'Еженедельная задача трекера',
                    body: 'Напоминание о задаче: {{ tracker.task_title }}.',
                    variables: ['tracker.task_title'],
                    subject: 'Задача трекера',
                ),
                'booking-created-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-created-crm',
                    name: 'Новая запись в CRM',
                    body: 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Новая запись',
                ),
                'booking-home-visit-review-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-home-visit-review-crm',
                    name: 'Заявка на выезд',
                    body: 'Новая заявка на выезд к клиенту {{ client.full_name }}: {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Новая заявка на выезд',
                ),
                'booking-confirmed-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-confirmed-crm',
                    name: 'Запись подтверждена в CRM',
                    body: 'Запись подтверждена: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Запись подтверждена',
                ),
                'booking-rescheduled-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-rescheduled-crm',
                    name: 'Запись перенесена в CRM',
                    body: 'Запись перенесена: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Запись перенесена',
                ),
                'booking-cancelled-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-cancelled-crm',
                    name: 'Запись отменена в CRM',
                    body: 'Запись отменена: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Запись отменена',
                ),
                'companion-fallback-failed' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'companion-fallback-failed',
                    name: 'Сбой передачи обращения специалисту',
                    body: 'AI-компаньон не смог продолжить разговор с клиентом {{ client.full_name }}. Проверьте обращение.',
                    variables: ['client.full_name'],
                    subject: 'Нужна проверка обращения',
                ),
                'survey-completed-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'survey-completed-crm',
                    name: 'Тест клиента завершён',
                    body: 'Клиент {{ client.full_name }} завершил тест «{{ survey.title }}». Результат готов к просмотру.',
                    variables: ['client.full_name', 'survey.title'],
                    subject: 'Тест завершён',
                ),
                'survey-stagnation-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'survey-stagnation-crm',
                    name: 'Показатели клиента не снижаются',
                    body: 'У клиента {{ client.full_name }} показатели не улучшились по итогам повторного теста. Проверьте результат.',
                    variables: ['client.full_name'],
                    subject: 'Нужна проверка повторного теста',
                ),
                'b2b-lead-submitted-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'b2b-lead-submitted-crm',
                    name: 'Новый B2B-запрос',
                    body: 'Поступил новый B2B-запрос от клиента {{ client.full_name }}.',
                    variables: ['client.full_name'],
                    subject: 'Новый B2B-запрос',
                ),
                'b2b-sales-call-ready-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'b2b-sales-call-ready-crm',
                    name: 'B2B-разговор готов',
                    body: 'B2B-разговор с клиентом {{ client.full_name }} готов: {{ sales_call.local_date }} в {{ sales_call.local_time }}.',
                    variables: ['client.full_name', 'sales_call.local_date', 'sales_call.local_time'],
                    subject: 'B2B-разговор готов',
                ),
                'knowledge-ingestion-failed-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'knowledge-ingestion-failed-crm',
                    name: 'Ошибка обработки материала базы знаний',
                    body: 'Не удалось обработать материал «{{ knowledge.source_title }}», версия {{ knowledge.revision_version }}. Откройте материал и повторите обработку.',
                    variables: ['knowledge.source_title', 'knowledge.revision_version'],
                    subject: 'Ошибка обработки материала',
                ),
            ];

            foreach ([
                [
                    'key' => 'companion-handoff-database',
                    'name' => 'Запрос специалиста — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'companion-handoff',
                    'event' => ScenarioEventType::CompanionRequestedSpecialist->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'manage_companion_handoff'],
                ],
                [
                    'key' => 'companion-handoff-telegram',
                    'name' => 'Запрос специалиста — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'companion-handoff',
                    'event' => ScenarioEventType::CompanionRequestedSpecialist->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'manage_companion_handoff'],
                ],
                [
                    'key' => 'referral-payout-request-database',
                    'name' => 'Запрос выплаты — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'referral-payout-request',
                    'event' => ScenarioEventType::PayoutRequested->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'referral-payout-status-database',
                    'name' => 'Изменение статуса выплаты — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'referral-payout-status-crm',
                    'event' => ScenarioEventType::PayoutStatusChanged->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'booking-created-specialist-database',
                    'name' => 'Новая запись — уведомление специалисту в CRM',
                    'channel' => 'database',
                    'template' => 'booking-created-crm',
                    'event' => ScenarioEventType::BookingCreated->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'assigned_specialist'],
                    'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'requested']],
                ],
                [
                    'key' => 'booking-home-visit-review-database',
                    'name' => 'Заявка на выезд — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'booking-home-visit-review-crm',
                    'event' => ScenarioEventType::BookingCreated->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'manage_scheduling'],
                    'conditions' => [['type' => 'booking.status', 'operator' => 'equals', 'value' => 'pending_review']],
                ],
                [
                    'key' => 'booking-confirmed-specialist-database',
                    'name' => 'Подтверждение записи — уведомление специалисту в CRM',
                    'channel' => 'database',
                    'template' => 'booking-confirmed-crm',
                    'event' => ScenarioEventType::BookingConfirmed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'assigned_specialist'],
                ],
                [
                    'key' => 'booking-rescheduled-specialist-database',
                    'name' => 'Перенос записи — уведомление специалисту в CRM',
                    'channel' => 'database',
                    'template' => 'booking-rescheduled-crm',
                    'event' => ScenarioEventType::BookingRescheduled->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'assigned_specialist'],
                ],
                [
                    'key' => 'booking-cancelled-specialist-database',
                    'name' => 'Отмена записи — уведомление специалисту в CRM',
                    'channel' => 'database',
                    'template' => 'booking-cancelled-crm',
                    'event' => ScenarioEventType::BookingCancelled->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'assigned_specialist'],
                ],
                [
                    'key' => 'companion-fallback-failed-database',
                    'name' => 'Сбой AI-компаньона — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'companion-fallback-failed',
                    'event' => ScenarioEventType::CompanionFallbackFailed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'manage_companion_handoff'],
                ],
                [
                    'key' => 'survey-completed-database',
                    'name' => 'Завершённый тест — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'survey-completed-crm',
                    'event' => ScenarioEventType::SurveyCompleted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_surveys'],
                ],
                [
                    'key' => 'survey-stagnation-database',
                    'name' => 'Повторный тест без улучшения — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'survey-stagnation-crm',
                    'event' => ScenarioEventType::TestStagnationDetected->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_surveys'],
                ],
                [
                    'key' => 'b2b-lead-submitted-database',
                    'name' => 'Новый B2B-запрос — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'b2b-lead-submitted-crm',
                    'event' => ScenarioEventType::B2bLeadSubmitted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_b2b_leads'],
                ],
                [
                    'key' => 'b2b-sales-call-ready-database',
                    'name' => 'Готовый B2B-разговор — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'b2b-sales-call-ready-crm',
                    'event' => ScenarioEventType::B2bSalesCallReady->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_b2b_leads'],
                ],
                [
                    'key' => 'knowledge-ingestion-failed-database',
                    'name' => 'Ошибка обработки материала — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'knowledge-ingestion-failed-crm',
                    'event' => ScenarioEventType::KnowledgeIngestionFailed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_knowledge'],
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
                [
                    'key' => 'tracker-task-daily-client-telegram',
                    'name' => 'Ежедневная задача трекера — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'tracker-task-daily',
                    'roles' => [],
                    'event' => ScenarioEventType::TrackerDailyTaskAssigned->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [
                        ['type' => 'tracker.access', 'operator' => 'equals', 'value' => true],
                        ['type' => 'tracker.task_active', 'operator' => 'equals', 'value' => true],
                    ],
                    'max_occurrences' => 100,
                    'repeat_interval_value' => 1,
                    'repeat_interval_unit' => 'days',
                ],
                [
                    'key' => 'tracker-task-weekly-client-telegram',
                    'name' => 'Еженедельная задача трекера — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'tracker-task-weekly',
                    'roles' => [],
                    'event' => ScenarioEventType::TrackerWeeklyTaskAssigned->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [
                        ['type' => 'tracker.access', 'operator' => 'equals', 'value' => true],
                        ['type' => 'tracker.task_active', 'operator' => 'equals', 'value' => true],
                    ],
                    'max_occurrences' => 53,
                    'repeat_interval_value' => 7,
                    'repeat_interval_unit' => 'days',
                ],
            ] as $definition) {
                $existingRule = ScenarioRule::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('rule_key', $definition['key'])
                    ->first();

                if ($existingRule instanceof ScenarioRule) {
                    if (isset($definition['recipient']['permission'])) {
                        $recipientStrategy = is_array($existingRule->recipient_strategy)
                            ? $existingRule->recipient_strategy
                            : [];
                        $recipientStrategy['permission'] = $definition['recipient']['permission'];
                        $existingRule->forceFill([
                            'recipient_strategy' => $recipientStrategy,
                        ])->save();
                    }

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
                    'conditions' => $definition['conditions'] ?? [],
                    'recipient_strategy' => $definition['recipient'] ?? ['type' => 'roles', 'roles' => $definition['roles']],
                    'channel_priority' => [$definition['channel']],
                    'template_version_id' => $templates[$definition['template']]->getKey(),
                    'max_occurrences' => $definition['max_occurrences'] ?? 1,
                    'repeat_interval_value' => $definition['repeat_interval_value'] ?? null,
                    'repeat_interval_unit' => $definition['repeat_interval_unit'] ?? null,
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
        ?string $subject = null,
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
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
            'published_at' => now(),
        ])->save();

        return $version;
    }
}
