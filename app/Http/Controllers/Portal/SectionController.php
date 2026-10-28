<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Support\RichText\RichTextDocument;
use App\Support\SupportedLocale;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class SectionController extends Controller
{
    public function __construct(
        private readonly ListPublishedContentSections $sections,
        private readonly ContentImageUrlResolver $imageResolver,
    ) {}

    public function __invoke(
        Request $request,
        ClientPortalContext $clientContext,
        string $section,
    ): Response {
        $sectionRegistry = config('portal.content_sections', []);
        if (! is_array($sectionRegistry) || ! array_key_exists($section, $sectionRegistry)) {
            abort(404);
        }

        $contentSections = $this->sections->handle($section, ContentDeliveryMode::MiniApp);
        $locale = $this->resolveLocale($request, $clientContext);
        $selectedSections = $contentSections->where('locale', $locale);

        if ($selectedSections->isEmpty() && $contentSections->isNotEmpty()) {
            $fallbackLocale = $locale === 'en' ? 'ru' : 'en';
            $fallbackSections = $contentSections->where('locale', $fallbackLocale);

            if ($fallbackSections->isNotEmpty()) {
                $locale = $fallbackLocale;
                $selectedSections = $fallbackSections;
            }
        }

        $content = $selectedSections->map(fn (ContentSection $contentSection): array => [
            'locale' => $contentSection->locale,
            'title' => $contentSection->title,
            'body' => $contentSection->body,
            'bodyHtml' => RichTextDocument::canonicalHtml($contentSection->body),
            'media' => self::projectMedia($contentSection->media, $this->imageResolver->resolve($contentSection)),
            'sortOrder' => $contentSection->sort_order,
        ])->values()->all();

        return Inertia::render('Portal/Section', [
            'section' => $section,
            'title' => $this->sectionTitle($sectionRegistry[$section], $locale, $section),
            'locale' => $locale,
            'content' => $content,
        ]);
    }

    private function sectionTitle(mixed $sectionConfig, string $locale, string $fallback): string
    {
        $titles = is_array($sectionConfig) && is_array($sectionConfig['title'] ?? null)
            ? $sectionConfig['title']
            : [];
        $title = $titles[$locale] ?? $titles['ru'] ?? $titles['en'] ?? $fallback;

        return is_string($title) && trim($title) !== '' ? $title : $fallback;
    }

    private function resolveLocale(Request $request, ClientPortalContext $clientContext): string
    {
        try {
            $language = $clientContext->client()->language;
        } catch (LogicException) {
            $language = $request->session()->get('portal.locale')
                ?? $request->getPreferredLanguage(['ru', 'en']);
        }

        return SupportedLocale::normalize($language);
    }

    /**
     * @param  array<string, mixed>|null  $media
     * @return array{image?: string, alt?: string, communities?: list<array{title: string, url: string, description: string|null}>}|null
     */
    private static function projectMedia(?array $media, ?string $imageUrl): ?array
    {
        $projected = [];
        if ($imageUrl !== null && trim($imageUrl) !== '') {
            $projected['image'] = $imageUrl;
        }

        $communities = is_array($media['communities'] ?? null) ? array_values(array_filter(
            $media['communities'],
            static fn (mixed $community): bool => is_array($community)
                && ($community['is_visible'] ?? true) === true
                && is_string($community['title'] ?? null)
                && trim($community['title']) !== ''
                && is_string($community['url'] ?? null)
                && str_starts_with($community['url'], 'https://')
                && filter_var($community['url'], FILTER_VALIDATE_URL) !== false,
        )) : [];
        usort($communities, static fn (array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0)));
        if ($communities !== []) {
            $projected['communities'] = array_map(static fn (array $community): array => [
                'title' => trim((string) $community['title']),
                'url' => trim((string) $community['url']),
                'description' => is_string($community['description'] ?? null) && trim($community['description']) !== ''
                    ? trim($community['description'])
                    : null,
            ], $communities);
        }

        if ($projected === []) {
            return null;
        }

        $alt = $media['alt'] ?? null;

        if (is_string($alt) && trim($alt) !== '') {
            $projected['alt'] = $alt;
        }

        return $projected;
    }
}
