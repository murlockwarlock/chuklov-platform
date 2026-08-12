<?php

use App\Modules\Channels\Application\GetTelegramMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

$bot->onCommand('start', function (Nutgram $bot, GetTelegramMenu $menu): void {
    $language = str_starts_with(strtolower((string) $bot->user()?->language_code), 'ru') ? 'ru' : 'en';
    $keyboard = InlineKeyboardMarkup::make();

    foreach ($menu->handle($language) as $entry) {
        $button = $entry['web_app']
            ? InlineKeyboardButton::make(
                text: $entry['label'],
                web_app: WebAppInfo::make($entry['url']),
            )
            : InlineKeyboardButton::make(
                text: $entry['label'],
                url: $entry['url'],
            );

        $keyboard->addRow($button);
    }

    $bot->sendMessage(
        (string) config('portal.telegram.greeting.'.$language, ''),
        reply_markup: $keyboard,
    );
})->description('Open the client portal');
