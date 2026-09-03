<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Support\RichText\RichTextDocument;
use InvalidArgumentException;

final class TelegramMessagePreview
{
    /** @return array{mode: string, captionPosition: string, bodyHtml: string, mediaUrl: string|null, mediaItems: list<array{type: string, url: string|null, name: string|null}>, hasText: bool, hasImage: bool, actionButton: array{text: string, url: string}|null} */
    public function handle(NotificationMessage $message): array
    {
        $bodyHtml = RichTextDocument::telegramHtml($message->body);
        $mediaUrl = $message->mediaUrl !== null && trim($message->mediaUrl) !== ''
            ? trim($message->mediaUrl)
            : null;
        $mediaItems = [];

        foreach ($message->mediaItems as $media) {
            if (! in_array($media->type, ['photo', 'video', 'document'], true)) {
                throw new InvalidArgumentException('The Telegram media is unavailable.');
            }

            $url = $media->url !== null && trim($media->url) !== '' ? trim($media->url) : null;
            if ($url === null && ($media->type !== 'document' || blank($media->fileName))) {
                throw new InvalidArgumentException('The Telegram media is unavailable.');
            }

            $mediaItems[] = [
                'type' => $media->type,
                'url' => $url,
                'name' => $media->fileName,
            ];
        }

        if ($mediaItems === [] && $mediaUrl !== null) {
            $mediaItems[] = [
                'type' => 'photo',
                'url' => $mediaUrl,
                'name' => null,
            ];
        }

        if ($message->mode->includesImage() && $mediaItems === []) {
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
            'mediaUrl' => $mediaItems !== [] && count($mediaItems) === 1 && $mediaItems[0]['type'] === 'photo'
                ? $mediaItems[0]['url']
                : null,
            'mediaItems' => $mediaItems,
            'hasText' => $message->mode->includesText() && $bodyHtml !== '',
            'hasImage' => $message->mode->includesImage() && $mediaItems !== [],
            'actionButton' => $actionButton,
        ];
    }
}
