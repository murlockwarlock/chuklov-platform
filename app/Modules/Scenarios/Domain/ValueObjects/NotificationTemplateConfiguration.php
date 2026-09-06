<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Support\RichText\RichTextDocument;
use InvalidArgumentException;

final readonly class NotificationTemplateConfiguration
{
    /** @param list<string> $variables */
    public function __construct(
        public string $templateKey,
        public string $name,
        public string $locale,
        public ScenarioRulePurpose $purpose,
        public bool $isActive,
        public ?string $subject,
        public string $body,
        public array $variables,
        public NotificationMessageMode $deliveryMode,
        public string $captionPosition,
        public ?array $media,
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $templateKey = trim((string) ($data['template_key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $purpose = ScenarioRulePurpose::tryFrom((string) ($data['purpose'] ?? ''));
        $subject = isset($data['subject']) ? trim((string) $data['subject']) : null;
        $body = trim((string) ($data['body'] ?? ''));
        $variables = is_array($data['variables'] ?? null) ? array_values($data['variables']) : [];
        $deliveryMode = NotificationMessageMode::tryFrom((string) ($data['delivery_mode'] ?? NotificationMessageMode::Text->value));
        $captionPosition = (string) ($data['caption_position'] ?? 'below');
        $media = self::normalizeMedia($data['media'] ?? null);

        if ($templateKey === '' || mb_strlen($templateKey) > 120 || preg_match('/^[a-z0-9._-]+$/', $templateKey) !== 1
            || $name === '' || mb_strlen($name) > 160
            || preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) !== 1
            || $purpose === null
            || $deliveryMode === null
            || ! in_array($captionPosition, ['above', 'below'], true)
            || ($deliveryMode->includesText() && $body === '')
            || mb_strlen($body) > 100000
            || ($subject !== null && mb_strlen($subject) > 255)
            || count($variables) > 32) {
            throw new InvalidArgumentException('The notification template configuration is invalid.');
        }

        if ($deliveryMode->includesImage() && $media === null) {
            throw new InvalidArgumentException('The notification template media is required.');
        }

        if (! $deliveryMode->includesImage() && $media !== null) {
            throw new InvalidArgumentException('The notification template media is unavailable for text-only messages.');
        }

        $normalizedVariables = [];

        foreach ($variables as $variable) {
            $variable = trim((string) $variable);

            if ($variable === '' || mb_strlen($variable) > 64 || preg_match('/^[a-z][a-z0-9_.]*$/', $variable) !== 1 || in_array($variable, $normalizedVariables, true)) {
                throw new InvalidArgumentException('The notification template variable list is invalid.');
            }

            $normalizedVariables[] = $variable;
        }

        $normalizedVariables = array_values(array_unique($normalizedVariables));
        $usedVariables = ScenarioTemplateVariableCatalog::used($body, $subject ?? '');

        $allowedVariables = self::allowedVariables($purpose);
        if (array_diff($normalizedVariables, $allowedVariables) !== []
            || array_diff($usedVariables, $allowedVariables) !== []) {
            throw new InvalidArgumentException('The notification template uses data unavailable for its purpose.');
        }

        if (array_diff($usedVariables, $normalizedVariables) !== []) {
            throw new InvalidArgumentException('The notification template must declare every used variable.');
        }

        if ($deliveryMode->includesText()) {
            try {
                $limit = $deliveryMode->usesCaption()
                    ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
                    : RichTextDocument::TELEGRAM_TEXT_LIMIT;
                if (RichTextDocument::telegramLength($body) > $limit) {
                    throw new InvalidArgumentException('The notification template text exceeds the Telegram limit.');
                }
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('The notification template text is invalid.', previous: $exception);
            }
        }

        return new self(
            templateKey: $templateKey,
            name: $name,
            locale: $locale,
            purpose: $purpose,
            isActive: self::booleanValue($data['is_active'] ?? true),
            subject: $subject === '' ? null : $subject,
            body: $body,
            variables: $normalizedVariables,
            deliveryMode: $deliveryMode,
            captionPosition: $captionPosition,
            media: $media,
        );
    }

    /** @return array{items: list<array{type: string, source: string, name: string|null}>}|null */
    private static function normalizeMedia(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! is_array($value['items'] ?? null) || ! array_is_list($value['items']) || $value['items'] === [] || count($value['items']) > 10) {
            throw new InvalidArgumentException('The notification template media is invalid.');
        }

        $items = [];
        foreach ($value['items'] as $item) {
            if (! is_array($item)
                || ! in_array($item['type'] ?? null, ['photo', 'video', 'document'], true)
                || ! is_string($item['source'] ?? null)
                || trim($item['source']) === '') {
                throw new InvalidArgumentException('The notification template media is invalid.');
            }

            $name = $item['name'] ?? null;
            if ($name !== null && (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255)) {
                throw new InvalidArgumentException('The notification template media is invalid.');
            }

            $items[] = [
                'type' => (string) $item['type'],
                'source' => trim($item['source']),
                'name' => $name === null ? null : trim($name),
            ];
        }

        $types = array_values(array_unique(array_column($items, 'type')));
        if (count($items) > 1 && in_array('document', $types, true) && count($types) > 1) {
            throw new InvalidArgumentException('The notification template media group is invalid.');
        }

        return ['items' => $items];
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new InvalidArgumentException('The notification template active state is invalid.');
        }

        return $parsed;
    }

    /** @return list<string> */
    private static function allowedVariables(ScenarioRulePurpose $purpose): array
    {
        return ScenarioTemplateVariableCatalog::allowedForPurpose($purpose);
    }
}
