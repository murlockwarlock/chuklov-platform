<?php

namespace App\Filament\Support;

final class BroadcastFailurePresentation
{
    public static function label(?string $code): string
    {
        return match ($code) {
            'organization_mismatch' => __('Клиент не принадлежит текущей организации'),
            'ineligible' => __('Получатель не подходит для этой рассылки'),
            'marketing_consent_missing' => __('Нет согласия на маркетинговые сообщения'),
            'marketing_suppressed' => __('Согласие на рассылки отозвано'),
            'verified_channel_unavailable' => __('Нет подтверждённого Telegram'),
            'eligibility_changed' => __('Согласие или Telegram изменились'),
            'snapshot_missing' => __('Список получателей не зафиксирован'),
            'snapshot_superseded' => __('Список получателей устарел'),
            'campaign_cancelled' => __('Рассылка отменена до отправки'),
            'campaign_state_changed' => __('Состояние рассылки изменилось'),
            'authorization_revoked' => __('Нет права запускать рассылку'),
            'creator_authority_revoked' => __('Право на отправку было отозвано'),
            'provider_not_configured' => __('Telegram-бот не настроен. Подключите бота и повторите тест'),
            'telegram_identity_unavailable' => __('У клиента нет доступного Telegram'),
            'provider_error' => __('Telegram не принял сообщение. Проверьте подключение бота и доступ клиента'),
            'telegram_provider_rejected' => __('Telegram отклонил запрос. Проверьте чат и параметры сообщения'),
            'telegram_bot_blocked' => __('Клиент заблокировал Telegram-бота'),
            'telegram_chat_not_found' => __('Telegram-чат клиента недоступен'),
            'telegram_user_deactivated' => __('Telegram-аккаунт клиента деактивирован'),
            'telegram_media_unavailable' => __('Telegram не смог получить изображение. Загрузите файл заново или проверьте ссылку'),
            'telegram_api_error' => __('Telegram временно недоступен. Повторите попытку позже'),
            'telegram_rate_limited' => __('Telegram временно ограничил отправку. Повторите попытку позже'),
            'telegram_message_too_long' => __('Текст превышает лимит Telegram'),
            'telegram_message_invalid', 'telegram_formatting_rejected', 'formatting_contract_mismatch', 'template_rendering_error' => __('Не удалось подготовить текст сообщения. Проверьте формат и шаблон'),
            'invalid_web_app_url', 'invalid_notification_button' => __('Ссылка или кнопка сообщения настроена неверно'),
            'content_unavailable' => __('Содержимое сообщения недоступно'),
            'media_unavailable' => __('Изображение недоступно. Проверьте ссылку или загрузите файл заново'),
            'delivery_configuration_invalid', 'delivery_configuration_unavailable' => __('Настройки сообщения неполные. Проверьте формат, изображение и шаблон'),
            'template_unavailable' => __('Текстовый шаблон не найден. Выберите сообщение заново'),
            'template_inactive_or_wrong_purpose' => __('Шаблон выключен или не предназначен для маркетинговой рассылки. Выберите маркетинговый шаблон'),
            'template_inactive_or_channel_unavailable' => __('Шаблон отключён или Telegram недоступен'),
            'delivery_outcome_unknown' => __('Telegram не подтвердил результат. Проверьте чат перед повторной отправкой'),
            'delivery_pre_send_failure' => __('Отправка не началась. Повторите попытку'),
            'queue_job_failed', 'queue_job_failed_terminal', 'queue_dispatch_failed', 'queue_dispatch_exhausted' => __('Не удалось отправить сообщение. Повторите попытку позже'),
            'channel_error', 'telegram_channel_unavailable' => __('Не удалось связаться с Telegram. Повторите попытку'),
            default => __('Отправка не выполнена. Проверьте настройки Telegram и получателя'),
        };
    }
}
