<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Content\Application\GetClientEntryMedia;
use App\Modules\Organizations\Application\OrganizationContext;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Command\MenuButtonWebApp;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Throwable;

final readonly class SendTelegramWelcome
{
    public function __construct(
        private ResolveTelegramMiniAppEntry $entries,
        private GetClientEntryMedia $media,
        private OrganizationContext $organizationContext,
    ) {}

    public function handle(Nutgram $bot, string $locale, ?string $continuationUrl = null): void
    {
        try {
            $portalUrl = $this->entries->launchUrl('portal');
        } catch (LogicException $exception) {
            $this->recordOutcome($bot, 'telegram.welcome.launch', 'mini_app_entry_unavailable', 'unavailable', 0, $exception);
            try {
                $notice = $bot->sendMessage($locale === 'ru' ? 'Приложение временно недоступно. Попробуйте открыть его позже.' : 'The app is temporarily unavailable. Try opening it later.');
                if ($notice === null) {
                    $this->recordOutcome($bot, 'telegram.welcome.unavailable_notice', 'notice_delivery_unconfirmed', 'unknown', 1);
                    throw new RuntimeException('Telegram welcome availability notice was not confirmed.');
                }
            } catch (TelegramException|GuzzleException $deliveryException) {
                $this->recordOutcome($bot, 'telegram.welcome.unavailable_notice', 'telegram_delivery_failed', 'failed', 1, $deliveryException);
                throw $deliveryException;
            }

            return;
        }
        $url = $continuationUrl ?? $portalUrl;
        $buttonLabel = $locale === 'ru' ? '🚀 Открыть приложение' : 'Open app';
        $menuLabel = $locale === 'ru' ? 'Открыть приложение' : 'Open app';
        $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(text: $buttonLabel, web_app: WebAppInfo::make($url)));
        $media = $this->media->handle($locale)['telegram_welcome'];
        $statusMessages = [];
        if ($media['videoUrl'] !== null) {
            try {
                $video = $bot->sendVideo($media['videoUrl'], supports_streaming: true);
                if ($video === null) {
                    $this->recordOutcome($bot, 'telegram.welcome.video_send', 'video_delivery_unconfirmed', 'unknown', 1);
                    $statusMessages[] = $this->notice($locale, 'video_failed');
                }
            } catch (TelegramException|GuzzleException $exception) {
                $this->recordOutcome($bot, 'telegram.welcome.video_send', 'video_send_failed', 'failed', 1, $exception);
                $statusMessages[] = $this->notice($locale, 'video_failed');
            }
        } else {
            $this->recordOutcome($bot, 'telegram.welcome.video', 'welcome_video_unavailable', 'unavailable', 0);
            $statusMessages[] = $this->notice($locale, 'video_unavailable');
        }
        $greeting = (string) config('portal.telegram.greeting.'.$locale);
        if ($statusMessages !== []) {
            $greeting .= "\n\n".implode("\n\n", $statusMessages);
        }
        try {
            $message = $bot->sendMessage($greeting, reply_markup: $keyboard);
            if ($message === null) {
                $this->recordOutcome($bot, 'telegram.welcome.greeting_send', 'greeting_delivery_unconfirmed', 'unknown', 1);
                throw new RuntimeException('Telegram welcome delivery was not confirmed.');
            }
        } catch (TelegramException|GuzzleException $exception) {
            $this->recordOutcome($bot, 'telegram.welcome.greeting_send', 'greeting_send_failed', 'failed', 1, $exception);
            throw $exception;
        }
        try {
            $menuUpdated = $bot->setChatMenuButton(menu_button: new MenuButtonWebApp($menuLabel, WebAppInfo::make($portalUrl)));
            if ($menuUpdated !== true) {
                $this->recordOutcome($bot, 'telegram.welcome.menu_button', 'menu_button_unconfirmed', 'unknown', 1);
                $this->sendMenuUnavailableNotice($bot, $locale);
            }
        } catch (TelegramException|GuzzleException $exception) {
            $this->recordOutcome($bot, 'telegram.welcome.menu_button', 'menu_button_setup_failed', 'failed', 1, $exception);
            $this->sendMenuUnavailableNotice($bot, $locale);
        }
    }

    private function sendMenuUnavailableNotice(Nutgram $bot, string $locale): void
    {
        try {
            if ($bot->sendMessage($this->notice($locale, 'menu_unavailable')) === null) {
                $this->recordOutcome($bot, 'telegram.welcome.menu_warning_send', 'menu_warning_delivery_unconfirmed', 'unknown', 1);
            }
        } catch (TelegramException|GuzzleException $exception) {
            $this->recordOutcome($bot, 'telegram.welcome.menu_warning_send', 'menu_warning_send_failed', 'failed', 1, $exception);
        }
    }

    private function notice(string $locale, string $reason): string
    {
        $notices = [
            'video_unavailable' => [
                'ru' => 'Видео приветствия пока недоступно. Откройте приложение кнопкой ниже.',
                'en' => 'The welcome video is not available yet. Open the app using the button below.',
            ],
            'video_failed' => [
                'ru' => 'Не удалось загрузить видео приветствия. Откройте приложение кнопкой ниже.',
                'en' => 'The welcome video could not be loaded. Open the app using the button below.',
            ],
            'menu_unavailable' => [
                'ru' => 'Кнопка меню сейчас недоступна. Откройте приложение кнопкой в приветствии выше.',
                'en' => 'The menu button is unavailable. Open the app with the button in the welcome message above.',
            ],
        ];

        return $notices[$reason][$locale] ?? $notices[$reason]['en'];
    }

    private function recordOutcome(
        Nutgram $bot,
        string $event,
        string $fallbackErrorCode,
        string $outcome,
        int $attempt,
        ?Throwable $exception = null,
    ): void {
        try {
            $organizationId = $this->organizationContext->id();
        } catch (LogicException) {
            $organizationId = null;
        }

        Log::warning('telegram_welcome_degraded', [
            'event' => $event,
            'organization_id' => $organizationId,
            'actor_type' => 'telegram_user',
            'correlation_id' => $this->correlationId($bot),
            'provider' => 'telegram',
            'attempt' => $attempt,
            'error_code' => $exception instanceof TelegramException
                ? 'telegram_api_failure'
                : ($exception instanceof GuzzleException ? 'telegram_transport_failure' : $fallbackErrorCode),
            'exception_class' => $exception === null ? null : $exception::class,
            'provider_status' => $exception instanceof TelegramException && $exception->getCode() > 0
                ? $exception->getCode()
                : null,
            'occurred_at' => now()->utc()->toIso8601String(),
            'outcome' => $outcome,
        ]);
    }

    private function correlationId(Nutgram $bot): ?string
    {
        try {
            $updateId = $bot->updateId();
        } catch (\Error) {
            return null;
        }

        return $updateId === null ? null : 'telegram:update:'.$updateId;
    }
}
