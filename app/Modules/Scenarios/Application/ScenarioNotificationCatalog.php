<?php

namespace App\Modules\Scenarios\Application;

final class ScenarioNotificationCatalog
{
    /** @return list<array{event: string, label: string, recipients: string, channels: list<string>, enabled: bool, template: string}> */
    public static function definitions(): array
    {
        return [
            ['event' => 'companion.requested_specialist', 'label' => 'Клиент запросил специалиста', 'recipients' => 'Активные сотрудники CRM с включёнными уведомлениями', 'channels' => ['CRM', 'Telegram'], 'enabled' => true, 'template' => 'Запрос специалиста из AI-компаньона'],
            ['event' => 'companion.fallback_failed', 'label' => 'AI не смог ответить после разрешённой попытки', 'recipients' => 'Ответственные сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Необходима помощь специалиста'],
            ['event' => 'broadcast.delivery_failed', 'label' => 'Сбой автоматического сообщения или рассылки', 'recipients' => 'Ответственные сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Сбой доставки'],
            ['event' => 'booking.created', 'label' => 'Новая запись', 'recipients' => 'Клиент и назначенный специалист', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'Новая запись'],
            ['event' => 'booking.confirmed', 'label' => 'Подтверждение записи', 'recipients' => 'Клиент и специалист', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'Подтверждение записи'],
            ['event' => 'booking.rescheduled', 'label' => 'Перенос записи', 'recipients' => 'Клиент и специалист', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'Запись перенесена'],
            ['event' => 'booking.rejected', 'label' => 'Отклонение записи', 'recipients' => 'Клиент', 'channels' => ['Telegram'], 'enabled' => false, 'template' => 'Запись отклонена'],
            ['event' => 'booking.cancelled', 'label' => 'Отмена записи', 'recipients' => 'Клиент и специалист', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'Запись отменена'],
            ['event' => 'booking.home_visit.changed', 'label' => 'Изменение выездного визита', 'recipients' => 'Клиент и ответственные сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Изменение выездного визита'],
            ['event' => 'booking.completed', 'label' => 'Завершение визита', 'recipients' => 'Клиент', 'channels' => ['Telegram'], 'enabled' => true, 'template' => 'После визита'],
            ['event' => 'feedback.submitted', 'label' => 'Обратная связь клиента', 'recipients' => 'Ответственные сотрудники', 'channels' => ['CRM'], 'enabled' => false, 'template' => 'Новая обратная связь'],
            ['event' => 'referral.payout.requested', 'label' => 'Запрос выплаты партнёра', 'recipients' => 'Финансовые сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Запрос выплаты'],
            ['event' => 'referral.payout.status_changed', 'label' => 'Изменение статуса выплаты', 'recipients' => 'Партнёр с проверенным каналом', 'channels' => ['Telegram'], 'enabled' => false, 'template' => 'Статус выплаты'],
            ['event' => 'ai.evaluation.failed', 'label' => 'Сбой проверки AI', 'recipients' => 'Ответственные AI-сотрудники', 'channels' => ['CRM', 'Telegram'], 'enabled' => false, 'template' => 'Сбой проверки AI'],
            ['event' => 'referral.link.visited', 'label' => 'Переход по реферальной ссылке', 'recipients' => 'Никому по умолчанию', 'channels' => [], 'enabled' => false, 'template' => 'Не отправлять по умолчанию'],
            ['event' => 'payment.provider.event.prepared', 'label' => 'Событие платёжного провайдера (подготовлено)', 'recipients' => 'Не настроено', 'channels' => [], 'enabled' => false, 'template' => 'Платёжные потоки не реализованы'],
        ];
    }
}
