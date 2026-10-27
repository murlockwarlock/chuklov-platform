<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;

final readonly class GetClientEntryMedia
{
    public const STORIES = [
        'story_author' => 'Кто такой Евгений Чуклов (видео)',
        'story_history' => 'История мастера (видео)',
        'story_method' => 'Авторский метод (видео)',
        'story_details' => 'Подробно о методике (видео)',
    ];

    public function __construct(private OrganizationContext $context, private ContentMediaStorageInterface $storage) {}

    /** @return array<string, array{key: string, title: string, videoUrl: ?string, previewUrl: ?string, subtitlesUrl: ?string}> */
    public function handle(string $locale): array
    {
        try {
            $organizationId = $this->context->id();
        } catch (\LogicException) {
            $organizationId = null;
        }
        $query = ContentSection::query()->where('organization_id', $organizationId)
            ->whereIn('section_key', ['telegram_welcome', ...array_keys(self::STORIES)])
            ->where('is_visible', true)->whereIn('locale', ['ru', 'en'])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('section_key', 'telegram_welcome')->whereIn('delivery_mode', [ContentDeliveryMode::Telegram->value, ContentDeliveryMode::Both->value]))
                ->orWhere(fn (Builder $query) => $query->whereIn('section_key', array_keys(self::STORIES))->whereIn('delivery_mode', [ContentDeliveryMode::MiniApp->value, ContentDeliveryMode::Both->value])));
        $sections = (clone $query)->whereIn('id', (clone $query)->selectRaw('MAX(id)')->groupBy('section_key', 'locale'))->orderByDesc('id')->get(['id', 'organization_id', 'section_key', 'locale', 'title', 'sort_order', 'media']);
        $result = [];
        $order = [];
        foreach (['telegram_welcome' => 'Приветствие', ...self::STORIES] as $key => $title) {
            $section = $sections->first(fn (ContentSection $section): bool => $section->section_key === $key && $section->locale === $locale)
                ?? $sections->first(fn (ContentSection $section): bool => $section->section_key === $key);
            $image = $section?->media['image'] ?? null;
            $subtitles = $section?->media['subtitles'] ?? null;
            $result[$key] = [
                'key' => $key, 'title' => $section?->title ?? $title,
                'videoUrl' => $section !== null ? app(ContentVideoUrlResolver::class)->resolve($section) : null,
                'previewUrl' => $organizationId !== null && is_string($image) && $this->storage->isManagedPath($organizationId, $image) ? $this->storage->url($image) : null,
                'subtitlesUrl' => is_string($subtitles) && $organizationId !== null && str_ends_with($subtitles, '.vtt') && $this->storage->isManagedPath($organizationId, $subtitles)
                    ? $this->storage->url($subtitles)
                    : (is_string($subtitles) && filter_var($subtitles, FILTER_VALIDATE_URL) !== false && str_starts_with($subtitles, 'https://') ? $subtitles : null),
            ];
            $order[$key] = $section?->sort_order ?? PHP_INT_MAX;
        }

        $welcome = $result['telegram_welcome'];
        unset($result['telegram_welcome']);
        uksort($result, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return ['telegram_welcome' => $welcome, ...$result];
    }
}
