<?php

use App\Modules\Channels\Application\HandleTelegramBookingConfirmation;
use App\Modules\Channels\Application\PreparePatientTelegramInvitation;
use App\Modules\Channels\Application\SendTelegramContentSection;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Infrastructure\Telegram\SendTelegramWelcome;
use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionCallback;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionDocument;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionPhoto;
use App\Modules\ClientCompanion\Application\Actions\HandleTelegramCompanionText;
use App\Modules\Identity\Application\CompleteTelegramWebAuthentication;
use App\Modules\Identity\Application\ConnectTelegramClientIdentity;
use App\Modules\Identity\Application\ConnectTelegramOrganizationIdentity;
use App\Modules\Identity\Application\ExpiredTelegramLinkToken;
use App\Modules\Identity\Application\InvalidTelegramLinkToken;
use App\Modules\Identity\Application\InvalidTelegramWebAuthentication;
use App\Modules\Identity\Application\RefreshTelegramClientIdentity;
use App\Modules\Identity\Application\TelegramIdentityAlreadyLinked;
use App\Modules\Identity\Application\TelegramStaffMembershipUnavailable;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Application\HandleReferralTelegramStart;
use App\Modules\Referrals\Application\SendReferralInvite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use SergiX44\Nutgram\Nutgram;
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

$bot->onCommand('start ref_{token}', function (
    Nutgram $bot,
    string $token,
    TelegramBotIdentityVerifier $identityVerifier,
    HandleReferralTelegramStart $referrals,
    OrganizationContext $organizationContext,
    RefreshTelegramClientIdentity $refreshIdentity,
): void {
    $language = str_starts_with(strtolower((string) $bot->user()?->language_code), 'ru') ? 'ru' : 'en';
    $invalidMessage = $language === 'ru'
        ? 'Реферальная ссылка недействительна или отключена.'
        : 'This referral link is invalid or disabled.';
    $organizationId = config('tenancy.default_organization_id');

    if (! is_int($organizationId) && ! (is_string($organizationId) && ctype_digit($organizationId))) {
        $bot->sendMessage($invalidMessage);

        return;
    }

    $organization = Organization::query()->find((int) $organizationId);

    if (! $organization instanceof Organization) {
        $bot->sendMessage($invalidMessage);

        return;
    }

    try {
        $organizationContext->set($organization);
        $identity = $identityVerifier->handle($bot);
        $url = $referrals->handle('ref_'.$token, $identity->externalId);

        if ($url === null) {
            $bot->sendMessage($invalidMessage);

            return;
        }

        $refreshIdentity->handle($organization, $identity);
        app(SendTelegramWelcome::class)->handle($bot, $language, $url);
    } catch (AuthorizationException|UnauthorizedHttpException|LogicException) {
        $bot->sendMessage($invalidMessage);
    }
})->where('token', '[A-Za-z0-9_-]{16,128}')->description('Открыть реферальное приложение');

$bot->onCommand('start family_{token}', function (
    Nutgram $bot,
    string $token,
    TelegramBotIdentityVerifier $identityVerifier,
    PreparePatientTelegramInvitation $prepare,
    OrganizationContext $context,
): void {
    try {
        $organizationId = config('tenancy.default_organization_id');
        if (! is_int($organizationId) && ! (is_string($organizationId) && ctype_digit($organizationId))) {
            throw new AuthorizationException;
        }
        $context->set(Organization::query()->findOrFail((int) $organizationId));
        $identity = $identityVerifier->handle($bot);
        $url = $prepare->handle($token, $identity);
        app(SendTelegramWelcome::class)->handle($bot, $identity->language, $url);
    } catch (AuthorizationException|UnauthorizedHttpException|ModelNotFoundException) {
        $bot->sendMessage('Приглашение недействительно, устарело или предназначено другому аккаунту.');
    }
})->where('token', '[A-Za-z0-9_-]{43}')->description('Открыть приглашение семьи');

