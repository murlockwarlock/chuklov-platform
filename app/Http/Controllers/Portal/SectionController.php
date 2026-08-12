<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    public function __invoke(string $section): Response
    {
        $sections = config('portal.telegram.sections', []);
        abort_unless(is_array($sections) && array_key_exists($section, $sections), 404);

        return Inertia::render('Portal/Section', [
            'section' => $section,
            'title' => $sections[$section]['title'] ?? ['en' => $section, 'ru' => $section],
        ]);
    }
}
