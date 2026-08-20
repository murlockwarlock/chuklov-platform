<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Models\ContentSection;
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
        $contentSections = $this->sections->handle($section);
        $locale = $this->resolveLocale($request, $clientContext);
        $selectedSections = $contentSections->where('locale', $locale);

        if ($selectedSections->isEmpty()) {
            $locale = $locale === 'en' ? 'ru' : 'en';
            $selectedSections = $contentSections->where('locale', $locale);
        }

        abort_unless($selectedSections->isNotEmpty(), 404);

        $content = $selectedSections->map(fn (ContentSection $contentSection): array => [
            'locale' => $contentSection->locale,
            'title' => $contentSection->title,
            'body' => $contentSection->body,
            'media' => self::projectMedia($contentSection->media, $this->imageResolver->resolve($contentSection)),
            'sortOrder' => $contentSection->sort_order,
        ])->values()->all();

        return Inertia::render('Portal/Section', [
            'section' => $section,
            'locale' => $locale,
            'content' => $content,
        ]);
    }

    private function resolveLocale(Request $request, ClientPortalContext $clientContext): string
    {
        try {
            $language = $clientContext->client()->language;
        } catch (LogicException) {
            $language = $request->session()->get('portal.locale')
                ?? $request->getPreferredLanguage(['ru', 'en']);
        }

        return str_starts_with(strtolower((string) $language), 'ru') ? 'ru' : 'en';
    }

    /**
     * @param  array<string, string>|null  $media
     * @return array{image: string, alt?: string}|null
     */
    private static function projectMedia(?array $media, ?string $imageUrl): ?array
    {
        if ($imageUrl === null || trim($imageUrl) === '') {
            return null;
        }

        $projected = ['image' => $imageUrl];
        $alt = $media['alt'] ?? null;

        if (is_string($alt) && trim($alt) !== '') {
            $projected['alt'] = $alt;
        }

        return $projected;
    }
}
