<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Support\RichText\RichTextDocument;
use InvalidArgumentException;

final readonly class ContentSectionData
{
    /** @param array<string, string>|null $media */
    private function __construct(
        public string $sectionKey,
        public string $locale,
        public string $title,
        public string $body,
        public ContentDeliveryMode $deliveryMode,
        public ?array $media,
        public int $sortOrder,
        public bool $isVisible,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function from(array $attributes): self
    {
        $sectionKey = self::stringValue($attributes['section_key'] ?? null, 'The content section key is invalid.', 64);
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $sectionKey) !== 1) {
            throw new InvalidArgumentException('The content section key is invalid.');
        }

        $locale = self::stringValue($attributes['locale'] ?? null, 'The content locale is invalid.', 10);
        if (! in_array($locale, ['en', 'ru'], true)) {
            throw new InvalidArgumentException('The content locale is invalid.');
        }

        $title = self::stringValue($attributes['title'] ?? null, 'The content title is invalid.', 160);
        $body = self::stringValue($attributes['body'] ?? null, 'The content body is invalid.', 100000);
        try {
            RichTextDocument::canonicalHtml($body);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('The content body is invalid.', previous: $exception);
        }
        $deliveryMode = ContentDeliveryMode::tryFrom((string) ($attributes['delivery_mode'] ?? ContentDeliveryMode::Both->value));
        if (! $deliveryMode instanceof ContentDeliveryMode) {
            throw new InvalidArgumentException('The content delivery mode is invalid.');
        }
        $media = self::media($attributes['media'] ?? null);
        if ($deliveryMode->supportsTelegram()) {
            try {
                $telegramBody = '<p><strong>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</strong></p>'.$body;
                if (RichTextDocument::exceedsTelegramLimit($telegramBody)) {
                    throw new InvalidArgumentException('The Telegram content exceeds the message limit.');
                }
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('The Telegram content is invalid.', previous: $exception);
            }
        }
        $sortOrder = self::nonNegativeInteger($attributes['sort_order'] ?? 0, 'The content order is invalid.');

        return new self(
            sectionKey: $sectionKey,
            locale: $locale,
            title: $title,
            body: $body,
            deliveryMode: $deliveryMode,
            media: $media,
            sortOrder: $sortOrder,
            isVisible: (bool) ($attributes['is_visible'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'section_key' => $this->sectionKey,
            'locale' => $this->locale,
            'title' => $this->title,
            'body' => $this->body,
            'delivery_mode' => $this->deliveryMode->value,
            'media' => $this->media,
            'sort_order' => $this->sortOrder,
            'is_visible' => $this->isVisible,
        ];
    }

    private static function stringValue(mixed $value, string $message, int $maxLength): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($message);
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private static function nonNegativeInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($normalized !== false) {
                return $normalized;
            }
        }

        throw new InvalidArgumentException($message);
    }

    /** @return array<string, string>|null */
    private static function media(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value) || count($value) > 20) {
            throw new InvalidArgumentException('The content media metadata is invalid.');
        }

        $media = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || preg_match('/^[a-z0-9._-]+$/', $key) !== 1 || ! is_string($item) || mb_strlen($item) > 2000) {
                throw new InvalidArgumentException('The content media metadata is invalid.');
            }

            $media[$key] = $item;
        }

        return $media;
    }
}
