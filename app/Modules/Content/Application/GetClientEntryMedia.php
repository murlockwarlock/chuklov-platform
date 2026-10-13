<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;

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
            ->where('is_visible', true)->whereIn('locale', ['ru', 'en']);
        $sections = (clone $query)->whereIn('id', (clone $query)->selectRaw('MAX(id)')->groupBy('section_key', 'locale'))->orderByDesc('id')->get(['id', 'organization_id', 'section_key', 'locale', 'media']);
        $result = [];
        foreach (['telegram_welcome' => 'Приветствие', ...self::STORIES] as $key => $title) {
            $section = $sections->first(fn (ContentSection $section): bool => $section->section_key === $key && $section->locale === $locale)
                ?? $sections->first(fn (ContentSection $section): bool => $section->section_key === $key);
            $image = $section?->media['image'] ?? null;
            $subtitles = $section?->media['subtitles'] ?? null;
            $result[$key] = [
                'key' => $key, 'title' => $title,
                'videoUrl' => $section !== null ? app(ContentVideoUrlResolver::class)->resolve($section) : null,
                'previewUrl' => $organizationId !== null && is_string($image) && $this->storage->isManagedPath($organizationId, $image) ? $this->storage->url($image) : null,
                'subtitlesUrl' => is_string($subtitles) && filter_var($subtitles, FILTER_VALIDATE_URL) !== false && str_starts_with($subtitles, 'https://') ? $subtitles : null,
            ];
        }

        return $result;
    }
}
