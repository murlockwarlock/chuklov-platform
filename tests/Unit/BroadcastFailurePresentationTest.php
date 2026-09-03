<?php

namespace Tests\Unit;

use App\Filament\Support\BroadcastFailurePresentation;
use PHPUnit\Framework\TestCase;

final class BroadcastFailurePresentationTest extends TestCase
{
    public function test_delivery_failures_have_actionable_operator_labels(): void
    {
        self::assertSame(
            'Telegram-бот не настроен. Подключите бота и повторите тест',
            BroadcastFailurePresentation::label('provider_not_configured'),
        );
        self::assertSame(
            'Текст превышает лимит Telegram',
            BroadcastFailurePresentation::label('telegram_message_too_long'),
        );
        self::assertSame(
            'Telegram не смог получить изображение. Загрузите файл заново или проверьте ссылку',
            BroadcastFailurePresentation::label('telegram_media_unavailable'),
        );
        self::assertSame(
            'Клиент заблокировал Telegram-бота',
            BroadcastFailurePresentation::label('telegram_bot_blocked'),
        );
        self::assertSame(
            'Задача отправки не выполнена. Проверьте очередь сообщений',
            BroadcastFailurePresentation::label('queue_job_failed_terminal'),
        );
        self::assertSame(
            'Шаблон выключен или не предназначен для маркетинговой рассылки. Выберите маркетинговый шаблон',
            BroadcastFailurePresentation::label('template_inactive_or_wrong_purpose'),
        );
        self::assertSame(
            'Не удалось связаться с Telegram. Повторите попытку',
            BroadcastFailurePresentation::label('telegram_channel_unavailable'),
        );
    }
}