$bot->onCommand('start staff_{token}', function (
    Nutgram $bot,
    string $token,
    TelegramBotIdentityVerifier $identityVerifier,
    ConnectTelegramOrganizationIdentity $connect,
): void {
    try {
        $alreadyConnected = $connect->handle($token, $identityVerifier->handle($bot));
        $bot->sendMessage($alreadyConnected
            ? 'Telegram уже подключён.'
            : 'Telegram подключён к уведомлениям CRM.');
    } catch (ExpiredTelegramLinkToken) {
        $bot->sendMessage('Ссылка устарела. Создайте новую в CRM.');
    } catch (TelegramIdentityAlreadyLinked) {
        $bot->sendMessage('Этот Telegram уже связан с другим аккаунтом CRM. Обратитесь к администратору.');
    } catch (TelegramStaffMembershipUnavailable) {
        $bot->sendMessage('Подключение сейчас недоступно. Обратитесь к администратору.');
    } catch (InvalidTelegramLinkToken|AuthorizationException|UnauthorizedHttpException) {
        $bot->sendMessage('Ссылка недействительна или уже использована.');
    }
})->where('token', '[A-Za-z0-9_-]{32,128}')->description('Подключить уведомления CRM');

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
})->where('token', '(?!(?:staff_|web_|ref_|family_))[A-Za-z0-9_-]+')->description('Запустить приложение');

$bot->onCommand('invite', function (
    Nutgram $bot,
    TelegramBotIdentityVerifier $identityVerifier,
    RefreshTelegramClientIdentity $refreshIdentity,
    OrganizationContext $organizationContext,
    SendReferralInvite $sendInvite,
): void {
    $language = str_starts_with(strtolower((string) $bot->user()?->language_code), 'ru') ? 'ru' : 'en';
    $organizationId = config('tenancy.default_organization_id');

    if (! is_int($organizationId) && ! (is_string($organizationId) && ctype_digit($organizationId))) {
        $bot->sendMessage($language === 'ru' ? 'Приглашение сейчас недоступно.' : 'Invites are currently unavailable.');

        return;
    }

    $organization = Organization::query()->find((int) $organizationId);
    if (! $organization instanceof Organization) {
        $bot->sendMessage($language === 'ru' ? 'Приглашение сейчас недоступно.' : 'Invites are currently unavailable.');

        return;
    }

    try {
        $organizationContext->set($organization);
        $identity = $identityVerifier->handle($bot);
        $client = $refreshIdentity->handle($organization, $identity);
        if ($client === null) {
            $bot->sendMessage($language === 'ru' ? 'Откройте приложение, чтобы продолжить.' : 'Open the app to continue.');

            return;
        }

        $result = $sendInvite->handle($client, $identity->externalId);
        if ($result->outcome !== NotificationDeliveryOutcome::Delivered) {
            $bot->sendMessage($language === 'ru' ? 'Не удалось подготовить приглашение. Откройте приложение и попробуйте ещё раз.' : 'The invite could not be prepared. Open the app and try again.');
        }
    } catch (AuthorizationException|UnauthorizedHttpException|LogicException|InvalidArgumentException) {
        $bot->sendMessage($language === 'ru' ? 'Приглашение сейчас недоступно.' : 'Invites are currently unavailable.');
    }
})->description('Пригласить друга');

$bot->onCommand('start', function (
    Nutgram $bot,
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
    app(SendTelegramWelcome::class)->handle($bot, $language);
})->description('Запустить приложение');

$bot->onText('^(?!/).+', function (Nutgram $bot, HandleTelegramCompanionText $handler): void {
    $handler->handle($bot);
});

$bot->onPhoto(function (Nutgram $bot, HandleTelegramCompanionPhoto $handler): void {
    $handler->handle($bot);
});

$bot->onDocument(function (Nutgram $bot, HandleTelegramCompanionDocument $handler): void {
    $handler->handle($bot);
});

$bot->onCallbackQueryData('cc:(?:feedback:(?:helpful|not_helpful)|human|reinspect|retry):\d+', function (Nutgram $bot, HandleTelegramCompanionCallback $handler): void {
    $handler->handle($bot);
});

$bot->onCallbackQueryData('booking:confirm:\d+(?::\d+)?', function (Nutgram $bot, HandleTelegramBookingConfirmation $handler): void {
    $handler->handle($bot);
});

$bot->onCallbackQueryData('content:[a-z0-9][a-z0-9._-]{0,55}', function (
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
        $result = $sendContent->handle(
            identity: $identity,
            sectionKey: $sectionKey,
            locale: $locale,
            requestId: (string) ($bot->callbackQuery()?->id ?? ''),
        );
        $bot->answerCallbackQuery(text: match ($result->outcome->value) {
            'delivered' => 'Готово.',
            'suppressed' => 'Раздел уже отправлен.',
            default => 'Раздел пока недоступен.',
        });
    } catch (Throwable) {
        $bot->answerCallbackQuery(text: 'Раздел пока недоступен.');
    }
});
