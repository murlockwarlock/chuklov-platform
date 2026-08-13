<?php

use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\Identity\Application\CompleteTelegramWebAuthentication;
use App\Modules\Identity\Application\ConnectTelegramClientIdentity;
use App\Modules\Identity\Application\InvalidTelegramLinkToken;
use App\Modules\Identity\Application\InvalidTelegramWebAuthentication;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

$bot->onCommand('start web_{token}', function (
    Nutgram $bot,
    string $token,
    TelegramBotIdentityVerifier $identityVerifier,
    CompleteTelegramWebAuthentication $complete,
): void {
    try {
        $identity = $identityVerifier->handle($bot);
        $complete->handle($token, $identity);
        $bot->sendMessage('Вход подтверждён. Вернитесь в браузер.');
    } catch (InvalidTelegramWebAuthentication|AuthorizationException|UnauthorizedHttpException) {
        $bot->sendMessage('Ссылка для входа недействительна или уже использована.');
    }
});

$bot->onCommand('start {token}', function (
    Nutgram $bot,
    string $token,
    TelegramBotIdentityVerifier $identityVerifier,
    ConnectTelegramClientIdentity $connect,
): void {
    try {
        $identity = $identityVerifier->handle($bot);
        $connect->handle($token, $identity);
        $bot->sendMessage('Telegram подключён.');
    } catch (InvalidTelegramLinkToken|AuthorizationException|UnauthorizedHttpException) {
        $bot->sendMessage('Ссылка недействительна или уже использована.');
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
})->description('Запустить приложение');
