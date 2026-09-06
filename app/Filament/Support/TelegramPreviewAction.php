<?php

namespace App\Filament\Support;

use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class TelegramPreviewAction
{
    public static function make(Closure $messageBuilder): Action
    {
        return Action::make('telegramPreview')
            ->label('Предпросмотр Telegram')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading('Предпросмотр Telegram')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(function (Get $get, ?Model $record) use ($messageBuilder): View {
                try {
                    $message = $messageBuilder($get, $record);
                    if (! $message instanceof NotificationMessage) {
                        throw new InvalidArgumentException('The Telegram message is unavailable.');
                    }

                    $preview = app(TelegramMessagePreview::class)->handle($message);
                    $error = null;
                } catch (ValidationException $exception) {
                    $preview = null;
                    $error = collect($exception->errors())->flatten()->first() ?: 'Заполните обязательные поля.';
                } catch (InvalidArgumentException $exception) {
                    $preview = null;
                    $error = self::messageFor($exception);
                } catch (Throwable) {
                    $preview = null;
                    $error = 'Предпросмотр пока недоступен. Проверьте текст и медиа.';
                }

                return view('filament.resources.broadcasts.preview', [
                    'preview' => $preview,
                    'previewError' => $error,
                    'summary' => null,
                    'reasonLabels' => [],
                ]);
            });
    }

    private static function messageFor(InvalidArgumentException $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'too long') => 'Текст превышает лимит Telegram для выбранного формата.',
            str_contains($message, 'media') => 'Проверьте медиа: добавьте поддерживаемый файл или HTTPS-ссылку.',
            default => 'Проверьте текст сообщения и доступные данные.',
        };
    }
}
