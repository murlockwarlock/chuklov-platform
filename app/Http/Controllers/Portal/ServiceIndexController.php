<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Services\Application\ListPublishedServices;
use Inertia\Inertia;
use Inertia\Response;

class ServiceIndexController extends Controller
{
    public function __invoke(ListPublishedServices $services): Response
    {
        return Inertia::render('Services/Index', [
            'services' => $services->handle()->map->only(['id', 'name', 'summary']),
            'runtimeMode' => 'web',
        ]);
    }
}
