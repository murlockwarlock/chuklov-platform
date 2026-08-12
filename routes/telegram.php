<?php

use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\Identity\Application\ConnectTelegramClientIdentity;
use App\Modules\Identity\Application\InvalidTelegramLinkToken;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

$bot->onCommand('start {token}', function (
    Nutgram $bot,
    string $token,
    TelegramBotIdentityVerifier $identityVerifier,
    ConnectTelegramClientIdentity $connect,
): void {
    try {
        $identity = $identityVerifier->handle($bot);
        $connect->handle($token, $identity);
        $bot->sendMessage('Telegram is connected to your client portal.');
    } catch (InvalidTelegramLinkToken|AuthorizationException|UnauthorizedHttpException) {
        $bot->sendMessage('This Telegram connection link is invalid, expired, or already used.');
    }
})->description('Connect Telegram to an existing client portal account');

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
