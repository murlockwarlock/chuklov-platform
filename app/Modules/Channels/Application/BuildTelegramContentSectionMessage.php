<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Support\RichText\RichTextDocument;

final class BuildTelegramContentSectionMessage
{
    public function __construct(
        private readonly ContentImageUrlResolver $images,
        private readonly ResolveTelegramMiniAppEntry $entries,
    ) {}

    public function handle(string $recipientExternalId, ContentSection $section, string $locale): NotificationMessage
    {
        $title = htmlspecialchars($section->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = RichTextDocument::canonicalHtml(
            '<p><strong>'.$title.'</strong></p>'.RichTextDocument::canonicalHtml($section->body),
        );
        $imageUrl = $this->images->resolve($section);
        $mode = $imageUrl === null
            ? NotificationMessageMode::Text
            : (RichTextDocument::telegramLength($body) <= RichTextDocument::TELEGRAM_CAPTION_LIMIT
                ? NotificationMessageMode::ImageWithCaption
                : NotificationMessageMode::ImageThenText);
        $button = $section->delivery_mode === ContentDeliveryMode::Both
            ? new NotificationActionButton(
                text: $locale === 'ru' ? 'Открыть полностью' : 'Open full version',
                url: $this->entries->launchUrl($section->section_key),
            )
            : null;
        $updatedAt = $section->updated_at?->getTimestamp() ?? 0;
        $mediaStream = $this->images->resolveStream($section);

        return new NotificationMessage(
            recipientExternalId: $recipientExternalId,
            body: $body,
            subject: null,
            locale: $locale,
            idempotencyKey: 'content:'.$section->getKey().':'.$recipientExternalId.':'.$updatedAt,
            mode: $mode,
            actionButton: $button,
            mediaUrl: $imageUrl,
            mediaStream: $mediaStream,
        );
    }
}
