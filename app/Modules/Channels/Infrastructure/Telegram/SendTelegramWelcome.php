<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Content\Application\GetClientEntryMedia;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\MenuButtonWebApp;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

final readonly class SendTelegramWelcome
{
    public function __construct(private ResolveTelegramMiniAppEntry $entries, private GetClientEntryMedia $media) {}

    public function handle(Nutgram $bot, string $locale, ?string $continuationUrl = null): void
    {
        try {
            $portalUrl = $this->entries->launchUrl('portal');
        } catch (\LogicException) {
            $bot->sendMessage($locale === 'ru' ? 'Приложение временно недоступно. Попробуйте открыть его позже.' : 'The app is temporarily unavailable. Try opening it later.');

            return;
        }
        $url = $continuationUrl ?? $portalUrl;
        $label = $locale === 'ru' ? 'Открыть приложение' : 'Open app';
        $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(text: $label, web_app: WebAppInfo::make($url)));
        $media = $this->media->handle($locale)['telegram_welcome'];
        if ($media['videoUrl'] !== null) {
            $bot->sendVideo($media['videoUrl'], supports_streaming: true);
        }
        $bot->sendMessage((string) config('portal.telegram.greeting.'.$locale), reply_markup: $keyboard);
        $bot->setChatMenuButton(menu_button: new MenuButtonWebApp($label, WebAppInfo::make($portalUrl)));
    }
}
