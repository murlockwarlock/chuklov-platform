<?php

namespace App\Modules\Scenarios\Application;

final class ScenarioNotificationCatalog
{
    /** @return list<array{event: string, label: string, recipients: string, channels: list<string>, enabled: bool, template: string}> */
    public static function definitions(): array
    {
        return [
            ['event' => 'companion.requested_specialist', 'label' => 'Клиент запросил специалиста', 'recipients' => 'Сотрудники с правом обработки обращений', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Запрос специалиста из AI-компаньона'],
            ['event' => 'companion.fallback_failed', 'label' => 'AI не смог ответить после разрешённой попытки', 'recipients' => 'Сотрудники, которые обрабатывают обращения', 'channels' => ['CRM'], 'enabled' => true, 'template' => 'Сбой передачи обращения специалисту'],
            ['event' => 'broadcast.delivery_failed', 'label' => 'Сбой автоматического сообщения или рассылки', 'recipients' => 'Ответственные сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Сбой доставки'],
            ['event' => 'booking.created', 'label' => 'Новая запись', 'recipients' => 'Клиент и назначенный специалист', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Новая запись'],
            ['event' => 'booking.confirmed', 'label' => 'Подтверждение записи', 'recipients' => 'Клиент и специалист', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Подтверждение записи'],
            ['event' => 'booking.rescheduled', 'label' => 'Перенос записи', 'recipients' => 'Клиент и специалист', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Запись перенесена'],
            ['event' => 'booking.rejected', 'label' => 'Отклонение записи', 'recipients' => 'Клиент', 'channels' => ['Telegram'], 'enabled' => false, 'template' => 'Запись отклонена'],
            ['event' => 'booking.cancelled', 'label' => 'Отмена записи', 'recipients' => 'Клиент и специалист', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Запись отменена'],
            ['event' => 'booking.home_visit.changed', 'label' => 'Изменение выездного визита', 'recipients' => 'Клиент и ответственные сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Изменение выездного визита'],
            ['event' => 'booking.completed', 'label' => 'Завершение визита', 'recipients' => 'Клиент', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'После визита'],
            ['event' => 'feedback.submitted', 'label' => 'Обратная связь клиента', 'recipients' => 'Ответственные сотрудники', 'channels' => ['CRM'], 'enabled' => false, 'template' => 'Новая обратная связь'],
            ['event' => 'referral.payout.requested', 'label' => 'Запрос выплаты партнёра', 'recipients' => 'Финансовые сотрудники', 'channels' => ['CRM'], 'enabled' => true, 'template' => 'Запрос выплаты'],
            ['event' => 'referral.payout.status_changed', 'label' => 'Изменение статуса выплаты', 'recipients' => 'Финансовые сотрудники и партнёр', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Статус выплаты'],
            ['event' => 'survey.completed', 'label' => 'Тест клиента завершён', 'recipients' => 'Сотрудники, которые работают с тестами', 'channels' => ['CRM'], 'enabled' => true, 'template' => 'Тест клиента завершён'],
            ['event' => 'TEST_STAGNATION_DETECTED', 'label' => 'Показатели клиента не улучшились', 'recipients' => 'Сотрудники, которые работают с тестами', 'channels' => ['CRM'], 'enabled' => true, 'template' => 'Нужна проверка повторного теста'],
            ['event' => 'b2b.lead.submitted', 'label' => 'Новый B2B-запрос', 'recipients' => 'Сотрудники, которые работают с B2B-запросами', 'channels' => ['CRM'], 'enabled' => true, 'template' => 'Новый B2B-запрос'],
            ['event' => 'b2b.sales_call.ready', 'label' => 'B2B-разговор готов', 'recipients' => 'Клиент и сотрудники, которые работают с B2B-запросами', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'B2B-разговор готов'],
            ['event' => 'ai.evaluation.failed', 'label' => 'Сбой проверки AI', 'recipients' => 'Ответственные AI-сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Сбой проверки AI'],
            ['event' => 'knowledge.ingestion.failed', 'label' => 'Материал базы знаний не обработан', 'recipients' => 'Сотрудники с доступом к базе знаний', 'channels' => ['CRM'], 'enabled' => true, 'template' => 'Ошибка обработки материала'],
            ['event' => 'referral.link.visited', 'label' => 'Переход по реферальной ссылке', 'recipients' => 'Никому по умолчанию', 'channels' => [], 'enabled' => false, 'template' => 'Не отправлять по умолчанию'],
            ['event' => 'payment.provider.event.prepared', 'label' => 'Событие платёжного провайдера (подготовлено)', 'recipients' => 'Не настроено', 'channels' => [], 'enabled' => false, 'template' => 'Платёжные потоки не реализованы'],
            ['event' => 'tracker.task.daily_assigned', 'label' => 'Ежедневная задача трекера', 'recipients' => 'Клиент с доступом к трекеру', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'Ежедневная задача трекера'],
            ['event' => 'tracker.task.weekly_assigned', 'label' => 'Еженедельная задача трекера', 'recipients' => 'Клиент с доступом к трекеру', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'Еженедельная задача трекера'],
        ];
    }
}
