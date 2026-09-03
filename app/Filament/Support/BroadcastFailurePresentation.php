<?php

namespace App\Filament\Support;

final class BroadcastFailurePresentation
{
    public static function label(?string $code): string
    {
        return match ($code) {
            'organization_mismatch' => 'Клиент не принадлежит текущей организации',
            'ineligible' => 'Получатель не подходит для этой рассылки',
            'marketing_consent_missing' => 'Нет согласия на маркетинговые сообщения',
            'marketing_suppressed' => 'Согласие на рассылки отозвано',
            'verified_channel_unavailable' => 'Нет подтверждённого Telegram',
            'eligibility_changed' => 'Согласие или Telegram изменились',
            'snapshot_missing' => 'Список получателей не зафиксирован',
            'snapshot_superseded' => 'Список получателей устарел',
            'campaign_cancelled' => 'Рассылка отменена до отправки',
            'campaign_state_changed' => 'Состояние рассылки изменилось',
            'authorization_revoked' => 'Нет права запускать рассылку',
            'creator_authority_revoked' => 'Право на отправку было отозвано',
            'provider_not_configured' => 'Telegram-бот не настроен. Подключите бота и повторите тест',
            'telegram_identity_unavailable' => 'У клиента нет доступного Telegram',
            'provider_error' => 'Telegram не принял сообщение. Проверьте подключение бота и доступ клиента',
            'telegram_provider_rejected' => 'Telegram отклонил запрос. Проверьте чат и параметры сообщения',
            'telegram_bot_blocked' => 'Клиент заблокировал Telegram-бота',
            'telegram_chat_not_found' => 'Telegram-чат клиента недоступен',
            'telegram_user_deactivated' => 'Telegram-аккаунт клиента деактивирован',
            'telegram_media_unavailable' => 'Telegram не смог получить изображение. Загрузите файл заново или проверьте ссылку',
            'telegram_api_error' => 'Telegram временно недоступен. Повторите попытку позже',
            'telegram_rate_limited' => 'Telegram временно ограничил отправку. Повторите попытку позже',
            'telegram_message_too_long' => 'Текст превышает лимит Telegram',
            'telegram_message_invalid', 'telegram_formatting_rejected', 'formatting_contract_mismatch', 'template_rendering_error' => 'Не удалось подготовить текст сообщения. Проверьте формат и шаблон',
            'invalid_web_app_url', 'invalid_notification_button' => 'Ссылка или кнопка сообщения настроена неверно',
            'content_unavailable' => 'Содержимое сообщения недоступно',
            'media_unavailable' => 'Изображение недоступно. Проверьте ссылку или загрузите файл заново',
            'delivery_configuration_invalid', 'delivery_configuration_unavailable' => 'Настройки сообщения неполные. Проверьте формат, изображение и шаблон',
            'template_inactive_or_channel_unavailable' => 'Шаблон отключён или Telegram недоступен',
            'delivery_outcome_unknown' => 'Telegram не подтвердил результат. Проверьте чат перед повторной отправкой',
            'delivery_pre_send_failure' => 'Отправка не началась. Повторите попытку',
            'queue_job_failed', 'queue_job_failed_terminal', 'queue_dispatch_failed', 'queue_dispatch_exhausted' => 'Задача отправки не выполнена. Проверьте очередь сообщений',
            'channel_error', 'telegram_channel_unavailable' => 'Не удалось связаться с Telegram. Повторите попытку',
            default => 'Отправка не выполнена. Проверьте настройки Telegram и получателя',
        };
    }
}
