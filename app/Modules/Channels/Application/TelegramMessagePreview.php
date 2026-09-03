<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Support\RichText\RichTextDocument;
use InvalidArgumentException;

final class TelegramMessagePreview
{
    /** @return array{mode: string, captionPosition: string, bodyHtml: string, mediaUrl: string|null, hasText: bool, hasImage: bool, actionButton: array{text: string, url: string}|null} */
    public function handle(NotificationMessage $message): array
    {
        $bodyHtml = RichTextDocument::telegramHtml($message->body);
        if ($message->mode->includesImage() && ($message->mediaUrl === null || trim($message->mediaUrl) === '')) {
            throw new InvalidArgumentException('The Telegram media is required.');
        }

        if ($message->mode->includesText()) {
            $limit = $message->mode->usesCaption()
                ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
                : RichTextDocument::TELEGRAM_TEXT_LIMIT;

            if (RichTextDocument::renderedTelegramLength($bodyHtml) > $limit) {
                throw new InvalidArgumentException('The Telegram message is too long.');
            }
        }

        $actionButton = $message->actionButton === null
            ? null
            : ['text' => $message->actionButton->text, 'url' => $message->actionButton->url];

        return [
            'mode' => $message->mode->value,
            'captionPosition' => $message->showCaptionAboveMedia ? 'above' : 'below',
            'bodyHtml' => $bodyHtml,
            'mediaUrl' => $message->mediaUrl,
            'hasText' => $message->mode->includesText() && $bodyHtml !== '',
            'hasImage' => $message->mode->includesImage() && $message->mediaUrl !== null,
            'actionButton' => $actionButton,
        ];
    }
}
