<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Feedback\Domain\Enums\NpsBand;
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
                    name: 'Новая запись от клиента',
                    body: 'Клиент {{ client.full_name }} отправил заявку на запись: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку и подтвердите запись.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Новая запись от клиента',
                ),
                'booking-home-visit-review-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-home-visit-review-crm',
                    name: 'Заявка клиента на выезд',
                    body: 'Клиент {{ client.full_name }} запросил выезд: {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку.',
                    variables: ['client.full_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Заявка клиента на выезд',
                ),
                'booking-confirmed-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-confirmed-crm',
                    name: 'Запись клиента подтверждена',
                    body: 'Запись клиента {{ client.full_name }} подтверждена: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Запись клиента подтверждена',
                ),
                'booking-rescheduled-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-rescheduled-crm',
                    name: 'Запись клиента перенесена',
                    body: 'Запись клиента {{ client.full_name }} перенесена: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Запись клиента перенесена',
                ),
                'booking-cancelled-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'booking-cancelled-crm',
                    name: 'Запись клиента отменена',
                    body: 'Запись клиента {{ client.full_name }} отменена: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                    variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
                    subject: 'Запись клиента отменена',
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
                'survey-stagnation-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'survey-stagnation-client',
                    name: 'Повторный тест без заметного улучшения',
                    body: 'Похоже, по повторному замеру заметного улучшения пока нет. Расскажите, получилось ли выполнять рекомендации и что изменилось в самочувствии.',
                    variables: [],
                    subject: 'Повторный замер',
                ),
                'survey-progress-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'survey-progress-client',
                    name: 'Динамика повторного теста',
                    body: '{{ survey.progress_summary }}',
                    variables: ['survey.progress_summary'],
                    subject: 'Динамика повторного теста',
                ),
                'feedback-low-score-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'feedback-low-score-crm',
                    name: 'Низкая оценка клиента',
                    body: 'Клиент {{ client.full_name }} оставил оценку {{ feedback.score }}/10. Внутренний комментарий: {{ feedback.has_internal_feedback }}. Откройте обратную связь и свяжитесь с клиентом.',
                    variables: ['client.full_name', 'feedback.score', 'feedback.has_internal_feedback'],
                    subject: 'Низкая оценка клиента',
                ),
                'b2b-lead-submitted-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'b2b-lead-'.'submitted-crm',
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
                'finance-payment-succeeded' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'finance-payment-succeeded',
                    name: 'Оплата получена',
                    body: '{{ payment.message }} Сумма: {{ payment.amount }} за «{{ payment.product_name }}».',
                    variables: ['payment.message', 'payment.amount', 'payment.product_name'],
                    subject: 'Оплата получена',
                ),
                'finance-payment-succeeded-survey' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'finance-payment-succeeded-survey',
                    name: 'Диагностический тест после оплаты',
                    body: 'Оплата получена. Доступен диагностический тест, чтобы зафиксировать исходное состояние.',
                    variables: [],
                    subject: 'Доступен диагностический тест',
                ),
                'finance-debt-reminder-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'finance-debt-reminder-client',
                    name: 'Напоминание об оплате',
                    body: 'Напоминаем о задолженности: {{ finance.outstanding_amount_display }} {{ finance.currency }}. Если вы уже оплатили, сообщите нам — мы проверим платёж.',
                    variables: ['finance.outstanding_amount_display', 'finance.currency'],
                    subject: 'Напоминание об оплате',
                ),
                'retention-follow-up-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'retention-follow-up-client',
                    name: 'Следующая запись',
                    body: 'После завершённого визита у вас пока нет следующей записи. Если хотите продолжить, выберите удобное время в портале или напишите нам.',
                    variables: [],
                    subject: 'Следующая запись',
                ),
                'finance-payment-failed' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'finance-payment-failed',
                    name: 'Оплата не прошла',
                    body: 'Оплату завершить не удалось. Попробуйте ещё раз. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
                    variables: [],
                    subject: 'Оплата не прошла',
                ),
                'finance-payment-initiation-unavailable-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'finance-payment-initiation-unavailable-crm',
                    name: 'Проверка настроек онлайн-оплаты',
                    body: 'Онлайн-оплата недоступна. Товар или услуга: «{{ payment.product_name }}». Причина: {{ payment.reason }}',
                    variables: ['payment.product_name', 'payment.reason'],
                    subject: 'Онлайн-оплата недоступна',
                ),
                'finance-payment-reconciliation-required-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'finance-payment-reconciliation-required-crm',
                    name: 'Платёж требует проверки',
                    body: 'Платёж требует сверки. Клиент: {{ payment.client_label }}. Товар или услуга: «{{ payment.product_name }}». Сумма: {{ payment.amount }}. Причина: {{ payment.reason }}',
                    variables: ['payment.client_label', 'payment.product_name', 'payment.amount', 'payment.reason'],
                    subject: 'Платёж требует проверки',
                ),
                'commerce-fulfillment-failed-crm' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'commerce-fulfillment-failed-crm',
                    name: 'Оплата получена, доступ не выдан',
                    body: 'Оплата получена, но доступ клиенту не выдан. Клиент: {{ client.full_name }}. Продукт: «{{ fulfillment.product_name }}». Причина: {{ fulfillment.reason }}',
                    variables: ['client.full_name', 'fulfillment.product_name', 'fulfillment.reason'],
                    subject: 'Оплата получена, доступ не выдан',
                ),
                'commerce-fulfillment-failed-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'commerce-fulfillment-failed-client',
                    name: 'Доступ готовится',
                    body: '{{ fulfillment.message }}',
                    variables: ['fulfillment.message'],
                    subject: 'Доступ готовится',
                ),
                'commerce-fulfillment-completed-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'commerce-fulfillment-completed-client',
                    name: 'Доступ готов',
                    body: '{{ fulfillment.message }}',
                    variables: ['fulfillment.message'],
                    subject: 'Доступ готов',
                ),
                'referral-reward-earned-client' => $this->ensureTemplate(
                    organization: $organization,
                    key: 'referral-reward-earned-client',
                    name: 'Начисление по партнёрской программе',
                    body: 'Вам начислено {{ reward.amount }} по партнёрской программе.',
                    variables: ['reward.amount'],
                    subject: 'Начисление по партнёрской программе',
                ),
            ];

            foreach ($this->englishClientTemplateDefinitions() as $definition) {
                $this->ensureTemplate(
                    organization: $organization,
                    key: $definition['key'],
                    name: $definition['name'],
                    body: $definition['body'],
                    variables: $definition['variables'],
                    subject: $definition['subject'],
                    locale: 'en',
                );
            }

            $templates['finance-debt-reminder-client'] = $this->upgradeDefaultDebtTemplate(
                $templates['finance-debt-reminder-client'],
                'Напоминаем о задолженности: {{ finance.outstanding_amount }} {{ finance.currency }}. Если вы уже оплатили, сообщите нам — мы проверим платёж.',
                'Напоминаем о задолженности: {{ finance.outstanding_amount_display }} {{ finance.currency }}. Если вы уже оплатили, сообщите нам — мы проверим платёж.',
                ['finance.outstanding_amount_display', 'finance.currency'],
                'Напоминание об оплате',
            );
            $englishDebtTemplate = NotificationTemplate::query()
                ->where('organization_id', $organization->getKey())
                ->where('template_key', 'finance-debt-reminder-client')
                ->where('locale', 'en')
                ->with('latestVersion')
                ->first()?->latestVersion;
            if ($englishDebtTemplate instanceof NotificationTemplateVersion) {
                $this->upgradeDefaultDebtTemplate(
                    $englishDebtTemplate,
                    'A reminder about your outstanding balance: {{ finance.outstanding_amount }} {{ finance.currency }}. If you already paid, tell us and we will check the payment.',
                    'A reminder about your outstanding balance: {{ finance.outstanding_amount_display }} {{ finance.currency }}. If you already paid, tell us and we will check the payment.',
                    ['finance.outstanding_amount_display', 'finance.currency'],
                    'Payment reminder',
                );
            }

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
                    'key' => 'survey-completed-telegram',
                    'name' => 'Завершённый тест — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'survey-completed-crm',
                    'event' => ScenarioEventType::SurveyCompleted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_surveys'],
                ],
                [
                    'key' => 'survey-stagnation-client-telegram-ru',
                    'name' => 'Повторный тест без улучшения — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'survey-stagnation-client',
                    'event' => ScenarioEventType::TestStagnationDetected->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => 'ru']],
                ],
                [
                    'key' => 'survey-stagnation-client-telegram-en',
                    'name' => 'Repeat check without improvement — client Telegram',
                    'channel' => 'telegram',
                    'template' => 'survey-stagnation-client',
                    'event' => ScenarioEventType::TestStagnationDetected->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => 'en']],
                ],
                [
                    'key' => 'survey-progress-client-telegram-ru',
                    'name' => 'Динамика повторного теста — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'survey-progress-client',
                    'event' => ScenarioEventType::SurveyCompleted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [
                        ['type' => 'client.language', 'operator' => 'equals', 'value' => 'ru'],
                        ['type' => 'survey.progress_available', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                [
                    'key' => 'survey-progress-client-telegram-en',
                    'name' => 'Repeat check progress — client Telegram',
                    'channel' => 'telegram',
                    'template' => 'survey-progress-client',
                    'event' => ScenarioEventType::SurveyCompleted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [
                        ['type' => 'client.language', 'operator' => 'equals', 'value' => 'en'],
                        ['type' => 'survey.progress_available', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                [
                    'key' => 'feedback-low-score-database',
                    'name' => 'Низкая оценка — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'feedback-low-score-crm',
                    'event' => ScenarioEventType::ClientFeedbackSubmitted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_clients'],
                    'conditions' => [['type' => 'feedback.band', 'operator' => 'equals', 'value' => NpsBand::Internal->value]],
                ],
                [
                    'key' => 'feedback-low-score-telegram',
                    'name' => 'Низкая оценка — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'feedback-low-score-crm',
                    'event' => ScenarioEventType::ClientFeedbackSubmitted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_clients'],
                    'conditions' => [['type' => 'feedback.band', 'operator' => 'equals', 'value' => NpsBand::Internal->value]],
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
                [
                    'key' => 'finance-payment-succeeded-client-telegram',
                    'name' => 'Подтверждение оплаты — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'finance-payment-succeeded',
                    'event' => ScenarioEventType::PaymentSucceeded->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                ],
                [
                    'key' => 'finance-payment-succeeded-survey-client-telegram',
                    'name' => 'После оплаты — диагностический тест в Telegram',
                    'channel' => 'telegram',
                    'template' => 'finance-payment-succeeded-survey',
                    'event' => ScenarioEventType::PaymentSucceeded->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [
                        ['type' => 'payment.is_pre_visit_booking_payment', 'operator' => 'equals', 'value' => true],
                        ['type' => 'survey.available', 'operator' => 'equals', 'value' => true],
                    ],
                ],
                [
                    'key' => 'finance-debt-reminder-client-telegram',
                    'name' => 'Напоминание о задолженности — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'finance-debt-reminder-client',
                    'event' => ScenarioEventType::FinancialDebtReminderRequested->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [['type' => 'finance.has_outstanding_debt', 'operator' => 'equals', 'value' => true]],
                ],
                [
                    'key' => 'retention-follow-up-client-telegram',
                    'name' => 'Нет следующей записи — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'retention-follow-up-client',
                    'event' => ScenarioEventType::BookingCompleted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                    'conditions' => [
                        ['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed'],
                        ['type' => 'booking.has_qualifying_next_booking', 'operator' => 'equals', 'value' => false],
                    ],
                    'delay_value' => config('scenarios.retention_default_delay_days'),
                    'delay_unit' => 'days',
                ],
                [
                    'key' => 'finance-payment-failed-client-telegram',
                    'name' => 'Неуспешная оплата — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'finance-payment-failed',
                    'event' => ScenarioEventType::PaymentFailed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                ],
                [
                    'key' => 'finance-payment-initiation-unavailable-database',
                    'name' => 'Недоступная онлайн-оплата — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'finance-payment-initiation-unavailable-crm',
                    'event' => ScenarioEventType::PaymentInitiationUnavailable->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'finance-payment-initiation-unavailable-telegram',
                    'name' => 'Недоступная онлайн-оплата — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'finance-payment-initiation-unavailable-crm',
                    'event' => ScenarioEventType::PaymentInitiationUnavailable->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'finance-payment-reconciliation-database',
                    'name' => 'Платёж требует сверки — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'finance-payment-reconciliation-required-crm',
                    'event' => ScenarioEventType::PaymentReconciliationRequired->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'finance-payment-reconciliation-telegram',
                    'name' => 'Платёж требует сверки — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'finance-payment-reconciliation-required-crm',
                    'event' => ScenarioEventType::PaymentReconciliationRequired->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'commerce-fulfillment-failed-database',
                    'name' => 'Доступ не выдан — уведомление в CRM',
                    'channel' => 'database',
                    'template' => 'commerce-fulfillment-failed-crm',
                    'event' => ScenarioEventType::FulfillmentFailed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'commerce-fulfillment-failed-telegram',
                    'name' => 'Доступ не выдан — Telegram сотрудников',
                    'channel' => 'telegram',
                    'template' => 'commerce-fulfillment-failed-crm',
                    'event' => ScenarioEventType::FulfillmentFailed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'roles', 'roles' => ['owner', 'administrator', 'staff'], 'permission' => 'view_finance'],
                ],
                [
                    'key' => 'commerce-fulfillment-failed-client-telegram',
                    'name' => 'Доступ готовится — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'commerce-fulfillment-failed-client',
                    'event' => ScenarioEventType::FulfillmentFailed->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                ],
                [
                    'key' => 'commerce-fulfillment-completed-client-telegram',
                    'name' => 'Доступ выдан — Telegram клиента',
                    'channel' => 'telegram',
                    'template' => 'commerce-fulfillment-completed-client',
                    'event' => ScenarioEventType::FulfillmentCompleted->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                ],
                [
                    'key' => 'referral-reward-earned-client-telegram',
                    'name' => 'Начисление по партнёрской программе — Telegram партнёра',
                    'channel' => 'telegram',
                    'template' => 'referral-reward-earned-client',
                    'event' => ScenarioEventType::ReferralRewardEarned->value,
                    'enabled' => true,
                    'recipient' => ['type' => 'client'],
                ],
            ] as $definition) {
                $existingRule = ScenarioRule::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('rule_key', $definition['key'])
                    ->first();

                if ($existingRule instanceof ScenarioRule) {
                    $definitionEvent = ScenarioEventType::tryFrom((string) $definition['event']);

                    $this->upgradeUntouchedDefaultRule($existingRule, $templates);

                    if (isset($definition['recipient']['permission'])
                        && $definitionEvent !== null
                        && $existingRule->trigger_event === $definitionEvent) {
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
                    'delay_value' => $definition['delay_value'] ?? 0,
                    'delay_unit' => $definition['delay_unit'] ?? 'minutes',
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

    /** @param array<string, NotificationTemplateVersion> $templates */
    private function upgradeUntouchedDefaultRule(ScenarioRule $rule, array $templates): void
    {
        if ($rule->created_by_user_id !== null || $rule->updated_by_user_id !== null) {
            return;
        }

        $conditions = $rule->conditions;
        $updatedConditions = null;
        if (in_array($rule->rule_key, ['feedback-low-score-database', 'feedback-low-score-telegram'], true)
            && $conditions === [['type' => 'feedback.score', 'operator' => 'in', 'value' => [1, 2, 3, 4, 5, 6, 7]]]) {
            $updatedConditions = [['type' => 'feedback.band', 'operator' => 'equals', 'value' => NpsBand::Internal->value]];
        }
        if ($rule->rule_key === 'finance-payment-succeeded-survey-client-telegram'
            && $conditions === [['type' => 'survey.available', 'operator' => 'equals', 'value' => true]]) {
            $updatedConditions = [
                ['type' => 'payment.is_pre_visit_booking_payment', 'operator' => 'equals', 'value' => true],
                ['type' => 'survey.available', 'operator' => 'equals', 'value' => true],
            ];
        }

        $attributes = [];
        if ($updatedConditions !== null) {
            $attributes['conditions'] = $updatedConditions;
        }
        if ($rule->rule_key === 'finance-debt-reminder-client-telegram'
            && $rule->template_version_id !== $templates['finance-debt-reminder-client']->getKey()) {
            $attributes['template_version_id'] = $templates['finance-debt-reminder-client']->getKey();
        }

        if ($attributes === []) {
            return;
        }

        $attributes['version'] = $rule->version + 1;
        $rule->forceFill($attributes)->save();
    }

    /** @param list<string> $variables */
    private function upgradeDefaultDebtTemplate(
        NotificationTemplateVersion $version,
        string $legacyBody,
        string $body,
        array $variables,
        string $subject,
    ): NotificationTemplateVersion {
        if ($version->body !== $legacyBody || $version->created_by_user_id !== null) {
            return $version;
        }

        $next = new NotificationTemplateVersion;
        $next->forceFill([
            'organization_id' => $version->organization_id,
            'template_id' => $version->template_id,
            'version' => $version->version + 1,
            'status' => NotificationTemplateStatus::Published->value,
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
            'published_at' => now(),
        ])->save();

        return $next;
    }

    /** @return list<array{key: string, name: string, body: string, variables: list<string>, subject: string}> */
    private function englishClientTemplateDefinitions(): array
    {
        return [
            [
                'key' => 'referral-payout-status',
                'name' => 'Referral payout status',
                'body' => 'Your payout of {{ payout.amount }} is {{ payout.status_label }}.',
                'variables' => ['payout.amount', 'payout.status_label'],
                'subject' => 'Referral payout status',
            ],
            [
                'key' => 'tracker-task-daily',
                'name' => 'Daily tracker task',
                'body' => 'Reminder: {{ tracker.task_title }} is scheduled for today.',
                'variables' => ['tracker.task_title'],
                'subject' => 'Daily tracker task',
            ],
            [
                'key' => 'tracker-task-weekly',
                'name' => 'Weekly tracker task',
                'body' => 'Reminder: {{ tracker.task_title }} is scheduled for this week.',
                'variables' => ['tracker.task_title'],
                'subject' => 'Weekly tracker task',
            ],
            [
                'key' => 'finance-payment-succeeded',
                'name' => 'Payment received',
                'body' => '{{ payment.message }} Amount: {{ payment.amount }} for "{{ payment.product_name }}".',
                'variables' => ['payment.message', 'payment.amount', 'payment.product_name'],
                'subject' => 'Payment received',
            ],
            [
                'key' => 'finance-payment-succeeded-survey',
                'name' => 'Diagnostic check after payment',
                'body' => 'Payment received. A diagnostic check is available to record your baseline state.',
                'variables' => [],
                'subject' => 'Diagnostic check available',
            ],
            [
                'key' => 'finance-debt-reminder-client',
                'name' => 'Payment reminder',
                'body' => 'A reminder about your outstanding balance: {{ finance.outstanding_amount_display }} {{ finance.currency }}. If you already paid, tell us and we will check the payment.',
                'variables' => ['finance.outstanding_amount_display', 'finance.currency'],
                'subject' => 'Payment reminder',
            ],
            [
                'key' => 'retention-follow-up-client',
                'name' => 'Next appointment',
                'body' => 'There is no next appointment after your completed visit. If you would like to continue, choose a convenient time in the portal or write to us.',
                'variables' => [],
                'subject' => 'Next appointment',
            ],
            [
                'key' => 'survey-stagnation-client',
                'name' => 'Repeat check without clear improvement',
                'body' => 'It looks like there is no clear improvement in the repeat check yet. Tell us whether you were able to follow the recommendations and what changed in how you feel.',
                'variables' => [],
                'subject' => 'Repeat check',
            ],
            [
                'key' => 'survey-progress-client',
                'name' => 'Repeat check progress',
                'body' => '{{ survey.progress_summary }}',
                'variables' => ['survey.progress_summary'],
                'subject' => 'Repeat check progress',
            ],
            [
                'key' => 'finance-payment-failed',
                'name' => 'Payment failed',
                'body' => "We couldn't complete the payment. Please try again. If the money has already been charged, don't pay again — we'll check the payment.",
                'variables' => [],
                'subject' => 'Payment failed',
            ],
            [
                'key' => 'commerce-fulfillment-failed-client',
                'name' => 'Access being prepared',
                'body' => '{{ fulfillment.message }}',
                'variables' => ['fulfillment.message'],
                'subject' => 'Access being prepared',
            ],
            [
                'key' => 'commerce-fulfillment-completed-client',
                'name' => 'Access ready',
                'body' => '{{ fulfillment.message }}',
                'variables' => ['fulfillment.message'],
                'subject' => 'Access ready',
            ],
            [
                'key' => 'referral-reward-earned-client',
                'name' => 'Referral reward earned',
                'body' => 'You received {{ reward.amount }} through the referral program.',
                'variables' => ['reward.amount'],
                'subject' => 'Referral reward earned',
            ],
        ];
    }

    /** @param list<string> $variables */
    private function ensureTemplate(
        Organization $organization,
        string $key,
        string $name,
        string $body,
        array $variables,
        ?string $subject = null,
        string $locale = 'ru',
    ): NotificationTemplateVersion {
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', $key)
            ->where('locale', $locale)
            ->first();

        if ($template === null) {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => $key,
                'name' => $name,
                'locale' => $locale,
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
