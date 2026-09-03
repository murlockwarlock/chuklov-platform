<?php

use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Application\SendTelegramContentSection;
use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionCallback;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionPhoto;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionText;
use App\Modules\Identity\Application\CompleteTelegramWebAuthentication;
use App\Modules\Identity\Application\ConnectTelegramClientIdentity;
use App\Modules\Identity\Application\InvalidTelegramLinkToken;
use App\Modules\Identity\Application\InvalidTelegramWebAuthentication;
use App\Modules\Identity\Application\RefreshTelegramClientIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
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
})->where('token', '(?!web_)[A-Za-z0-9_-]+')->description('Запустить приложение');

$bot->onCommand('start', function (
    Nutgram $bot,
    GetTelegramMenu $menu,
    TelegramBotIdentityVerifier $identityVerifier,
    RefreshTelegramClientIdentity $refreshIdentity,
    OrganizationContext $organizationContext,
): void {
    $language = str_starts_with(strtolower((string) $bot->user()?->language_code), 'ru') ? 'ru' : 'en';
    $organizationId = config('tenancy.default_organization_id');
    if (is_int($organizationId) || (is_string($organizationId) && ctype_digit($organizationId))) {
        $organization = Organization::query()->find((int) $organizationId);
        if ($organization instanceof Organization) {
            $organizationContext->set($organization);
            try {
                $refreshIdentity->handle($organization, $identityVerifier->handle($bot));
            } catch (UnauthorizedHttpException) {
            }
        }
    }
    $keyboard = InlineKeyboardMarkup::make();

    foreach ($menu->handle($language) as $entry) {
        $button = match ($entry['launch']) {
            'mini_app' => InlineKeyboardButton::make(
                text: $entry['label'],
                web_app: WebAppInfo::make($entry['url']),
            ),
            'telegram_content' => InlineKeyboardButton::make(
                text: $entry['label'],
                callback_data: $entry['callback_data'],
            ),
            default => InlineKeyboardButton::make(
                text: $entry['label'],
                url: $entry['url'],
            ),
        };

        $keyboard->addRow($button);
    }

    $bot->sendMessage(
        (string) config('portal.telegram.greeting.'.$language, ''),
        reply_markup: $keyboard,
    );
})->description('Запустить приложение');

$bot->onText('^(?!/).+', function (Nutgram $bot, HandleTelegramCompanionText $handler): void {
    $handler->handle($bot);
});

$bot->onPhoto(function (Nutgram $bot, HandleTelegramCompanionPhoto $handler): void {
    $handler->handle($bot);
});

$bot->onCallbackQueryData('/^cc:(?:feedback:(?:helpful|not_helpful)|human):\d+$/', function (Nutgram $bot, HandleTelegramCompanionCallback $handler): void {
    $handler->handle($bot);
});

$bot->onCallbackQueryData('/^content:[a-z0-9][a-z0-9._-]{0,55}$/', function (
    Nutgram $bot,
    TelegramBotIdentityVerifier $identityVerifier,
    SendTelegramContentSection $sendContent,
    OrganizationContext $organizationContext,
): void {
    $organizationId = config('tenancy.default_organization_id');
    $data = (string) ($bot->callbackQuery()->data ?? '');
    $sectionKey = substr($data, strlen('content:'));

    if ((! is_int($organizationId) && ! (is_string($organizationId) && ctype_digit($organizationId)))
        || $sectionKey === '') {
        $bot->answerCallbackQuery(text: 'Раздел недоступен.');

        return;
    }

    $organization = Organization::query()->find((int) $organizationId);
    if (! $organization instanceof Organization) {
        $bot->answerCallbackQuery(text: 'Раздел недоступен.');

        return;
    }

    try {
        $organizationContext->set($organization);
        $identity = $identityVerifier->handle($bot);
        $locale = str_starts_with(strtolower((string) $bot->user()?->language_code), 'ru') ? 'ru' : 'en';
        $result = $sendContent->handle($identity, $sectionKey, $locale);
        $bot->answerCallbackQuery(text: $result->outcome->value === 'delivered' ? 'Готово.' : 'Раздел пока недоступен.');
    } catch (Throwable) {
        $bot->answerCallbackQuery(text: 'Раздел пока недоступен.');
    }
});
