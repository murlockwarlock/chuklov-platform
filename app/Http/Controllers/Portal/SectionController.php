<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\ListPublishedContentSections;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    public function __construct(private readonly ListPublishedContentSections $sections) {}

    public function __invoke(string $section): Response
    {
        $contentSections = $this->sections->handle($section);
        abort_unless($contentSections->isNotEmpty(), 404);

        $content = $contentSections->mapWithKeys(fn ($contentSection): array => [
            $contentSection->locale => [
                'title' => $contentSection->title,
                'body' => $contentSection->body,
                'media' => $contentSection->media,
            ],
        ])->all();

        return Inertia::render('Portal/Section', [
            'section' => $section,
            'content' => $content,
        ]);
    }
}
