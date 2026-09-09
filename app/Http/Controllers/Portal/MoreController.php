<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class MoreController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Portal/More');
    }
}
