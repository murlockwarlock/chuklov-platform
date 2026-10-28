<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
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

    public function handle(
        string $recipientExternalId,
        ContentSection $section,
        string $locale,
        bool $includeMediaStream = false,
    ): NotificationMessage {
        $title = htmlspecialchars($section->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = RichTextDocument::canonicalHtml(
            '<p><strong>'.$title.'</strong></p>'.RichTextDocument::canonicalHtml($section->body).$this->communityLinks($section),
        );
        $previewImageUrl = $this->images->resolve($section);
        $managedImage = $this->images->isManaged($section);
        $mode = $previewImageUrl === null
            ? NotificationMessageMode::Text
            : (RichTextDocument::telegramLength($body) <= RichTextDocument::TELEGRAM_CAPTION_LIMIT
                ? NotificationMessageMode::ImageWithCaption
                : NotificationMessageMode::ImageThenText);
        $webAppUrl = $section->delivery_mode === ContentDeliveryMode::Both
            ? $this->entries->launchUrl($section->section_key)
            : null;
        $webAppButtonText = $webAppUrl === null
            ? null
            : ($locale === 'ru' ? 'Открыть полностью' : 'Open full version');
        $updatedAt = $section->updated_at?->getTimestamp() ?? 0;
        $mediaStream = $includeMediaStream ? $this->images->resolveStream($section) : null;
        $mediaUrl = $includeMediaStream && $managedImage ? null : $previewImageUrl;

        return new NotificationMessage(
            recipientExternalId: $recipientExternalId,
            body: $body,
            subject: null,
            locale: $locale,
            idempotencyKey: 'content:'.$section->getKey().':'.$recipientExternalId.':'.$updatedAt,
            mode: $mode,
            webAppUrl: $webAppUrl,
            webAppButtonText: $webAppButtonText,
            mediaUrl: $mediaUrl,
            mediaStream: $mediaStream,
        );
    }

    private function communityLinks(ContentSection $section): string
    {
        if ($section->section_key !== 'communities' || ! is_array($section->media['communities'] ?? null)) {
            return '';
        }

        $items = array_filter($section->media['communities'], static fn (mixed $community): bool => is_array($community)
            && ($community['is_visible'] ?? true) === true
            && is_string($community['title'] ?? null)
            && trim($community['title']) !== ''
            && is_string($community['url'] ?? null)
            && str_starts_with($community['url'], 'https://')
            && filter_var($community['url'], FILTER_VALIDATE_URL) !== false);
        usort($items, static fn (array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0)));

        return implode('', array_map(static function (array $community): string {
            $title = htmlspecialchars(trim((string) $community['title']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $url = htmlspecialchars(trim((string) $community['url']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $description = is_string($community['description'] ?? null) && trim($community['description']) !== ''
                ? '<br>'.htmlspecialchars(trim($community['description']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : '';

            return '<p><a href="'.$url.'">'.$title.'</a>'.$description.'</p>';
        }, $items));
    }
}
